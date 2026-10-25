#!/usr/bin/env python3
"""Download public TSW product images for exact TapeTech part SKUs."""

from __future__ import annotations

import argparse
import csv
import os
import tempfile
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

from scrape_tsw_catalog import (
    DEFAULT_SOURCES,
    ScrapeError,
    discover_product_refs,
    load_brands,
    normalize_supplier_sku,
    page_count,
    with_page,
)

HERE = Path(__file__).resolve().parent
DEFAULT_PARTS = HERE.parents[1] / "docs" / "reference" / "data" / "TSW" / "TSW Product Data - TapeTech Parts - Enriched.csv"
DEFAULT_OUTPUT = HERE / "results" / "images" / "tapetech-parts"
MANIFEST_FIELDS = ("brand", "sku", "name", "image_file", "image_url", "product_url", "source_page", "image_status", "download_error", "observed_at_utc")
MAX_IMAGE_BYTES = 20 * 1024 * 1024
ALLOWED_IMAGE_LOCATIONS = {
    ("s3.amazonaws.com", "/tswfastcomfiles/"),
    ("s3.amazonaws.com", "/gmscom/master/media-common/product/"),
    ("s3.amazonaws.com", "/capmatfiles/product/"),
    ("s3.amazonaws.com", "/kodarisfiles2/images/product/"),
    ("s3-us-west-2.amazonaws.com", "/gtsinteriorsupplycomfiles/product/"),
}
ALLOWED_IMAGE_TYPES = {"image/jpeg": ".jpg", "image/png": ".png", "image/webp": ".webp", "image/gif": ".gif"}


def read_targets(path: Path) -> dict[str, str]:
    targets: dict[str, str] = {}
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if not {"sku", "name"}.issubset(reader.fieldnames or []):
            raise ScrapeError("TapeTech parts CSV must contain sku and name columns")
        for row in reader:
            sku = (row.get("sku") or "").strip()
            if not sku:
                raise ScrapeError("TapeTech parts CSV contains a blank SKU")
            key = sku.casefold()
            if key in targets:
                raise ScrapeError(f"Duplicate TapeTech parts SKU: {sku}")
            targets[key] = (row.get("name") or "").strip()
    if not targets:
        raise ScrapeError("TapeTech parts CSV is empty")
    return targets


def checked_image_url(url: str, sku: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme != "https" or parsed.hostname not in {"s3.amazonaws.com", "s3-us-west-2.amazonaws.com", "www.tswfast.com", "tswfast.com"}:
        raise ScrapeError(f"Unsafe or missing TSW image URL for {sku}: {url}")
    if parsed.hostname in {"s3.amazonaws.com", "s3-us-west-2.amazonaws.com"} and not any(
        parsed.hostname == host and parsed.path.startswith(prefix) for host, prefix in ALLOWED_IMAGE_LOCATIONS
    ):
        raise ScrapeError(f"Image is outside the observed TSW asset namespaces for {sku}: {url}")


def download_image(url: str, target: Path, sku: str) -> str:
    checked_image_url(url, sku)
    request = urllib.request.Request(url, headers={
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36",
        "Referer": "https://www.tswfast.com/",
        "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
    })
    with urllib.request.urlopen(request, timeout=40) as response:
        content_type = response.headers.get_content_type().lower()
        suffix = ALLOWED_IMAGE_TYPES.get(content_type)
        if not suffix:
            raise ScrapeError(f"TSW image response for {sku} has unsupported content type {content_type!r}")
        declared = response.headers.get("Content-Length")
        if declared and int(declared) > MAX_IMAGE_BYTES:
            raise ScrapeError(f"TSW image for {sku} exceeds the {MAX_IMAGE_BYTES}-byte limit")
        data = response.read(MAX_IMAGE_BYTES + 1)
    if not data or len(data) > MAX_IMAGE_BYTES:
        raise ScrapeError(f"TSW image for {sku} is empty or exceeds the size limit")
    filename = Path(urlparse(url).path).name
    if not filename or filename in {".", ".."}:
        filename = f"{sku}{suffix}"
    if Path(filename).suffix.lower() not in ALLOWED_IMAGE_TYPES.values():
        filename += suffix
    output = target / filename
    if output.exists():
        if output.read_bytes() != data:
            raise ScrapeError(f"Different TSW image payloads collide at {output.name}")
    else:
        fd, temp_name = tempfile.mkstemp(prefix=f".{filename}.", suffix=".tmp", dir=target)
        try:
            with os.fdopen(fd, "wb") as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temp_name, output)
        except Exception:
            Path(temp_name).unlink(missing_ok=True)
            raise
    return filename


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--parts-csv", type=Path, default=DEFAULT_PARTS)
    parser.add_argument("--brands-file", type=Path, default=DEFAULT_SOURCES)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--timeout-seconds", type=float, default=40.0)
    parser.add_argument("--delay-seconds", type=float, default=0.5)
    args = parser.parse_args()
    if args.timeout_seconds <= 0 or args.delay_seconds < 0:
        parser.error("timeout must be positive and delay must not be negative")

    targets = read_targets(args.parts_csv)
    sources = [source for source in load_brands(args.brands_file) if source.source_name.casefold() == "tapetech"]
    if len(sources) != 1:
        raise ScrapeError("Expected exactly one configured TapeTech catalog source")
    source = sources[0]
    found: dict[str, tuple[str, str, int]] = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page()
        try:
            page.goto(with_page(source.url, 1), wait_until="domcontentloaded", timeout=int(args.timeout_seconds * 1000))
            page.wait_for_selector(".cp-product", timeout=int(args.timeout_seconds * 1000))
            pages = page_count(page)
            for page_number in range(1, pages + 1):
                if page_number > 1:
                    page.goto(with_page(source.url, page_number), wait_until="domcontentloaded", timeout=int(args.timeout_seconds * 1000))
                    page.wait_for_selector(".cp-product", timeout=int(args.timeout_seconds * 1000))
                for product in discover_product_refs(page, page_number):
                    sku = normalize_supplier_sku(source.name, product.listing_sku)
                    key = sku.casefold()
                    if key not in targets:
                        continue
                    checked_image_url(product.image_url, sku)
                    value = (product.image_url, product.url, product.source_catalog_page)
                    if key in found and found[key] != value:
                        raise ScrapeError(f"Conflicting duplicate TapeTech listing for part SKU {sku}")
                    found[key] = value
                if args.delay_seconds:
                    page.wait_for_timeout(int(args.delay_seconds * 1000))
        finally:
            browser.close()

    # Some older parts have left the category listing but still resolve by TSW's stable part route.
    missing_from_listing = sorted(set(targets) - set(found))
    if missing_from_listing:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            page = browser.new_page()
            try:
                for key in missing_from_listing:
                    sku = next(value for value in targets if value.casefold() == key)
                    supplier_sku = f"TTT{sku}"
                    url = f"https://www.tswfast.com/product/{supplier_sku}"
                    response = page.goto(url, wait_until="domcontentloaded", timeout=int(args.timeout_seconds * 1000))
                    if response and response.status == 200 and page.locator(".cv-info").count():
                        detail = page.locator(".cv-info").inner_text()
                        if f"PART {supplier_sku}".casefold() in " ".join(detail.split()).casefold():
                            image = page.locator("img").evaluate_all(
                                "els => els.map(el => el.getAttribute('src') || '').find(src => /\\/product\\//i.test(src)) || ''"
                            )
                            if image:
                                from urllib.parse import urljoin
                                image_url = urljoin(url, image)
                                checked_image_url(image_url, sku)
                                found[key] = (image_url, url, 0)
                    if args.delay_seconds:
                        page.wait_for_timeout(int(args.delay_seconds * 1000))
            finally:
                browser.close()

    output_dir = args.output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    observed = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    rows = []
    for key, name in targets.items():
        sku = next(sku for sku in targets if sku.casefold() == key)
        listing = found.get(key)
        if listing:
            image_url, product_url, source_page = listing
        source_filename = Path(urlparse(image_url).path).name.casefold()
        placeholder = (
            source_filename == "product_image_blank_2.png"
            or "image_coming_soon" in source_filename
        )
            download_error = ""
            if placeholder:
                filename, status = "", "placeholder"
            else:
                try:
                    filename, status = download_image(image_url, output_dir, sku), "downloaded"
                except (urllib.error.URLError, OSError, ScrapeError) as exc:
                    filename, status = "", "download_failed"
                    download_error = f"{type(exc).__name__}: {exc}"
        else:
            image_url = ""
            product_url = f"https://www.tswfast.com/product/TTT{sku}"
            source_page = 0
            filename = ""
            status = "unavailable"
            download_error = ""
        rows.append({
            "brand": "TapeTech", "sku": sku, "name": name, "image_file": filename,
            "image_url": image_url, "product_url": product_url,
            "source_page": str(source_page),
            "image_status": status,
            "download_error": download_error,
            "observed_at_utc": observed,
        })
    rows.sort(key=lambda row: row["sku"].casefold())
    manifest = output_dir / "tapetech-parts-image-manifest.csv"
    fd, temp_name = tempfile.mkstemp(prefix=f".{manifest.name}.", suffix=".tmp", dir=output_dir)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS, lineterminator="\n")
            writer.writeheader()
            writer.writerows(rows)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, manifest)
    except Exception:
        Path(temp_name).unlink(missing_ok=True)
        raise
    print(f"Exported {len(rows)} TapeTech part images to {output_dir}")
    print(f"Images without product imagery (placeholder or unavailable): {sum(not row['image_file'] for row in rows)}")
    print(f"Image downloads failed: {sum(row['image_status'] == 'download_failed' for row in rows)}")
    print(f"Manifest: {manifest}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ScrapeError as exc:
        raise SystemExit(f"ERROR: {exc}")

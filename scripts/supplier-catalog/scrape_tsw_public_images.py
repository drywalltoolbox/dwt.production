#!/usr/bin/env python3
"""Export public TSW catalog image evidence without using account-specific prices."""

from __future__ import annotations

import argparse
import csv
import os
import tempfile
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
DEFAULT_OUTPUT = HERE / "results" / "cost" / "usg-public-images.csv"
FIELDS = ("brand", "sku", "supplier_sku", "image_url", "product_url", "source_page", "observed_at_utc")
ALLOWED_IMAGE_HOSTS = {"s3.amazonaws.com", "www.tswfast.com", "tswfast.com"}


def collect(source_name: str, config: Path, timeout_ms: int, delay_ms: int) -> list[dict[str, str]]:
    sources = [item for item in load_brands(config) if item.source_name.casefold() == source_name.casefold()]
    if len(sources) != 1:
        raise ScrapeError(f"Expected exactly one configured supplier source named {source_name!r}")
    source = sources[0]
    items: dict[str, dict[str, str]] = {}
    observed = datetime.now(timezone.utc).replace(microsecond=0).isoformat()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page()
        try:
            page.goto(with_page(source.url, 1), wait_until="domcontentloaded", timeout=timeout_ms)
            page.wait_for_selector(".cp-product", timeout=timeout_ms)
            total = page_count(page)
            for page_num in range(1, total + 1):
                if page_num > 1:
                    page.goto(with_page(source.url, page_num), wait_until="domcontentloaded", timeout=timeout_ms)
                    page.wait_for_selector(".cp-product", timeout=timeout_ms)
                for product in discover_product_refs(page, page_num):
                    sku = normalize_supplier_sku(source.name, product.listing_sku)
                    image = product.image_url
                    parsed = urlparse(image)
                    if parsed.scheme != "https" or parsed.hostname not in ALLOWED_IMAGE_HOSTS:
                        raise ScrapeError(f"Unsafe or missing supplier image URL for {sku}")
                    if parsed.hostname == "s3.amazonaws.com" and not parsed.path.startswith("/tswfastcomfiles/"):
                        raise ScrapeError(f"Image is outside the official TSW asset namespace for {sku}")
                    row = {
                        "brand": source.name,
                        "sku": sku,
                        "supplier_sku": product.listing_sku,
                        "image_url": image,
                        "product_url": product.url,
                        "source_page": str(page_num),
                        "observed_at_utc": observed,
                    }
                    key = sku.casefold()
                    if key in items and items[key] != row:
                        raise ScrapeError(f"Conflicting duplicate supplier listing for {sku}")
                    items[key] = row
                if delay_ms:
                    page.wait_for_timeout(delay_ms)
        finally:
            browser.close()
    return sorted(items.values(), key=lambda item: item["sku"].casefold())


def write_csv_atomic(output: Path, rows: list[dict[str, str]]) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=f".{output.name}.", suffix=".tmp", dir=output.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=FIELDS, extrasaction="raise", lineterminator="\n")
            writer.writeheader()
            writer.writerows(rows)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, output)
    except Exception:
        Path(tmp).unlink(missing_ok=True)
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-name", required=True, help="exact source_name from catalog-sources.json")
    parser.add_argument("--brands-file", type=Path, default=DEFAULT_SOURCES)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--timeout-seconds", type=float, default=30.0)
    parser.add_argument("--delay-seconds", type=float, default=0.5)
    args = parser.parse_args()
    if args.timeout_seconds <= 0 or args.delay_seconds < 0:
        parser.error("timeout must be positive and delay must not be negative")
    rows = collect(args.source_name, args.brands_file, int(args.timeout_seconds * 1000), int(args.delay_seconds * 1000))
    write_csv_atomic(args.output, rows)
    print(f"Exported {len(rows)} public image-reference rows to {args.output}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ScrapeError as exc:
        raise SystemExit(f"ERROR: {exc}")

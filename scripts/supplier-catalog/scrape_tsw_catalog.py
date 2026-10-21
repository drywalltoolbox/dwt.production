#!/usr/bin/env python3
"""Export authenticated TSW brand catalogs without persisting credentials."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import re
import sys
import tempfile
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urljoin, urlparse, urlunparse

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeoutError, sync_playwright


BASE_URL = "https://www.tswfast.com"
CHECKPOINT_SCHEMA_VERSION = 6
DEFAULT_COOKIES = Path(__file__).resolve().parent / ".tsw-cookies.json"
DEFAULT_PROFILE = Path(__file__).resolve().parent / ".browser-profile"
DEFAULT_SOURCES = Path(__file__).resolve().parent / "catalog-sources.json"
DEFAULT_OUTPUT = Path(__file__).resolve().parent / "results" / "cost" / "tsw-costs.csv"
PRICE_RE = re.compile(r"\$\s*([0-9,]+(?:\.[0-9]+)?)")
FIELDNAMES = [
    "source_name",
    "brand",
    "sku",
    "product_name",
    "manufacturer",
    "supplier_cost",
    "currency",
    "price_display",
    "price_unit",
    "product_description",
    "product_description_html",
    "product_url",
    "image_url",
    "source_catalog_page",
    "scraped_at_utc",
]
OUTPUT_FIELDS = [
    "brand",
    "sku",
    "product_name",
    "supplier_cost",
    "product_description_html",
]
BRAND_PREFIXES = {
    "Columbia Tools": ("CTT",),
    "TapeTech": ("TTT", "AME"),
    "Dura-Stilts": ("DSS",),
    "SurPro": ("SUR",),
    "USG Sheetrock® Tools": ("USG",),
}
GLOBAL_EXCLUDE_NAME_CONTAINS = (
    "kit",
    "trowel",
    "knife",
    "knives",
    "sand",
    "sponge",
    "smoothing blade",
)


class ScrapeError(RuntimeError):
    pass


@dataclass(frozen=True)
class Brand:
    source_name: str
    name: str
    url: str
    exclude_name_contains: tuple[str, ...] = ()


@dataclass(frozen=True)
class ProductRef:
    listing_sku: str
    url: str
    image_url: str
    source_catalog_page: int


def normalize_supplier_sku(brand: str, sku: str) -> str:
    prefixes = BRAND_PREFIXES.get(brand)
    if prefixes is None:
        raise ScrapeError(f"No explicit TSW SKU-prefix rule exists for {brand!r}")
    prefix = next((value for value in prefixes if sku.upper().startswith(value)), None)
    if prefix is None:
        expected = ", ".join(repr(value) for value in prefixes)
        raise ScrapeError(f"{sku}: expected an explicit TSW prefix ({expected}) for {brand}")
    normalized = sku[len(prefix):].strip()
    if not normalized:
        raise ScrapeError(f"{sku}: TSW prefix removal produced an empty SKU")
    return normalized


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--login", action="store_true", help="open TSW login and save a local browser session")
    parser.add_argument("--brands-file", type=Path, default=DEFAULT_SOURCES, help="JSON catalog source configuration")
    parser.add_argument("--only-source", action="append", default=[], help="scrape only the named configured source (repeatable)")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--cookies-file", type=Path, default=DEFAULT_COOKIES)
    parser.add_argument("--profile-dir", type=Path, default=DEFAULT_PROFILE)
    parser.add_argument("--headed", action="store_true", help="show Chromium during scraping")
    parser.add_argument("--delay-seconds", type=float, default=0.5, help="pause between individual product requests")
    parser.add_argument("--timeout-seconds", type=float, default=30.0)
    parser.add_argument("--allow-missing-prices", action="store_true")
    parser.add_argument("--full-evidence", action="store_true", help="include source URLs, image URLs, price units and provenance in CSV")
    parser.add_argument("--include-excluded-names", action="store_true", help="include product names normally excluded from cost-only exports")
    args = parser.parse_args()
    if args.delay_seconds < 0 or args.timeout_seconds <= 0:
        parser.error("timeouts must be positive and delay must not be negative")
    return args


def load_brands(path: Path) -> list[Brand]:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ScrapeError(f"Cannot read brands file {path}: {exc}") from exc
    if isinstance(raw, dict):
        raw = [
            {"source_name": name, "brand": name, "url": url}
            for name, url in raw.items()
        ]
    if not isinstance(raw, list) or not raw:
        raise ScrapeError("Brands file must be a nonempty JSON array of catalog source objects")
    brands: list[Brand] = []
    source_names: set[str] = set()
    for entry in raw:
        if not isinstance(entry, dict):
            raise ScrapeError("Every catalog source must be a JSON object")
        source_name = entry.get("source_name")
        name = entry.get("brand")
        url = entry.get("url")
        exclusions = entry.get("exclude_name_contains", [])
        if not all(isinstance(value, str) and value.strip() for value in (source_name, name, url)):
            raise ScrapeError("Every source requires nonempty source_name, brand, and url strings")
        if not isinstance(exclusions, list) or not all(isinstance(value, str) and value for value in exclusions):
            raise ScrapeError("exclude_name_contains must be an array of nonempty strings")
        source_key = source_name.strip().casefold()
        if source_key in source_names:
            raise ScrapeError(f"Duplicate source_name in brands file: {source_name}")
        source_names.add(source_key)
        parsed = urlparse(url)
        if parsed.scheme != "https" or parsed.netloc.lower() not in {"tswfast.com", "www.tswfast.com"}:
            raise ScrapeError(f"Brand URL is not an HTTPS tswfast.com URL: {url}")
        if not parsed.path.startswith("/category/"):
            raise ScrapeError(f"Brand URL is not a TSW category page: {url}")
        brands.append(
            Brand(
                source_name=source_name.strip(),
                name=name.strip(),
                url=url,
                exclude_name_contains=tuple(exclusions),
            )
        )
    return brands


def is_logged_in(page: Page) -> bool:
    return bool(
        page.evaluate(
            """() => {
                if (window.kd?.customer?.isLoggedIn === true) return true;
                const logout = [...document.querySelectorAll('a')].some(link => {
                    const href = (link.getAttribute('href') || '').toLowerCase();
                    const text = (link.textContent || '').trim().toLowerCase();
                    return href.includes('/logout') || text === 'logout';
                });
                const accountNavigation = document.querySelector(
                    'a[href*="/account/orders"], a[href*="/account/open-invoices"], a[href*="/account/my-lists"]'
                );
                return logout && Boolean(accountNavigation);
            }"""
        )
    )


def login(page: Page, timeout_ms: int, cookies_file: Path) -> None:
    page.goto(f"{BASE_URL}/spcu/login", wait_until="domcontentloaded", timeout=timeout_ms)
    print("Sign in to TSW in the opened browser. Waiting for the authenticated session ...")
    deadline = time.monotonic() + max(timeout_ms / 1000, 300)
    while time.monotonic() < deadline:
        try:
            if is_logged_in(page):
                page.wait_for_load_state("networkidle", timeout=timeout_ms)
                cookies = page.context.cookies()
                write_json_atomic(cookies_file, cookies)
                print("Authenticated TSW session saved in the local browser profile.")
                return
        except Exception:
            pass
        page.wait_for_timeout(1000)
    raise ScrapeError("Timed out waiting for TSW login")


def with_page(url: str, page_number: int) -> str:
    parsed = urlparse(url)
    query = dict(parse_qsl(parsed.query, keep_blank_values=True))
    query["from"] = str(page_number)
    return urlunparse(parsed._replace(query=urlencode(query)))


def wait_for_product_price(page: Page, timeout_ms: int) -> None:
    page.wait_for_selector(".cv-info .cp-price", timeout=timeout_ms)
    try:
        page.wait_for_function(
            """() => {
                const el = document.querySelector('.cv-info .cp-price');
                return el && !el.classList.contains('cp-price--loading') &&
                    (el.textContent || '').trim().length > 0 &&
                    !/calculating price/i.test(el.textContent || '');
            }""",
            timeout=timeout_ms,
        )
    except PlaywrightTimeoutError as exc:
        raise ScrapeError(f"Timed out waiting for the product price on {page.url}") from exc


def page_count(page: Page) -> int:
    locator = page.locator("#page")
    if not locator.count():
        return 1
    value = locator.first.get_attribute("max") or "1"
    try:
        count = int(value)
    except ValueError as exc:
        raise ScrapeError(f"Invalid page count {value!r} on {page.url}") from exc
    if count < 1 or count > 10_000:
        raise ScrapeError(f"Unsafe page count {count} on {page.url}")
    return count


def normalize_price(display: str) -> tuple[str, str, str]:
    text = " ".join(display.split())
    match = PRICE_RE.search(text)
    if not match:
        return "", "", ""
    try:
        amount = Decimal(match.group(1).replace(",", ""))
    except InvalidOperation:
        return "", "", ""
    unit_match = re.search(r"\bEACH\b(?:\s+of\s+\d+)?|\b(?:BOX|CASE|PACK|PAIR|SET)\b(?:\s+of\s+\d+)?", text, re.I)
    return format(amount, "f"), "USD", unit_match.group(0) if unit_match else ""


def discover_product_refs(page: Page, page_number: int) -> list[ProductRef]:
    raw = page.locator(".cp-product").evaluate_all(
        """els => els.map(el => {
            const link = el.querySelector('.cp-name');
            const image = el.querySelector('.cvg-img img');
            return {
                sku: (el.dataset.code || '').trim(),
                href: link?.getAttribute('href') || '',
                image: image?.getAttribute('src') || ''
            };
        })"""
    )
    refs: list[ProductRef] = []
    for item in raw:
        sku = str(item.get("sku", "")).strip()
        product_url = urljoin(BASE_URL, str(item.get("href", "")))
        if not sku or not urlparse(product_url).path.startswith("/product/"):
            raise ScrapeError(f"Product listing missing SKU or detail URL on {page.url}")
        refs.append(
            ProductRef(
                listing_sku=sku,
                url=product_url,
                image_url=urljoin(BASE_URL, str(item.get("image", ""))),
                source_catalog_page=page_number,
            )
        )
    if not refs:
        raise ScrapeError(f"No products found on {page.url}")
    return refs


def scrape_product_detail(
    page: Page, brand: Brand, product: ProductRef, timeout_ms: int, scraped_at: str
) -> dict[str, str]:
    page.goto(product.url, wait_until="domcontentloaded", timeout=timeout_ms)
    if not page.locator(".cv-info").count():
        fallback_url = f"{BASE_URL}/product/{product.listing_sku}"
        print(f"    supplier detail URL did not render; retrying stable SKU route {fallback_url}")
        page.goto(fallback_url, wait_until="domcontentloaded", timeout=timeout_ms)
    if not is_logged_in(page):
        raise ScrapeError(f"TSW session is not authenticated while loading {product.url}")
    page.wait_for_selector(".cv-info .cp-name", timeout=timeout_ms)
    wait_for_product_price(page, timeout_ms)
    detail = page.evaluate(
        """() => {
            const clean = value => (value || '').replace(/\s+/g, ' ').trim();
            const attributes = {};
            document.querySelectorAll('.cv-info .cv-attribute').forEach(el => {
                const spans = [...el.querySelectorAll(':scope > span')];
                if (spans.length >= 2) attributes[clean(spans[0].textContent).toUpperCase()] = clean(spans[1].textContent);
            });
            const description = document.querySelector('#product-description');
            const selectedUnit = document.querySelector('.cv-info .cp-units option:checked');
            const image = document.querySelector('.cv-images img, .cv-image img, .product-images img');
            return {
                name: clean(document.querySelector('.cv-info .cp-name')?.textContent),
                part: attributes.PART || '',
                manufacturer: attributes.MANUFACTURER || '',
                price: clean(document.querySelector('.cv-info .cp-price')?.textContent),
                unit: clean(selectedUnit?.textContent || selectedUnit?.value),
                description: description ? description.innerText.trim() : '',
                descriptionHtml: description ? description.innerHTML.trim() : '',
                image: image?.getAttribute('src') || ''
            };
        }"""
    )
    sku = str(detail.get("part", "")).strip()
    name = " ".join(str(detail.get("name", "")).split())
    if not sku or not name:
        raise ScrapeError(f"Product detail page missing PART or name: {product.url}")
    if sku.casefold() != product.listing_sku.casefold():
        raise ScrapeError(
            f"Listing/detail part mismatch on {product.url}: {product.listing_sku!r} != {sku!r}"
        )
    display = " ".join(str(detail.get("price", "")).split())
    amount, currency, inferred_unit = normalize_price(display)
    return {
        "source_name": brand.source_name,
        "brand": brand.name,
        "sku": normalize_supplier_sku(brand.name, sku),
        "product_name": name,
        "manufacturer": " ".join(str(detail.get("manufacturer", "")).split()),
        "supplier_cost": amount,
        "currency": currency,
        "price_display": display,
        "price_unit": str(detail.get("unit", "")).strip() or inferred_unit,
        "product_description": str(detail.get("description", "")).strip(),
        "product_description_html": str(detail.get("descriptionHtml", "")).strip(),
        "product_url": page.url,
        "image_url": (
            urljoin(BASE_URL, str(detail.get("image", "")))
            if str(detail.get("image", "")).strip()
            else product.image_url
        ),
        "source_catalog_page": str(product.source_catalog_page),
        "scraped_at_utc": scraped_at,
    }


def checkpoint_key(source_name: str, sku: str) -> str:
    return f"{source_name.casefold()}\0{sku.casefold()}"


def checkpoint_path(output: Path) -> Path:
    return output.with_name(output.name + ".checkpoint.json")


def config_fingerprint(path: Path) -> str:
    try:
        return hashlib.sha256(path.read_bytes()).hexdigest()
    except OSError as exc:
        raise ScrapeError(f"Cannot fingerprint catalog source configuration {path}: {exc}") from exc


def load_checkpoint(path: Path, fingerprint: str) -> dict[str, dict[str, object]]:
    if not path.exists():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ScrapeError(f"Cannot read progress checkpoint {path}: {exc}") from exc
    if not isinstance(payload, dict):
        raise ScrapeError(f"Progress checkpoint {path} must contain a JSON object")
    if payload.get("schema_version") != CHECKPOINT_SCHEMA_VERSION or payload.get("config_sha256") != fingerprint:
        raise ScrapeError(
            f"Progress checkpoint {path} does not match the current source configuration; "
            "move it aside before starting a new catalog definition"
        )
    raw_items = payload.get("items")
    if not isinstance(raw_items, dict):
        raise ScrapeError(f"Progress checkpoint {path} is missing its items object")
    items: dict[str, dict[str, object]] = {}
    for key, item in raw_items.items():
        if not isinstance(key, str) or not isinstance(item, dict):
            raise ScrapeError(f"Progress checkpoint {path} contains invalid item data")
        status = item.get("status")
        if status not in {"included", "excluded"}:
            raise ScrapeError(f"Progress checkpoint {path} contains invalid status {status!r}")
        row = item.get("row")
        if status == "included" and not isinstance(row, dict):
            raise ScrapeError(f"Progress checkpoint {path} has an included item without a row")
        items[key] = item
    return items


def write_json_atomic(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, indent=2, ensure_ascii=False)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, path)
    except Exception:
        try:
            os.unlink(temp_name)
        except OSError:
            pass
        raise


def save_checkpoint(path: Path, fingerprint: str, items: dict[str, dict[str, object]]) -> None:
    write_json_atomic(
        path,
        {
            "schema_version": CHECKPOINT_SCHEMA_VERSION,
            "config_sha256": fingerprint,
            "updated_at_utc": datetime.now(timezone.utc).isoformat(),
            "items": items,
        },
    )


def compact_csv_text(value: object) -> str:
    return " ".join(str(value or "").replace("\r", " ").replace("\n", " ").split())


def write_output_atomic(path: Path, rows: list[dict[str, object]], fields: list[str] = OUTPUT_FIELDS) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(
                handle,
                fieldnames=fields,
                extrasaction="ignore",
                quoting=csv.QUOTE_NONNUMERIC,
                lineterminator="\n",
            )
            writer.writeheader()
            for row in rows:
                exported = {field: compact_csv_text(row.get(field, "")) for field in fields}
                cost = exported["supplier_cost"]
                if cost:
                    exported["supplier_cost"] = float(Decimal(cost).quantize(Decimal("0.01")))
                writer.writerow(exported)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, path)
    except Exception:
        try:
            os.unlink(temp_name)
        except OSError:
            pass
        raise


def excluded_by_name(brand: Brand, name: str) -> bool:
    lowered = name.casefold()
    exclusions = GLOBAL_EXCLUDE_NAME_CONTAINS + tuple(value.casefold() for value in brand.exclude_name_contains)
    return any(value in lowered for value in exclusions)


def included_rows(items: dict[str, dict[str, object]]) -> list[dict[str, object]]:
    rows = [item["row"] for item in items.values() if item.get("status") == "included"]
    return sorted(rows, key=lambda row: (str(row.get("brand", "")).casefold(), str(row.get("sku", "")).casefold()))


def validate_rows(rows: list[dict[str, object]], allow_missing_prices: bool) -> None:
    seen: dict[tuple[str, str], dict[str, object]] = {}
    missing: list[str] = []
    for row in rows:
        brand = str(row.get("brand", ""))
        sku = str(row.get("sku", ""))
        key = (brand.casefold(), sku.casefold())
        existing = seen.get(key)
        if existing:
            same_cost = str(existing.get("supplier_cost", "")) == str(row.get("supplier_cost", ""))
            same_currency = str(existing.get("currency", "")) == str(row.get("currency", ""))
            if not same_cost or not same_currency:
                raise ScrapeError(f"Conflicting duplicate supplier cost for {brand} {sku}")
        else:
            seen[key] = row
        if not str(row.get("supplier_cost", "")).strip() and "call for" not in str(row.get("price_display", "")).casefold():
            missing.append(f"{brand} {sku}")
    if missing and not allow_missing_prices:
        preview = ", ".join(missing[:10])
        suffix = " ..." if len(missing) > 10 else ""
        raise ScrapeError(f"Supplier prices unresolved for {len(missing)} products: {preview}{suffix}")


def load_cookies(path: Path) -> list[dict]:
    if not path.exists():
        raise ScrapeError("TSW session is not authenticated; run this script with --login first")
    try:
        cookies = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ScrapeError(f"Cannot read cookies file {path}: {exc}") from exc
    if not isinstance(cookies, list) or not cookies:
        raise ScrapeError("Cookies file is empty or invalid; run this script with --login first")
    return cookies


def main() -> int:
    args = parse_args()
    timeout_ms = int(args.timeout_seconds * 1000)
    fingerprint = config_fingerprint(args.brands_file)
    checkpoint = checkpoint_path(args.output)
    brands = load_brands(args.brands_file)
    if args.only_source:
        wanted = {value.strip().casefold() for value in args.only_source}
        available = {brand.source_name.casefold() for brand in brands}
        unknown = wanted - available
        if unknown:
            raise ScrapeError(f"Unknown configured sources: {', '.join(sorted(unknown))}")
        brands = [brand for brand in brands if brand.source_name.casefold() in wanted]
    if args.only_source or args.include_excluded_names:
        variant = json.dumps({"sources": [brand.source_name for brand in brands], "include_excluded_names": args.include_excluded_names}, sort_keys=True)
        fingerprint = hashlib.sha256(f"{fingerprint}:{variant}".encode("utf-8")).hexdigest()
    export_fields = FIELDNAMES if args.full_evidence else OUTPUT_FIELDS
    items = load_checkpoint(checkpoint, fingerprint)

    with sync_playwright() as playwright:
        if args.login:
            context = playwright.chromium.launch_persistent_context(
                str(args.profile_dir),
                headless=False,
            )
            page = context.pages[0] if context.pages else context.new_page()
            try:
                login(page, timeout_ms, args.cookies_file)
            finally:
                context.close()
            return 0

        cookies = load_cookies(args.cookies_file)
        context = playwright.chromium.launch(
            headless=not args.headed
        ).new_context()
        context.add_cookies(cookies)
        page = context.new_page()
        try:
            page.goto(f"{BASE_URL}/account/orders", wait_until="networkidle", timeout=timeout_ms)
            if "/login" in page.url or "/spcu/" in page.url:
                raise ScrapeError("TSW session is not authenticated; run this script with --login first")

            scraped_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
            for brand in brands:
                print(f"Scraping {brand.source_name} ...")
                page.goto(with_page(brand.url, 1), wait_until="domcontentloaded", timeout=timeout_ms)
                if not is_logged_in(page):
                    raise ScrapeError(f"TSW session expired while loading {brand.url}")
                total_pages = page_count(page)
                for page_number in range(1, total_pages + 1):
                    catalog_url = with_page(brand.url, page_number)
                    if page_number != 1:
                        page.goto(catalog_url, wait_until="domcontentloaded", timeout=timeout_ms)
                    refs = discover_product_refs(page, page_number)
                    for product in refs:
                        key = checkpoint_key(brand.source_name, product.listing_sku)
                        if key in items:
                            continue
                        print(f"  {product.listing_sku}")
                        row = scrape_product_detail(page, brand, product, timeout_ms, scraped_at)
                        if not args.include_excluded_names and excluded_by_name(brand, str(row["product_name"])):
                            items[key] = {"status": "excluded", "row": None}
                        else:
                            items[key] = {"status": "included", "row": row}
                        save_checkpoint(checkpoint, fingerprint, items)
                        write_output_atomic(args.output, included_rows(items), export_fields)
                        if args.delay_seconds:
                            page.wait_for_timeout(int(args.delay_seconds * 1000))
                        page.goto(catalog_url, wait_until="domcontentloaded", timeout=timeout_ms)
        finally:
            context.close()

    rows = included_rows(items)
    validate_rows(rows, args.allow_missing_prices)
    write_output_atomic(args.output, rows, export_fields)
    try:
        checkpoint.unlink()
    except FileNotFoundError:
        pass
    print(f"Wrote {len(rows)} supplier-cost rows to {args.output}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ScrapeError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)

from __future__ import annotations

import argparse
import csv
import json
import logging
import mimetypes
import os
import re
import sys
import tempfile
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import quote, urljoin, urlsplit
from urllib.robotparser import RobotFileParser

try:
    import cloudscraper
except ImportError as exc:
    raise SystemExit(
        "Missing dependency. Install it with: python -m pip install cloudscraper"
    ) from exc


BASE_URL = "https://shopcolumbiatools.com"
PAGE_SIZE = 250  # Shopify's public JSON endpoints cap this at 250.
USER_AGENT = "DTB-Columbia-Catalog-Exporter/1.0 (+https://drywalltoolbox.com)"
MAX_PAGES_PER_ENDPOINT = 1000
MAX_IMAGE_BYTES = 100 * 1024 * 1024
RETRYABLE_STATUS = {429, 500, 502, 503, 504}
LOG = logging.getLogger("columbia_catalog")


class ScrapeError(RuntimeError):
    """A source response is incomplete, blocked, or structurally unexpected."""


class RateLimiter:
    """Apply one minimum start-to-start delay across API and image requests."""

    def __init__(self, delay: float) -> None:
        self.delay = max(0.0, delay)
        self._lock = threading.Lock()
        self._next_start = 0.0

    def wait(self) -> None:
        with self._lock:
            now = time.monotonic()
            pause = max(0.0, self._next_start - now)
            self._next_start = max(now, self._next_start) + self.delay
        if pause:
            time.sleep(pause)


class PlainText(HTMLParser):
    """Small stdlib-only HTML-to-text conversion while preserving body_html."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []

    def handle_data(self, data: str) -> None:
        text = " ".join(data.split())
        if text:
            self.parts.append(text)


def html_to_text(value: str | None) -> str:
    parser = PlainText()
    parser.feed(value or "")
    return " ".join(parser.parts)


def make_session() -> Any:
    # cloudscraper supplies the requested HTTP client. Requests remain limited
    # to the public storefront API and its Shopify CDN image host.
    session = cloudscraper.create_scraper(
        browser={"browser": "chrome", "platform": "windows", "desktop": True}
    )
    session.headers.update(
        {
            "User-Agent": USER_AGENT,
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "en-US,en;q=0.8",
        }
    )
    return session


class ShopifyClient:
    def __init__(self, base_url: str, delay: float, timeout: float, retries: int) -> None:
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.retries = retries
        self.limiter = RateLimiter(delay)
        self.session = make_session()
        self._worker_local = threading.local()
        self._robots: dict[str, RobotFileParser] = {}
        self._robots_lock = threading.Lock()
        self._robots_for(self.base_url + "/")

    def _robots_for(self, url: str) -> RobotFileParser:
        parsed = urlsplit(url)
        origin = f"{parsed.scheme}://{parsed.netloc}"
        if origin in self._robots:
            return self._robots[origin]
        with self._robots_lock:
            if origin in self._robots:
                return self._robots[origin]
            robots_url = urljoin(origin + "/", "robots.txt")
            response = self._request(robots_url, self.session, check_robots=False)
            parser = RobotFileParser()
            parser.set_url(robots_url)
            parser.parse(response.text.splitlines())
            self._robots[origin] = parser
            return parser

    def _session_for_worker(self) -> Any:
        session = getattr(self._worker_local, "session", None)
        if session is None:
            session = make_session()
            self._worker_local.session = session
        return session

    def _request(
        self, url: str, session: Any, *, check_robots: bool = True, stream: bool = False
    ) -> Any:
        if check_robots:
            parsed = urlsplit(url)
            allowed_hosts = {urlsplit(self.base_url).hostname, "cdn.shopify.com"}
            if parsed.scheme != "https" or parsed.hostname not in allowed_hosts or parsed.port not in (None, 443):
                raise ScrapeError(f"Refusing URL outside the approved storefront/CDN hosts: {url}")
            if not self._robots_for(url).can_fetch(USER_AGENT, url):
                raise ScrapeError(f"robots.txt disallows this URL: {url}")

        for attempt in range(self.retries + 1):
            self.limiter.wait()
            try:
                response = session.get(url, timeout=self.timeout, stream=stream)
            except Exception as exc:
                if attempt >= self.retries:
                    raise ScrapeError(f"Request failed after retries for {url}: {exc}") from exc
                time.sleep(min(30.0, 1.5**attempt))
                continue

            if response.status_code == 403:
                response.close()
                raise ScrapeError(f"Source returned HTTP 403 (access denied) for {url}; aborting.")
            if response.status_code in RETRYABLE_STATUS:
                if attempt >= self.retries:
                    response.raise_for_status()
                retry_after = response.headers.get("Retry-After", "")
                try:
                    wait = max(0.0, min(120.0, float(retry_after)))
                except ValueError:
                    wait = min(60.0, 1.5**attempt)
                response.close()
                time.sleep(wait)
                continue
            if response.status_code >= 400:
                response.raise_for_status()
            return response

        raise ScrapeError(f"Request failed for {url}")

    def get_json(self, path: str) -> dict[str, Any]:
        url = path if path.startswith("http") else urljoin(self.base_url + "/", path.lstrip("/"))
        response = self._request(url, self.session)
        content_type = response.headers.get("Content-Type", "").lower()
        try:
            data = response.json()
        except Exception as exc:
            preview = response.text[:250].replace("\n", " ")
            raise ScrapeError(
                f"Expected JSON from {url}; got {content_type or 'unknown content type'}: {preview}"
            ) from exc
        if not isinstance(data, dict):
            raise ScrapeError(f"Expected a JSON object from {url}")
        return data

    def download_image(self, url: str, destination: Path, overwrite: bool) -> dict[str, Any]:
        if destination.is_file() and destination.stat().st_size > 0 and not overwrite:
            return {"status": "existing", "bytes": destination.stat().st_size}

        response = self._request(url, self._session_for_worker(), stream=True)
        content_type = response.headers.get("Content-Type", "").lower()
        if "text/html" in content_type:
            response.close()
            raise ScrapeError(f"Image URL returned HTML instead of an image: {url}")

        destination.parent.mkdir(parents=True, exist_ok=True)
        fd, temp_name = tempfile.mkstemp(prefix=".download-", dir=destination.parent)
        total = 0
        try:
            with os.fdopen(fd, "wb") as output:
                for chunk in response.iter_content(chunk_size=1024 * 128):
                    if not chunk:
                        continue
                    total += len(chunk)
                    if total > MAX_IMAGE_BYTES:
                        raise ScrapeError(f"Image exceeded {MAX_IMAGE_BYTES} bytes: {url}")
                    output.write(chunk)
            response.close()
            if total == 0:
                raise ScrapeError(f"Image response was empty: {url}")
            os.replace(temp_name, destination)
        except Exception:
            response.close()
            try:
                os.unlink(temp_name)
            except FileNotFoundError:
                pass
            raise
        return {"status": "downloaded", "bytes": total, "content_type": content_type}


def paginated_objects(
    client: ShopifyClient, endpoint: str, object_key: str
) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    seen: set[Any] = set()
    for page in range(1, MAX_PAGES_PER_ENDPOINT + 1):
        data = client.get_json(f"{endpoint}?limit={PAGE_SIZE}&page={page}")
        page_items = data.get(object_key)
        if not isinstance(page_items, list):
            raise ScrapeError(f"Expected list field {object_key!r} from {endpoint}, page {page}")
        before = len(seen)
        for item in page_items:
            if not isinstance(item, dict):
                continue
            key = item.get("id") or item.get("handle")
            if key is None or key not in seen:
                results.append(item)
                if key is not None:
                    seen.add(key)
        LOG.info("%s page %d: %d items", endpoint, page, len(page_items))
        if page_items and len(seen) == before:
            raise ScrapeError(
                f"Pagination made no progress for {endpoint}, page {page}; refusing to claim a complete export."
            )
        if len(page_items) < PAGE_SIZE:
            return results
    raise ScrapeError(f"Pagination safety limit reached for {endpoint}")


def image_records(product: dict[str, Any]) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    by_url: dict[str, dict[str, Any]] = {}

    def add(raw: Any, fallback_position: int) -> None:
        if isinstance(raw, str):
            item = {"src": raw, "position": fallback_position}
        elif isinstance(raw, dict):
            item = dict(raw)
        else:
            return
        src = item.get("src") or item.get("url")
        if not isinstance(src, str) or not src.strip():
            return
        item["src"] = src
        if src in by_url:
            for key, value in item.items():
                if by_url[src].get(key) in (None, "", []):
                    by_url[src][key] = value
            return
        by_url[src] = item
        records.append(item)

    for position, image in enumerate(product.get("images") or [], 1):
        add(image, position)
    for variant in product.get("variants") or []:
        if isinstance(variant, dict) and variant.get("featured_image"):
            add(variant["featured_image"], len(records) + 1)
    return records


def slugify(value: str, fallback: str = "item") -> str:
    value = re.sub(r"[^A-Za-z0-9._-]+", "-", value.strip()).strip("-._")
    return value[:100] or fallback


def image_extension(url: str, content_type: str = "") -> str:
    suffix = Path(urlsplit(url).path).suffix.lower()
    if suffix in {".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif", ".tif", ".tiff", ".bmp"}:
        return suffix
    guessed = mimetypes.guess_extension(content_type.split(";", 1)[0].strip()) if content_type else None
    return guessed or ".img"


def build_export_products(
    raw_products: dict[Any, dict[str, Any]],
    memberships: dict[Any, set[str]],
    collections_by_handle: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    exported = []
    for product_id, product in raw_products.items():
        handle = str(product.get("handle") or product_id)
        product_dir = Path("images") / f"{slugify(str(product_id))}-{slugify(handle)}"
        images = []
        for index, image in enumerate(image_records(product), 1):
            source = image["src"]
            ext = image_extension(source)
            filename = f"{index:02d}-{slugify(Path(urlsplit(source).path).stem, 'image')}{ext}"
            image["local_path"] = (product_dir / filename).as_posix()
            images.append(image)
        collection_handles = sorted(memberships.get(product_id, set()))
        collection_data = [
            {"handle": h, "title": collections_by_handle.get(h, {}).get("title", h)}
            for h in collection_handles
        ]
        exported.append(
            {
                "id": product_id,
                "title": product.get("title", ""),
                "handle": handle,
                "vendor": product.get("vendor", ""),
                "product_type": product.get("product_type", ""),
                "tags": product.get("tags", []),
                "description_html": product.get("body_html", ""),
                "description_text": html_to_text(product.get("body_html")),
                "collections": collection_data,
                "variants": product.get("variants", []),
                "images": images,
                "options": product.get("options", []),
                "shopify_payload": product,
            }
        )
    return sorted(exported, key=lambda p: (str(p["title"]).casefold(), str(p["id"])))


def save_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, path)


def write_variants_csv(path: Path, products: list[dict[str, Any]]) -> None:
    fields = [
        "product_id", "product_name", "handle", "vendor", "product_type", "tags",
        "collections", "variant_id", "variant_name", "sku", "price", "compare_at_price",
        "available", "description_html", "description_text", "image_urls", "image_files",
    ]
    with path.open("w", newline="", encoding="utf-8-sig") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields)
        writer.writeheader()
        for product in products:
            variants = product["variants"] or [{}]
            for variant in variants:
                writer.writerow(
                    {
                        "product_id": product["id"],
                        "product_name": product["title"],
                        "handle": product["handle"],
                        "vendor": product["vendor"],
                        "product_type": product["product_type"],
                        "tags": " | ".join(map(str, product["tags"] or [])),
                        "collections": " | ".join(c["title"] for c in product["collections"]),
                        "variant_id": variant.get("id", ""),
                        "variant_name": variant.get("title", ""),
                        "sku": variant.get("sku", ""),
                        "price": variant.get("price", ""),
                        "compare_at_price": variant.get("compare_at_price", ""),
                        "available": variant.get("available", ""),
                        "description_html": product["description_html"],
                        "description_text": product["description_text"],
                        "image_urls": " | ".join(i["src"] for i in product["images"]),
                        "image_files": " | ".join(i["local_path"] for i in product["images"]),
                    }
                )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=Path("columbia-catalog-export"))
    parser.add_argument("--delay", type=float, default=0.35, help="minimum seconds between request starts")
    parser.add_argument("--workers", type=int, default=3, help="parallel image downloads (1-6)")
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--retries", type=int, default=5)
    parser.add_argument("--skip-images", action="store_true", help="export image URLs but do not download image files")
    parser.add_argument("--overwrite-images", action="store_true", help="replace already-downloaded image files")
    args = parser.parse_args()
    args.workers = min(6, max(1, args.workers))
    args.delay = max(0.0, args.delay)

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    output: Path = args.output.expanduser().resolve()
    output.mkdir(parents=True, exist_ok=True)

    try:
        client = ShopifyClient(BASE_URL, args.delay, args.timeout, args.retries)
        LOG.info("Fetching public Shopify collections from %s", BASE_URL)
        collections = paginated_objects(client, "/collections.json", "collections")
        by_handle = {str(c.get("handle")): c for c in collections if c.get("handle")}
        if not by_handle:
            raise ScrapeError("No collections were returned by /collections.json")

        products: dict[Any, dict[str, Any]] = {}
        memberships: dict[Any, set[str]] = {}
        collection_audits = []

        for index, collection in enumerate(collections, 1):
            handle = collection.get("handle")
            if not handle:
                collection_audits.append({"collection": collection, "error": "missing handle"})
                continue
            endpoint = f"/collections/{quote(str(handle), safe='')}/products.json"
            collection_products = paginated_objects(client, endpoint, "products")
            for product in collection_products:
                product_id = product.get("id")
                if product_id is None:
                    continue
                products[product_id] = product
                memberships.setdefault(product_id, set()).add(str(handle))
            expected = collection.get("products_count")
            audit = {
                "handle": handle,
                "title": collection.get("title", ""),
                "products_count_reported": expected,
                "products_extracted": len(collection_products),
                "count_matches": expected is None or int(expected) == len(collection_products),
            }
            collection_audits.append(audit)
            LOG.info("[%d/%d] %s: %d products", index, len(collections), handle, len(collection_products))

        # Include published products that may not be assigned to a visible collection.
        global_products = paginated_objects(client, "/products.json", "products")
        for product in global_products:
            product_id = product.get("id")
            if product_id is not None:
                products[product_id] = product
                memberships.setdefault(product_id, set())
        LOG.info("Global products endpoint returned %d distinct products", len(global_products))

        exported = build_export_products(products, memberships, by_handle)
        save_json(output / "collections.json", collections)
        save_json(output / "products.json", exported)

        image_tasks = []
        for product in exported:
            for image in product["images"]:
                image_tasks.append((product["id"], image, output / image["local_path"]))

        image_failures = []
        if not args.skip_images and image_tasks:
            LOG.info("Downloading %d original product images", len(image_tasks))
            with ThreadPoolExecutor(max_workers=args.workers) as pool:
                futures = {
                    pool.submit(client.download_image, image["src"], path, args.overwrite_images):
                    (product_id, image, path)
                    for product_id, image, path in image_tasks
                }
                for completed, future in enumerate(as_completed(futures), 1):
                    product_id, image, path = futures[future]
                    try:
                        result = future.result()
                        image["download"] = result
                    except Exception as exc:
                        image["download"] = {"status": "error", "error": str(exc)}
                        image_failures.append(
                            {"product_id": product_id, "url": image["src"], "error": str(exc)}
                        )
                    if completed % 25 == 0 or completed == len(image_tasks):
                        LOG.info("Image downloads: %d/%d", completed, len(image_tasks))
            # Persist download results even when one or more individual images fail.
            save_json(output / "products.json", exported)

        write_variants_csv(output / "product_variants.csv", exported)
        mismatches = [audit for audit in collection_audits if not audit.get("count_matches", True)]
        summary = {
            "source": BASE_URL,
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "collections_count": len(collections),
            "global_products_count": len(global_products),
            "unique_products_count": len(exported),
            "product_image_count": len(image_tasks),
            "image_download_failures": len(image_failures),
            "collection_count_mismatches": len(mismatches),
            "complete": not mismatches and not image_failures,
            "collection_audits": collection_audits,
            "image_failures": image_failures,
            "notes": [
                "Prices and SKUs are variant-level values; product_variants.csv has one row per variant.",
                "description_html preserves Shopify body_html; description_text is a convenience rendering.",
                "Image URLs are the source URLs returned in product JSON; files are downloaded without resizing.",
                "Products are public products exposed by Shopify's public JSON endpoints; unpublished/private products are not accessible here.",
            ],
        }
        save_json(output / "scrape_summary.json", summary)
        LOG.info(
            "Export complete: %d collections, %d unique products, %d images; output=%s",
            len(collections), len(exported), len(image_tasks), output,
        )
        if mismatches or image_failures:
            LOG.warning("Export has incomplete items; inspect scrape_summary.json and products.json")
            return 2
        return 0
    except Exception as exc:
        LOG.exception("Catalog export stopped: %s", exc)
        return 1


if __name__ == "__main__":
    sys.exit(main())
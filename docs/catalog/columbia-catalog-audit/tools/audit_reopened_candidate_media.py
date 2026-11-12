#!/usr/bin/env python3
"""Verify media for the reopened Columbia kits and packs without attaching it."""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

from PIL import Image, ImageOps


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs" / "catalog" / "columbia-catalog-audit"
ACCOUNTING = AUDIT / "review" / "current-manufacturer-source-accounting.csv"
EXPORT = AUDIT / "columbia-catalog-export" / "products.json"
IMAGE_DIR = AUDIT / "columbia-catalog-export" / "images"
OUTPUT = AUDIT / "review" / "current-reopened-kit-pack-media-review.csv"
SUMMARY = AUDIT / "review" / "current-reopened-kit-pack-media-review-summary.json"
MAX_EDGE = 1600
WEBP_QUALITY = 100
ENCODER_METHOD = 1
MAX_SOURCE_BYTES = 20 * 1024 * 1024
MAX_WORKERS = 8
TIMEOUT_SECONDS = 25


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def sku_slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.casefold()).strip("_")


class TrustedShopifyRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request: Any, fp: Any, code: int, message: str,
                         headers: Any, new_url: str) -> Any:
        parsed = urllib.parse.urlparse(new_url)
        if parsed.scheme != "https" or parsed.hostname != "cdn.shopify.com":
            raise urllib.error.URLError("redirect left the verified Shopify CDN host")
        return super().redirect_request(request, fp, code, message, headers, new_url)


OPENER = urllib.request.build_opener(TrustedShopifyRedirectHandler())


def fetch_source(url: str) -> tuple[bytes | None, str]:
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme != "https" or parsed.hostname != "cdn.shopify.com":
        return None, "rejected_untrusted_source_url"
    request = urllib.request.Request(url, headers={"User-Agent": "DTB-Catalog-Media-Audit/1.0"})
    try:
        with OPENER.open(request, timeout=TIMEOUT_SECONDS) as response:
            content_type = response.headers.get_content_type().lower()
            if not content_type.startswith("image/"):
                return None, f"unexpected_content_type:{content_type}"
            data = response.read(MAX_SOURCE_BYTES + 1)
            if len(data) > MAX_SOURCE_BYTES:
                return None, "source_exceeds_20mb_limit"
            return data, ""
    except Exception as exc:  # bounded remote read; report failure without aborting the whole audit
        return None, f"{type(exc).__name__}:{str(exc)[:180]}"


def prepare_pixels(opened: Image.Image) -> Image.Image:
    image = ImageOps.exif_transpose(opened)
    if max(image.size) > MAX_EDGE:
        image.thumbnail((MAX_EDGE, MAX_EDGE), Image.Resampling.LANCZOS)
    has_alpha = "A" in image.getbands() or "transparency" in image.info
    return image.convert("RGBA" if has_alpha else "RGB")


def optimized_digest(source_bytes: bytes) -> tuple[str, str, str, str]:
    with Image.open(io.BytesIO(source_bytes)) as opened:
        source_dimensions = f"{opened.width}x{opened.height}"
        image = prepare_pixels(opened)
        pixel_digest = sha256_bytes(image.tobytes())
        options: dict[str, Any] = {
            "format": "WEBP", "quality": WEBP_QUALITY,
            "lossless": True, "method": ENCODER_METHOD,
        }
        if opened.info.get("icc_profile"):
            options["icc_profile"] = opened.info["icc_profile"]
        output = io.BytesIO()
        image.save(output, **options)
    with Image.open(io.BytesIO(output.getvalue())) as check:
        check.load()
        if check.format != "WEBP" or max(check.size) > MAX_EDGE:
            raise ValueError("optimized source failed WebP validation")
        dimensions = f"{check.width}x{check.height}"
    return sha256_bytes(output.getvalue()), pixel_digest, source_dimensions, dimensions


def local_pixel_digest(path: Path) -> tuple[str, str] | None:
    if not path.is_file():
        return None
    with Image.open(path) as opened:
        if opened.format != "WEBP":
            raise ValueError(f"local candidate is not WebP: {path}")
        image = prepare_pixels(opened)
        return sha256_bytes(image.tobytes()), f"{image.width}x{image.height}"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write", action="store_true", help="write the review CSV and summary JSON")
    args = parser.parse_args()

    accounting = read_csv(ACCOUNTING)
    reopened = [row for row in accounting if row["current_disposition"].startswith("include_candidate_")]
    products = json.loads(EXPORT.read_text(encoding="utf-8-sig"))
    products_by_id = {str(product.get("id")): product for product in products}
    image_rows: list[dict[str, str]] = []
    downloads: dict[str, tuple[bytes | None, str]] = {}

    for row in reopened:
        product = products_by_id.get(row["source_product_id"])
        if not product:
            raise ValueError(f"manufacturer product missing for {row['source_sku']}")
        variants = [variant for variant in product.get("variants", [])
                    if str(variant.get("id")) == row["source_variant_id"]]
        if len(variants) != 1 or str(variants[0].get("sku") or "").strip() != row["source_sku"]:
            raise ValueError(f"exact source SKU/variant binding failed for {row['source_sku']}")

        images = sorted(product.get("images") or [], key=lambda image: int(image.get("position") or 0))
        if not images:
            image_rows.append({
                "source_product_id": row["source_product_id"],
                "source_variant_id": row["source_variant_id"],
                "source_sku": row["source_sku"],
                "source_name": row["source_name"],
                "current_disposition": row["current_disposition"],
                "source_image_count": "0",
                "source_position": "",
                "manufacturer_image_id": "",
                "manufacturer_image_url": "",
                "image_variant_binding": "no_source_image",
                "expected_local_asset": "",
                "local_asset_exists": "0",
                "local_asset_bytes": "",
                "local_asset_sha256": "",
                "manufacturer_source_sha256": "",
                "optimized_source_sha256": "",
                "optimized_source_pixel_sha256": "",
                "local_asset_pixel_sha256": "",
                "local_asset_dimensions": "",
                "source_dimensions": "",
                "optimized_dimensions": "",
                "media_identity_status": "manufacturer_source_has_no_image",
                "media_mutation": "none",
            })
            continue

        base = sku_slug(row["source_sku"])
        for image in images:
            position = int(image.get("position") or 0)
            if position < 1:
                raise ValueError(f"invalid image position for {row['source_sku']}")
            url = str(image.get("src") or "").strip()
            if not url:
                raise ValueError(f"blank manufacturer image URL for {row['source_sku']} position {position}")
            local_path = IMAGE_DIR / f"columbia_tools_{base}_{position:02d}.webp"
            if url not in downloads:
                downloads[url] = (None, "pending")
            variant_ids = {str(value) for value in image.get("variant_ids", [])}
            image_product_id = str(image.get("product_id") or "")
            if image_product_id != row["source_product_id"]:
                binding = "source_product_id_mismatch"
            elif variant_ids and row["source_variant_id"] not in variant_ids:
                binding = "variant_not_associated"
            elif variant_ids:
                binding = "exact_product_and_variant"
            else:
                binding = "exact_product_level_image_shared_by_variants"
            image_rows.append({
                "source_product_id": row["source_product_id"],
                "source_variant_id": row["source_variant_id"],
                "source_sku": row["source_sku"],
                "source_name": row["source_name"],
                "current_disposition": row["current_disposition"],
                "source_image_count": str(len(images)),
                "source_position": str(position),
                "manufacturer_image_id": str(image.get("id") or ""),
                "manufacturer_image_url": url,
                "image_variant_binding": binding,
                "expected_local_asset": str(local_path.relative_to(ROOT)).replace("\\", "/"),
                "local_asset_exists": "1" if local_path.is_file() else "0",
                "local_asset_bytes": str(local_path.stat().st_size) if local_path.is_file() else "",
                "local_asset_sha256": sha256_file(local_path) if local_path.is_file() else "",
                "manufacturer_source_sha256": "",
                "optimized_source_sha256": "",
                "optimized_source_pixel_sha256": "",
                "local_asset_pixel_sha256": "",
                "local_asset_dimensions": "",
                "source_dimensions": "",
                "optimized_dimensions": "",
                "media_identity_status": "pending_source_read",
                "media_mutation": "none",
            })

    pending_urls = [url for url, (_, status) in downloads.items() if status == "pending"]
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {executor.submit(fetch_source, url): url for url in pending_urls}
        for future in as_completed(futures):
            downloads[futures[future]] = future.result()

    for output in image_rows:
        url = output["manufacturer_image_url"]
        if not url:
            continue
        source_bytes, error = downloads[url]
        if error:
            output["media_identity_status"] = f"manufacturer_source_read_failed:{error}"
            continue
        assert source_bytes is not None
        output["manufacturer_source_sha256"] = sha256_bytes(source_bytes)
        try:
            optimized_hash, pixel_hash, source_dims, optimized_dims = optimized_digest(source_bytes)
        except Exception as exc:
            output["media_identity_status"] = f"manufacturer_image_decode_failed:{type(exc).__name__}"
            continue
        output["optimized_source_sha256"] = optimized_hash
        output["optimized_source_pixel_sha256"] = pixel_hash
        output["source_dimensions"] = source_dims
        output["optimized_dimensions"] = optimized_dims
        if output["image_variant_binding"] in {"source_product_id_mismatch", "variant_not_associated"}:
            output["media_identity_status"] = output["image_variant_binding"]
        elif output["local_asset_exists"] != "1":
            output["media_identity_status"] = "exact_manufacturer_source_verified_local_asset_missing"
        else:
            local_path = ROOT / output["expected_local_asset"]
            try:
                local_pixels = local_pixel_digest(local_path)
            except Exception as exc:
                output["media_identity_status"] = f"local_asset_decode_failed:{type(exc).__name__}"
                continue
            if local_pixels is None:
                output["media_identity_status"] = "exact_manufacturer_source_verified_local_asset_missing"
                continue
            output["local_asset_pixel_sha256"], output["local_asset_dimensions"] = local_pixels
            if output["local_asset_pixel_sha256"] == pixel_hash:
                output["media_identity_status"] = "exact_manufacturer_source_and_local_asset_pixel_match"
            else:
                output["media_identity_status"] = "local_filename_candidate_differs_from_manufacturer_source_pixels"

    fields = list(image_rows[0]) if image_rows else []
    counts: dict[str, int] = {}
    for row in image_rows:
        key = row["media_identity_status"]
        counts[key] = counts.get(key, 0) + 1
    summary = {
        "purpose": "read-only manufacturer-image identity audit for the 46 reopened parts kits and two packs",
        "source_accounting_sha256": sha256_file(ACCOUNTING),
        "manufacturer_products_json_sha256": sha256_file(EXPORT),
        "source_products_reviewed": len(reopened),
        "source_images_reviewed": sum(int(row["source_image_count"]) > 0 for row in image_rows),
        "image_rows": len(image_rows),
        "unique_source_urls_fetched": len(downloads),
        "status_counts": counts,
        "pipeline": {"max_edge": MAX_EDGE, "webp_quality": WEBP_QUALITY,
                     "lossless": True, "encoder_method": ENCODER_METHOD},
        "network_limits": {"max_workers": MAX_WORKERS, "timeout_seconds": TIMEOUT_SECONDS,
                           "max_source_bytes": MAX_SOURCE_BYTES},
        "media_mutations": 0,
        "report_csv": str(OUTPUT.relative_to(ROOT)).replace("\\", "/"),
    }
    if args.write:
        with OUTPUT.open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\r\n")
            writer.writeheader()
            writer.writerows(image_rows)
        SUMMARY.write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

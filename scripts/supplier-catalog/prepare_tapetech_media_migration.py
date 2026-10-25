#!/usr/bin/env python3
"""Normalize public TSW TapeTech images and map exact SKUs to launch products."""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import os
import shutil
import tempfile
import urllib.request
from collections import Counter
from pathlib import Path, PurePosixPath
from urllib.parse import urlparse

from PIL import Image, ImageOps

HERE = Path(__file__).resolve().parent
DEFAULT_SOURCE_DIR = HERE / "results" / "images" / "tapetech-parts"
DEFAULT_MEDIA_DIR = HERE.parents[1] / "products" / "launch" / "media" / "media-10-23-2026" / "media"
DEFAULT_CATALOG = HERE.parents[1] / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_DRAFT = HERE.parents[1] / "products" / "launch" / "in-works" / "tapetech_draft_parts_optiwrite_temp_20261025.csv"
DEFAULT_AUDIT = DEFAULT_SOURCE_DIR / "tapetech-parts-migration-audit.csv"
DEFAULT_UPDATE = HERE.parents[1] / "products" / "launch" / "in-works" / "tapetech_parts_images_update_existing_20261025.csv"
MAX_IMAGE_BYTES = 20 * 1024 * 1024
ALLOWED_IMAGE_HOSTS = {"s3.amazonaws.com", "s3-us-west-2.amazonaws.com"}
IMAGE_BASE_URL = "https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/"
AUDIT_FIELDS = (
    "sku", "supplier_product_name", "supplier_image_status", "selected_image_url",
    "source_image_file", "source_variant", "launch_catalog_match", "launch_catalog_type", "launch_catalog_name",
    "catalog_image_already_set", "draft_match", "draft_name", "media_file",
    "source_width", "source_height", "source_bytes", "webp_bytes", "source_sha256",
    "webp_sha256", "migration_status",
)


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames:
            raise ValueError(f"CSV has no header: {path}")
        return list(reader.fieldnames), list(reader)


def write_csv(path: Path, fields: tuple[str, ...] | list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def fetch_l_image(url: str, sku: str) -> bytes:
    parsed = urlparse(url)
    if parsed.scheme != "https" or parsed.hostname not in ALLOWED_IMAGE_HOSTS:
        raise ValueError(f"Unsafe higher-resolution image URL for {sku}: {url}")
    req = urllib.request.Request(url, headers={
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36",
        "Referer": "https://www.tswfast.com/",
        "Accept": "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
    })
    with urllib.request.urlopen(req, timeout=30) as response:
        if response.headers.get_content_type().lower() not in {"image/jpeg", "image/png", "image/webp"}:
            raise ValueError(f"Unexpected content type for {sku}: {response.headers.get_content_type()}")
        data = response.read(MAX_IMAGE_BYTES + 1)
    if not data or len(data) > MAX_IMAGE_BYTES:
        raise ValueError(f"Image for {sku} is empty or exceeds the size limit")
    return data


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def open_image(data: bytes, label: str) -> Image.Image:
    try:
        with Image.open(io.BytesIO(data)) as image:
            image.verify()
        with Image.open(io.BytesIO(data)) as image:
            return ImageOps.exif_transpose(image).copy()
    except Exception as exc:
        raise ValueError(f"Invalid source image {label}: {exc}") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-dir", type=Path, default=DEFAULT_SOURCE_DIR)
    parser.add_argument("--media-dir", type=Path, default=DEFAULT_MEDIA_DIR)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--draft-products", type=Path, default=DEFAULT_DRAFT)
    parser.add_argument("--audit", type=Path, default=DEFAULT_AUDIT)
    parser.add_argument("--update-csv", type=Path, default=DEFAULT_UPDATE)
    parser.add_argument("--quality", type=int, default=88)
    args = parser.parse_args()
    if not 70 <= args.quality <= 95:
        parser.error("quality must be between 70 and 95")

    _, source_rows = read_csv(args.source_dir / "tapetech-parts-image-manifest.csv")
    _, high_rows = read_csv(args.source_dir / "tapetech-higher-resolution-audit.csv")
    _, catalog_rows = read_csv(args.catalog)
    _, draft_rows = read_csv(args.draft_products)
    high_by_sku = {r["sku"].strip().casefold(): r for r in high_rows}
    catalog_by_sku: dict[str, dict[str, str]] = {}
    for row in catalog_rows:
        brand = (row.get("Brands") or "").strip().casefold()
        category = (row.get("Categories") or "")
        sku = (row.get("SKU") or "").strip()
        if brand == "tapetech" and "Replacement Parts" in category and sku:
            key = sku.casefold()
            if key in catalog_by_sku:
                raise ValueError(f"Duplicate TapeTech replacement-part catalog SKU: {sku}")
            catalog_by_sku[key] = row
    draft_by_sku: dict[str, dict[str, str]] = {}
    for row in draft_rows:
        sku = (row.get("SKU") or "").strip()
        if sku:
            key = sku.casefold()
            if key in draft_by_sku:
                raise ValueError(f"Duplicate TapeTech draft SKU: {sku}")
            draft_by_sku[key] = row

    placeholder_keys = {
        row["sku"].strip().casefold()
        for row in source_rows
        if row["image_status"] == "downloaded"
        and "IMAGE_COMING_SOON" in (row.get("image_file") or "").upper()
    }
    downloaded = [
        row for row in source_rows
        if row["image_status"] == "downloaded" and row["sku"].strip().casefold() not in placeholder_keys
    ]
    chosen: dict[str, tuple[str, bytes, bool]] = {}
    name_to_url: dict[str, str] = {}
    for row in downloaded:
        sku = row["sku"].strip()
        high = high_by_sku.get(sku.casefold())
        url = high["candidate_image_url"] if high and high["status"] == "ok" else row["image_url"]
        source_url_key = url.casefold()
        if source_url_key in chosen:
            continue
        original_file = args.source_dir / row["image_file"]
        if not original_file.is_file():
            raise ValueError(f"Downloaded source file is missing for {sku}: {original_file}")
        if url == row["image_url"]:
            raw = original_file.read_bytes()
            used_l = False
        else:
            try:
                raw = fetch_l_image(url, sku)
                used_l = True
            except Exception:
                # The audited M file is already downloaded and verified; retain it if L retrieval fails.
                url = row["image_url"]
                source_url_key = url.casefold()
                if source_url_key in chosen:
                    continue
                raw = original_file.read_bytes()
                used_l = False
        image = open_image(raw, sku)
        output_name = PurePosixPath(urlparse(url).path).stem + ".webp"
        prior = name_to_url.get(output_name.casefold())
        if prior and prior.casefold() != url.casefold():
            raise ValueError(f"Different source images share destination basename {output_name}")
        name_to_url[output_name.casefold()] = url
        chosen[source_url_key] = (output_name, raw, used_l)
        image.close()

    args.media_dir.mkdir(parents=True, exist_ok=True)
    # Stage and validate every normalized file before adding any to the launch media directory.
    with tempfile.TemporaryDirectory(prefix="tapetech-media-") as temp_name:
        temp_dir = Path(temp_name)
        file_stats: dict[str, dict[str, str]] = {}
        for row in downloaded:
            sku = row["sku"].strip()
            high = high_by_sku.get(sku.casefold())
            url = high["candidate_image_url"] if high and high["status"] == "ok" else row["image_url"]
            if url.casefold() not in chosen:
                url = row["image_url"]
            item = chosen[url.casefold()]
            name, raw, _used_l = item
            if name not in file_stats:
                image = open_image(raw, sku)
                original_width, original_height = image.size
                if image.mode not in {"RGB", "RGBA"}:
                    image = image.convert("RGBA" if "A" in image.getbands() else "RGB")
                staged = temp_dir / name
                image.save(staged, format="WEBP", quality=args.quality, method=6)
                image.close()
                optimized = staged.read_bytes()
                verified = open_image(optimized, name)
                if verified.size != (original_width, original_height):
                    raise ValueError(f"Unexpected dimensions after WebP normalization for {name}")
                verified.close()
                file_stats[name] = {
                    "source_width": str(original_width), "source_height": str(original_height),
                    "source_bytes": str(len(raw)), "webp_bytes": str(len(optimized)),
                    "source_sha256": digest(raw), "webp_sha256": digest(optimized),
                }
        # Never overwrite an existing asset with different bytes.
        for name, stats in file_stats.items():
            existing = args.media_dir / name
            staged = temp_dir / name
            if existing.exists() and digest(existing.read_bytes()) != stats["webp_sha256"]:
                raise ValueError(f"Destination collision would overwrite existing media: {existing}")
        for name in file_stats:
            staged = temp_dir / name
            dest = args.media_dir / name
            if dest.exists():
                continue
            temp_dest = args.media_dir / f".{name}.tmp"
            shutil.copyfile(staged, temp_dest)
            os.replace(temp_dest, dest)

    audit_rows: list[dict[str, str]] = []
    update_rows: list[dict[str, str]] = []
    for row in source_rows:
        sku = row["sku"].strip()
        key = sku.casefold()
        catalog = catalog_by_sku.get(key)
        draft = draft_by_sku.get(key)
        source_url = row["image_url"]
        media_file = ""
        source_variant = ""
        stats = {key: "" for key in ("source_width", "source_height", "source_bytes", "webp_bytes", "source_sha256", "webp_sha256")}
        migration_status = row["image_status"]
        if key in placeholder_keys:
            migration_status = "placeholder"
        elif row["image_status"] == "downloaded":
            high = high_by_sku.get(key)
            chosen_url = high["candidate_image_url"] if high and high["status"] == "ok" else row["image_url"]
            if chosen_url.casefold() not in chosen:
                chosen_url = row["image_url"]
            media_file, raw, used_l = chosen[chosen_url.casefold()]
            source_url = chosen_url
            source_variant = "L" if used_l else "listed"
            stats = file_stats[media_file]
            migration_status = "migrated"
            if catalog and not (catalog.get("Images") or "").strip():
                update_rows.append({
                    "Type": catalog["Type"], "SKU": catalog["SKU"],
                    "Images": IMAGE_BASE_URL + media_file,
                })
        audit_rows.append({
            "sku": sku,
            "supplier_product_name": row["name"],
            "supplier_image_status": row["image_status"],
            "selected_image_url": source_url,
            "source_image_file": row["image_file"],
            "source_variant": source_variant,
            "launch_catalog_match": "exact_sku" if catalog else "no_exact_match",
            "launch_catalog_type": catalog.get("Type", "") if catalog else "",
            "launch_catalog_name": catalog.get("Name", "") if catalog else "",
            "catalog_image_already_set": "yes" if catalog and (catalog.get("Images") or "").strip() else "no" if catalog else "",
            "draft_match": "exact_sku" if draft else "no_exact_match",
            "draft_name": draft.get("Name", "") if draft else "",
            "media_file": media_file,
            **stats,
            "migration_status": migration_status,
        })
    write_csv(args.audit, AUDIT_FIELDS, audit_rows)
    write_csv(args.update_csv, ("Type", "SKU", "Images"), sorted(update_rows, key=lambda r: r["SKU"].casefold()))

    print(f"Source SKU rows: {len(source_rows)}")
    print(f"Usable product images: {len(downloaded)}; unique normalized files: {len(file_stats)}")
    print(f"Coming-soon images excluded: {len(placeholder_keys)}")
    print(f"Exact launch catalog matches: {sum(r['launch_catalog_match'] == 'exact_sku' for r in audit_rows)}")
    print(f"Exact draft-product matches: {sum(r['draft_match'] == 'exact_sku' for r in audit_rows)}")
    print(f"Update-existing rows with no current image: {len(update_rows)}")
    print(f"Migrated media directory: {args.media_dir}")
    print(f"Audit: {args.audit}")
    print(f"Update-existing CSV: {args.update_csv}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

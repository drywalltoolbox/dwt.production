#!/usr/bin/env python3
"""Normalize the catalog photo placeholder and map products with no image."""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import os
import shutil
import tempfile
from pathlib import Path

from PIL import Image, ImageOps

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
DEFAULT_SOURCE = ROOT / "products" / "launch" / "media" / "Photo-Coming-Soon-Placeholder.webp"
DEFAULT_MEDIA = ROOT / "products" / "launch" / "media" / "media-10-23-2026" / "media"
DEFAULT_CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_UPDATE = ROOT / "products" / "launch" / "in-works" / "dtb_missing_product_image_placeholder_update_existing_20261025.csv"
DEFAULT_AUDIT_DIR = HERE / "results" / "images" / "placeholder"
DEFAULT_URL = "https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/Photo-Coming-Soon-Placeholder.webp"
TARGET_SIZE = (1200, 1200)
WEBP_QUALITY = 95
UPDATE_FIELDS = ("Type", "SKU", "Images")
AUDIT_FIELDS = ("Type", "SKU", "Name", "Brands", "Parent", "previous_images", "action", "Images")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_catalog(path: Path) -> tuple[bytes, str, list[list[str]]]:
    raw = path.read_bytes()
    encoding = "utf-8-sig" if raw.startswith(b"\xef\xbb\xbf") else "utf-8"
    text = raw.decode(encoding)
    records = list(csv.reader(io.StringIO(text, newline="")))
    if not records or not records[0]:
        raise ValueError(f"Catalog has no header: {path}")
    return raw, encoding, records


def csv_bytes(records: list[list[str]], encoding: str, newline: str) -> bytes:
    output = io.StringIO(newline="")
    writer = csv.writer(output, lineterminator=newline, quoting=csv.QUOTE_MINIMAL)
    writer.writerows(records)
    text = output.getvalue()
    if encoding == "utf-8-sig":
        return ("\ufeff" + text).encode("utf-8")
    return text.encode("utf-8")


def atomic_write(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_name, path)
    except Exception:
        Path(tmp_name).unlink(missing_ok=True)
        raise


def normalize_image(source: Path) -> bytes:
    with Image.open(source) as image:
        if image.format != "WEBP":
            raise ValueError(f"Placeholder source must be WebP: {source}")
        image.verify()
    with Image.open(source) as image:
        image = ImageOps.exif_transpose(image).convert("RGB")
        if image.size != TARGET_SIZE:
            image = image.resize(TARGET_SIZE, Image.Resampling.LANCZOS)
        output = io.BytesIO()
        image.save(output, format="WEBP", quality=WEBP_QUALITY, method=6)
        result = output.getvalue()
    with Image.open(io.BytesIO(result)) as image:
        if image.format != "WEBP" or image.size != TARGET_SIZE or image.mode != "RGB":
            raise ValueError("Normalized placeholder failed its format, dimensions, or color-mode check")
        image.verify()
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--media-dir", type=Path, default=DEFAULT_MEDIA)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--update-csv", type=Path, default=DEFAULT_UPDATE)
    parser.add_argument("--audit-dir", type=Path, default=DEFAULT_AUDIT_DIR)
    parser.add_argument("--image-url", default=DEFAULT_URL)
    args = parser.parse_args()

    if not args.source.is_file():
        raise ValueError(f"Placeholder source not found: {args.source}")
    image_bytes = normalize_image(args.source)
    destination = args.media_dir / args.source.name
    if destination.exists() and destination.read_bytes() != image_bytes:
        raise ValueError(f"Refusing to overwrite a different media asset: {destination}")

    original, encoding, records = read_catalog(args.catalog)
    header = records[0]
    required = ("Type", "SKU", "Name", "Images", "Parent")
    if len(set(header)) != len(header) or any(name not in header for name in required):
        raise ValueError("Catalog is missing required unique headers: Type, SKU, Name, Images, Parent")
    indexes = {name: header.index(name) for name in required}
    brand_index = header.index("Brands") if "Brands" in header else None
    row_indexes: dict[str, list[int]] = {}
    for i, row in enumerate(records[1:], start=1):
        if len(row) != len(header):
            raise ValueError(f"Catalog row {i + 1} has {len(row)} fields; expected {len(header)}")
        sku = row[indexes["SKU"]].strip()
        if sku:
            row_indexes.setdefault(sku.casefold(), []).append(i)
    duplicate_skus = [sku for sku, indexes_for_sku in row_indexes.items() if len(indexes_for_sku) != 1]
    if duplicate_skus:
        raise ValueError(f"Catalog contains duplicate SKUs; refusing SKU mapping: {duplicate_skus[:10]}")

    audit_rows: list[dict[str, str]] = []
    update_by_sku: dict[str, dict[str, str]] = {}
    changes = 0
    for i, row in enumerate(records[1:], start=1):
        sku = row[indexes["SKU"]].strip()
        product_type = row[indexes["Type"]].strip().casefold()
        current_images = row[indexes["Images"]].strip()
        parent_sku = row[indexes["Parent"]].strip()
        mapped = current_images == args.image_url
        action = "already_placeholder" if mapped else "unchanged"
        if not current_images:
            parent_rows = row_indexes.get(parent_sku.casefold(), []) if parent_sku else []
            parent_has_gallery = any(records[parent_i][indexes["Images"]].strip() for parent_i in parent_rows)
            if product_type == "variation" and parent_has_gallery:
                action = "inherits_parent_gallery"
            else:
                action = "map_placeholder"
                row[indexes["Images"]] = args.image_url
                mapped = True
                changes += 1
        if mapped and sku:
            key = sku.casefold()
            if key in update_by_sku:
                raise ValueError(f"Duplicate import mapping for SKU {sku}")
            update_by_sku[key] = {
                "Type": row[indexes["Type"]], "SKU": sku, "Images": args.image_url,
            }
        if action != "unchanged":
            audit_rows.append({
                "Type": row[indexes["Type"]],
                "SKU": sku,
                "Name": row[indexes["Name"]],
                "Brands": row[brand_index] if brand_index is not None else "",
                "Parent": parent_sku,
                "previous_images": current_images,
                "action": action,
                "Images": args.image_url if mapped else "",
            })

    # Confirm the writer reproduces the original byte stream before replacing anything.
    newline = "\r\n" if b"\r\n" in original else "\n"
    roundtrip = csv_bytes(read_catalog(args.catalog)[2], encoding, newline)
    if roundtrip != original:
        raise ValueError("CSV round-trip would alter unrelated catalog bytes; refusing catalog update")
    updated_catalog = csv_bytes(records, encoding, newline)

    if destination.exists() and destination.read_bytes() == image_bytes:
        pass
    else:
        atomic_write(destination, image_bytes)
    # Keep a byte-identical recovery copy of the current working catalog before the first mutation.
    backup_path = args.audit_dir / "dtb_official_catalog_before_placeholder_map.csv"
    if not backup_path.exists():
        atomic_write(backup_path, original)
    elif backup_path.read_bytes() != original and updated_catalog != original:
        raise ValueError(f"Existing catalog backup differs from current catalog: {backup_path}")
    atomic_write(args.catalog, updated_catalog)

    update_rows = sorted(update_by_sku.values(), key=lambda row: row["SKU"].casefold())
    args.update_csv.parent.mkdir(parents=True, exist_ok=True)
    with args.update_csv.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=UPDATE_FIELDS, lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(update_rows)
    args.audit_dir.mkdir(parents=True, exist_ok=True)
    with (args.audit_dir / "placeholder-product-image-assignment-audit.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=AUDIT_FIELDS, lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(audit_rows)

    print(f"Placeholder source: {args.source} ({args.source.stat().st_size:,} bytes)")
    print(f"Normalized file: {destination} ({len(image_bytes):,} bytes; 1200x1200 WebP, quality {WEBP_QUALITY})")
    print(f"New product mappings: {changes}")
    print(f"Update-existing rows: {len(update_rows)}")
    print(f"Variation rows retaining parent-gallery behavior: {sum(r['action'] == 'inherits_parent_gallery' for r in audit_rows)}")
    print(f"Updated catalog: {args.catalog}")
    print(f"Update-existing CSV: {args.update_csv}")
    print(f"Assignment audit: {args.audit_dir / 'placeholder-product-image-assignment-audit.csv'}")
    print(f"Catalog recovery copy: {backup_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Prepare exact-SKU Columbia manufacturer image replacements for the official catalog.

Default mode is an audit/preview. Pass --apply to write optimized WebP copies,
the official catalog image URLs, a per-image manifest, and an audit report.
Only one-to-one exact SKU matches between the official catalog and the
manufacturer image manifest are eligible. No fuzzy matching is performed.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import re
import tempfile
from collections import defaultdict
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image, ImageOps


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/catalog/columbia-catalog-audit"
EXPORT_IMAGES = AUDIT / "columbia-catalog-export/images"
SOURCE_MANIFEST = AUDIT / "evidence/manufacturer/columbia-manufacturer-image-manifest.csv"
OFFICIAL_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
MEDIA_DIR = ROOT / "products/launch/media/fresh-catalog-media-20261028/media"
PUBLIC_MEDIA_BASE = "https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/"
OUTPUT_DIR = AUDIT / "evidence/manufacturer/image-sync-20261101"
MAX_EDGE = 2000
WEBP_QUALITY = 88


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream)
        if not reader.fieldnames:
            raise ValueError(f"Missing CSV header: {path}")
        return list(reader.fieldnames), list(reader)


def write_csv_atomic(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        mode="w", encoding="utf-8-sig", newline="", dir=path.parent,
        prefix=f".{path.stem}.", suffix=".tmp", delete=False,
    ) as stream:
        temporary = Path(stream.name)
        writer = csv.DictWriter(stream, fieldnames=fields, lineterminator="\r\n", extrasaction="raise")
        writer.writeheader()
        writer.writerows(rows)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)


def is_columbia(row: dict[str, str]) -> bool:
    identity = " ".join(
        row.get(field, "")
        for field in ("Brands", "Meta: _dtb_brand_label", "Meta: _dtb_brand_key")
    ).casefold()
    return "columbia" in identity


def output_name(source_name: str) -> str:
    match = re.fullmatch(r"(.+)_([0-9]{2,})(\.webp)", source_name, flags=re.IGNORECASE)
    if not match:
        raise ValueError(f"Unexpected manufacturer image filename: {source_name}")
    return f"{match.group(1)}_mfr_{match.group(2)}{match.group(3).lower()}"


def optimize(source: Path, destination: Path) -> tuple[tuple[int, int], tuple[int, int], int, int]:
    destination.parent.mkdir(parents=True, exist_ok=True)
    with Image.open(source) as opened:
        image = ImageOps.exif_transpose(opened)
        before = image.size
        if max(image.size) > MAX_EDGE:
            image.thumbnail((MAX_EDGE, MAX_EDGE), Image.Resampling.LANCZOS)
        has_alpha = "A" in image.getbands() or "transparency" in image.info
        image = image.convert("RGBA" if has_alpha else "RGB")
        profile = opened.info.get("icc_profile")
        options = {"format": "WEBP", "quality": WEBP_QUALITY, "method": 6}
        if profile:
            options["icc_profile"] = profile
        image.save(destination, **options)
        with Image.open(destination) as check:
            check.load()
            if check.format != "WEBP" or check.size[0] > MAX_EDGE or check.size[1] > MAX_EDGE:
                raise ValueError(f"Optimized image failed validation: {destination}")
            after = check.size
    return before, after, source.stat().st_size, destination.stat().st_size


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="write assets, manifest, report, and catalog changes")
    args = parser.parse_args()

    image_manifest_fields, source_images = read_csv(SOURCE_MANIFEST)
    catalog_fields, catalog_rows = read_csv(OFFICIAL_CATALOG)
    required_fields = {"SKU", "Images", "Name"}
    if not required_fields.issubset(catalog_fields):
        raise ValueError(f"Official catalog is missing required fields: {required_fields - set(catalog_fields)}")
    if not MEDIA_DIR.is_dir() or not EXPORT_IMAGES.is_dir():
        raise FileNotFoundError("Expected manufacturer export image or upload media directory is missing")

    columbia_rows: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in catalog_rows:
        if is_columbia(row) and row.get("SKU", "").strip():
            columbia_rows[row["SKU"].strip().upper()].append(row)

    images_by_sku: dict[str, list[dict[str, str]]] = defaultdict(list)
    source_products_by_sku: dict[str, set[str]] = defaultdict(set)
    for row in source_images:
        sku = row.get("source_sku", "").strip().upper()
        if not sku or sku not in columbia_rows:
            continue
        if len(columbia_rows[sku]) != 1:
            raise ValueError(f"Exact SKU is not unique in the official Columbia catalog: {sku}")
        source_products_by_sku[sku].add(row.get("source_product_id", ""))
        images_by_sku[sku].append(row)

    eligible_skus = sorted(images_by_sku)
    for sku in eligible_skus:
        product_ids = source_products_by_sku[sku]
        if len(product_ids) != 1:
            raise ValueError(f"Manufacturer manifest has multiple source product identities for SKU {sku}")
        images_by_sku[sku].sort(key=lambda item: int(item["position"]))
        positions = [int(item["position"]) for item in images_by_sku[sku]]
        if positions != list(range(1, len(positions) + 1)):
            raise ValueError(f"Manufacturer image positions are incomplete or duplicated for SKU {sku}")

    staged_rows = {sku: dict(rows[0]) for sku, rows in columbia_rows.items() if len(rows) == 1}
    updated_by_sku: dict[str, list[str]] = {}
    output_manifest: list[dict[str, str]] = []
    assets: list[tuple[Path, Path]] = []
    source_bytes_total = 0
    optimized_bytes_total = 0
    replaced_galleries = 0
    for sku in eligible_skus:
        target_row = columbia_rows[sku][0]
        old_gallery = target_row.get("Images", "")
        urls: list[str] = []
        for item in images_by_sku[sku]:
            relative = Path(item["local_path"])
            if relative.is_absolute() or len(relative.parts) != 2 or relative.parts[0] != "images":
                raise ValueError(f"Unsafe source image path in manifest: {item['local_path']}")
            source = (AUDIT / "columbia-catalog-export" / relative).resolve()
            # The first parent check above is supplemented with a resolved-root check.
            source_root = EXPORT_IMAGES.resolve()
            if source_root not in source.parents or not source.is_file():
                raise FileNotFoundError(f"Manufacturer image is missing or outside export image directory: {source}")
            source_url = item.get("manufacturer_image_url", "")
            if urlparse(source_url).scheme != "https" or urlparse(source_url).hostname != "cdn.shopify.com":
                raise ValueError(f"Unexpected manufacturer image URL for {sku}: {source_url}")
            name = output_name(source.name)
            destination = MEDIA_DIR / name
            with Image.open(source) as image:
                image.verify()
            before_size = source.stat().st_size
            with Image.open(source) as image:
                before_dims = image.size
            if args.apply:
                if destination.exists():
                    # `_mfr_` is a reserved generated-derivative namespace. Reuse
                    # a validated derivative to make interrupted runs resumable.
                    with Image.open(destination) as existing:
                        existing.load()
                        if existing.format != "WEBP" or max(existing.size) > MAX_EDGE:
                            raise FileExistsError(f"Refusing to reuse an invalid generated derivative: {destination}")
                        after_dims = existing.size
                    source_bytes = before_size
                    output_bytes = destination.stat().st_size
                else:
                    before_dims, after_dims, source_bytes, output_bytes = optimize(source, destination)
                    assets.append((source, destination))
            else:
                source_bytes = before_size
                after_dims = (min(before_dims[0], MAX_EDGE), min(before_dims[1], MAX_EDGE))
                output_bytes = 0
            source_bytes_total += source_bytes
            optimized_bytes_total += output_bytes
            public_url = PUBLIC_MEDIA_BASE + name
            urls.append(public_url)
            output_manifest.append({
                "sku": sku,
                "product_name": target_row.get("Name", ""),
                "source_product_id": item["source_product_id"],
                "source_variant_id": item["source_variant_id"],
                "manufacturer_image_url": source_url,
                "manufacturer_image_id": item.get("manufacturer_image_id", ""),
                "source_position": item["position"],
                "source_file": str(source.relative_to(ROOT)).replace("\\", "/"),
                "source_sha256": sha256(source),
                "source_bytes": str(source_bytes),
                "source_dimensions": f"{before_dims[0]}x{before_dims[1]}",
                "upload_file": str(destination.relative_to(ROOT)).replace("\\", "/"),
                "public_url": public_url,
                "output_dimensions": f"{after_dims[0]}x{after_dims[1]}",
                "output_bytes": str(output_bytes),
                "output_sha256": sha256(destination) if args.apply else "not-generated-preview",
                "previous_gallery": old_gallery,
                "source_association": item.get("association", ""),
            })
        updated_by_sku[sku] = urls
        if [value.strip() for value in old_gallery.split(",") if value.strip()] != urls:
            replaced_galleries += 1

    updated_rows: list[dict[str, str]] = []
    for row in catalog_rows:
        sku = row.get("SKU", "").strip().upper()
        if sku in updated_by_sku and is_columbia(row):
            row = dict(row)
            row["Images"] = ", ".join(updated_by_sku[sku])
        updated_rows.append(row)

    unmatched_catalog = sorted(sku for sku in columbia_rows if sku not in images_by_sku)
    unmatched_manifest = sorted({row.get("source_sku", "").strip().upper() for row in source_images} - set(columbia_rows))
    summary = {
        "mode": "apply" if args.apply else "preview",
        "official_catalog_rows": len(catalog_rows),
        "columbia_catalog_rows": sum(len(value) for value in columbia_rows.values()),
        "exact_sku_products_mapped": len(eligible_skus),
        "products_with_gallery_replaced": replaced_galleries,
        "manufacturer_images_mapped": len(output_manifest),
        "catalog_columbia_rows_left_unchanged": len(unmatched_catalog),
        "manufacturer_manifest_skus_without_exact_catalog_match": len(unmatched_manifest),
        "source_bytes_for_mapped_images": source_bytes_total,
        "optimized_bytes_generated": optimized_bytes_total,
        "max_edge_px": MAX_EDGE,
        "webp_quality": WEBP_QUALITY,
    }

    if args.apply:
        # Catalog is committed only after every image has been written and validated.
        write_csv_atomic(OFFICIAL_CATALOG, catalog_fields, updated_rows)
        write_csv_atomic(OUTPUT_DIR / "image-sync-manifest.csv", list(output_manifest[0]), output_manifest)
        catalog_matches = sum(1 for row in updated_rows if is_columbia(row) and row.get("SKU", "").strip().upper() in updated_by_sku)
        if catalog_matches != len(eligible_skus):
            raise RuntimeError("Post-write catalog row count does not match eligible SKU count")
        (OUTPUT_DIR / "unmatched-catalog-skus.json").write_text(
            json.dumps({"catalog_skus_without_exact_manufacturer_image_match": unmatched_catalog,
                        "manufacturer_skus_without_exact_catalog_match": unmatched_manifest}, indent=2) + "\n",
            encoding="utf-8",
        )
        report = (
            "# Columbia manufacturer image sync\n\n"
            "Generated 2026-11-01 from the existing manufacturer image manifest. "
            "Eligibility uses a one-to-one exact SKU match only. Source images were "
            "mapped in the existing manifest from Columbia's Shopify product image "
            "lists and image order; no filename similarity or product-name inference was used.\n\n"
            "## Applied scope\n\n"
            f"- Official catalog Columbia rows: {summary['columbia_catalog_rows']}\n"
            f"- Exact-SKU products updated: {summary['exact_sku_products_mapped']}\n"
            f"- Manufacturer gallery images linked: {summary['manufacturer_images_mapped']}\n"
            f"- Catalog galleries changed: {summary['products_with_gallery_replaced']}\n"
            f"- Columbia catalog rows left unchanged due to no exact image-manifest SKU: {summary['catalog_columbia_rows_left_unchanged']}\n"
            f"- Manifest SKUs with no exact official-catalog row: {summary['manufacturer_manifest_skus_without_exact_catalog_match']}\n"
            f"- Source bytes: {source_bytes_total:,}; optimized bytes: {optimized_bytes_total:,}; "
            f"reduction: {100 * (1 - optimized_bytes_total / source_bytes_total):.1f}%\n\n"
            f"New assets are WebP, capped at {MAX_EDGE}px on the longest edge, encoded at quality {WEBP_QUALITY} "
            "with a balanced encoding setting, "
            "and stored under distinct `_mfr_` filenames so existing live-media files are not overwritten. "
            "Only the official catalog `Images` field changed. Unmatched rows are listed in "
            "`unmatched-catalog-skus.json`; prior gallery URLs are retained per image in "
            "`image-sync-manifest.csv`.\n\n"
            "## Limits\n\n"
            "The manifest establishes source product/image association and manufacturer CDN provenance, "
            "but this run did not independently visually verify every image against a physical product. "
            "The new files and catalog CSV are prepared in the repository upload package; this script "
            "does not itself transfer files to the production server or mutate live WooCommerce products. "
            "The public URLs must resolve after the upload package is deployed.\n"
        )
        (OUTPUT_DIR / "report.md").write_text(report, encoding="utf-8")

    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()

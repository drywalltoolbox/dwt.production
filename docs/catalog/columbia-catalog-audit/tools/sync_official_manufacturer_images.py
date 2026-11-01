#!/usr/bin/env python3
"""Prepare exact-identity Columbia manufacturer image replacements.

Preview by default. Pass --apply to write optimized media derivatives, a detailed
manifest/report, and updated image URLs in the official catalog. Matching uses
exact catalog SKU, manufacturer SKU, MPN, or schema MPN values only.
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
MAX_EDGE = 1600
WEBP_QUALITY = 100
ENCODER_METHOD = 1
IDENTITY_FIELDS = ("SKU", "Meta: _dtb_manufacturer_sku", "Meta: _dtb_mpn", "Meta: schema_mpn")
PRODUCT_TYPES = {"simple", "variation", "variable"}


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
    identity = " ".join(row.get(field, "") for field in (
        "Brands", "Meta: _dtb_brand_label", "Meta: _dtb_brand_key"
    )).casefold()
    return "columbia" in identity


def derivative_name(source_name: str) -> str:
    match = re.fullmatch(r"(.+)_([0-9]{2,})(\.webp)", source_name, flags=re.IGNORECASE)
    if not match:
        raise ValueError(f"Unexpected manufacturer image filename: {source_name}")
    return f"{match.group(1)}_mfr_{match.group(2)}{match.group(3).lower()}"


def optimize(source: Path, destination: Path) -> tuple[tuple[int, int], tuple[int, int]]:
    destination.parent.mkdir(parents=True, exist_ok=True)
    with Image.open(source) as opened:
        image = ImageOps.exif_transpose(opened)
        before = image.size
        if max(image.size) > MAX_EDGE:
            image.thumbnail((MAX_EDGE, MAX_EDGE), Image.Resampling.LANCZOS)
        has_alpha = "A" in image.getbands() or "transparency" in image.info
        image = image.convert("RGBA" if has_alpha else "RGB")
        options = {
            "format": "WEBP", "quality": WEBP_QUALITY,
            "lossless": True, "method": ENCODER_METHOD,
        }
        if opened.info.get("icc_profile"):
            options["icc_profile"] = opened.info["icc_profile"]
        image.save(destination, **options)
        with Image.open(destination) as check:
            check.load()
            if check.format != "WEBP" or max(check.size) > MAX_EDGE:
                raise ValueError(f"Optimized image failed validation: {destination}")
            after = check.size
    return before, after


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="write derivatives, manifest, report, and catalog updates")
    args = parser.parse_args()

    _, source_images = read_csv(SOURCE_MANIFEST)
    catalog_fields, catalog_rows = read_csv(OFFICIAL_CATALOG)
    if not {"Type", "SKU", "Images", "Name"}.issubset(catalog_fields):
        raise ValueError("The official catalog is missing a required identity or image field")
    if not EXPORT_IMAGES.is_dir() or not MEDIA_DIR.is_dir():
        raise FileNotFoundError("Expected manufacturer export or upload media directory is missing")

    columbia_rows = [
        (index, row) for index, row in enumerate(catalog_rows)
        if is_columbia(row) and row.get("Type", "").strip().casefold() in PRODUCT_TYPES
    ]
    identity_rows: dict[str, set[int]] = defaultdict(set)
    for index, row in columbia_rows:
        for field in IDENTITY_FIELDS:
            value = row.get(field, "").strip()
            if value:
                identity_rows[value].add(index)

    images_by_source_sku: dict[str, list[dict[str, str]]] = defaultdict(list)
    source_products_by_sku: dict[str, set[str]] = defaultdict(set)
    target_by_source_sku: dict[str, tuple[int, dict[str, str], str]] = {}
    unmatched_source_skus: set[str] = set()
    ambiguous_source_skus: dict[str, list[dict[str, str]]] = {}
    for image in source_images:
        source_sku = image.get("source_sku", "").strip()
        if not source_sku:
            raise ValueError("Manufacturer image manifest contains a blank source SKU")
        candidates = identity_rows.get(source_sku, set())
        if not candidates:
            unmatched_source_skus.add(source_sku)
            continue
        if len(candidates) != 1:
            ambiguous_source_skus[source_sku] = [catalog_rows[index] for index in sorted(candidates)]
            continue
        target_index = next(iter(candidates))
        target = catalog_rows[target_index]
        match_field = next(field for field in IDENTITY_FIELDS if target.get(field, "").strip() == source_sku)
        previous_match = next((sku for sku, value in target_by_source_sku.items() if value[0] == target_index), None)
        if previous_match and previous_match != source_sku:
            raise ValueError(f"Multiple manufacturer SKUs resolve to one catalog row: {previous_match}, {source_sku}")
        target_by_source_sku[source_sku] = (target_index, target, match_field)
        source_products_by_sku[source_sku].add(image.get("source_product_id", ""))
        images_by_source_sku[source_sku].append(image)

    for sku, items in images_by_source_sku.items():
        if len(source_products_by_sku[sku]) != 1:
            raise ValueError(f"Manufacturer manifest has multiple source product identities for SKU {sku}")
        items.sort(key=lambda item: int(item["position"]))
        positions = [int(item["position"]) for item in items]
        if positions != list(range(1, len(positions) + 1)):
            raise ValueError(f"Manufacturer gallery positions are incomplete or duplicated for SKU {sku}")

    updated_by_row: dict[int, list[str]] = {}
    output_manifest: list[dict[str, str]] = []
    source_bytes_total = 0
    output_bytes_total = 0
    replaced_gallery_count = 0
    output_sources: dict[str, str] = {}

    for source_sku in sorted(images_by_source_sku):
        target_index, target_row, match_field = target_by_source_sku[source_sku]
        old_gallery = target_row.get("Images", "")
        gallery_urls: list[str] = []
        for image in images_by_source_sku[source_sku]:
            relative = Path(image["local_path"])
            if relative.is_absolute() or len(relative.parts) != 2 or relative.parts[0] != "images":
                raise ValueError(f"Unsafe source image path: {image['local_path']}")
            source = (EXPORT_IMAGES.parent / relative).resolve()
            if EXPORT_IMAGES.resolve() not in source.parents or not source.is_file():
                raise FileNotFoundError(f"Source image missing or outside export directory: {source}")
            source_url = image.get("manufacturer_image_url", "")
            parsed_url = urlparse(source_url)
            if parsed_url.scheme != "https" or parsed_url.hostname != "cdn.shopify.com":
                raise ValueError(f"Unexpected manufacturer image URL for {source_sku}: {source_url}")
            with Image.open(source) as check_source:
                check_source.verify()
            source_digest = sha256(source)
            name = derivative_name(source.name)
            destination = MEDIA_DIR / name
            previous_digest = output_sources.get(name)
            if previous_digest and previous_digest != source_digest:
                raise ValueError(f"Different source images collide on output filename: {name}")
            output_sources[name] = source_digest

            with Image.open(source) as image_file:
                source_dims = image_file.size
            if args.apply:
                # `_mfr_` filenames are reserved for this migration. Overwrite
                # partial outputs from interrupted conversion runs safely.
                source_dims, output_dims = optimize(source, destination)
                output_bytes = destination.stat().st_size
                output_digest = sha256(destination)
            else:
                output_dims = (min(source_dims[0], MAX_EDGE), min(source_dims[1], MAX_EDGE))
                output_bytes = 0
                output_digest = "not-generated-preview"

            source_bytes = source.stat().st_size
            source_bytes_total += source_bytes
            output_bytes_total += output_bytes
            public_url = PUBLIC_MEDIA_BASE + name
            gallery_urls.append(public_url)
            output_manifest.append({
                "source_sku": source_sku,
                "matched_catalog_field": match_field,
                "catalog_type": target_row.get("Type", ""),
                "catalog_sku": target_row.get("SKU", ""),
                "catalog_parent_sku": target_row.get("Parent", ""),
                "catalog_name": target_row.get("Name", ""),
                "source_product_id": image.get("source_product_id", ""),
                "source_variant_id": image.get("source_variant_id", ""),
                "source_position": image["position"],
                "manufacturer_image_url": source_url,
                "manufacturer_image_id": image.get("manufacturer_image_id", ""),
                "source_file": str(source.relative_to(ROOT)).replace("\\", "/"),
                "source_sha256": source_digest,
                "source_bytes": str(source_bytes),
                "source_dimensions": f"{source_dims[0]}x{source_dims[1]}",
                "upload_file": str(destination.relative_to(ROOT)).replace("\\", "/"),
                "public_url": public_url,
                "output_dimensions": f"{output_dims[0]}x{output_dims[1]}",
                "output_bytes": str(output_bytes),
                "output_sha256": output_digest,
                "previous_gallery": old_gallery,
                "source_association": image.get("association", ""),
            })
            if args.apply and len(output_manifest) % 25 == 0:
                print(f"Optimized and mapped {len(output_manifest)} / 731 manufacturer images", flush=True)
        updated_by_row[target_index] = gallery_urls
        if [url.strip() for url in old_gallery.split(",") if url.strip()] != gallery_urls:
            replaced_gallery_count += 1

    updated_rows: list[dict[str, str]] = []
    for index, row in enumerate(catalog_rows):
        if index in updated_by_row:
            row = dict(row)
            row["Images"] = ", ".join(updated_by_row[index])
        updated_rows.append(row)

    matched_rows = set(updated_by_row)
    unmatched_catalog_rows = [
        {"Type": row.get("Type", ""), "SKU": row.get("SKU", ""), "Name": row.get("Name", "")}
        for index, row in columbia_rows if index not in matched_rows
    ]
    ambiguous_report = {
        sku: [{"Type": row.get("Type", ""), "SKU": row.get("SKU", ""), "Name": row.get("Name", "")}
              for row in rows]
        for sku, rows in ambiguous_source_skus.items()
    }
    summary = {
        "mode": "apply" if args.apply else "preview",
        "official_catalog_rows": len(catalog_rows),
        "columbia_simple_variation_variable_rows": len(columbia_rows),
        "one_to_one_exact_identifier_rows_mapped": len(updated_by_row),
        "catalog_galleries_replaced": replaced_gallery_count,
        "manufacturer_images_mapped": len(output_manifest),
        "catalog_rows_left_unchanged": len(unmatched_catalog_rows),
        "manufacturer_skus_without_exact_catalog_match": len(unmatched_source_skus),
        "ambiguous_manufacturer_skus_held_back": len(ambiguous_source_skus),
        "source_bytes": source_bytes_total,
        "optimized_bytes": output_bytes_total,
        "max_edge_px": MAX_EDGE,
        "webp_lossless": True,
        "webp_quality": WEBP_QUALITY,
        "encoder_method": ENCODER_METHOD,
    }

    if args.apply:
        write_csv_atomic(OFFICIAL_CATALOG, catalog_fields, updated_rows)
        write_csv_atomic(OUTPUT_DIR / "image-sync-manifest.csv", list(output_manifest[0]), output_manifest)
        update_fields = ["Type", "SKU", "Images"]
        update_rows = []
        for index, urls in updated_by_row.items():
            update_rows.append({
                "Type": catalog_rows[index].get("Type", ""),
                "SKU": catalog_rows[index].get("SKU", ""),
                "Images": ", ".join(urls),
            })
        write_csv_atomic(OUTPUT_DIR / "columbia-images-update-existing.csv", update_fields, update_rows)
        (OUTPUT_DIR / "unmatched-identities.json").write_text(json.dumps({
            "catalog_rows_without_exact_manufacturer_image_match": unmatched_catalog_rows,
            "manufacturer_skus_without_exact_catalog_match": sorted(unmatched_source_skus),
            "ambiguous_manufacturer_skus_held_back": ambiguous_report,
        }, indent=2) + "\n", encoding="utf-8")
        report = (
            "# Columbia manufacturer image gallery rebuild\n\n"
            "Generated 2026-11-01 from the existing manufacturer image manifest. Each image is mapped through "
            "the manifest's source SKU to one unique Columbia catalog row using an exact match in catalog SKU, "
            "manufacturer SKU, MPN, or schema MPN. Fuzzy matching and cross-assignment are excluded.\n\n"
            "## Applied scope\n\n"
            f"- Columbia simple, variation, and variable rows: {len(columbia_rows)}\n"
            f"- One-to-one catalog rows mapped: {len(updated_by_row)}\n"
            f"- Galleries replaced with manufacturer image order: {replaced_gallery_count}\n"
            f"- Manufacturer images linked: {len(output_manifest)}\n"
            f"- Catalog rows held unchanged without an exact match: {len(unmatched_catalog_rows)}\n"
            f"- Source SKUs with no exact catalog identity: {len(unmatched_source_skus)}\n"
            f"- Ambiguous source SKUs held back: {len(ambiguous_source_skus)}\n"
            f"- Source bytes: {source_bytes_total:,}; optimized bytes: {output_bytes_total:,}; "
            f"reduction: {100 * (1 - output_bytes_total / source_bytes_total):.1f}%\n\n"
            f"New WebP assets preserve their aspect ratios, are capped at {MAX_EDGE}px on the longest edge, "
            "and use lossless WebP encoding after high-quality Lanczos resizing. They use separate `_mfr_` "
            "filenames, preserving existing media files for rollback. The image manifest "
            "records source and destination hashes, dimensions, source IDs, match field, old gallery, and URL.\n\n"
            "## Validation limits\n\n"
            "Source identity and image order are evidenced by the existing manufacturer product export and image manifest. "
            "This run does not independently certify every photograph against a physical product. The output folder is "
            "the repository upload package; this script does not transfer files to production or mutate live WooCommerce. "
            "After upload, verify each public URL and live gallery before considering the deployment complete.\n"
        )
        (OUTPUT_DIR / "report.md").write_text(report, encoding="utf-8")

    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()

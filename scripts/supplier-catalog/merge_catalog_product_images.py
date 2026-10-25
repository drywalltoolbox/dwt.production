#!/usr/bin/env python3
"""Apply a narrow Type/SKU/Images update to the official catalog safely."""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import os
import tempfile
from pathlib import Path

from PIL import Image

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
DEFAULT_CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_UPDATE = ROOT / "products" / "launch" / "in-works" / "tapetech_parts_images_update_existing_20261025.csv"
DEFAULT_MEDIA = ROOT / "products" / "launch" / "media" / "media-10-23-2026" / "media"
DEFAULT_BACKUP = HERE / "results" / "images" / "tapetech-parts" / "dtb_official_catalog_before_tapetech_image_merge.csv"
DEFAULT_AUDIT = HERE / "results" / "images" / "tapetech-parts" / "tapetech-official-catalog-image-merge-audit.csv"
AUDIT_FIELDS = ("Type", "SKU", "Name", "Brand", "previous_images", "new_images", "local_file", "file_sha256", "action")


def read_catalog(path: Path) -> tuple[bytes, str, list[list[str]]]:
    raw = path.read_bytes()
    encoding = "utf-8-sig" if raw.startswith(b"\xef\xbb\xbf") else "utf-8"
    records = list(csv.reader(io.StringIO(raw.decode(encoding), newline="")))
    if not records:
        raise ValueError(f"Catalog is empty: {path}")
    return raw, encoding, records


def encode_catalog(records: list[list[str]], encoding: str, newline: str) -> bytes:
    output = io.StringIO(newline="")
    csv.writer(output, lineterminator=newline, quoting=csv.QUOTE_MINIMAL).writerows(records)
    text = output.getvalue()
    return ("\ufeff" + text).encode("utf-8") if encoding == "utf-8-sig" else text.encode("utf-8")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def atomic_write(path: Path, content: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, path)
    except Exception:
        Path(temp_name).unlink(missing_ok=True)
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--update-csv", type=Path, default=DEFAULT_UPDATE)
    parser.add_argument("--media-dir", type=Path, default=DEFAULT_MEDIA)
    parser.add_argument("--backup", type=Path, default=DEFAULT_BACKUP)
    parser.add_argument("--audit", type=Path, default=DEFAULT_AUDIT)
    args = parser.parse_args()

    with args.update_csv.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != ["Type", "SKU", "Images"]:
            raise ValueError("Image update CSV must contain exactly Type, SKU, Images")
        update_rows = list(reader)
    if not update_rows:
        raise ValueError("Image update CSV has no product rows")
    updates: dict[str, dict[str, str]] = {}
    for row in update_rows:
        sku = (row.get("SKU") or "").strip()
        key = sku.casefold()
        if not sku or key in updates:
            raise ValueError(f"Blank or duplicate SKU in update CSV: {sku!r}")
        if not row.get("Type") or not row.get("Images"):
            raise ValueError(f"Incomplete image update row for SKU {sku}")
        basename = row["Images"].split("?", 1)[0].rstrip("/").rsplit("/", 1)[-1]
        image_path = args.media_dir / basename
        if not image_path.is_file():
            raise ValueError(f"Mapped media file is missing for SKU {sku}: {image_path}")
        with Image.open(image_path) as image:
            if image.format != "WEBP":
                raise ValueError(f"Mapped media is not WebP for SKU {sku}: {image_path}")
            image.verify()
        updates[key] = row

    original, encoding, records = read_catalog(args.catalog)
    header = records[0]
    if len(set(header)) != len(header) or any(field not in header for field in ("Type", "SKU", "Images", "Name", "Brands")):
        raise ValueError("Official catalog lacks unique Type, SKU, Images, Name, or Brands headers")
    indexes = {field: header.index(field) for field in ("Type", "SKU", "Images", "Name", "Brands")}
    catalog_index: dict[str, int] = {}
    for index, row in enumerate(records[1:], start=1):
        sku = row[indexes["SKU"]].strip()
        if sku:
            key = sku.casefold()
            if key in catalog_index:
                raise ValueError(f"Duplicate SKU in official catalog: {sku}")
            catalog_index[key] = index

    newline = "\r\n" if b"\r\n" in original else "\n"
    if encode_catalog(read_catalog(args.catalog)[2], encoding, newline) != original:
        raise ValueError("CSV round-trip would alter unrelated catalog bytes; refusing update")

    audit_rows = []
    changed = 0
    for key, update in updates.items():
        index = catalog_index.get(key)
        if index is None:
            raise ValueError(f"Update SKU is absent from official catalog: {update['SKU']}")
        row = records[index]
        if row[indexes["Type"]].strip().casefold() != update["Type"].strip().casefold():
            raise ValueError(f"Type mismatch for SKU {update['SKU']}: catalog={row[indexes['Type']]} import={update['Type']}")
        if row[indexes["Brands"]].strip().casefold() != "tapetech":
            raise ValueError(f"Brand mismatch for TapeTech image update SKU {update['SKU']}")
        old_image = row[indexes["Images"]].strip()
        target_image = update["Images"].strip()
        if old_image not in {"", target_image, "https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/Photo-Coming-Soon-Placeholder.webp"}:
            raise ValueError(f"Refusing to overwrite an existing product image for SKU {update['SKU']}: {old_image}")
        action = "already_current" if old_image == target_image else "replaced_placeholder" if old_image else "filled_blank"
        if action != "already_current":
            row[indexes["Images"]] = target_image
            changed += 1
        image_path = args.media_dir / target_image.split("?", 1)[0].rstrip("/").rsplit("/", 1)[-1]
        audit_rows.append({
            "Type": update["Type"], "SKU": update["SKU"], "Name": row[indexes["Name"]],
            "Brand": row[indexes["Brands"]], "previous_images": old_image,
            "new_images": target_image, "local_file": image_path.name,
            "file_sha256": sha256_file(image_path), "action": action,
        })

    updated = encode_catalog(records, encoding, newline)
    if updated != original:
        if not args.backup.exists():
            atomic_write(args.backup, original)
        elif args.backup.read_bytes() == updated:
            raise ValueError("Catalog backup unexpectedly matches the post-update catalog")
        atomic_write(args.catalog, updated)

    args.audit.parent.mkdir(parents=True, exist_ok=True)
    with args.audit.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=AUDIT_FIELDS, lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(audit_rows)

    print(f"Image update rows reconciled: {len(update_rows)}")
    print(f"Official catalog Images cells updated: {changed}")
    print(f"Catalog rows/columns preserved: {len(records) - 1}/{len(header)}")
    print(f"Catalog backup: {args.backup}")
    print(f"Merge audit: {args.audit}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

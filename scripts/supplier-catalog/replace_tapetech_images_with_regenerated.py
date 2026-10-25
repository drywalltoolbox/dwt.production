#!/usr/bin/env python3
"""Replace exact TSW-derived media files with regenerated TapeTech WebP assets."""

from __future__ import annotations

import argparse
import csv
import hashlib
import os
import shutil
import tempfile
from collections import defaultdict
from pathlib import Path

from PIL import Image

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
DEFAULT_GENERATED = ROOT / "products" / "launch" / "media" / "regenerated_images_webp"
DEFAULT_MEDIA = ROOT / "products" / "launch" / "media" / "media-10-23-2026" / "media"
DEFAULT_SOURCE = HERE / "results" / "images" / "tapetech-parts"
DEFAULT_BACKUP = DEFAULT_SOURCE / "original_webp_before_generated"
DEFAULT_REPORT = DEFAULT_SOURCE / "tapetech-generated-replacements.csv"
REPORT_FIELDS = (
    "generated_file", "source_skus", "source_product_names", "launch_catalog_skus",
    "draft_product_skus", "target_media_file", "old_sha256", "generated_sha256",
    "old_width", "old_height", "new_width", "new_height", "old_bytes", "new_bytes",
    "status",
)


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verified_dimensions(path: Path) -> tuple[int, int]:
    with Image.open(path) as image:
        if image.format != "WEBP":
            raise ValueError(f"Expected WebP: {path}")
        image.verify()
    with Image.open(path) as image:
        return image.size


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--generated-dir", type=Path, default=DEFAULT_GENERATED)
    parser.add_argument("--media-dir", type=Path, default=DEFAULT_MEDIA)
    parser.add_argument("--source-dir", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--backup-dir", type=Path, default=DEFAULT_BACKUP)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    source_rows = read_csv(args.source_dir / "tapetech-parts-image-manifest.csv")
    audit_rows = read_csv(args.source_dir / "tapetech-parts-migration-audit.csv")
    source_by_stem: dict[str, list[dict[str, str]]] = defaultdict(list)
    audit_by_sku = {row["sku"].casefold(): row for row in audit_rows}
    for row in source_rows:
        filename = (row.get("image_file") or "").strip()
        if filename:
            source_by_stem[Path(filename).stem.casefold()].append(row)

    generated_files = sorted((path for path in args.generated_dir.iterdir() if path.is_file()), key=lambda p: p.name.casefold())
    if not generated_files:
        raise ValueError(f"No generated images found under {args.generated_dir}")

    replacements: list[dict[str, object]] = []
    targets_seen: set[str] = set()
    for generated in generated_files:
        matches = source_by_stem.get(generated.stem.casefold(), [])
        if not matches:
            raise ValueError(f"Generated image has no exact source-image basename match: {generated.name}")
        sku_keys = {row["sku"].casefold() for row in matches}
        mapped = [audit_by_sku.get(key) for key in sku_keys]
        if any(row is None for row in mapped):
            raise ValueError(f"No migration audit row for source image {generated.name}")
        if any(row["migration_status"] != "migrated" for row in mapped):
            raise ValueError(f"Source image maps to an unavailable/placeholder product: {generated.name}")
        destination_names = {row["media_file"] for row in mapped}
        selected_urls = {row["selected_image_url"] for row in mapped}
        if len(destination_names) != 1 or len(selected_urls) != 1:
            raise ValueError(f"Shared source basename has conflicting product destinations: {generated.name}")
        destination_name = destination_names.pop()
        selected_urls.pop()
        target = args.media_dir / destination_name
        if not target.is_file():
            raise ValueError(f"Original migrated image is missing: {target}")
        if destination_name.casefold() in targets_seen:
            raise ValueError(f"Multiple generated images target the same media file: {destination_name}")
        targets_seen.add(destination_name.casefold())

        target_hash = sha256(target)
        expected_old_hashes = {row["webp_sha256"] for row in mapped}
        generated_hash = sha256(generated)
        if len(expected_old_hashes) != 1:
            raise ValueError(f"Source rows disagree on the original media hash: {generated.name}")
        expected_old_hash = next(iter(expected_old_hashes))
        if target_hash not in {expected_old_hash, generated_hash}:
            raise ValueError(f"Current media no longer matches the audited source WebP: {target}")
        old_width, old_height = (
            verified_dimensions(target)
            if target_hash == expected_old_hash
            else (int(mapped[0]["source_width"]), int(mapped[0]["source_height"]))
        )
        new_width, new_height = verified_dimensions(generated)
        if new_width < old_width or new_height < old_height:
            raise ValueError(f"Generated image is smaller than the current media: {generated.name}")

        backup = args.backup_dir / destination_name
        if backup.exists() and sha256(backup) != expected_old_hash:
            raise ValueError(f"Existing original-image backup does not match the current media: {backup}")
        replacements.append({
            "generated": generated,
            "target": target,
            "backup": backup,
            "sku_rows": matches,
            "audit_rows": mapped,
            "old_hash": expected_old_hash,
            "new_hash": generated_hash,
            "old_size": (old_width, old_height),
            "new_size": (new_width, new_height),
            "status": "already_replaced" if target_hash == generated_hash else "replace",
        })

    if not args.dry_run:
        args.backup_dir.mkdir(parents=True, exist_ok=True)
        # Preserve all originals before replacing any destination.
        for item in replacements:
            backup: Path = item["backup"]
            if not backup.exists():
                shutil.copy2(item["target"], backup)
                if sha256(backup) != item["old_hash"]:
                    raise ValueError(f"Original-image backup verification failed: {backup}")

        args.media_dir.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="tapetech-generated-", dir=args.media_dir) as temp_name:
            staged: list[tuple[Path, Path, Path]] = []
            for item in replacements:
                if item["status"] == "already_replaced":
                    continue
                temp_path = Path(temp_name) / item["target"].name
                shutil.copyfile(item["generated"], temp_path)
                if sha256(temp_path) != item["new_hash"]:
                    raise ValueError(f"Staged generated-image verification failed: {item['generated']}")
                staged.append((temp_path, item["target"], item["backup"]))
            committed: list[tuple[Path, Path]] = []
            try:
                for temp_path, target, backup in staged:
                    os.replace(temp_path, target)
                    committed.append((target, backup))
            except Exception:
                for target, backup in reversed(committed):
                    rollback_temp = target.with_name(f".{target.name}.rollback")
                    shutil.copy2(backup, rollback_temp)
                    os.replace(rollback_temp, target)
                raise
        for item in replacements:
            item["status"] = "already_replaced" if item["status"] == "already_replaced" else "replaced"

    report_rows = []
    for item in replacements:
        sku_rows = item["sku_rows"]
        audit = item["audit_rows"]
        new_width, new_height = item["new_size"]
        old_width, old_height = item["old_size"]
        report_rows.append({
            "generated_file": item["generated"].name,
            "source_skus": "|".join(sorted({r["sku"] for r in sku_rows}, key=str.casefold)),
            "source_product_names": " | ".join(sorted({r["name"] for r in sku_rows})),
            "launch_catalog_skus": "|".join(sorted({r["sku"] for r in audit if r["launch_catalog_match"] == "exact_sku"}, key=str.casefold)),
            "draft_product_skus": "|".join(sorted({r["sku"] for r in audit if r["draft_match"] == "exact_sku"}, key=str.casefold)),
            "target_media_file": item["target"].name,
            "old_sha256": item["old_hash"],
            "generated_sha256": item["new_hash"],
            "old_width": str(old_width),
            "old_height": str(old_height),
            "new_width": str(new_width),
            "new_height": str(new_height),
            "old_bytes": str(item["backup"].stat().st_size if item["backup"].exists() else item["target"].stat().st_size),
            "new_bytes": str(item["generated"].stat().st_size),
            "status": item["status"],
        })
    args.report.parent.mkdir(parents=True, exist_ok=True)
    with args.report.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=REPORT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(report_rows)

    print(f"Generated images matched by exact supplier image basename: {len(replacements)}")
    print(f"Unique destination media files: {len(targets_seen)}")
    print(f"Exact launch/draft product SKUs represented: {sum(bool(r['launch_catalog_skus'] or r['draft_product_skus']) for r in report_rows)}")
    print(f"Replacements applied: {sum(row['status'] == 'replaced' for row in report_rows)}")
    print(f"Report: {args.report}")
    print(f"Original WebP backups: {args.backup_dir}")
    if args.dry_run:
        print("Dry run only; media files were not replaced.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

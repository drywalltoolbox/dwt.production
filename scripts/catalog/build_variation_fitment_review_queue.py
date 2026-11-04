#!/usr/bin/env python3
"""Build a read-only queue for exact fitment edges that point to variations."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path

from catalog_change_manifest import file_sha256
from official_catalog_schema import CatalogValidationError, validate_catalog


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/variation-fitment-review-queue.csv"
COMPATIBLE = "Meta: _dtb_compatible_tool_skus"
TRUTHY = {"1", "true", "yes", "y"}


def load_rows(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = list(reader.fieldnames or [])
        return fields, list(reader)


def is_part(row: dict[str, str]) -> bool:
    return (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part" or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY


def build_queue(catalog_path: Path) -> tuple[list[dict[str, str]], dict[str, object]]:
    fields, rows = load_rows(catalog_path)
    required = {"SKU", "Name", "Type", "Published", "Parent", COMPATIBLE, "Meta: _dtb_schematic_id", "Meta: _dtb_schematic_brand", "Meta: _dtb_schematic_group", "Meta: _dtb_schematic_page", "Meta: _dtb_schematic_variant"}
    if not required.issubset(fields):
        raise CatalogValidationError(f"catalog is missing required variation-review columns: {sorted(required - set(fields))}")
    catalog: dict[str, dict[str, str]] = {}
    for row in rows:
        sku = (row.get("SKU") or "").strip().upper()
        if sku:
            if sku in catalog:
                raise CatalogValidationError(f"canonical catalog contains duplicate SKU: {sku}")
            catalog[sku] = row

    variations = {sku: row for sku, row in catalog.items() if (row.get("Type") or "").strip().casefold() == "variation"}
    parents = Counter((row.get("Parent") or "").strip().upper() for row in variations.values())
    queue: list[dict[str, str]] = []
    source_digest = file_sha256(catalog_path)
    for part in rows:
        if not is_part(part) or (part.get("Published") or "").strip().casefold() not in TRUTHY:
            continue
        part_sku = (part.get("SKU") or "").strip().upper()
        try:
            targets = json.loads(part.get(COMPATIBLE) or "[]")
        except json.JSONDecodeError as exc:
            raise CatalogValidationError(f"{part_sku}: {COMPATIBLE} is not valid JSON") from exc
        if not isinstance(targets, list) or any(not isinstance(target, str) for target in targets):
            raise CatalogValidationError(f"{part_sku}: {COMPATIBLE} must be a string array")
        for target_sku in targets:
            target_key = target_sku.strip().upper()
            variation = variations.get(target_key)
            if variation is None:
                continue
            parent_sku = (variation.get("Parent") or "").strip().upper()
            parent = catalog.get(parent_sku)
            attributes = {
                field.removeprefix("Attribute ").replace(" name", "").replace(" value(s)", " value(s)"): (variation.get(field) or "").strip()
                for field in fields
                if field.startswith("Attribute ") and (field.endswith(" name") or field.endswith(" value(s)")) and (variation.get(field) or "").strip()
            }
            queue.append({
                "catalog_sha256": source_digest,
                "part_sku": part_sku,
                "part_name": (part.get("Name") or "").strip(),
                "part_schematic_id": (part.get("Meta: _dtb_schematic_id") or "").strip(),
                "part_schematic_brand": (part.get("Meta: _dtb_schematic_brand") or "").strip(),
                "part_schematic_group": (part.get("Meta: _dtb_schematic_group") or "").strip(),
                "part_schematic_page": (part.get("Meta: _dtb_schematic_page") or "").strip(),
                "part_schematic_variant": (part.get("Meta: _dtb_schematic_variant") or "").strip(),
                "target_variation_sku": target_sku.strip(),
                "target_variation_name": (variation.get("Name") or "").strip(),
                "target_parent_sku": parent_sku,
                "target_parent_name": (parent.get("Name") or "").strip() if parent else "",
                "target_parent_published": (parent.get("Published") or "").strip() if parent else "",
                "target_parent_type": (parent.get("Type") or "").strip() if parent else "",
                "parent_variation_count": str(parents[parent_sku]),
                "variation_attributes_json": json.dumps(attributes, ensure_ascii=False, sort_keys=True),
                "suggested_decision": "review_parent_family" if parent else "review_parent_missing",
                "review_status": "needs_review",
                "reviewer": "",
                "reviewed_at": "",
                "decision_parent_sku": "",
                "evidence_reference": "",
            })
    queue.sort(key=lambda row: (row["part_sku"], row["target_variation_sku"].upper()))
    summary = {
        "schema_version": 1,
        "read_only": True,
        "catalog_sha256": source_digest,
        "published_part_variation_edges": len(queue),
        "unique_parts": len({row["part_sku"] for row in queue}),
        "unique_variation_skus": len({row["target_variation_sku"].upper() for row in queue}),
        "rows_with_parent": sum(bool(row["target_parent_sku"]) for row in queue),
        "rows_with_catalog_parent": sum(bool(row["target_parent_name"]) for row in queue),
        "review_status": "needs_review_for_all_rows",
        "catalog_mutated": False,
    }
    return queue, summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    catalog_path = args.catalog.resolve()
    validate_catalog(catalog_path, args.include_gap_audit.resolve())
    queue, summary = build_queue(catalog_path)
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    columns = list(queue[0]) if queue else ["catalog_sha256", "part_sku", "part_name", "part_schematic_id", "part_schematic_brand", "part_schematic_group", "part_schematic_page", "part_schematic_variant", "target_variation_sku", "target_variation_name", "target_parent_sku", "target_parent_name", "target_parent_published", "target_parent_type", "parent_variation_count", "variation_attributes_json", "suggested_decision", "review_status", "reviewer", "reviewed_at", "decision_parent_sku", "evidence_reference"]
    with output.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(queue)
    summary_path = output.with_suffix(".summary.json")
    summary_path.write_text(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({**summary, "queue": str(output), "summary": str(summary_path)}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error) as exc:
        raise SystemExit(f"ERROR: {exc}")

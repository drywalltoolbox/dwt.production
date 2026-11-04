#!/usr/bin/env python3
"""Create a review-only manifest for removing one exact, evidenced fitment target."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, file_sha256, row_sha256
from official_catalog_schema import CatalogValidationError, validate_catalog


ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
FIELD = "Meta: _dtb_compatible_tool_skus"
TRUTHY = {"1", "true", "yes", "y"}


def build_row(catalog_path: Path, part_sku: str, remove_tool_sku: str, evidence: str) -> dict[str, str]:
    with catalog_path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    matches = [row for row in rows if (row.get("SKU") or "").strip().upper() == part_sku.strip().upper()]
    if len(matches) != 1:
        raise CatalogValidationError(f"expected one canonical part SKU {part_sku!r}; found {len(matches)}")
    owner = matches[0]
    is_part = (owner.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part" or (owner.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY
    if not is_part or (owner.get("Type") or "").strip().casefold() == "variation" or (owner.get("Published") or "").strip().casefold() not in TRUTHY:
        raise CatalogValidationError(f"{part_sku}: removal owner must be a published parent part")
    raw = (owner.get(FIELD) or "").strip()
    try:
        current = json.loads(raw) if raw else []
    except json.JSONDecodeError as exc:
        raise CatalogValidationError(f"{part_sku}: current compatibility field is malformed") from exc
    if not isinstance(current, list) or any(not isinstance(value, str) or not value.strip() for value in current):
        raise CatalogValidationError(f"{part_sku}: current compatibility field must be a string array")
    matches_target = [value for value in current if value.strip().upper() == remove_tool_sku.strip().upper()]
    if len(matches_target) != 1:
        raise CatalogValidationError(f"{part_sku}: exact target {remove_tool_sku!r} must occur once in the field")
    proposed = [value for value in current if value.strip().upper() != remove_tool_sku.strip().upper()]
    return {
        "catalog_sha256": file_sha256(catalog_path),
        "row_sha256": row_sha256(owner),
        "sku": (owner.get("SKU") or "").strip().upper(),
        "workflow": "compatibility_remove",
        "field": FIELD,
        "current_value": raw,
        "proposed_value": json.dumps(proposed, separators=(",", ":")),
        "evidence": json.dumps({"operation": "remove_exact_fitment_target", "removed_tool_sku": matches_target[0], "reference": evidence.strip()}, ensure_ascii=False, separators=(",", ":")),
        "review_status": "needs_review",
        "reviewer": "",
        "reviewed_at": "",
        "approval_sha256": "",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=GAPS)
    parser.add_argument("--sku", required=True, help="Exact canonical part SKU")
    parser.add_argument("--remove-tool-sku", required=True, help="Exact target SKU to remove")
    parser.add_argument("--evidence", required=True, help="Specific source/evidence reference supporting this correction")
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not args.evidence.strip():
        raise CatalogValidationError("removal requires a non-empty evidence reference")
    catalog = args.catalog.resolve()
    validate_catalog(catalog, args.include_gap_audit.resolve())
    row = build_row(catalog, args.sku, args.remove_tool_sku, args.evidence)
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(row)
    print(json.dumps({"manifest": str(output), "rows": 1, "review_status": "needs_review", "catalog_mutated": False}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error) as exc:
        raise SystemExit(f"ERROR: {exc}")

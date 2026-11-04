#!/usr/bin/env python3
"""Audit canonical catalog relationship coverage without mutating source data."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path

from catalog_change_manifest import file_sha256
from official_catalog_schema import validate_catalog
from build_schematic_compatibility_review_manifest import is_part
from prepare_schematic_compatibility_proposals import COMPATIBLE_FIELD, REPLACEMENT_FIELD


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_PROPOSALS = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-summary.json"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/product-relationship-coverage.json"
PART_FLAG = "Meta: _dtb_is_parts"
KIND = "Meta: _dtb_product_kind"


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def values(raw: str) -> list[str]:
    if not raw:
        return []
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        decoded = [item.strip() for item in raw.split(",")]
    if not isinstance(decoded, list):
        return []
    return [str(item).strip().upper() for item in decoded if str(item).strip()]


def decode_relationship(raw: str) -> tuple[list[str], bool]:
    if not raw:
        return [], False
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return values(raw), raw.lstrip().startswith(("[", "{"))
    if not isinstance(decoded, list) or any(not isinstance(item, str) or not item.strip() for item in decoded):
        return [], True
    return [item.strip().upper() for item in decoded], False


def is_published(row: dict[str, str]) -> bool:
    return (row.get("Published") or "").strip().casefold() in {"1", "yes", "true"}


def audit(catalog_path: Path, proposals_path: Path) -> dict[str, object]:
    rows = read_csv(catalog_path)
    by_sku = {(row.get("SKU") or "").strip().upper(): row for row in rows if (row.get("SKU") or "").strip()}
    published = [row for row in rows if is_published(row) and (row.get("Type") or "").strip().casefold() != "variation"]
    published_parts = [row for row in published if is_part(row)]
    published_tools = [row for row in published if not is_part(row)]
    fitment_populated = 0
    fitment_missing = 0
    fitment_unresolved: Counter[str] = Counter()
    field_overlap_count = 0
    malformed_fitment_parts = 0
    for part in published_parts:
        compatible, malformed_compatible = decode_relationship(part.get(COMPATIBLE_FIELD) or "")
        replacement, malformed_replacement = decode_relationship(part.get(REPLACEMENT_FIELD) or "")
        if malformed_compatible or malformed_replacement:
            malformed_fitment_parts += 1
        if compatible or replacement:
            fitment_populated += 1
        else:
            fitment_missing += 1
        if set(compatible).intersection(replacement):
            field_overlap_count += 1
        for target in set(compatible + replacement):
            target_row = by_sku.get(target)
            if target_row is None:
                fitment_unresolved["target_sku_missing"] += 1
            elif (target_row.get("Type") or "").strip().casefold() == "variation":
                fitment_unresolved["target_is_variation"] += 1
            elif not is_published(target_row):
                fitment_unresolved["target_unpublished"] += 1
            elif is_part(target_row):
                fitment_unresolved["target_is_part"] += 1

    upsell_sources = 0
    cross_sell_sources = 0
    merch_overlap = 0
    self_links = 0
    for row in published:
        sku = (row.get("SKU") or "").strip().upper()
        upsells = set(values(row.get("Upsells") or ""))
        cross_sells = set(values(row.get("Cross-sells") or ""))
        upsell_sources += bool(upsells)
        cross_sell_sources += bool(cross_sells)
        merch_overlap += bool(upsells.intersection(cross_sells))
        self_links += bool(sku and sku in upsells.union(cross_sells))

    proposal_summary = json.loads(proposals_path.read_text(encoding="utf-8")) if proposals_path.exists() else {}
    return {
        "schema_version": 1,
        "catalog_sha256": file_sha256(catalog_path),
        "catalog_rows": len(rows),
        "denominators": {
            "published_parts_excluding_variations": len(published_parts),
            "published_non_part_products_excluding_variations": len(published_tools),
        },
        "canonical_fitment": {
            "parts_with_structured_relationships": fitment_populated,
            "parts_without_structured_relationships": fitment_missing,
            "target_field_overlap_parts": field_overlap_count,
            "malformed_relationship_parts": malformed_fitment_parts,
            "unresolved_targets": dict(sorted(fitment_unresolved.items())),
        },
        "merchandising": {
            "published_products_with_upsells": upsell_sources,
            "published_products_with_cross_sells": cross_sell_sources,
            "same_target_in_both_fields": merch_overlap,
            "self_link_sources": self_links,
        },
        "schematic_proposals": {
            "proposal_rows": proposal_summary.get("proposal_rows", 0),
            "by_status": proposal_summary.get("by_status", {}),
            "unresolved_rows": proposal_summary.get("unresolved_rows", 0),
            "unresolved_by_status": proposal_summary.get("unresolved_by_status", {}),
        },
        "runtime_projection": {
            "audited": False,
            "reason": "This report reads the canonical repository catalog only; runtime projection needs a separate authorized export and reconciliation.",
        },
        "interpretation": "Missing relationship metadata means no structured relationship is recorded; it does not mean incompatible.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--proposals-summary", type=Path, default=DEFAULT_PROPOSALS)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    catalog_path = args.catalog.resolve()
    validate_catalog(catalog_path, args.include_gap_audit.resolve())
    report = audit(catalog_path, args.proposals_summary.resolve())
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

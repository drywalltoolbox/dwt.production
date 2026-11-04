#!/usr/bin/env python3
"""Convert exact schematic compatibility proposals into a review-only catalog manifest."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import defaultdict
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, file_sha256, row_sha256
from official_catalog_schema import validate_catalog


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_PROPOSALS = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-proposals.csv"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv"
FIELD = "Meta: _dtb_compatible_tool_skus"
TRUTHY = {"1", "true", "yes", "y"}


def is_part(row: dict[str, str]) -> bool:
    return (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part" or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def build_manifest(catalog_path: Path, proposal_path: Path) -> list[dict[str, str]]:
    _fields, rows = read_csv(catalog_path)
    catalog = {(row.get("SKU") or "").strip().upper(): row for row in rows}
    _proposal_fields, proposals = read_csv(proposal_path)
    by_part: dict[str, dict[str, object]] = defaultdict(lambda: {"targets": set(), "evidence": []})
    for proposal in proposals:
        if (proposal.get("status") or "").strip() != "proposal_exact":
            continue
        part_sku = (proposal.get("part_sku") or "").strip().upper()
        if part_sku not in catalog:
            raise ValueError(f"proposal part SKU is absent from the canonical catalog: {part_sku}")
        if not is_part(catalog[part_sku]):
            raise ValueError(f"proposal owner is not classified as a part: {part_sku}")
        try:
            targets = json.loads(proposal.get("target_tool_skus") or "[]")
        except json.JSONDecodeError as exc:
            raise ValueError(f"{part_sku}: proposal targets are not valid JSON") from exc
        if not isinstance(targets, list) or not targets:
            raise ValueError(f"{part_sku}: exact proposal has no tool SKU targets")
        target_set = by_part[part_sku]["targets"]
        assert isinstance(target_set, set)
        for target in targets:
            sku = str(target).strip().upper()
            if not sku or sku not in catalog:
                raise ValueError(f"{part_sku}: proposal target is absent from the canonical catalog: {sku}")
            if is_part(catalog[sku]):
                raise ValueError(f"{part_sku}: proposal target is a part, expected a tool: {sku}")
            target_set.add(sku)
        evidence = by_part[part_sku]["evidence"]
        assert isinstance(evidence, list)
        evidence.append({
            "type": "exact_schematic_occurrence",
            "brand": proposal.get("brand", ""),
            "schematic_id": proposal.get("schematic_id", ""),
            "source_schematic_id": proposal.get("source_schematic_id", ""),
            "source_file": proposal.get("source_file", ""),
            "reason": proposal.get("reason", ""),
        })

    digest = file_sha256(catalog_path)
    manifest: list[dict[str, str]] = []
    for sku in sorted(by_part):
        row = catalog[sku]
        targets = sorted(by_part[sku]["targets"])
        existing = row.get(FIELD, "") or ""
        try:
            current_targets = json.loads(existing) if existing else []
        except json.JSONDecodeError:
            raise ValueError(f"{sku}: current compatibility field is not valid JSON; resolve it before generating a manifest")
        if not isinstance(current_targets, list) or any(not isinstance(value, str) or not value.strip() for value in current_targets):
            raise ValueError(f"{sku}: current compatibility field is not a string array; resolve it before generating a manifest")
        normalized_current = sorted({value.strip().upper() for value in current_targets})
        targets = sorted(set(targets).union(normalized_current))
        manifest.append({
            "catalog_sha256": digest,
            "row_sha256": row_sha256(row),
            "sku": sku,
            "workflow": "compatibility",
            "field": FIELD,
            "current_value": existing,
            "proposed_value": json.dumps(targets, separators=(",", ":")),
            "evidence": json.dumps(by_part[sku]["evidence"], ensure_ascii=False, separators=(",", ":")),
            "review_status": "needs_review",
            "reviewer": "",
            "reviewed_at": "",
            "approval_sha256": "",
        })
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--proposals", type=Path, default=DEFAULT_PROPOSALS)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    validate_catalog(args.catalog.resolve(), args.include_gap_audit.resolve())
    rows = build_manifest(args.catalog.resolve(), args.proposals.resolve())
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerows(rows)
    print(json.dumps({"manifest": output.relative_to(ROOT).as_posix() if output.is_relative_to(ROOT) else str(output), "rows": len(rows), "review_status": "needs_review", "catalog_mutated": False}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, csv.Error, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)

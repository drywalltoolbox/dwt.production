#!/usr/bin/env python3
"""Build a review-only compatibility manifest from cited manufacturer evidence."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import defaultdict
from pathlib import Path
from urllib.parse import urlparse

from catalog_change_manifest import MANIFEST_FIELDS, file_sha256, row_sha256
from official_catalog_schema import CatalogValidationError, validate_catalog


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_EVIDENCE = ROOT / "products/dev/catalog-enrichment/compatibility/documented-compatibility-evidence.csv"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/documented-compatibility-review-manifest.csv"
FIELD = "Meta: _dtb_compatible_tool_skus"
TRUTHY = {"1", "true", "yes", "y"}
EVIDENCE_FIELDS = ("part_sku", "target_tool_sku", "source_url", "source_title", "source_locator", "evidence_note")


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def is_part(row: dict[str, str]) -> bool:
    return (
        (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part"
        or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY
    )


def is_part_owner(row: dict[str, str], catalog: dict[str, dict[str, str]]) -> bool:
    if (row.get("Type") or "").strip().casefold() != "variation":
        return is_part(row)
    parent_sku = row.get("Parent") or row.get("Meta: _dtb_parent_product_sku") or ""
    parent = catalog.get(parent_sku.strip().upper())
    return bool(parent and parent.get("SKU", "") == parent_sku and (parent.get("Type") or "").strip().casefold() == "variable" and is_part(parent) and (parent.get("Published") or "").strip().casefold() in TRUTHY)


def decode_targets(value: str, sku: str) -> list[str]:
    if not value:
        return []
    try:
        targets = json.loads(value)
    except json.JSONDecodeError as exc:
        raise CatalogValidationError(f"{sku}: current compatibility value is not valid JSON") from exc
    if not isinstance(targets, list) or any(not isinstance(item, str) or not item or item != item.strip() for item in targets):
        raise CatalogValidationError(f"{sku}: current compatibility value is not a string array")
    if len({item.upper() for item in targets}) != len(targets):
        raise CatalogValidationError(f"{sku}: current compatibility value contains duplicate or case-conflicting SKUs")
    return targets


def build_manifest(catalog_path: Path, evidence_path: Path) -> list[dict[str, str]]:
    catalog_fields, rows = read_csv(catalog_path)
    if not {"SKU", "Type", "Published", FIELD}.issubset(catalog_fields):
        raise CatalogValidationError("canonical catalog is missing identity, publication, or compatibility columns")
    catalog: dict[str, dict[str, str]] = {}
    for row in rows:
        sku = (row.get("SKU") or "").strip().upper()
        if not sku:
            continue
        if sku in catalog:
            raise CatalogValidationError(f"canonical catalog contains duplicate SKU identity: {sku}")
        catalog[sku] = row

    evidence_fields, evidence_rows = read_csv(evidence_path)
    if tuple(evidence_fields) != EVIDENCE_FIELDS:
        raise CatalogValidationError(f"evidence header mismatch: expected {EVIDENCE_FIELDS!r}, found {tuple(evidence_fields)!r}")
    grouped: dict[str, dict[str, object]] = defaultdict(lambda: {"targets": set(), "evidence": []})
    seen_pairs: set[tuple[str, str]] = set()
    for evidence in evidence_rows:
        part_sku = evidence.get("part_sku") or ""
        target_sku = evidence.get("target_tool_sku") or ""
        pair = (part_sku, target_sku)
        if not part_sku or not target_sku or pair in seen_pairs:
            raise CatalogValidationError(f"empty or duplicate evidence relationship: {pair!r}")
        seen_pairs.add(pair)
        if any(not (evidence.get(key) or "").strip() for key in EVIDENCE_FIELDS):
            raise CatalogValidationError(f"{part_sku} -> {target_sku}: every evidence field is required")
        source_url = evidence["source_url"].strip()
        parsed_url = urlparse(source_url)
        if (
            parsed_url.scheme != "https"
            or not parsed_url.hostname
            or parsed_url.username
            or parsed_url.password
            or any(char.isspace() for char in source_url)
        ):
            raise CatalogValidationError(f"{part_sku} -> {target_sku}: evidence URL must be an absolute HTTPS URL without credentials or whitespace")
        part = catalog.get(part_sku.upper())
        target = catalog.get(target_sku.upper())
        if part is None or not is_part_owner(part, catalog):
            raise CatalogValidationError(f"{part_sku}: compatibility owner is absent or not classified as a part")
        if part.get("SKU", "") != part_sku:
            raise CatalogValidationError(f"{part_sku}: compatibility owner must match its exact canonical SKU")
        if (part.get("Published") or "").strip().casefold() not in TRUTHY:
            raise CatalogValidationError(f"{part_sku}: compatibility owner must be published")
        if target is None or is_part(target):
            raise CatalogValidationError(f"{target_sku}: compatibility target is absent or classified as a part")
        if target.get("SKU", "") != target_sku:
            raise CatalogValidationError(f"{target_sku}: compatibility target must match its exact canonical SKU")
        if (target.get("Published") or "").strip().casefold() not in TRUTHY:
            raise CatalogValidationError(f"{target_sku}: compatibility target is not published")
        if (target.get("Type") or "").strip().casefold() == "variation":
            parent_value = target.get("Parent") or ""
            parent_sku = parent_value.strip().upper()
            parent = catalog.get(parent_sku)
            if not parent_sku or parent is None or parent.get("SKU", "") != parent_value or parent_value != parent_value.strip() or is_part(parent) or (parent.get("Published") or "").strip().casefold() not in TRUTHY:
                raise CatalogValidationError(f"{target_sku}: variation target lacks a published non-part parent")
        bucket = grouped[part_sku]
        targets = bucket["targets"]
        assert isinstance(targets, set)
        targets.add(target_sku)
        refs = bucket["evidence"]
        assert isinstance(refs, list)
        refs.append({
            "type": "manufacturer_document",
            "evidence_role": "supports",
            "url": source_url,
            "title": evidence["source_title"].strip(),
            "locator": evidence["source_locator"].strip(),
            "note": evidence["evidence_note"].strip(),
            "part_sku": part_sku,
            "target_tool_sku": target_sku,
        })

    catalog_digest = file_sha256(catalog_path)
    manifest: list[dict[str, str]] = []
    for sku in sorted(grouped):
        row = catalog[sku.upper()]
        current_value = row.get(FIELD, "") or ""
        current_targets = decode_targets(current_value, sku)
        for value in current_targets:
            target_row = catalog.get(value.upper())
            if target_row is None or target_row.get("SKU", "") != value:
                raise CatalogValidationError(f"{sku}: current compatibility target must match its exact canonical SKU: {value}")
        added_targets = grouped[sku]["targets"]
        assert isinstance(added_targets, set)
        if not added_targets.difference(current_targets):
            continue
        if len(current_targets) + len(added_targets.difference(current_targets)) > 50:
            raise CatalogValidationError(f"{sku}: proposed compatibility value exceeds the 50-SKU bound")
        proposed = sorted(set(current_targets).union(added_targets))
        manifest.append({
            "catalog_sha256": catalog_digest,
            "row_sha256": row_sha256(row),
            "sku": sku,
            "workflow": "compatibility",
            "field": FIELD,
            "current_value": current_value,
            "proposed_value": json.dumps(proposed, separators=(",", ":")),
            "evidence": json.dumps(grouped[sku]["evidence"], ensure_ascii=False, separators=(",", ":")),
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
    parser.add_argument("--evidence", type=Path, default=DEFAULT_EVIDENCE)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    validate_catalog(args.catalog.resolve(), args.include_gap_audit.resolve())
    manifest = build_manifest(args.catalog.resolve(), args.evidence.resolve())
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerows(manifest)
    print(json.dumps({"manifest": output.relative_to(ROOT).as_posix() if output.is_relative_to(ROOT) else str(output), "rows": len(manifest), "review_status": "needs_review", "catalog_mutated": False}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, csv.Error, CatalogValidationError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)

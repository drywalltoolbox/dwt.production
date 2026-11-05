#!/usr/bin/env python3
"""Convert exact and multi-tool schematic candidates into a review-only manifest."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import defaultdict
from pathlib import Path
from urllib.parse import urlparse

from catalog_change_manifest import MANIFEST_FIELDS, file_sha256, row_sha256
from official_catalog_schema import validate_catalog


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_PROPOSALS = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-proposals.csv"
DEFAULT_SUPPLEMENTAL_EVIDENCE = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-manufacturer-evidence.csv"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv"
FIELD = "Meta: _dtb_compatible_tool_skus"
TRUTHY = {"1", "true", "yes", "y"}
SUPPLEMENTAL_FIELDS = ("part_sku", "target_tool_sku", "evidence_role", "source_url", "source_title", "source_locator", "evidence_note")
SUPPLEMENTAL_ROLES = {"supports", "context_only"}


def is_part(row: dict[str, str]) -> bool:
    return (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part" or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY


def is_published(row: dict[str, str]) -> bool:
    return (row.get("Published") or "").strip().casefold() in TRUTHY


def is_part_owner(row: dict[str, str], catalog: dict[str, dict[str, str]]) -> bool:
    if (row.get("Type") or "").strip().casefold() != "variation":
        return is_part(row)
    parent_sku = row.get("Parent") or row.get("Meta: _dtb_parent_product_sku") or ""
    parent = catalog.get(parent_sku.strip().upper())
    return bool(parent and parent.get("SKU", "") == parent_sku and (parent.get("Type") or "").strip().casefold() == "variable" and is_part(parent) and is_published(parent))


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def build_manifest(
    catalog_path: Path,
    proposal_path: Path,
    supplemental_evidence_path: Path | None = None,
) -> list[dict[str, str]]:
    _fields, rows = read_csv(catalog_path)
    catalog = {(row.get("SKU") or "").strip().upper(): row for row in rows}
    _proposal_fields, proposals = read_csv(proposal_path)
    by_part: dict[str, dict[str, object]] = defaultdict(lambda: {"targets": set(), "evidence": []})
    for proposal in proposals:
        proposal_status = (proposal.get("status") or "").strip()
        if proposal_status not in {"proposal_exact", "review_multi_tool"}:
            continue
        part_sku = proposal.get("part_sku") or ""
        part_row = catalog.get(part_sku.upper())
        if part_row is None:
            raise ValueError(f"proposal part SKU is absent or not exact in the canonical catalog: {part_sku}")
        if part_row.get("SKU", "") != part_sku:
            raise ValueError(f"proposal part SKU must match its exact canonical SKU: {part_sku}")
        if not is_part_owner(part_row, catalog):
            raise ValueError(f"proposal owner is not classified as a part: {part_sku}")
        if not is_published(part_row):
            raise ValueError(f"proposal owner is not an eligible published part: {part_sku}")
        try:
            targets = json.loads(proposal.get("target_tool_skus") or "[]")
        except json.JSONDecodeError as exc:
            raise ValueError(f"{part_sku}: proposal targets are not valid JSON") from exc
        if not isinstance(targets, list) or not targets:
            raise ValueError(f"{part_sku}: exact proposal has no tool SKU targets")
        target_set = by_part[part_sku]["targets"]
        assert isinstance(target_set, set)
        for target in targets:
            if not isinstance(target, str) or not target:
                raise ValueError(f"{part_sku}: proposal contains an empty or invalid target SKU")
            sku = target
            target_row = catalog.get(sku.upper())
            if target_row is None:
                raise ValueError(f"{part_sku}: proposal target is absent from the canonical catalog: {sku}")
            if target_row.get("SKU", "") != sku:
                raise ValueError(f"{part_sku}: proposal target must match its exact canonical SKU: {sku}")
            if is_part(target_row):
                raise ValueError(f"{part_sku}: proposal target is a part, expected a tool: {sku}")
            target = target_row
            if not is_published(target):
                raise ValueError(f"{part_sku}: proposal target is not published: {sku}")
            if (target.get("Type") or "").strip().casefold() == "variation":
                parent_value = target.get("Parent") or ""
                parent_sku = parent_value.strip().upper()
                parent = catalog.get(parent_sku)
                if not parent_sku or parent is None or parent.get("SKU", "") != parent_value or parent_value != parent_value.strip() or is_part(parent) or not is_published(parent):
                    raise ValueError(f"{part_sku}: proposal variation target lacks a published non-part parent: {sku}")
            target_set.add(sku)
        evidence = by_part[part_sku]["evidence"]
        assert isinstance(evidence, list)
        evidence.append({
            "type": "exact_schematic_occurrence" if proposal_status == "proposal_exact" else "multi_tool_schematic_review",
            "brand": proposal.get("brand", ""),
            "schematic_id": proposal.get("schematic_id", ""),
            "source_schematic_id": proposal.get("source_schematic_id", ""),
            "source_file": proposal.get("source_file", ""),
            "reason": proposal.get("reason", ""),
        })

    if supplemental_evidence_path is not None:
        if not supplemental_evidence_path.is_file():
            raise FileNotFoundError(f"supplemental manufacturer evidence file is missing: {supplemental_evidence_path}")
        evidence_fields, supplemental_rows = read_csv(supplemental_evidence_path)
        if tuple(evidence_fields) != SUPPLEMENTAL_FIELDS:
            raise ValueError(f"supplemental evidence header mismatch: expected {SUPPLEMENTAL_FIELDS!r}, found {tuple(evidence_fields)!r}")
        seen_supplements: set[tuple[str, str, str, str]] = set()
        for item in supplemental_rows:
            part_sku = item.get("part_sku") or ""
            target_sku = item.get("target_tool_sku") or ""
            role = (item.get("evidence_role") or "").strip().casefold()
            reference_key = (part_sku, target_sku, item.get("source_url", "").strip(), item.get("source_locator", "").strip())
            if not part_sku or not target_sku or reference_key in seen_supplements:
                raise ValueError(f"supplemental evidence has an empty or duplicate source reference: {reference_key!r}")
            seen_supplements.add(reference_key)
            if any(not (item.get(field) or "").strip() for field in SUPPLEMENTAL_FIELDS):
                raise ValueError(f"{part_sku} -> {target_sku}: supplemental evidence fields are required")
            if role not in SUPPLEMENTAL_ROLES:
                raise ValueError(f"{part_sku} -> {target_sku}: evidence_role must be one of {sorted(SUPPLEMENTAL_ROLES)}")
            parsed_url = urlparse(item["source_url"].strip())
            if parsed_url.scheme != "https" or not parsed_url.hostname or parsed_url.username or parsed_url.password:
                raise ValueError(f"{part_sku} -> {target_sku}: supplemental evidence URL must be an absolute HTTPS URL without credentials")
            if part_sku not in by_part or target_sku not in by_part[part_sku]["targets"]:
                # Evidence is durable across manifest rebuilds. Once the exact
                # relationship has been applied, keep its source record without
                # forcing it back into a pending review row.
                part_row = catalog.get(part_sku.upper())
                target_row = catalog.get(target_sku.upper())
                target_parent = None
                if target_row is not None and (target_row.get("Type") or "").strip().casefold() == "variation":
                    parent_value = target_row.get("Parent") or ""
                    target_parent = catalog.get(parent_value.strip().upper())
                current_targets: list[object] = []
                current_targets_valid = False
                if part_row is not None:
                    try:
                        decoded_targets = json.loads(part_row.get(FIELD, "") or "[]")
                        current_targets_valid = (
                            isinstance(decoded_targets, list)
                            and all(
                                isinstance(value, str)
                                and value
                                and value == value.strip()
                                and value.upper() in catalog
                                and catalog[value.upper()].get("SKU", "") == value
                                for value in decoded_targets
                            )
                            and len({value.upper() for value in decoded_targets}) == len(decoded_targets)
                        )
                        if current_targets_valid:
                            current_targets = decoded_targets
                    except json.JSONDecodeError:
                        current_targets = []
                exact_current_pair = (
                    part_row is not None
                    and part_row.get("SKU", "") == part_sku
                    and is_part_owner(part_row, catalog)
                    and is_published(part_row)
                    and current_targets_valid
                    and target_row is not None
                    and target_row.get("SKU", "") == target_sku
                    and not is_part(target_row)
                    and is_published(target_row)
                    and (
                        (target_row.get("Type") or "").strip().casefold() != "variation"
                        or (
                            target_parent is not None
                            and target_parent.get("SKU", "") == (target_row.get("Parent") or "")
                            and not is_part(target_parent)
                            and is_published(target_parent)
                        )
                    )
                    and target_sku in current_targets
                )
                if exact_current_pair:
                    continue
                raise ValueError(f"{part_sku} -> {target_sku}: supplemental evidence does not match a schematic review candidate or an exact current relationship")
            evidence = by_part[part_sku]["evidence"]
            assert isinstance(evidence, list)
            evidence.append({
                "type": "manufacturer_document",
                "target_tool_sku": target_sku,
                "evidence_role": role,
                "url": item["source_url"].strip(),
                "title": item["source_title"].strip(),
                "locator": item["source_locator"].strip(),
                "note": item["evidence_note"].strip(),
            })

    digest = file_sha256(catalog_path)
    manifest: list[dict[str, str]] = []
    for sku in sorted(by_part):
        row = catalog[sku.upper()]
        targets = sorted(by_part[sku]["targets"])
        existing = row.get(FIELD, "") or ""
        try:
            current_targets = json.loads(existing) if existing else []
        except json.JSONDecodeError:
            raise ValueError(f"{sku}: current compatibility field is not valid JSON; resolve it before generating a manifest")
        if not isinstance(current_targets, list) or any(not isinstance(value, str) or not value or value != value.strip() for value in current_targets):
            raise ValueError(f"{sku}: current compatibility field is not a string array; resolve it before generating a manifest")
        if len({value.upper() for value in current_targets}) != len(current_targets):
            raise ValueError(f"{sku}: current compatibility field contains duplicate or case-conflicting SKUs")
        for value in current_targets:
            target_row = catalog.get(value.upper())
            if target_row is None or target_row.get("SKU", "") != value:
                raise ValueError(f"{sku}: current compatibility target must match its exact canonical SKU: {value}")
        if len(set(targets).union(current_targets)) > 50:
            raise ValueError(f"{sku}: proposed compatibility value exceeds the 50-SKU bound")
        targets = sorted(set(targets).union(current_targets))
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
    parser.add_argument("--supplemental-evidence", type=Path, default=DEFAULT_SUPPLEMENTAL_EVIDENCE)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    validate_catalog(args.catalog.resolve(), args.include_gap_audit.resolve())
    rows = build_manifest(args.catalog.resolve(), args.proposals.resolve(), args.supplemental_evidence.resolve())
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

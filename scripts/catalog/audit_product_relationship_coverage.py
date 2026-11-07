#!/usr/bin/env python3
"""Audit canonical catalog relationship coverage without mutating source data."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter, defaultdict
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, approval_sha256, file_sha256, row_sha256
from official_catalog_schema import validate_catalog
from build_schematic_compatibility_review_manifest import is_part
from prepare_schematic_compatibility_proposals import COMPATIBLE_FIELD, REPLACEMENT_FIELD


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_PROPOSALS = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-summary.json"
DEFAULT_SCHEMATIC_MANIFEST = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv"
DEFAULT_DOCUMENTED_MANIFEST = ROOT / "products/dev/catalog-enrichment/compatibility/documented-compatibility-review-manifest.csv"
DEFAULT_UNRESOLVED = ROOT / "products/dev/catalog-enrichment/compatibility/schematic-compatibility-unresolved.csv"
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
    decoded, malformed = decode_relationship_raw(raw)
    if malformed:
        return [], True
    return [item.strip().upper() for item in decoded], False


def decode_relationship_raw(raw: str) -> tuple[list[object], bool]:
    if not raw:
        return [], False
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        if raw.lstrip().startswith(("[", "{")):
            return [], True
        decoded = raw.split(",")
    if not isinstance(decoded, list):
        return [], True
    malformed = any(not isinstance(item, str) or not item.strip() for item in decoded)
    return decoded, malformed


def is_published(row: dict[str, str]) -> bool:
    return (row.get("Published") or "").strip().casefold() in {"1", "yes", "true"}


def is_part_owner(row: dict[str, str], catalog_rows_by_sku: dict[str, list[dict[str, str]]]) -> bool:
    if (row.get("Type") or "").strip().casefold() != "variation":
        return is_part(row)
    parent_sku = (row.get("Parent") or row.get("Meta: _dtb_parent_product_sku") or "").strip()
    parents = catalog_rows_by_sku.get(parent_sku.upper(), [])
    return bool(
        parent_sku
        and len(parents) == 1
        and parents[0].get("SKU", "") == parent_sku
        and (parents[0].get("Type") or "").strip().casefold() == "variable"
        and is_published(parents[0])
        and is_part(parents[0])
    )


def audit_review_manifest(
    path: Path,
    catalog_path: Path,
    catalog: dict[str, dict[str, str]],
    catalog_rows_by_sku: dict[str, list[dict[str, str]]],
) -> dict[str, object]:
    if not path.exists():
        return {"present": False, "rows": 0}
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if tuple(reader.fieldnames or ()) != MANIFEST_FIELDS:
            raise ValueError(f"review manifest header mismatch: {path}")
        manifest = list(reader)

    catalog_digest = file_sha256(catalog_path)
    statuses: Counter[str] = Counter()
    stale_rows = 0
    invalid_approvals = 0
    malformed_proposals = 0
    invalid_owners = 0
    unsupported_mutations = 0
    candidate_edges: set[tuple[str, str]] = set()
    current_candidate_owner_skus: set[str] = set()
    variation_target_edges: set[tuple[str, str]] = set()
    variable_parent_target_edges: set[tuple[str, str]] = set()
    other_product_target_edges: set[tuple[str, str]] = set()
    evidence_references = 0
    evidence_roles: Counter[str] = Counter()
    target_identity_issues: Counter[str] = Counter()
    for item in manifest:
        status = (item.get("review_status") or "").strip().casefold() or "(blank)"
        statuses[status] += 1
        sku = (item.get("sku") or "").strip().upper()
        owner = catalog.get(sku)
        owner_matches = catalog_rows_by_sku.get(sku, [])
        row_is_current = (
            item.get("catalog_sha256", "").casefold() == catalog_digest
            and owner is not None
            and len(owner_matches) == 1
            and item.get("row_sha256", "").casefold() == row_sha256(owner)
            and (owner.get(item.get("field", "")) or "") == (item.get("current_value") or "")
            and is_part_owner(owner, catalog_rows_by_sku)
            and is_published(owner)
            and item.get("workflow") in {"compatibility", "compatibility_remove"}
            and item.get("field") == COMPATIBLE_FIELD
            and status in {"needs_review", "pending", "approved"}
        )
        if (
            item.get("catalog_sha256", "").casefold() != catalog_digest
            or owner is None
            or len(owner_matches) != 1
            or item.get("row_sha256", "").casefold() != row_sha256(owner)
            or (owner.get(item.get("field", "")) or "") != (item.get("current_value") or "")
        ):
            stale_rows += 1
        if status == "approved" and item.get("approval_sha256", "").casefold() != approval_sha256(item):
            invalid_approvals += 1
        if not owner or not is_part_owner(owner, catalog_rows_by_sku) or not is_published(owner):
            invalid_owners += 1
        if item.get("workflow") not in {"compatibility", "compatibility_remove"} or item.get("field") != COMPATIBLE_FIELD:
            unsupported_mutations += 1
        try:
            before = json.loads(item.get("current_value") or "[]")
            after = json.loads(item.get("proposed_value") or "[]")
            evidence = json.loads(item.get("evidence") or "[]")
        except json.JSONDecodeError:
            malformed_proposals += 1
            continue
        if (
            not isinstance(before, list)
            or not isinstance(after, list)
            or any(not isinstance(value, str) or not value.strip() for value in before + after)
            or not isinstance(evidence, list)
        ):
            malformed_proposals += 1
            continue
        existing = {value.strip().upper() for value in before}
        for value in after:
            target_sku = value.strip().upper()
            if target_sku in existing:
                continue
            candidate_edges.add((sku, target_sku))
            if row_is_current:
                current_candidate_owner_skus.add(sku)
            target_matches = catalog_rows_by_sku.get(target_sku, [])
            target = target_matches[0] if len(target_matches) == 1 else None
            if not target_matches:
                target_identity_issues["target_sku_missing"] += 1
            elif len(target_matches) > 1:
                target_identity_issues["target_sku_ambiguous"] += 1
            elif (target.get("Type") or "").strip().casefold() == "variation":
                parent_sku = (target.get("Parent") or "").strip().upper()
                parent_matches = catalog_rows_by_sku.get(parent_sku, [])
                parent_is_valid_tool = False
                if not is_published(target):
                    target_identity_issues["target_unpublished"] += 1
                if not parent_sku or not parent_matches:
                    target_identity_issues["variation_parent_missing"] += 1
                elif len(parent_matches) > 1:
                    target_identity_issues["variation_parent_ambiguous"] += 1
                else:
                    parent = parent_matches[0]
                    parent_is_valid_tool = (
                        (parent.get("Type") or "").strip().casefold() != "variation"
                        and not is_part(parent)
                        and is_published(parent)
                    )
                    if not parent_is_valid_tool:
                        target_identity_issues["variation_parent_invalid"] += 1
                if is_published(target) and parent_is_valid_tool:
                    variation_target_edges.add((sku, target_sku))
            elif not is_published(target):
                target_identity_issues["target_unpublished"] += 1
            elif is_part(target):
                target_identity_issues["target_is_part"] += 1
            elif (target.get("Type") or "").strip().casefold() == "variable":
                variable_parent_target_edges.add((sku, target_sku))
            else:
                other_product_target_edges.add((sku, target_sku))
        for reference in evidence:
            if not isinstance(reference, dict):
                continue
            if reference.get("url") or reference.get("source_file") or reference.get("source_schematic_id"):
                evidence_references += 1
            if reference.get("type") == "manufacturer_document":
                raw_role = reference.get("evidence_role")
                role = raw_role.strip().casefold() if isinstance(raw_role, str) and raw_role.strip() else "unclassified"
                evidence_roles[role] += 1

    return {
        "present": True,
        "sha256": file_sha256(path),
        "rows": len(manifest),
        "rows_by_review_status": dict(sorted(statuses.items())),
        "catalog_digest_matches": all(item.get("catalog_sha256", "").casefold() == catalog_digest for item in manifest),
        "stale_rows": stale_rows,
        "invalid_approved_rows": invalid_approvals,
        "malformed_rows": malformed_proposals,
        "invalid_owners": invalid_owners,
        "unsupported_mutations": unsupported_mutations,
        "new_candidate_edges": len(candidate_edges),
        "current_candidate_owner_skus": sorted(current_candidate_owner_skus),
        "exact_variation_target_edges": len(variation_target_edges),
        "variable_parent_target_edges": len(variable_parent_target_edges),
        "other_product_target_edges": len(other_product_target_edges),
        "candidate_target_identity_issues": dict(sorted(target_identity_issues.items())),
        "evidence_references": evidence_references,
        "manufacturer_evidence_by_role": dict(sorted(evidence_roles.items())),
    }


def audit(
    catalog_path: Path,
    proposals_path: Path,
    schematic_manifest_path: Path,
    documented_manifest_path: Path,
    unresolved_path: Path | None = None,
) -> dict[str, object]:
    rows = read_csv(catalog_path)
    catalog_rows_by_sku: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        sku = (row.get("SKU") or "").strip().upper()
        if sku:
            catalog_rows_by_sku[sku].append(row)
    by_sku = {sku: matches[0] for sku, matches in catalog_rows_by_sku.items() if len(matches) == 1}
    published = [row for row in rows if is_published(row) and (row.get("Type") or "").strip().casefold() != "variation"]
    published_parts = [row for row in published if is_part(row)]
    published_tools = [row for row in published if not is_part(row)]
    published_part_variations = [
        row for row in rows
        if is_published(row) and is_part_owner(row, catalog_rows_by_sku)
        and (row.get("Type") or "").strip().casefold() == "variation"
    ]
    fitment_populated = 0
    fitment_missing = 0
    fitment_missing_by_brand: Counter[str] = Counter()
    fitment_missing_owner_skus: set[str] = set()
    fitment_by_owner_type: dict[str, Counter[str]] = defaultdict(Counter)
    fitment_by_catalog_visibility: dict[str, Counter[str]] = defaultdict(Counter)
    fitment_unresolved: Counter[str] = Counter()
    field_overlap_count = 0
    malformed_fitment_parts = 0
    noncanonical_target_sku_references = 0
    target_sku_whitespace_references = 0
    blank_target_references = 0
    exact_product_targets = 0
    exact_variation_targets = 0
    variable_parent_targets = 0
    other_product_targets = 0
    duplicate_fitment_targets = 0
    ambiguous_fitment_owners = 0
    exact_target_owner_fanout: Counter[str] = Counter()
    for part in published_parts + published_part_variations:
        visibility = (part.get("Visibility in catalog") or "").strip().casefold() or "unknown"
        owner_is_variation = (part.get("Type") or "").strip().casefold() == "variation"
        fitment_by_catalog_visibility[visibility]["published_part_variations" if owner_is_variation else "published_parts"] += 1
        owner_sku = (part.get("SKU") or "").strip().upper()
        owner_type = "variation" if owner_is_variation else "parent"
        if len(catalog_rows_by_sku.get(owner_sku, [])) != 1:
            ambiguous_fitment_owners += 1
        compatible_raw, malformed_compatible_raw = decode_relationship_raw(part.get(COMPATIBLE_FIELD) or "")
        replacement_raw, malformed_replacement_raw = decode_relationship_raw(part.get(REPLACEMENT_FIELD) or "")
        compatible, malformed_compatible = decode_relationship(part.get(COMPATIBLE_FIELD) or "")
        replacement, malformed_replacement = decode_relationship(part.get(REPLACEMENT_FIELD) or "")
        if malformed_compatible or malformed_replacement or malformed_compatible_raw or malformed_replacement_raw:
            malformed_fitment_parts += 1
        for raw_target in compatible_raw + replacement_raw:
            if not isinstance(raw_target, str):
                continue
            if not raw_target.strip():
                blank_target_references += 1
                continue
            if raw_target != raw_target.strip():
                target_sku_whitespace_references += 1
            target_matches = catalog_rows_by_sku.get(raw_target.strip().upper(), [])
            if len(target_matches) == 1 and (target_matches[0].get("SKU") or "") != raw_target:
                noncanonical_target_sku_references += 1
        if compatible or replacement:
            fitment_populated += 1
            fitment_by_owner_type[owner_type]["with_structured_fitment"] += 1
            fitment_by_catalog_visibility[visibility]["with_structured_fitment"] += 1
        else:
            fitment_missing += 1
            fitment_by_owner_type[owner_type]["without_structured_fitment"] += 1
            fitment_missing_owner_skus.add(owner_sku)
            fitment_by_catalog_visibility[visibility]["without_structured_fitment"] += 1
            brand = (part.get("Brands") or "").strip() or "(unassigned)"
            fitment_missing_by_brand[brand] += 1
        if set(compatible).intersection(replacement):
            field_overlap_count += 1
        all_targets = compatible + replacement
        duplicate_fitment_targets += len(all_targets) - len(set(all_targets))
        exact_canonical_targets = {
            raw_target
            for raw_target in compatible_raw + replacement_raw
            if isinstance(raw_target, str)
            and raw_target
            and raw_target == raw_target.strip()
            and len(catalog_rows_by_sku.get(raw_target.upper(), [])) == 1
            and (catalog_rows_by_sku[raw_target.upper()][0].get("SKU") or "") == raw_target
        }
        for target_sku in exact_canonical_targets:
            exact_target_owner_fanout[target_sku] += 1
        for target in dict.fromkeys(all_targets):
            target_matches = catalog_rows_by_sku.get(target, [])
            if not target_matches:
                fitment_unresolved["target_sku_missing"] += 1
                continue
            if len(target_matches) > 1:
                fitment_unresolved["target_sku_ambiguous"] += 1
                continue
            target_row = target_matches[0]
            if (target_row.get("Type") or "").strip().casefold() == "variation":
                parent_sku = (target_row.get("Parent") or "").strip().upper()
                parent_matches = catalog_rows_by_sku.get(parent_sku, [])
                if not is_published(target_row):
                    fitment_unresolved["target_unpublished"] += 1
                elif not parent_sku or not parent_matches:
                    fitment_unresolved["variation_parent_missing"] += 1
                elif len(parent_matches) > 1:
                    fitment_unresolved["variation_parent_ambiguous"] += 1
                elif (
                    (parent_matches[0].get("Type") or "").strip().casefold() == "variation"
                    or not is_published(parent_matches[0])
                ):
                    fitment_unresolved["variation_parent_invalid"] += 1
                elif is_part(parent_matches[0]):
                    fitment_unresolved["target_is_part"] += 1
                else:
                    exact_variation_targets += 1
            elif not is_published(target_row):
                fitment_unresolved["target_unpublished"] += 1
            elif is_part(target_row):
                fitment_unresolved["target_is_part"] += 1
            else:
                exact_product_targets += 1
                if (target_row.get("Type") or "").strip().casefold() == "variable":
                    variable_parent_targets += 1
                else:
                    other_product_targets += 1

    cross_sell_sources = 0
    self_links = 0
    for row in published:
        sku = (row.get("SKU") or "").strip().upper()
        cross_sells = set(values(row.get("Cross-sells") or ""))
        cross_sell_sources += bool(cross_sells)
        self_links += bool(sku and sku in cross_sells)

    proposal_summary = json.loads(proposals_path.read_text(encoding="utf-8")) if proposals_path.exists() else {}
    review_manifests = {
        "schematic": audit_review_manifest(schematic_manifest_path, catalog_path, by_sku, catalog_rows_by_sku),
        "documented_evidence": audit_review_manifest(documented_manifest_path, catalog_path, by_sku, catalog_rows_by_sku),
    }
    current_candidate_owners_by_manifest = {
        name: set(summary.get("current_candidate_owner_skus", []))
        if summary.get("present") and summary.get("stale_rows", 0) == 0
        else set()
        for name, summary in review_manifests.items()
    }
    all_current_candidate_owners = set().union(*current_candidate_owners_by_manifest.values())
    candidate_covered_missing_owners = fitment_missing_owner_skus & all_current_candidate_owners
    uncovered_missing_owners = fitment_missing_owner_skus - all_current_candidate_owners
    uncovered_by_brand: Counter[str] = Counter()
    for sku in uncovered_missing_owners:
        owner = by_sku.get(sku, {})
        uncovered_by_brand[(owner.get("Brands") or "").strip() or "(unassigned)"] += 1
    unresolved_source_occurrences: dict[str, dict[str, object]] = {}
    if unresolved_path is not None and unresolved_path.is_file():
        exact_source_fields = ("raw_part_identifier", "source_sku", "source_part_id", "source_display_id", "source_part_number")
        for source in read_csv(unresolved_path):
            if (source.get("status") or "").strip() != "unresolved_schematic_identity":
                continue
            source_identifiers = {source.get(field, "").strip() for field in exact_source_fields}
            for sku in uncovered_missing_owners.intersection(source_identifiers):
                occurrence = unresolved_source_occurrences.setdefault(sku, {"occurrence_count": 0, "source_files": set(), "schematic_ids": set()})
                occurrence["occurrence_count"] = int(occurrence["occurrence_count"]) + 1
                occurrence["source_files"].add(source.get("source_file", ""))
                occurrence["schematic_ids"].add(source.get("raw_schematic_id", ""))
    return {
        "schema_version": 13,
        "catalog_sha256": file_sha256(catalog_path),
        "catalog_rows": len(rows),
        "denominators": {
            "published_parts_excluding_variations": len(published_parts),
            "published_part_variations_with_exact_published_part_parent": len(published_part_variations),
            "published_non_part_products_excluding_variations": len(published_tools),
        },
        "canonical_fitment": {
            "parts_with_structured_relationships": fitment_populated,
            "parts_without_structured_relationships": fitment_missing,
            "part_relationship_owners_by_scope": {
                owner_type: {
                    "with_structured_fitment": counts["with_structured_fitment"],
                    "without_structured_fitment": counts["without_structured_fitment"],
                }
                for owner_type, counts in sorted(fitment_by_owner_type.items())
            },
            "missing_parts_by_brand": dict(sorted(fitment_missing_by_brand.items())),
            "parts_by_catalog_visibility": {
                visibility: {
                    "published_parts": counts["published_parts"],
                    "published_part_variations": counts["published_part_variations"],
                    "with_structured_fitment": counts["with_structured_fitment"],
                    "without_structured_fitment": counts["without_structured_fitment"],
                }
                for visibility, counts in sorted(fitment_by_catalog_visibility.items())
            },
            "exact_published_product_targets": exact_product_targets,
            "published_variable_parent_targets": variable_parent_targets,
            "other_published_product_targets": other_product_targets,
            "exact_published_variation_targets": exact_variation_targets,
            "max_published_part_owner_fanout_for_exact_target_sku": max(exact_target_owner_fanout.values(), default=0),
            "exact_target_skus_at_max_owner_fanout": sorted(
                sku for sku, count in exact_target_owner_fanout.items()
                if count == max(exact_target_owner_fanout.values(), default=0)
            ),
            "duplicate_targets_within_owner": duplicate_fitment_targets,
            "ambiguous_owner_skus": ambiguous_fitment_owners,
            "target_field_overlap_parts": field_overlap_count,
            "malformed_relationship_parts": malformed_fitment_parts,
            "noncanonical_target_sku_references": noncanonical_target_sku_references,
            "target_sku_whitespace_references": target_sku_whitespace_references,
            "blank_target_references": blank_target_references,
            "unresolved_targets": dict(sorted(fitment_unresolved.items())),
        },
        "merchandising": {
            "published_products_with_cross_sells": cross_sell_sources,
            "self_link_sources": self_links,
        },
        "schematic_proposals": {
            "proposal_rows": proposal_summary.get("proposal_rows", 0),
            "by_status": proposal_summary.get("by_status", {}),
            "unresolved_rows": proposal_summary.get("unresolved_rows", 0),
            "unresolved_by_status": proposal_summary.get("unresolved_by_status", {}),
            "cross_brand_identity_candidates": proposal_summary.get("cross_brand_identity_candidates", 0),
        },
        "review_manifests": review_manifests,
        "coverage_pipeline": {
            "published_parts_missing_fitment": fitment_missing,
            "missing_fitment_parts_with_current_review_candidate": len(candidate_covered_missing_owners),
            "missing_fitment_parts_without_current_review_candidate": len(uncovered_missing_owners),
            "without_candidate_by_brand": dict(sorted(uncovered_by_brand.items())),
            "uncovered_part_skus": sorted(uncovered_missing_owners),
            "uncovered_parts_with_exact_source_identity_but_unresolved_schematic": [
                {
                    "part_sku": sku,
                    "occurrence_count": occurrence["occurrence_count"],
                    "source_files": sorted(value for value in occurrence["source_files"] if value),
                    "schematic_ids": sorted(value for value in occurrence["schematic_ids"] if value),
                    "interpretation": "The exact part SKU appears in schematic source rows, but the tool schematic identity is unresolved; no fitment target is proposed.",
                }
                for sku, occurrence in sorted(unresolved_source_occurrences.items())
            ],
            "candidate_owner_count_by_manifest": {
                name: len(owners) for name, owners in sorted(current_candidate_owners_by_manifest.items())
            },
            "interpretation": "A review candidate is a current digest-bound proposal, not approved fitment. An uncovered part has no current candidate in the audited manifests; it is not evidence of incompatibility.",
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
    parser.add_argument("--schematic-manifest", type=Path, default=DEFAULT_SCHEMATIC_MANIFEST)
    parser.add_argument("--documented-manifest", type=Path, default=DEFAULT_DOCUMENTED_MANIFEST)
    parser.add_argument("--unresolved", type=Path, default=DEFAULT_UNRESOLVED)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    catalog_path = args.catalog.resolve()
    validate_catalog(catalog_path, args.include_gap_audit.resolve())
    report = audit(catalog_path, args.proposals_summary.resolve(), args.schematic_manifest.resolve(), args.documented_manifest.resolve(), args.unresolved.resolve())
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Export a full-catalog, source-attributed view of recorded relationships.

This is a derived audit/export only. It does not approve candidates, mutate the
canonical catalog, change WooCommerce products, or infer links from similarity.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_TAPETECH = ROOT / "docs/reference/data/brands/tapetech/Copy-of-TT-Where-Parts-Are-Used.csv"
DEFAULT_OUT = ROOT / "products/dev/catalog-enrichment/compatibility"
COMPATIBLE = "Meta: _dtb_compatible_tool_skus"
REPLACEMENT = "Meta: _dtb_replacement_part_for"
KIND = "Meta: _dtb_product_kind"
IS_PART = "Meta: _dtb_is_parts"
INCLUDES_SKU = re.compile(r"^Meta: _includes_(\d+)_sku$")
TRUTHY = {"1", "true", "yes", "y"}

EDGE_FIELDS = [
    "source_sku", "source_name", "source_type", "source_parent_sku", "source_status",
    "source_visibility", "relationship_type", "target_sku", "target_name", "target_type",
    "target_parent_sku", "target_status", "target_visibility", "source_field",
    "sequence", "evidence_status", "evidence_source", "evidence_ref", "evidence_note",
]
COVERAGE_FIELDS = [
    "sku", "name", "type", "parent_sku", "product_kind", "is_part", "published",
    "visibility", "fitment_outgoing_count", "fitment_incoming_count",
    "bundle_components_count", "included_in_bundle_count", "pdp_upsell_count",
    "cart_cross_sell_count", "recorded_relationship_count", "coverage_state",
]
FAMILY_FIELDS = [
    "sku", "name", "type", "parent_sku", "brand_key", "brand_label", "category_path",
    "product_family", "series_subfamily", "model", "family_group_key",
    "family_metadata_state", "family_metadata_review_state",
]


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream)
        return list(reader.fieldnames or []), list(reader)


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def published(value: str) -> bool:
    return str(value or "").strip().casefold() in TRUTHY


def parse_sku_array(raw: str, label: str) -> list[str]:
    if not raw or not raw.strip():
        return []
    try:
        values = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError(f"{label}: expected a JSON string array") from exc
    if not isinstance(values, list) or any(not isinstance(v, str) or not v.strip() for v in values):
        raise ValueError(f"{label}: expected non-empty string values")
    if any(v != v.strip() for v in values):
        raise ValueError(f"{label}: whitespace around SKU is not permitted")
    if len({v.upper() for v in values}) != len(values):
        raise ValueError(f"{label}: duplicate SKU")
    return values


def sku_list(raw: str) -> list[str]:
    """Parse native WooCommerce CSV relationship values as comma-separated SKUs."""
    return [part.strip() for part in str(raw or "").split(",") if part.strip()]


def load_catalog(path: Path) -> tuple[list[dict[str, str]], dict[str, dict[str, str]]]:
    fields, rows = read_csv(path)
    required = {"SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent", KIND, IS_PART, COMPATIBLE, REPLACEMENT, "Upsells", "Cross-sells"}
    if not required.issubset(fields):
        raise ValueError(f"Catalog missing required columns: {sorted(required - set(fields))}")
    by_sku: dict[str, dict[str, str]] = {}
    for row in rows:
        sku = str(row.get("SKU") or "").strip()
        if not sku or sku.upper() in by_sku:
            raise ValueError(f"Blank or duplicate catalog SKU: {sku!r}")
        by_sku[sku.upper()] = row
    return rows, by_sku


def target_identity(target_sku: str, by_sku: dict[str, dict[str, str]]) -> tuple[dict[str, str] | None, str]:
    target = by_sku.get(target_sku.upper())
    if target is None:
        return None, "target_sku_absent"
    if str(target.get("SKU") or "") != target_sku:
        return target, "target_sku_noncanonical_case_or_spelling"
    return target, "exact"


def read_tapetech(path: Path) -> dict[str, set[str]]:
    fields, rows = read_csv(path)
    required = {"PartNumber", "Description", "Number of Tools Where Part Is Used"}
    if not required.issubset(fields):
        raise ValueError("TapeTech matrix is missing its part number, description, or declared-use count")
    model_fields = [field for field in fields if field not in required]
    result: dict[str, set[str]] = {}
    for row in rows:
        sku = str(row.get("PartNumber") or "").strip()
        if not sku or sku.upper() in result:
            raise ValueError(f"Blank or duplicate TapeTech part SKU: {sku!r}")
        targets = {field for field in model_fields if str(row.get(field) or "").strip().casefold() == "n"}
        try:
            declared = int(str(row.get("Number of Tools Where Part Is Used") or "").strip())
        except ValueError as exc:
            raise ValueError(f"Invalid declared TapeTech use count for {sku}") from exc
        if declared != len(targets):
            raise ValueError(f"Declared TapeTech use count disagrees with marked model columns for {sku}")
        result[sku.upper()] = targets
    return result


def build(catalog_path: Path, tapetech_path: Path) -> tuple[list[dict[str, str]], list[dict[str, str]], list[dict[str, str]], dict[str, object]]:
    rows, by_sku = load_catalog(catalog_path)
    tapetech = read_tapetech(tapetech_path)
    include_columns = sorted(
        (field for field in rows[0] if INCLUDES_SKU.match(field)),
        key=lambda field: int(INCLUDES_SKU.match(field).group(1)),  # type: ignore[union-attr]
    )
    exact_tape_part_skus = {
        str(row.get("SKU") or "").strip().upper()
        for row in rows
        if str(row.get("Meta: _dtb_brand_label") or "").strip().casefold() == "tapetech"
        and (str(row.get(KIND) or "").strip().casefold() == "part" or str(row.get(IS_PART) or "").strip().casefold() in TRUTHY)
        and str(row.get("SKU") or "").strip().upper() in tapetech
    }

    edges: list[dict[str, str]] = []
    issues: Counter[str] = Counter()
    edge_counts: Counter[tuple[str, str]] = Counter()
    type_counts: Counter[str] = Counter()
    tape_edges = 0

    def add_edge(source: dict[str, str], relationship: str, target_sku: str, field: str, sequence: str = "", evidence_status: str = "catalog_recorded", evidence_source: str = "", evidence_ref: str = "", evidence_note: str = "") -> None:
        nonlocal tape_edges
        source_sku = str(source.get("SKU") or "").strip()
        target, identity_status = target_identity(target_sku, by_sku)
        if identity_status != "exact":
            issues[identity_status] += 1
        if relationship == "part_fits_tool":
            if target_sku.upper() in tapetech.get(source_sku.upper(), set()) and source_sku.upper() in exact_tape_part_skus:
                evidence_status = "manufacturer_matrix_exact_match"
                evidence_source = "TapeTech Where Parts Are Used manufacturer matrix"
                evidence_ref = "docs/reference/data/brands/tapetech/Copy-of-TT-Where-Parts-Are-Used.csv"
                evidence_note = "Exact part SKU row and exact marked tool-model column; catalog target SKU resolves exactly."
                tape_edges += 1
            elif str(source.get("Meta: _dtb_brand_label") or "").strip().casefold() == "tapetech":
                evidence_status = "canonical_relationship_source_not_in_matrix"
                evidence_source = "Canonical catalog relationship field"
                evidence_ref = f"products/launch/official/dtb_official_catalog.csv:{source_sku}"
                evidence_note = "The part or exact tool SKU has no matching marked edge in the provided matrix; retain canonical record and do not infer manufacturer support."
        edge = {
            "source_sku": source_sku,
            "source_name": str(source.get("Name") or ""),
            "source_type": str(source.get("Type") or ""),
            "source_parent_sku": str(source.get("Parent") or ""),
            "source_status": "published" if published(source.get("Published", "")) else "not_published",
            "source_visibility": str(source.get("Visibility in catalog") or ""),
            "relationship_type": relationship,
            "target_sku": target_sku,
            "target_name": str(target.get("Name") or "") if target else "",
            "target_type": str(target.get("Type") or "") if target else "",
            "target_parent_sku": str(target.get("Parent") or "") if target else "",
            "target_status": ("published" if published(target.get("Published", "")) else "not_published") if target else "unresolved",
            "target_visibility": str(target.get("Visibility in catalog") or "") if target else "",
            "source_field": field,
            "sequence": sequence,
            "evidence_status": evidence_status if identity_status == "exact" else identity_status,
            "evidence_source": evidence_source,
            "evidence_ref": evidence_ref,
            "evidence_note": evidence_note,
        }
        edges.append(edge)
        edge_counts[(source_sku.upper(), relationship)] += 1
        type_counts[relationship] += 1

    for row in rows:
        sku = str(row.get("SKU") or "").strip()
        part = str(row.get(KIND) or "").strip().casefold() == "part" or str(row.get(IS_PART) or "").strip().casefold() in TRUTHY
        for field in (COMPATIBLE, REPLACEMENT):
            for target_sku in parse_sku_array(str(row.get(field) or ""), f"{sku}:{field}"):
                if not part:
                    issues["fitment_owner_not_classified_part"] += 1
                add_edge(row, "part_fits_tool", target_sku, field, evidence_status="canonical_relationship_recorded", evidence_source="Canonical catalog compatibility field", evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{sku}", evidence_note="Recorded exact-SKU fitment projection; source evidence status is separately identified.")

        if str(row.get(KIND) or "").strip().casefold() == "toolset":
            for field in include_columns:
                target_sku = str(row.get(field) or "").strip()
                if target_sku:
                    index = INCLUDES_SKU.match(field).group(1)  # type: ignore[union-attr]
                    name_field = f"Meta: _includes_{index}_name"
                    note = str(row.get(name_field) or "").strip()
                    add_edge(row, "kit_contains", target_sku, field, index, evidence_status="canonical_structured_bundle_content", evidence_source="Canonical toolset included-SKU metadata", evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{sku}", evidence_note=note)

        for field, relationship in (("Upsells", "pdp_upsell"), ("Cross-sells", "cart_cross_sell")):
            for index, target_sku in enumerate(sku_list(row.get(field, ""))):
                add_edge(row, relationship, target_sku, field, str(index + 1), evidence_status="canonical_merchandising_recorded", evidence_source="Canonical catalog merchandising field", evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{sku}", evidence_note="Directional merchandising placement; not a fitment claim.")

    edges.sort(key=lambda e: (e["source_sku"].casefold(), e["relationship_type"], int(e["sequence"] or "0"), e["target_sku"].casefold()))
    by_coverage: dict[str, Counter[str]] = defaultdict(Counter)
    for edge in edges:
        owner = edge["source_sku"].upper()
        relation = edge["relationship_type"]
        if relation == "part_fits_tool":
            by_coverage[owner]["fitment_outgoing_count"] += 1
            by_coverage[edge["target_sku"].upper()]["fitment_incoming_count"] += 1
        elif relation == "kit_contains":
            by_coverage[owner]["bundle_components_count"] += 1
            by_coverage[edge["target_sku"].upper()]["included_in_bundle_count"] += 1
        elif relation == "pdp_upsell":
            by_coverage[owner]["pdp_upsell_count"] += 1
        elif relation == "cart_cross_sell":
            by_coverage[owner]["cart_cross_sell_count"] += 1

    coverage: list[dict[str, str]] = []
    family_memberships: list[dict[str, str]] = []
    family_groups: Counter[tuple[str, str, str]] = Counter()
    family_by_brand: dict[str, Counter[str]] = defaultdict(Counter)
    brand_groups: dict[str, set[tuple[str, str]]] = defaultdict(set)
    for row in sorted(rows, key=lambda r: str(r.get("SKU") or "").casefold()):
        sku = str(row.get("SKU") or "").strip()
        counts = by_coverage[sku.upper()]
        total = sum(counts.values())
        coverage.append({
            "sku": sku,
            "name": str(row.get("Name") or ""),
            "type": str(row.get("Type") or ""),
            "parent_sku": str(row.get("Parent") or ""),
            "product_kind": str(row.get(KIND) or ""),
            "is_part": "1" if str(row.get(KIND) or "").strip().casefold() == "part" or str(row.get(IS_PART) or "").strip().casefold() in TRUTHY else "0",
            "published": "1" if published(row.get("Published", "")) else "0",
            "visibility": str(row.get("Visibility in catalog") or ""),
            **{key: str(counts[key]) for key in COVERAGE_FIELDS[8:14]},
            "recorded_relationship_count": str(total),
            "coverage_state": "has_recorded_relationships" if total else "no_relationship_recorded",
        })
        brand_key = str(row.get("Meta: _dtb_brand_key") or "").strip()
        family = str(row.get("Meta: product_family") or "").strip()
        series = str(row.get("Meta: series") or "").strip()
        family_state = "complete" if family and series else "family_only" if family else "series_only" if series else "missing"
        group_key = "|".join((brand_key, family, series)) if brand_key and family and series else ""
        if group_key:
            family_groups[(brand_key, family, series)] += 1
            brand_groups[brand_key].add((family, series))
        brand_counts = family_by_brand[brand_key or "(missing brand key)"]
        brand_counts["catalog_entities"] += 1
        brand_counts["with_family"] += bool(family)
        brand_counts["with_series"] += bool(series)
        brand_counts["with_family_and_series"] += bool(family and series)
        family_memberships.append({
            "sku": sku,
            "name": str(row.get("Name") or ""),
            "type": str(row.get("Type") or ""),
            "parent_sku": str(row.get("Parent") or ""),
            "brand_key": brand_key,
            "brand_label": str(row.get("Meta: _dtb_brand_label") or ""),
            "category_path": str(row.get("Categories") or ""),
            "product_family": family,
            "series_subfamily": series,
            "model": str(row.get("Meta: model") or "").strip(),
            "family_group_key": group_key,
            "family_metadata_state": family_state,
            "family_metadata_review_state": "canonical_value_present_source_not_independently_verified" if family or series else "no_family_metadata",
        })

    summary: dict[str, object] = {
        "schema_version": 1,
        "report_type": "dtb_catalog_product_relationship_tree",
        "mutates_catalog": False,
        "runtime_projection_audited": False,
        "catalog_sha256": digest(catalog_path),
        "tapetech_matrix_sha256": digest(tapetech_path),
        "catalog_entities": len(rows),
        "relationship_edges": len(edges),
        "relationship_counts": dict(sorted(type_counts.items())),
        "catalog_entities_with_recorded_relationships": sum(row["coverage_state"] == "has_recorded_relationships" for row in coverage),
        "catalog_entities_without_recorded_relationships": sum(row["coverage_state"] == "no_relationship_recorded" for row in coverage),
        "tapetech_exact_part_rows": len(exact_tape_part_skus),
        "tapetech_exact_catalog_fitment_edges": tape_edges,
        "family_metadata": {
            "entities_with_family_and_series": sum(1 for item in family_memberships if item["family_metadata_state"] == "complete"),
            "entities_with_family_only": sum(1 for item in family_memberships if item["family_metadata_state"] == "family_only"),
            "entities_with_series_only": sum(1 for item in family_memberships if item["family_metadata_state"] == "series_only"),
            "entities_without_family_or_series": sum(1 for item in family_memberships if item["family_metadata_state"] == "missing"),
            "distinct_brand_family_series_groups": len(family_groups),
            "groups_with_multiple_catalog_entities": sum(1 for count in family_groups.values() if count > 1),
            "brand_coverage": {
                brand: {
                    **dict(counts),
                    "distinct_family_series_groups": len(brand_groups.get(brand, set())),
                }
                for brand, counts in sorted(family_by_brand.items())
            },
            "interpretation": "Membership is copied from exact canonical product_family and series fields. It is not proof of substitution, compatibility, or source verification; review family definitions before using them to generate recommendations.",
        },
        "unresolved_or_noncanonical_targets": dict(sorted(issues.items())),
        "interpretation": "No recorded relationship is not evidence of incompatibility or a recommendation. AI or similarity-based candidates require evidence review before projection.",
    }
    return edges, coverage, family_memberships, summary


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as stream:
        writer = csv.DictWriter(stream, fieldnames=fields, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--tapetech-matrix", type=Path, default=DEFAULT_TAPETECH)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args()
    edges, coverage, family_memberships, summary = build(args.catalog, args.tapetech_matrix)
    write_csv(args.output_dir / "product-relationship-tree.csv", EDGE_FIELDS, edges)
    write_csv(args.output_dir / "product-relationship-coverage.csv", COVERAGE_FIELDS, coverage)
    write_csv(args.output_dir / "product-family-membership.csv", FAMILY_FIELDS, family_memberships)
    (args.output_dir / "product-relationship-tree-summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"output_dir": str(args.output_dir), **summary}, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Export a full-catalog, source-attributed view of recorded relationships.

This is a derived audit/export only. It does not approve candidates, mutate the
    canonical catalog, or change WooCommerce products. It emits exact recorded
    links plus discovery-only peers from exact canonical family/category metadata.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_TAPETECH = ROOT / "docs/reference/data/brands/tapetech/Copy-of-TT-Where-Parts-Are-Used.csv"
DEFAULT_MANUFACTURER_EVIDENCE = ROOT / "products/dev/catalog-enrichment/compatibility/manufacturer-supported-compatibility-evidence.csv"
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
    "visibility", "variation_parent_count", "variation_child_count",
    "fitment_outgoing_count", "fitment_incoming_count", "family_related_count",
    "category_related_count",
    "bundle_components_count", "included_in_bundle_count", "pdp_upsell_count",
    "cart_cross_sell_count", "recorded_relationship_count", "coverage_state",
]
FAMILY_FIELDS = [
    "sku", "name", "type", "parent_sku", "brand_key", "brand_label", "category_path",
    "product_family", "series_subfamily", "model", "family_group_key",
    "family_metadata_state", "family_metadata_review_state",
]
PART_FAMILY_AFFILIATION_FIELDS = [
    "part_sku", "part_name", "part_status", "part_visibility", "target_brand_key",
    "target_brand_label", "product_family", "series_subfamily", "family_group_key",
    "model_values_json", "supporting_tool_skus_json", "source_relationship_fields_json",
    "evidence_statuses_json", "evidence_sources_json", "evidence_note",
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
    required = {"SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent", KIND, IS_PART, COMPATIBLE, REPLACEMENT, "Upsells", "Cross-sells", "Meta: _dtb_brand_key", "Meta: _dtb_display_category_key"}
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


def read_manufacturer_evidence(
    path: Path | None,
    by_sku: dict[str, dict[str, str]],
    catalog_edges: set[tuple[str, str]],
) -> dict[tuple[str, str], dict[str, str]]:
    """Load direct manufacturer callouts and bind each to an exact catalog edge."""
    if path is None:
        return {}
    fields, rows = read_csv(path)
    required = {"part_sku", "target_tool_sku", "source_url", "source_title", "source_locator", "evidence_note"}
    if not required.issubset(fields):
        raise ValueError(f"Manufacturer evidence is missing columns: {sorted(required - set(fields))}")
    evidence: dict[tuple[str, str], dict[str, str]] = {}
    for row in rows:
        part_sku = str(row.get("part_sku") or "").strip()
        target_sku = str(row.get("target_tool_sku") or "").strip()
        pair = (part_sku.upper(), target_sku.upper())
        if not part_sku or not target_sku or pair in evidence:
            raise ValueError(f"Blank or duplicate manufacturer evidence pair: {pair!r}")
        part = by_sku.get(part_sku.upper())
        target = by_sku.get(target_sku.upper())
        if not part or str(part.get("SKU") or "") != part_sku or not (
            str(part.get(KIND) or "").strip().casefold() == "part"
            or str(part.get(IS_PART) or "").strip().casefold() in TRUTHY
        ):
            raise ValueError(f"Manufacturer evidence owner is not an exact canonical part SKU: {part_sku!r}")
        if not target or str(target.get("SKU") or "") != target_sku or (
            str(target.get(KIND) or "").strip().casefold() == "part"
            or str(target.get(IS_PART) or "").strip().casefold() in TRUTHY
        ):
            raise ValueError(f"Manufacturer evidence target is not an exact canonical non-part SKU: {target_sku!r}")
        parsed = urlparse(str(row.get("source_url") or "").strip())
        if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
            raise ValueError(f"Manufacturer evidence URL is not a credential-free HTTPS URL: {part_sku} -> {target_sku}")
        if any(not str(row.get(field) or "").strip() for field in ("source_title", "source_locator", "evidence_note")):
            raise ValueError(f"Manufacturer evidence is missing source detail: {part_sku} -> {target_sku}")
        if pair not in catalog_edges:
            raise ValueError(f"Manufacturer evidence has no matching canonical fitment edge: {part_sku} -> {target_sku}")
        evidence[pair] = row
    return evidence


def build(
    catalog_path: Path,
    tapetech_path: Path,
    manufacturer_evidence_path: Path | None = None,
) -> tuple[list[dict[str, str]], list[dict[str, str]], list[dict[str, str]], list[dict[str, str]], dict[str, object]]:
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

    catalog_fitment_edges = {
        (str(row.get("SKU") or "").strip().upper(), target.upper())
        for row in rows
        for field in (COMPATIBLE, REPLACEMENT)
        for target in parse_sku_array(str(row.get(field) or ""), f"{row.get('SKU')}:{field}")
    }
    manufacturer_evidence = read_manufacturer_evidence(
        manufacturer_evidence_path,
        by_sku,
        catalog_fitment_edges,
    )

    def add_edge(source: dict[str, str], relationship: str, target_sku: str, field: str, sequence: str = "", evidence_status: str = "catalog_recorded", evidence_source: str = "", evidence_ref: str = "", evidence_note: str = "") -> None:
        nonlocal tape_edges
        source_sku = str(source.get("SKU") or "").strip()
        target, identity_status = target_identity(target_sku, by_sku)
        if identity_status != "exact":
            issues[identity_status] += 1
        if relationship == "part_fits_tool":
            manufacturer_item = manufacturer_evidence.get((source_sku.upper(), target_sku.upper()))
            if manufacturer_item:
                evidence_status = "manufacturer_document_exact_callout"
                evidence_source = str(manufacturer_item.get("source_title") or "Manufacturer document")
                evidence_ref = f"{manufacturer_item.get('source_url', '')}#{manufacturer_item.get('source_locator', '')}"
                evidence_note = str(manufacturer_item.get("evidence_note") or "")
            elif target_sku.upper() in tapetech.get(source_sku.upper(), set()) and source_sku.upper() in exact_tape_part_skus:
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
        parent_sku = str(row.get("Parent") or "").strip()
        if str(row.get("Type") or "").strip().casefold() == "variation" and parent_sku:
            add_edge(
                row,
                "variation_of",
                parent_sku,
                "Parent",
                evidence_status="canonical_exact_parent_sku",
                evidence_source="Canonical WooCommerce CSV Parent field",
                evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{sku}",
                evidence_note="Variation belongs to this exact catalog parent SKU; this structural edge is not a compatibility or recommendation claim.",
            )
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

    # Family discovery uses the canonical brand, kind, and family assignment.
    # Series and category differences are allowed: these are broad family
    # relationships, not fitment, equivalence, or upgrade claims.
    family_peers: dict[tuple[str, str, str], list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        if str(row.get("Type") or "").strip().casefold() == "variation":
            continue
        kind = str(row.get(KIND) or "").strip().casefold()
        if kind not in {"tool", "toolset"}:
            continue
        group = (
            str(row.get("Meta: _dtb_brand_key") or "").strip(),
            kind,
            str(row.get("Meta: product_family") or "").strip(),
        )
        if all(group):
            family_peers[group].append(row)
    family_peer_group_count = 0
    family_peer_edge_count = 0
    for members in family_peers.values():
        if len(members) < 2:
            continue
        family_peer_group_count += 1
        for source in members:
            for target in members:
                if source is target:
                    continue
                add_edge(
                    source,
                    "same_product_family",
                    str(target.get("SKU") or ""),
                    "Meta: _dtb_brand_key + Meta: _dtb_product_kind + Meta: product_family",
                    evidence_status="exact_catalog_product_family_match",
                    evidence_source="Canonical catalog brand, kind, and product-family fields",
                    evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{source.get('SKU', '')};{target.get('SKU', '')}",
                    evidence_note="Same recorded tool/toolset product family, brand, and product kind. Series and category may differ. Family discovery only; not fitment, equivalence, substitution, upgrade, or kit inclusion.",
                )
                family_peer_edge_count += 1

    # Category siblings are a distinct browse/discovery relationship. Keep it
    # to published visible non-part products with an exact brand, kind, and
    # customer-facing display-category key; never imply fitment or equivalence.
    category_peers: dict[tuple[str, str, str], list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        if str(row.get("Type") or "").strip().casefold() == "variation":
            continue
        if not published(row.get("Published", "")) or str(row.get("Visibility in catalog") or "").strip().casefold() != "visible":
            continue
        kind = str(row.get(KIND) or "").strip().casefold()
        if kind not in {"tool", "toolset", "stilt", "accessory"}:
            continue
        group = (
            str(row.get("Meta: _dtb_brand_key") or "").strip(),
            kind,
            str(row.get("Meta: _dtb_display_category_key") or "").strip(),
        )
        if all(group):
            category_peers[group].append(row)
    category_peer_group_count = 0
    category_peer_edge_count = 0
    for members in category_peers.values():
        if len(members) < 2:
            continue
        category_peer_group_count += 1
        for source in members:
            for target in members:
                if source is target:
                    continue
                add_edge(
                    source,
                    "same_catalog_category",
                    str(target.get("SKU") or ""),
                    "Meta: _dtb_brand_key + Meta: _dtb_product_kind + Meta: _dtb_display_category_key",
                    evidence_status="exact_catalog_category_match",
                    evidence_source="Canonical catalog brand, product-kind, and display-category fields",
                    evidence_ref=f"products/launch/official/dtb_official_catalog.csv:{source.get('SKU', '')};{target.get('SKU', '')}",
                    evidence_note="Same recorded brand, product kind, and customer-facing display category. Browse discovery only; not fitment, equivalence, substitution, upgrade, or a curated commercial recommendation.",
                )
                category_peer_edge_count += 1

    edges.sort(key=lambda e: (e["source_sku"].casefold(), e["relationship_type"], int(e["sequence"] or "0"), e["target_sku"].casefold()))
    # A part may be used across multiple product families. Preserve that as a
    # separate derived relation rather than copying a single family value onto
    # the part's canonical product metadata.
    affiliation_evidence: dict[tuple[str, str, str, str], dict[str, set[str]]] = {}
    for edge in edges:
        if edge["relationship_type"] != "part_fits_tool" or edge["evidence_status"] in {
            "target_sku_absent", "target_sku_noncanonical_case_or_spelling"
        }:
            continue
        target = by_sku.get(edge["target_sku"].upper())
        if target is None or str(target.get("SKU") or "") != edge["target_sku"]:
            continue
        family = str(target.get("Meta: product_family") or "").strip()
        series = str(target.get("Meta: series") or "").strip()
        target_brand = str(target.get("Meta: _dtb_brand_key") or "").strip()
        if not family or not series or not target_brand:
            continue
        key = (edge["source_sku"], target_brand, family, series)
        entry = affiliation_evidence.setdefault(key, {
            "models": set(), "tool_skus": set(), "source_fields": set(),
            "brand_labels": set(), "evidence_statuses": set(), "evidence_sources": set(),
        })
        brand_label = str(target.get("Meta: _dtb_brand_label") or "").strip()
        if brand_label:
            entry["brand_labels"].add(brand_label)
        model = str(target.get("Meta: model") or "").strip()
        if model:
            entry["models"].add(model)
        entry["tool_skus"].add(edge["target_sku"])
        entry["source_fields"].add(edge["source_field"])
        entry["evidence_statuses"].add(edge["evidence_status"])
        entry["evidence_sources"].add(edge["evidence_source"])
    part_family_affiliations: list[dict[str, str]] = []
    for (part_sku, brand_key, family, series), evidence in sorted(affiliation_evidence.items()):
        part = by_sku[part_sku.upper()]
        part_family_affiliations.append({
            "part_sku": part_sku,
            "part_name": str(part.get("Name") or ""),
            "part_status": "published" if published(part.get("Published", "")) else "not_published",
            "part_visibility": str(part.get("Visibility in catalog") or ""),
            "target_brand_key": brand_key,
            "target_brand_label": ", ".join(sorted(evidence["brand_labels"])),
            "product_family": family,
            "series_subfamily": series,
            "family_group_key": "|".join((brand_key, family, series)),
            "model_values_json": json.dumps(sorted(evidence["models"]), ensure_ascii=False, separators=(",", ":")),
            "supporting_tool_skus_json": json.dumps(sorted(evidence["tool_skus"]), ensure_ascii=False, separators=(",", ":")),
            "source_relationship_fields_json": json.dumps(sorted(evidence["source_fields"]), ensure_ascii=False, separators=(",", ":")),
            "evidence_statuses_json": json.dumps(sorted(evidence["evidence_statuses"]), ensure_ascii=False, separators=(",", ":")),
            "evidence_sources_json": json.dumps(sorted(evidence["evidence_sources"]), ensure_ascii=False, separators=(",", ":")),
            "evidence_note": "Derived only from exact recorded part-to-tool SKU edges and exact canonical tool family metadata. This does not assign product_family or series to the part, and does not independently verify non-manufacturer fitment evidence.",
        })
    by_coverage: dict[str, Counter[str]] = defaultdict(Counter)
    for edge in edges:
        owner = edge["source_sku"].upper()
        relation = edge["relationship_type"]
        if relation == "same_product_family":
            by_coverage[owner]["family_related_count"] += 1
        elif relation == "same_catalog_category":
            by_coverage[owner]["category_related_count"] += 1
        elif relation == "part_fits_tool":
            by_coverage[owner]["fitment_outgoing_count"] += 1
            by_coverage[edge["target_sku"].upper()]["fitment_incoming_count"] += 1
        elif relation == "kit_contains":
            by_coverage[owner]["bundle_components_count"] += 1
            by_coverage[edge["target_sku"].upper()]["included_in_bundle_count"] += 1
        elif relation == "pdp_upsell":
            by_coverage[owner]["pdp_upsell_count"] += 1
        elif relation == "cart_cross_sell":
            by_coverage[owner]["cart_cross_sell_count"] += 1
        elif relation == "variation_of":
            by_coverage[owner]["variation_parent_count"] += 1
            by_coverage[edge["target_sku"].upper()]["variation_child_count"] += 1

    coverage: list[dict[str, str]] = []
    family_memberships: list[dict[str, str]] = []
    family_groups: Counter[tuple[str, str, str]] = Counter()
    family_by_brand: dict[str, Counter[str]] = defaultdict(Counter)
    family_missing_by_brand: Counter[str] = Counter()
    family_missing_by_kind: Counter[str] = Counter()
    family_missing_by_type: Counter[str] = Counter()
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
            **{key: str(counts[key]) for key in COVERAGE_FIELDS[8:18]},
            "recorded_relationship_count": str(total),
            "coverage_state": "has_recorded_relationships" if total else "no_relationship_recorded",
        })
        brand_key = str(row.get("Meta: _dtb_brand_key") or "").strip()
        family = str(row.get("Meta: product_family") or "").strip()
        series = str(row.get("Meta: series") or "").strip()
        family_state = "complete" if family and series else "family_only" if family else "series_only" if series else "missing"
        if "missing" == family_state:
            family_missing_by_brand[brand_key or "(missing brand key)"] += 1
            family_missing_by_kind[str(row.get(KIND) or "(missing product kind)").strip() or "(missing product kind)"] += 1
            family_missing_by_type[str(row.get("Type") or "(missing type)").strip() or "(missing type)"] += 1
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
        "manufacturer_evidence": {
            "path": str(manufacturer_evidence_path) if manufacturer_evidence_path else "",
            "sha256": digest(manufacturer_evidence_path) if manufacturer_evidence_path else "",
            "verified_exact_part_tool_pairs": len(manufacturer_evidence),
            "interpretation": "Each manufacturer-supported pair is bound to an exact canonical part/tool SKU edge, a credential-free HTTPS source, a locator, and a note. This is edge-level source support; it does not establish that every other part/tool edge has manufacturer evidence.",
        },
        "catalog_entities": len(rows),
        "relationship_edges": len(edges),
        "relationship_counts": dict(sorted(type_counts.items())),
        "same_product_family": {
            "groups": family_peer_group_count,
            "directed_edges": family_peer_edge_count,
            "source_fields": ["Meta: _dtb_brand_key", KIND, "Meta: product_family"],
            "interpretation": "Exact brand, product-kind, and product-family matches for tools and toolsets. Series and category may differ. This is broad family discovery, not fitment, equivalence, replacement, upgrade, bundle contents, or commercial suitability.",
        },
        "same_catalog_category": {
            "groups": category_peer_group_count,
            "directed_edges": category_peer_edge_count,
            "source_fields": ["Meta: _dtb_brand_key", KIND, "Meta: _dtb_display_category_key"],
            "eligible_product_kinds": ["tool", "toolset", "stilt", "accessory"],
            "interpretation": "Exact brand, product-kind, and display-category peers for published visible non-part products. Discovery-only; it does not establish fitment, equivalence, substitution, or a curated commercial recommendation.",
        },
        "variation_hierarchy": {
            "variation_to_parent_edges": type_counts.get("variation_of", 0),
            "interpretation": "Exact SKU-to-parent-SKU structure from the canonical WooCommerce Parent field; it is not a fitment or merchandising relationship.",
        },
        "catalog_entities_with_recorded_relationships": sum(row["coverage_state"] == "has_recorded_relationships" for row in coverage),
        "catalog_entities_without_recorded_relationships": sum(row["coverage_state"] == "no_relationship_recorded" for row in coverage),
        "tapetech_exact_part_rows": len(exact_tape_part_skus),
        "tapetech_exact_catalog_fitment_edges": tape_edges,
        "part_family_affiliations": {
            "rows": len(part_family_affiliations),
            "part_owners": len({row["part_sku"] for row in part_family_affiliations}),
            "owners_with_multiple_families": sum(
                1 for part_sku in {row["part_sku"] for row in part_family_affiliations}
                if len({row["family_group_key"] for row in part_family_affiliations if row["part_sku"] == part_sku}) > 1
            ),
            "evidence_status_counts": dict(sorted(Counter(
                status
                for row in part_family_affiliations
                for status in json.loads(row["evidence_statuses_json"])
            ).items())),
            "interpretation": "Derived family/subfamily affiliations inherit the evidence status of exact recorded fitment edges and are not written back as canonical part metadata.",
        },
        "family_metadata": {
            "entities_with_family_and_series": sum(1 for item in family_memberships if item["family_metadata_state"] == "complete"),
            "entities_with_family_only": sum(1 for item in family_memberships if item["family_metadata_state"] == "family_only"),
            "entities_with_series_only": sum(1 for item in family_memberships if item["family_metadata_state"] == "series_only"),
            "entities_without_family_or_series": sum(1 for item in family_memberships if item["family_metadata_state"] == "missing"),
            "distinct_brand_family_series_groups": len(family_groups),
            "groups_with_multiple_catalog_entities": sum(1 for count in family_groups.values() if count > 1),
            "missing_by_brand": dict(sorted(family_missing_by_brand.items())),
            "missing_by_product_kind": dict(sorted(family_missing_by_kind.items())),
            "missing_by_type": dict(sorted(family_missing_by_type.items())),
            "brand_coverage": {
                brand: {
                    **dict(counts),
                    "distinct_family_series_groups": len(brand_groups.get(brand, set())),
                }
                for brand, counts in sorted(family_by_brand.items())
            },
            "interpretation": "Membership is copied from exact canonical product_family and series fields. It does not prove substitution, compatibility, or independent source verification. The separate same_product_family edge uses exact brand, kind, and product_family while allowing category and series differences; same_catalog_category separately uses exact brand, kind, and display-category fields. Both are discovery-only.",
        },
        "unresolved_or_noncanonical_targets": dict(sorted(issues.items())),
        "interpretation": "No recorded relationship is not evidence of incompatibility or a recommendation. Family discovery uses exact canonical brand, product kind, and product family, while remaining separate from fitment and merchandising.",
    }
    return edges, coverage, family_memberships, part_family_affiliations, summary


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
    parser.add_argument("--manufacturer-evidence", type=Path, default=DEFAULT_MANUFACTURER_EVIDENCE)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args()
    edges, coverage, family_memberships, part_family_affiliations, summary = build(
        args.catalog,
        args.tapetech_matrix,
        args.manufacturer_evidence,
    )
    write_csv(args.output_dir / "product-relationship-tree.csv", EDGE_FIELDS, edges)
    write_csv(args.output_dir / "product-relationship-coverage.csv", COVERAGE_FIELDS, coverage)
    write_csv(args.output_dir / "product-family-membership.csv", FAMILY_FIELDS, family_memberships)
    write_csv(args.output_dir / "product-part-family-affiliations.csv", PART_FAMILY_AFFILIATION_FIELDS, part_family_affiliations)
    (args.output_dir / "product-relationship-tree-summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"output_dir": str(args.output_dir), **summary}, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

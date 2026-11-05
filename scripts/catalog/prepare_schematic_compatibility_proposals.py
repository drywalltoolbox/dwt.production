#!/usr/bin/env python3
"""Prepare evidence-backed compatibility proposals from existing schematic data.

This command is intentionally read-only. It reuses repository authorities to
propose `_dtb_compatible_tool_skus` relationships without mutating the catalog:

- products/launch/official/dtb_official_catalog.csv for canonical identity;
- products/launch/universal_parts/references/all_brands_schematic_parts_master.csv
  for part-to-schematic occurrence evidence;
- frontend/src/data/productSchematicLinks.generated.js for purchasable
  product-to-schematic identity.

Exact SKU and shared canonical schematic identity are required. No fuzzy name
matching is performed. Variation tool SKUs remain exact variation identities;
they are never widened to their variable-product parent. Multi-tool schematic
matches remain review-only.
"""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter, defaultdict
from pathlib import Path

from official_catalog_schema import CatalogValidationError, validate_catalog

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.include-gaps.json"
DEFAULT_MASTER = ROOT / "products" / "launch" / "universal_parts" / "references" / "all_brands_schematic_parts_master.csv"
DEFAULT_LINKS = ROOT / "frontend" / "src" / "data" / "productSchematicLinks.generated.js"
DEFAULT_VERBOSE_MAP = ROOT / "scripts" / "catalog" / "data" / "schematic_verbose_id_map.json"
DEFAULT_OUTPUT = ROOT / "products" / "dev" / "catalog-enrichment" / "compatibility"

PRODUCT_KIND_FIELD = "Meta: _dtb_product_kind"
IS_PART_FIELD = "Meta: _dtb_is_parts"
PARENT_SKU_FIELD = "Meta: _dtb_parent_product_sku"
COMPATIBLE_FIELD = "Meta: _dtb_compatible_tool_skus"
REPLACEMENT_FIELD = "Meta: _dtb_replacement_part_for"
TRUTHY = {"1", "true", "yes", "y"}
# Schematic sources sometimes use a shortened display label while the catalog
# uses the canonical brand label. This list affects conflict reporting only.
SCHEMATIC_BRAND_LABEL_ALIASES = {"columbia": "columbia tools"}


def value(row: dict[str, str], field: str) -> str:
    return (row.get(field) or "").strip()


def is_part(row: dict[str, str]) -> bool:
    """Use the same canonical part contract as the enrichment audit."""
    return (
        value(row, PRODUCT_KIND_FIELD).casefold() == "part"
        or value(row, IS_PART_FIELD).casefold() in TRUTHY
    )


def is_published(row: dict[str, str]) -> bool:
    return value(row, "Published").casefold() in TRUTHY


def load_catalog(path: Path) -> tuple[list[dict[str, str]], dict[str, dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    by_sku = {value(row, "SKU").upper(): row for row in rows if value(row, "SKU")}
    return rows, by_sku


def load_product_schematic_links(path: Path) -> dict[str, dict[str, object]]:
    source = path.read_text(encoding="utf-8")
    start = source.index("{")
    end = source.rindex("};")
    parsed = json.loads(source[start : end + 1])
    return {str(sku).upper(): entry for sku, entry in parsed.items()}


def normalized_schematic_key(value: str) -> str:
    return "".join(character for character in value.casefold() if character.isalnum())


def load_schematic_aliases(path: Path) -> dict[str, str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    aliases: dict[str, str] = {}
    for raw_key, target in payload.items():
        if not isinstance(target, list) or not target:
            continue
        canonical_id = str(target[0] or "").strip()
        if canonical_id:
            aliases[normalized_schematic_key(str(raw_key))] = canonical_id
            aliases[normalized_schematic_key(canonical_id)] = canonical_id
    return aliases


def canonical_schematic_id(
    raw: str,
    aliases: dict[str, str],
    known_ids: set[str],
) -> str | None:
    value = raw.strip()
    if not value:
        return None
    if value in known_ids:
        return value
    resolved = aliases.get(normalized_schematic_key(value))
    return resolved if resolved in known_ids else None


def canonical_tool_sku(sku: str, catalog: dict[str, dict[str, str]]) -> str | None:
    key = sku.upper()
    row = catalog.get(key)
    if row is None or is_part(row) or not is_published(row):
        return None
    if value(row, "Type").casefold() == "variation":
        parent = (value(row, "Parent") or value(row, PARENT_SKU_FIELD)).upper()
        parent_row = catalog.get(parent)
        if parent_row is None or is_part(parent_row) or not is_published(parent_row):
            return None
    return key


def build_tool_index(
    links: dict[str, dict[str, object]],
    catalog: dict[str, dict[str, str]],
) -> dict[str, list[str]]:
    tools: dict[str, set[str]] = defaultdict(set)
    for sku, entry in links.items():
        schematic_id = str(entry.get("schematicId") or "").strip()
        if not schematic_id:
            continue
        resolved = canonical_tool_sku(sku, catalog)
        if resolved:
            tools[schematic_id].add(resolved)
    return {
        schematic_id: prefer_exact_variation_targets(skus, catalog)
        for schematic_id, skus in tools.items()
    }


def prefer_exact_variation_targets(
    tool_skus: set[str],
    catalog: dict[str, dict[str, str]],
) -> list[str]:
    """Drop a variable parent when the same schematic names exact child SKUs."""
    exact_parent_skus = {
        (value(catalog[sku], "Parent") or value(catalog[sku], PARENT_SKU_FIELD)).upper()
        for sku in tool_skus
        if sku in catalog and value(catalog[sku], "Type").casefold() == "variation"
    }
    return sorted(
        sku
        for sku in tool_skus
        if not (
            sku in exact_parent_skus
            and sku in catalog
            and value(catalog[sku], "Type").casefold() == "variable"
        )
    )


def augment_tool_index_from_master(
    master_path: Path,
    tool_index: dict[str, list[str]],
    catalog: dict[str, dict[str, str]],
    aliases: dict[str, str],
) -> dict[str, list[str]]:
    tools = {schematic_id: set(skus) for schematic_id, skus in tool_index.items()}
    known_ids = set(tools)
    with master_path.open("r", encoding="utf-8-sig", newline="") as handle:
        for source_row in csv.DictReader(handle):
            raw_schematic_id = (source_row.get("schematic_id") or "").strip()
            schematic_id = canonical_schematic_id(raw_schematic_id, aliases, known_ids)
            product_sku = (source_row.get("product_sku") or "").strip()
            if not schematic_id or not product_sku:
                continue
            resolved = canonical_tool_sku(product_sku, catalog)
            if resolved:
                tools.setdefault(schematic_id, set()).add(resolved)
    return {
        schematic_id: prefer_exact_variation_targets(skus, catalog)
        for schematic_id, skus in tools.items()
    }


def resolve_part_sku(
    source_row: dict[str, str],
    catalog: dict[str, dict[str, str]],
) -> tuple[str | None, str]:
    candidates = {
        (source_row.get(field) or "").strip().upper()
        for field in ("sku", "part_number")
        if (source_row.get(field) or "").strip()
    }
    matches = sorted(
        candidate
        for candidate in candidates
        if candidate in catalog and is_part(catalog[candidate])
    )
    if len(matches) == 1:
        return matches[0], "exact_part_identifier"
    if len(matches) > 1:
        return None, "ambiguous_part_identifiers"
    return None, "part_identifier_not_in_catalog"


def existing_target_skus(row: dict[str, str]) -> set[str]:
    values: set[str] = set()
    for field in (COMPATIBLE_FIELD, REPLACEMENT_FIELD):
        raw = value(row, field)
        if not raw:
            continue
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError:
            decoded = [item.strip() for item in raw.split(",")]
        if isinstance(decoded, list):
            values.update(str(item).strip().upper() for item in decoded if str(item).strip())
    return values


def build_cross_brand_identity_candidates(
    diagnostics: list[dict[str, str]],
    catalog_rows: list[dict[str, str]],
) -> list[dict[str, str]]:
    """Expose exact cross-brand part identity collisions for human review only."""
    catalog_index: dict[str, list[tuple[dict[str, str], str]]] = defaultdict(list)
    catalog_fields = ("SKU", "Meta: _dtb_mpn", "Meta: _dtb_manufacturer_sku", "Meta: schema_mpn")
    for row in catalog_rows:
        if not is_part(row):
            continue
        for field in catalog_fields:
            token = row.get(field) or ""
            if token:
                catalog_index[token].append((row, field))

    candidates: dict[tuple[str, str, str, str], dict[str, object]] = {}
    source_fields = (
        ("raw_part_identifier", "raw_part_identifier"),
        ("source_sku", "source_sku"),
        ("source_part_id", "source_part_id"),
        ("source_display_id", "source_display_id"),
        ("source_part_number", "source_part_number"),
    )
    for diagnostic in diagnostics:
        if diagnostic.get("status") != "unresolved_schematic_identity":
            continue
        source_brand = diagnostic.get("brand") or ""
        for output_field, source_field in source_fields:
            token = diagnostic.get(source_field) or ""
            if not token:
                continue
            for catalog_row, catalog_field in catalog_index.get(token, []):
                catalog_brand = value(catalog_row, "Meta: _dtb_brand_label") or value(catalog_row, "Brands")
                source_brand_key = SCHEMATIC_BRAND_LABEL_ALIASES.get(source_brand.casefold(), source_brand.casefold())
                catalog_brand_key = SCHEMATIC_BRAND_LABEL_ALIASES.get(catalog_brand.casefold(), catalog_brand.casefold())
                if not catalog_brand or catalog_brand_key == source_brand_key:
                    continue
                sku = value(catalog_row, "SKU")
                key = (diagnostic.get("source_file", ""), diagnostic.get("raw_schematic_id", ""), sku, catalog_brand)
                entry = candidates.setdefault(key, {
                    "source_brand": source_brand,
                    "source_schematic_id": diagnostic.get("raw_schematic_id", ""),
                    "source_file": diagnostic.get("source_file", ""),
                    "source_product_sku": diagnostic.get("source_product_sku", ""),
                    "source_schematic_tool": diagnostic.get("source_schematic_tool", ""),
                    "source_schematic_tool_path": diagnostic.get("source_schematic_tool_path", ""),
                    "candidate_part_sku": sku,
                    "candidate_part_name": value(catalog_row, "Name"),
                    "candidate_brand": catalog_brand,
                    "matched_source_fields": set(),
                    "matched_catalog_fields": set(),
                    "matched_identifier_values": set(),
                })
                entry["matched_source_fields"].add(output_field)
                entry["matched_catalog_fields"].add(catalog_field)
                entry["matched_identifier_values"].add(token)

    rows: list[dict[str, str]] = []
    for entry in candidates.values():
        row = {key: val for key, val in entry.items() if not isinstance(val, set)}
        for field in ("matched_source_fields", "matched_catalog_fields", "matched_identifier_values"):
            row[field] = json.dumps(sorted(entry[field]), ensure_ascii=False, separators=(",", ":"))
        row["status"] = "cross_brand_exact_identity_conflict"
        row["reason"] = "Exact identifier collision across brands; candidate is not an identity mapping or fitment claim and requires source review"
        rows.append(row)
    return sorted(rows, key=lambda row: (row["source_brand"], row["source_file"], row["source_schematic_id"], row["candidate_part_sku"]))


def prepare_proposals(
    *,
    catalog: dict[str, dict[str, str]],
    master_path: Path,
    tool_index: dict[str, list[str]],
    brand_filter: str,
    schematic_aliases: dict[str, str] | None = None,
    diagnostics: list[dict[str, str]] | None = None,
) -> list[dict[str, str]]:
    proposals: list[dict[str, str]] = []
    seen: set[tuple[str, str]] = set()
    requested_brand = brand_filter.casefold().strip()
    aliases = schematic_aliases or {}
    known_ids = set(tool_index)
    diagnostic_rows = diagnostics if diagnostics is not None else []
    diagnostic_seen: set[tuple[str, str, str, str]] = set()

    with master_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for source_row in reader:
            brand = (source_row.get("brand") or "").strip()
            if requested_brand and requested_brand not in brand.casefold():
                continue

            raw_schematic_id = source_row.get("schematic_id") or ""
            raw_schematic_lookup = raw_schematic_id.strip()
            raw_sku = source_row.get("sku") or ""
            raw_part_number = source_row.get("part_number") or ""
            raw_part_id = raw_sku or raw_part_number
            raw_part_lookup = raw_part_id.strip().upper()
            source_file = (source_row.get("source_file_from_brands") or "").strip().replace("\\", "/")
            schematic_id = canonical_schematic_id(raw_schematic_lookup, aliases, known_ids)
            if not raw_part_lookup or not raw_schematic_lookup:
                continue
            if not schematic_id:
                key = (source_file, raw_part_id, raw_schematic_id, "unresolved_schematic_identity")
                if key not in diagnostic_seen:
                    diagnostic_seen.add(key)
                    diagnostic_rows.append({
                        "brand": brand,
                        "raw_schematic_id": raw_schematic_id,
                        "raw_part_identifier": raw_part_id,
                        "source_file": source_file,
                        "source_product_sku": source_row.get("product_sku") or "",
                        "source_schematic_tool": source_row.get("schematic_tool") or "",
                        "source_schematic_tool_path": source_row.get("schematic_tool_path") or "",
                        "source_sku": raw_sku,
                        "source_part_id": source_row.get("part_id") or "",
                        "source_display_id": source_row.get("display_id") or "",
                        "source_part_number": raw_part_number,
                        "source_part_name": source_row.get("part_name") or "",
                        "status": "unresolved_schematic_identity",
                        "reason": "master schematic identity does not resolve to a canonical tool schematic",
                    })
                continue

            part_sku, part_resolution = resolve_part_sku(source_row, catalog)
            if not part_sku:
                key = (source_file, raw_part_id, schematic_id, part_resolution)
                if key not in diagnostic_seen:
                    diagnostic_seen.add(key)
                    diagnostic_rows.append({
                        "brand": brand,
                        "raw_schematic_id": raw_schematic_id,
                        "raw_part_identifier": raw_part_id,
                        "source_file": source_file,
                        "source_product_sku": source_row.get("product_sku") or "",
                        "source_schematic_tool": source_row.get("schematic_tool") or "",
                        "source_schematic_tool_path": source_row.get("schematic_tool_path") or "",
                        "source_sku": raw_sku,
                        "source_part_id": source_row.get("part_id") or "",
                        "source_display_id": source_row.get("display_id") or "",
                        "source_part_number": raw_part_number,
                        "source_part_name": source_row.get("part_name") or "",
                        "status": part_resolution,
                        "reason": "master part identifier does not resolve to exactly one canonical part SKU",
                    })
                continue
            dedupe_key = (part_sku, schematic_id)
            if dedupe_key in seen:
                continue
            seen.add(dedupe_key)

            part = catalog[part_sku]

            targets = tool_index.get(schematic_id, [])
            existing = existing_target_skus(part)
            missing_targets = [target for target in targets if target.upper() not in existing]
            if not is_published(part):
                status = "review_part_unpublished"
                reason = "exact part identity is not published and is not eligible for the current fitment projection"
            elif targets and not missing_targets:
                status = "already_populated"
                reason = "all exact schematic tool SKU targets already exist on the canonical part"
            elif len(missing_targets) == 1:
                status = "proposal_exact"
                reason = "exact part SKU and exact tool product/variation SKU share canonical schematic identity; target is missing from current compatibility metadata; variation owners require exact variant review"
            elif len(missing_targets) > 1:
                status = "review_multi_tool"
                reason = "exact part SKU shares canonical schematic identity with multiple exact tool product/variation SKUs not already present; variation owners require exact variant review"
            else:
                status = "review_no_tool"
                reason = "part occurrence is known but no canonical non-part tool SKU resolves to this schematic"

            proposals.append(
                {
                    "brand": brand,
                    "schematic_id": schematic_id,
                    "source_schematic_id": raw_schematic_id,
                    "source_file": source_file,
                    "part_sku": part_sku,
                    "part_name": value(part, "Name"),
                    "part_type": value(part, "Type"),
                    "part_parent_sku": value(part, PARENT_SKU_FIELD),
                    "target_tool_skus": json.dumps(targets, separators=(",", ":")),
                    "status": status,
                    "reason": f"{reason}; part identity: {part_resolution}",
                }
            )

    return sorted(proposals, key=lambda item: (item["status"], item["brand"], item["schematic_id"], item["part_sku"]))


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fields = (
        "brand",
        "schematic_id",
        "source_schematic_id",
        "source_file",
        "part_sku",
        "part_name",
        "part_type",
        "part_parent_sku",
        "target_tool_skus",
        "status",
        "reason",
    )
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--schematic-master", type=Path, default=DEFAULT_MASTER)
    parser.add_argument("--product-links", type=Path, default=DEFAULT_LINKS)
    parser.add_argument("--schematic-aliases", type=Path, default=DEFAULT_VERBOSE_MAP)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument(
        "--brand",
        default="",
        help="Optional case-insensitive brand substring. Default processes all brands.",
    )
    args = parser.parse_args()

    catalog_path = args.catalog.resolve()
    validate_catalog(catalog_path, args.include_gap_audit.resolve())
    _rows, catalog = load_catalog(catalog_path)
    links = load_product_schematic_links(args.product_links.resolve())
    aliases = load_schematic_aliases(args.schematic_aliases.resolve())
    tool_index = build_tool_index(links, catalog)
    tool_index = augment_tool_index_from_master(
        args.schematic_master.resolve(), tool_index, catalog, aliases
    )
    diagnostics: list[dict[str, str]] = []
    proposals = prepare_proposals(
        catalog=catalog,
        master_path=args.schematic_master.resolve(),
        tool_index=tool_index,
        brand_filter=args.brand,
        schematic_aliases=aliases,
        diagnostics=diagnostics,
    )

    output = args.output_dir.resolve()
    proposal_path = output / "schematic-compatibility-proposals.csv"
    summary_path = output / "schematic-compatibility-summary.json"
    diagnostics_path = output / "schematic-compatibility-unresolved.csv"
    cross_brand_path = output / "schematic-cross-brand-identity-candidates.csv"
    write_csv(proposal_path, proposals)
    diagnostics_path.parent.mkdir(parents=True, exist_ok=True)
    with diagnostics_path.open("w", encoding="utf-8", newline="") as handle:
        fields = (
            "brand", "raw_schematic_id", "raw_part_identifier", "source_file",
            "source_product_sku", "source_schematic_tool", "source_schematic_tool_path",
            "source_sku", "source_part_id", "source_display_id", "source_part_number",
            "source_part_name", "status", "reason",
        )
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(sorted(diagnostics, key=lambda item: (item["status"], item["brand"], item["raw_schematic_id"], item["raw_part_identifier"])))

    cross_brand_candidates = build_cross_brand_identity_candidates(diagnostics, _rows)
    with cross_brand_path.open("w", encoding="utf-8", newline="") as handle:
        fields = (
            "source_brand", "source_schematic_id", "source_file", "source_product_sku",
            "source_schematic_tool", "source_schematic_tool_path", "candidate_part_sku",
            "candidate_part_name", "candidate_brand", "matched_source_fields",
            "matched_catalog_fields", "matched_identifier_values", "status", "reason",
        )
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(cross_brand_candidates)

    counts: dict[str, int] = defaultdict(int)
    for proposal in proposals:
        counts[proposal["status"]] += 1
    summary = {
        "schema_version": 4,
        "brand_filter": args.brand or None,
        "mutates_catalog": False,
        "proposal_rows": len(proposals),
        "by_status": dict(sorted(counts.items())),
        "unique_parts": len({item["part_sku"] for item in proposals}),
        "unique_schematics": len({item["schematic_id"] for item in proposals}),
        "unresolved_rows": len(diagnostics),
        "unresolved_by_status": dict(sorted(Counter(item["status"] for item in diagnostics).items())),
        "cross_brand_identity_candidates": len(cross_brand_candidates),
        "outputs": {
            "proposals": proposal_path.relative_to(ROOT).as_posix() if proposal_path.is_relative_to(ROOT) else str(proposal_path),
            "unresolved": diagnostics_path.relative_to(ROOT).as_posix() if diagnostics_path.is_relative_to(ROOT) else str(diagnostics_path),
            "cross_brand_identity_candidates": cross_brand_path.relative_to(ROOT).as_posix() if cross_brand_path.is_relative_to(ROOT) else str(cross_brand_path),
        },
    }
    summary_path.parent.mkdir(parents=True, exist_ok=True)
    summary_path.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error, json.JSONDecodeError, ValueError) as exc:
        print(f"ERROR: {exc}")
        raise SystemExit(1)

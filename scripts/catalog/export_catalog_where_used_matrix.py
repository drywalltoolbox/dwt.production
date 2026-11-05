#!/usr/bin/env python3
"""Export exact canonical part-to-tool relationships as a reviewable matrix."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_TAPETECH = ROOT / "docs/reference/data/brands/tapetech/Copy-of-TT-Where-Parts-Are-Used.csv"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/catalog-where-parts-are-used.csv"
COMPATIBLE = "Meta: _dtb_compatible_tool_skus"
PART_FLAG = "Meta: _dtb_is_parts"
PART_KIND = "Meta: _dtb_product_kind"
TRUTHY = {"1", "true", "yes", "y"}
BASE_FIELDS = [
    "Part SKU",
    "Part Number",
    "Part Name",
    "Brand",
    "Catalog Type",
    "Parent SKU",
    "Published",
    "Catalog Visibility",
    "Relationship Count",
    "Mapping Status",
    "Evidence Source",
]


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def is_part(row: dict[str, str]) -> bool:
    return (row.get(PART_KIND) or "").strip().casefold() == "part" or (row.get(PART_FLAG) or "").strip().casefold() in TRUTHY


def parse_targets(row: dict[str, str]) -> list[str]:
    raw = (row.get(COMPATIBLE) or "").strip()
    if not raw:
        return []
    try:
        values = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError(f"Malformed {COMPATIBLE} for SKU {row.get('SKU', '')!r}") from exc
    if not isinstance(values, list) or any(not isinstance(value, str) or not value.strip() for value in values):
        raise ValueError(f"Invalid {COMPATIBLE} list for SKU {row.get('SKU', '')!r}")
    targets = [value.strip() for value in values]
    if len({value.upper() for value in targets}) != len(targets):
        raise ValueError(f"Duplicate {COMPATIBLE} values for SKU {row.get('SKU', '')!r}")
    return targets


def read_tapetech_matrix(path: Path) -> tuple[list[str], dict[str, dict[str, str]]]:
    fields, rows = read_csv(path)
    required = {"PartNumber", "Description", "Number of Tools Where Part Is Used"}
    if not required.issubset(fields):
        raise ValueError(f"TapeTech matrix is missing columns: {sorted(required - set(fields))}")
    tool_fields = [field for field in fields if field not in required]
    by_part: dict[str, dict[str, str]] = {}
    for row in rows:
        part_sku = (row.get("PartNumber") or "").strip().upper()
        if not part_sku or part_sku in by_part:
            raise ValueError(f"Blank or duplicate TapeTech part number: {part_sku!r}")
        marked = [field for field in tool_fields if (row.get(field) or "").strip().casefold() == "n"]
        try:
            declared_count = int((row.get("Number of Tools Where Part Is Used") or "").strip())
        except ValueError as exc:
            raise ValueError(f"Invalid TapeTech tool count for part {part_sku}") from exc
        if declared_count != len(marked):
            raise ValueError(f"TapeTech count does not match marked tools for part {part_sku}")
        by_part[part_sku] = {field.upper(): "1" for field in marked}
    return tool_fields, by_part


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def build(catalog_path: Path, tapetech_path: Path) -> tuple[list[str], list[dict[str, str]], dict[str, object]]:
    catalog_fields, catalog_rows = read_csv(catalog_path)
    required = {"SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent", COMPATIBLE, "Meta: schema_mpn", "Meta: _dtb_manufacturer_sku", "Meta: _dtb_brand_label", PART_FLAG, PART_KIND}
    if not required.issubset(catalog_fields):
        raise ValueError(f"Canonical catalog is missing columns: {sorted(required - set(catalog_fields))}")

    by_sku: dict[str, dict[str, str]] = {}
    for row in catalog_rows:
        sku = (row.get("SKU") or "").strip()
        if not sku or sku.upper() in by_sku:
            raise ValueError(f"Blank or duplicate canonical SKU: {sku!r}")
        by_sku[sku.upper()] = row

    parts = [row for row in catalog_rows if is_part(row)]
    relationships = {row["SKU"].strip(): parse_targets(row) for row in parts}
    missing_targets = sorted({target for targets in relationships.values() for target in targets if target.upper() not in by_sku})
    if missing_targets:
        raise ValueError(f"Canonical relationships have unresolved target SKUs: {missing_targets[:20]}")

    # Include every published, visible exact-SKU tool-like record and every
    # currently recorded exact target, while excluding variable parent SKUs.
    eligible_kinds = {"tool", "toolset", "stilt", "accessory"}
    target_rows = {
        row["SKU"].strip(): row
        for row in catalog_rows
        if row.get("Type", "").strip().casefold() in {"simple", "variation"}
        and row.get("Published", "").strip().casefold() in TRUTHY
        and row.get("Visibility in catalog", "").strip().casefold() == "visible"
        and row.get(PART_FLAG, "").strip().casefold() not in TRUTHY
        and row.get(PART_KIND, "").strip().casefold() in eligible_kinds
    }
    for targets in relationships.values():
        for target in targets:
            target_rows.setdefault(target, by_sku[target.upper()])
    target_skus = sorted(target_rows, key=lambda sku: (target_rows[sku].get("Meta: _dtb_brand_label", "").casefold(), sku.casefold()))

    matrix_fields, tapetech_by_part = read_tapetech_matrix(tapetech_path)
    tape_brand = "tapetech"
    tape_exact_parts = 0
    tape_exact_edges = 0
    for part in parts:
        if (part.get("Meta: _dtb_brand_label") or "").strip().casefold() != tape_brand:
            continue
        sku = part["SKU"].strip()
        source_markers = tapetech_by_part.get(sku.upper())
        if source_markers is None:
            continue
        tape_exact_parts += 1
        source_targets = {
            header for header in matrix_fields
            if source_markers.get(header.upper()) == "1" and header.upper() in by_sku
        }
        canonical_targets = {target.upper() for target in relationships[sku]}
        if source_targets != canonical_targets:
            raise ValueError(
                f"TapeTech exact-SKU matrix differs from canonical relationships for {sku}: "
                f"matrix={sorted(source_targets)}, catalog={sorted(canonical_targets)}"
            )
        tape_exact_edges += len(source_targets)

    output_fields = BASE_FIELDS + [f"Tool: {sku}" for sku in target_skus]
    output_rows: list[dict[str, str]] = []
    for part in sorted(parts, key=lambda row: ((row.get("Meta: _dtb_brand_label") or "").casefold(), row["SKU"].casefold())):
        sku = part["SKU"].strip()
        targets = relationships[sku]
        exact_tapetech = (
            (part.get("Meta: _dtb_brand_label") or "").strip().casefold() == tape_brand
            and sku.upper() in tapetech_by_part
        )
        if exact_tapetech:
            evidence_source = "TapeTech manufacturer matrix (exact part SKU); canonical compatibility field"
        else:
            evidence_source = "Canonical catalog compatibility field; source evidence requires its owning review record"
        mapped = {
            "Part SKU": sku,
            "Part Number": (part.get("Meta: _dtb_manufacturer_sku") or "").strip() or (part.get("Meta: schema_mpn") or "").strip(),
            "Part Name": (part.get("Name") or "").strip(),
            "Brand": (part.get("Meta: _dtb_brand_label") or "").strip(),
            "Catalog Type": (part.get("Type") or "").strip(),
            "Parent SKU": (part.get("Parent") or "").strip(),
            "Published": (part.get("Published") or "").strip(),
            "Catalog Visibility": (part.get("Visibility in catalog") or "").strip(),
            "Relationship Count": str(len(targets)),
            "Mapping Status": "mapped" if targets else "no_relationship_recorded",
            "Evidence Source": evidence_source,
        }
        for target in target_skus:
            mapped[f"Tool: {target}"] = "1" if target.upper() in {value.upper() for value in targets} else ""
        output_rows.append(mapped)

    summary = {
        "catalog_rows": len(catalog_rows),
        "part_rows": len(parts),
        "part_rows_with_relationships": sum(bool(values) for values in relationships.values()),
        "part_rows_without_recorded_relationships": sum(not values for values in relationships.values()),
        "relationship_edges": sum(len(values) for values in relationships.values()),
        "tool_sku_columns": len(target_skus),
        "tapetech_exact_part_rows": tape_exact_parts,
        "tapetech_catalog_relationship_edges": tape_exact_edges,
        "catalog_sha256": sha256(catalog_path),
        "tapetech_matrix_sha256": sha256(tapetech_path),
    }
    return output_fields, output_rows, summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--tapetech-matrix", type=Path, default=DEFAULT_TAPETECH)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()

    fields, rows, summary = build(args.catalog, args.tapetech_matrix)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    print(json.dumps({"output": str(args.output), **summary}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

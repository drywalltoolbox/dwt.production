#!/usr/bin/env python3
"""Project approved SKU taxonomy assignments into the official Woo CSV."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import shutil
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from catalog_taxonomy_policy import COMPATIBILITY_BY_TAXON, woocommerce_category_path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_TAXONOMY = ROOT / "products/catalog/source/taxonomy.json"
DEFAULT_ASSIGNMENTS = ROOT / "products/catalog/source/product_categories.csv"

POWERED_APPLICATOR_SKUS = {"4-772", "TT-MUDRUNNER"}
LEGACY_TAXON_ALIASES = {
    "automatic_angle_heads": "automatic_corner_finishers",
    "semi_automatic_tools": "semi_automatic_tapers_banjos",
}

COMPATIBILITY_KEYS = COMPATIBILITY_BY_TAXON


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or ()), list(reader)


def load_taxa(path: Path) -> dict[str, dict[str, object]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    taxa = {str(item["key"]): item for item in data["taxa"]}
    slugs = [str(item["slug"]) for item in data["taxa"]]
    if len(taxa) != len(data["taxa"]) or len(slugs) != len(set(slugs)):
        raise ValueError("taxonomy registry contains duplicate keys or slugs")
    return taxa


def path_for(key: str, taxa: dict[str, dict[str, object]]) -> str:
    labels: list[str] = []
    seen: set[str] = set()
    current: str | None = key
    while current is not None:
        if current in seen or current not in taxa:
            raise ValueError(f"invalid taxonomy ancestry at {current}")
        seen.add(current)
        item = taxa[current]
        labels.append(str(item["label"]))
        parent = item.get("parent_key")
        current = str(parent) if parent is not None else None
    return woocommerce_category_path(list(reversed(labels)))


def canonical_assignment_key(sku: str, key: str) -> str:
    if key == "automatic_compound_applicators":
        return "powered_compound_applicators" if sku in POWERED_APPLICATOR_SKUS else "applicator_heads"
    return LEGACY_TAXON_ALIASES.get(key, key)


def load_assignments(path: Path, taxa: dict[str, dict[str, object]]) -> dict[str, list[tuple[int, str]]]:
    _, rows = load_csv(path)
    result: dict[str, list[tuple[int, str]]] = {}
    for row in rows:
        sku = row["sku"].strip()
        key = canonical_assignment_key(sku, row["taxon_key"].strip())
        if row["review_status"].strip() != "approved":
            raise ValueError(f"{sku}/{key}: category assignment is not approved")
        if key not in taxa:
            raise ValueError(f"{sku}: unknown taxon {key}")
        result.setdefault(sku, []).append((int(row["position"]), key))
    for sku, values in result.items():
        keys = [key for _, key in values]
        if len(keys) != len(set(keys)):
            raise ValueError(f"{sku}: duplicate category assignment")
        values.sort(key=lambda item: (item[0], int(taxa[item[1]]["sort"]), item[1]))
    return result


def compatibility_keys(taxon_key: str) -> tuple[str, str]:
    try:
        return COMPATIBILITY_KEYS[taxon_key]
    except KeyError as exc:
        raise ValueError(f"taxon {taxon_key} has no compatibility-facet contract") from exc


def rebuild(rows: list[dict[str, str]], assignments: dict[str, list[tuple[int, str]]], taxa: dict[str, dict[str, object]]) -> tuple[list[dict[str, str]], dict[str, int]]:
    owners = {row["SKU"].strip(): row for row in rows if row["Type"].strip() != "variation"}
    if set(assignments) != set(owners):
        missing = sorted(set(owners) - set(assignments))
        extra = sorted(set(assignments) - set(owners))
        raise ValueError(f"assignment coverage mismatch; missing={missing[:10]}, extra={extra[:10]}")

    changes = {"Categories": 0, "Meta: _dtb_category_key": 0, "Meta: _dtb_display_category_key": 0}
    owner_paths = {
        sku: ", ".join(path_for(key, taxa) for _, key in assignments[sku])
        for sku in owners
    }
    owner_primary = {sku: assignments[sku][0][1] for sku in owners}

    for row in rows:
        if row["Type"].strip() == "variation":
            parent = row["Parent"].strip()
            if parent not in owner_paths:
                raise ValueError(f"variation {row['SKU']} has unresolved parent {parent}")
            expected = owner_paths[parent]
            primary = owner_primary[parent]
        else:
            sku = row["SKU"].strip()
            expected = owner_paths[sku]
            primary = owner_primary[sku]

        category_key, display_key = compatibility_keys(primary)
        expected_fields = {
            "Categories": expected,
            "Meta: _dtb_category_key": category_key,
            "Meta: _dtb_display_category_key": display_key,
        }
        for field, value in expected_fields.items():
            if row[field] != value:
                row[field] = value
                changes[field] += 1
    return rows, changes


def write_atomic(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    os.close(fd)
    temp = Path(name)
    try:
        with temp.open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="raise", lineterminator="\r\n")
            writer.writeheader()
            writer.writerows(rows)
        os.replace(temp, path)
    finally:
        temp.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--taxonomy", type=Path, default=DEFAULT_TAXONOMY)
    parser.add_argument("--assignments", type=Path, default=DEFAULT_ASSIGNMENTS)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    catalog = args.catalog.resolve()
    fields, rows = load_csv(catalog)
    taxa = load_taxa(args.taxonomy.resolve())
    assignments = load_assignments(args.assignments.resolve(), taxa)
    rows, changes = rebuild(rows, assignments, taxa)
    before = digest(catalog)
    total_changes = sum(changes.values())
    result = {
        "rows": len(rows),
        "columns": len(fields),
        "field_changes": changes,
        "total_field_changes": total_changes,
        "before_sha256": before,
        "applied": False,
    }

    if args.apply and total_changes:
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup = catalog.with_name(catalog.name + f".pre-taxonomy-rebuild-{stamp}.bak")
        if backup.exists():
            raise FileExistsError(f"refusing to replace existing backup: {backup}")
        temp_backup = backup.with_name(backup.name + ".tmp")
        shutil.copy2(catalog, temp_backup)
        os.replace(temp_backup, backup)
        if digest(backup) != before:
            raise RuntimeError("backup hash does not match mutation input")
        write_atomic(catalog, fields, rows)
        result.update({
            "applied": True,
            "backup": str(backup),
            "backup_sha256": digest(backup),
            "after_sha256": digest(catalog),
        })

    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

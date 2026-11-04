#!/usr/bin/env python3
"""Preview or apply an approved, field-allowlisted official-catalog manifest."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, approval_sha256, file_sha256, row_sha256
from official_catalog_schema import (
    CatalogValidationError,
    create_catalog_backup,
    validate_catalog,
    validate_taxonomy_rows,
    write_catalog_atomic,
)


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.include-gaps.json"
WORKFLOW_FIELDS = {
    "compatibility": {
        "Meta: _dtb_compatible_tool_skus",
    },
    "compatibility_remove": {"Meta: _dtb_compatible_tool_skus"},
    "taxonomy": {
        "Categories",
        "Meta: _dtb_category_key",
        "Meta: _dtb_display_category_key",
    },
    "editorial": {
        "Short description",
        "Description",
        "Meta: _dtb_seo_title",
        "Meta: _dtb_seo_description",
        "Meta: _dtb_seo_focus_kw",
    },
    "media": {"Images", "Meta: _dtb_inherit_parent_image"},
    "shipping_evidence": {"Weight (lbs)", "Length (in)", "Width (in)", "Height (in)"},
}
TRUTHY = {"1", "true", "yes", "y"}


def is_part(row: dict[str, str]) -> bool:
    return (
        (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part"
        or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY
    )


def load_catalog(path: Path) -> tuple[list[str], list[dict[str, str]], dict[str, dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = list(reader.fieldnames or [])
        rows = list(reader)
    return fields, rows, {(row.get("SKU") or "").strip().upper(): row for row in rows}


def load_manifest(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = tuple(reader.fieldnames or ())
        if fields != MANIFEST_FIELDS:
            raise CatalogValidationError(
                f"manifest header mismatch: expected {MANIFEST_FIELDS!r}, found {fields!r}"
            )
        return list(reader)


def validate_compatibility_value(
    owner: dict[str, str],
    proposed: str,
    catalog: dict[str, dict[str, str]],
    allow_empty: bool = False,
) -> None:
    if not is_part(owner):
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility owner is not a part")
    if (owner.get("Type") or "").strip().casefold() == "variation" or (owner.get("Published") or "").strip().casefold() not in TRUTHY:
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility owner must be a published parent product")
    try:
        targets = json.loads(proposed)
    except json.JSONDecodeError as exc:
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility value is not JSON") from exc
    if not isinstance(targets, list) or (not targets and not allow_empty) or any(not isinstance(item, str) or not item.strip() for item in targets):
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility value must be a {'string array' if allow_empty else 'non-empty string array'}")
    normalized = [item.strip().upper() for item in targets]
    if len(normalized) > 50:
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility relationships may contain at most 50 tool SKUs")
    if normalized != sorted(set(normalized)):
        raise CatalogValidationError(f"{owner.get('SKU')}: compatibility targets must be unique and sorted")
    for target in normalized:
        target_row = catalog.get(target)
        if target_row is None:
            raise CatalogValidationError(f"{owner.get('SKU')}: compatibility target {target!r} is absent")
        if is_part(target_row):
            raise CatalogValidationError(f"{owner.get('SKU')}: compatibility target {target!r} is a part")
        if (target_row.get("Type") or "").strip().casefold() == "variation":
            parent_sku = (target_row.get("Parent") or "").strip().upper()
            parent = catalog.get(parent_sku)
            if not parent_sku or parent is None or is_part(parent) or (parent.get("Published") or "").strip().casefold() not in TRUTHY:
                raise CatalogValidationError(f"{owner.get('SKU')}: variation target {target!r} has no valid published tool parent")
        if (target_row.get("Published") or "").strip().casefold() not in TRUTHY:
            raise CatalogValidationError(f"{owner.get('SKU')}: target {target!r} is not published")


def prepare_changes(
    manifest: list[dict[str, str]],
    catalog_sha256: str,
    catalog: dict[str, dict[str, str]],
) -> tuple[list[dict[str, str]], Counter[str]]:
    approved: list[dict[str, str]] = []
    statuses: Counter[str] = Counter()
    seen: set[tuple[str, str]] = set()
    for item in manifest:
        status = (item.get("review_status") or "").strip().casefold()
        statuses[status or "(blank)"] += 1
        if status != "approved":
            continue
        if not (item.get("reviewer") or "").strip() or not (item.get("reviewed_at") or "").strip():
            raise CatalogValidationError("approved manifest rows require reviewer and reviewed_at")
        if (item.get("approval_sha256") or "").strip().casefold() != approval_sha256(item):
            raise CatalogValidationError(f"{item.get('sku')}: approved manifest payload changed after review")
        if (item.get("catalog_sha256") or "").strip().casefold() != catalog_sha256.casefold():
            raise CatalogValidationError(f"{item.get('sku')}: manifest catalog digest is stale")
        sku = (item.get("sku") or "").strip().upper()
        workflow = (item.get("workflow") or "").strip()
        field = (item.get("field") or "").strip()
        key = (sku, field)
        if key in seen:
            raise CatalogValidationError(f"duplicate approved manifest mutation: {sku} {field}")
        seen.add(key)
        row = catalog.get(sku)
        if row is None:
            raise CatalogValidationError(f"approved manifest SKU is absent: {sku}")
        if field not in WORKFLOW_FIELDS.get(workflow, set()):
            raise CatalogValidationError(f"{sku}: field {field!r} is not writable by {workflow!r}")
        if row_sha256(row) != (item.get("row_sha256") or "").strip().casefold():
            raise CatalogValidationError(f"{sku}: row digest is stale")
        if (row.get(field) or "") != (item.get("current_value") or ""):
            raise CatalogValidationError(f"{sku}: current value changed after proposal generation")
        proposed = item.get("proposed_value") or ""
        if proposed == (row.get(field) or ""):
            continue
        if workflow in {"compatibility", "compatibility_remove"}:
            validate_compatibility_value(row, proposed, catalog, allow_empty=workflow == "compatibility_remove")
            try:
                current_targets = json.loads(item.get("current_value") or "[]")
            except json.JSONDecodeError as exc:
                raise CatalogValidationError(f"{sku}: current compatibility value is not valid JSON") from exc
            normalized_current = {str(value).strip().upper() for value in current_targets if isinstance(value, str) and value.strip()} if isinstance(current_targets, list) else set()
            normalized_proposed = {str(value).strip().upper() for value in json.loads(proposed)}
            if workflow == "compatibility" and not normalized_current.issubset(normalized_proposed):
                raise CatalogValidationError(f"{sku}: additive compatibility workflow cannot remove existing targets")
            if workflow == "compatibility_remove" and (not normalized_proposed.issubset(normalized_current) or normalized_proposed == normalized_current):
                raise CatalogValidationError(f"{sku}: removal workflow must remove at least one existing target and cannot add targets")
        approved.append({**item, "sku": sku, "field": field, "proposed_value": proposed})
    return approved, statuses


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--report", type=Path)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    catalog_path = args.catalog.resolve()
    validate_catalog(catalog_path, args.include_gap_audit.resolve())
    fields, rows, catalog = load_catalog(catalog_path)
    digest = file_sha256(catalog_path)
    manifest = load_manifest(args.manifest.resolve())
    changes, statuses = prepare_changes(manifest, digest, catalog)
    report = {
        "schema_version": 1,
        "mode": "apply" if args.apply else "preview",
        "catalog_sha256_before": digest,
        "manifest": str(args.manifest.resolve()),
        "manifest_sha256": file_sha256(args.manifest.resolve()),
        "manifest_rows": len(manifest),
        "review_statuses": dict(sorted(statuses.items())),
        "approved_change_count": len(changes),
        "approved_changes": [
            {
                "sku": change["sku"],
                "field": change["field"],
                "current_value": change["current_value"],
                "proposed_value": change["proposed_value"],
                "approval_sha256": change["approval_sha256"],
                "workflow": change["workflow"],
            }
            for change in changes
        ],
        "applied": False,
        "backup": None,
    }
    if args.apply and changes:
        backup = create_catalog_backup(catalog_path)
        for change in changes:
            catalog[change["sku"]][change["field"]] = change["proposed_value"]
        if any(change["workflow"] == "taxonomy" for change in changes):
            validate_taxonomy_rows(rows)
        write_catalog_atomic(catalog_path, fields, rows)
        validate_catalog(catalog_path, args.include_gap_audit.resolve())
        report["applied"] = True
        report["backup"] = str(backup)
    elif args.apply:
        report["applied"] = True
    report["catalog_sha256_after"] = file_sha256(catalog_path)
    payload = json.dumps(report, indent=2, sort_keys=True) + "\n"
    if args.report:
        args.report.resolve().parent.mkdir(parents=True, exist_ok=True)
        args.report.resolve().write_text(payload, encoding="utf-8")
    print(payload, end="")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error) as exc:
        print(f"ERROR: {exc}")
        raise SystemExit(1)

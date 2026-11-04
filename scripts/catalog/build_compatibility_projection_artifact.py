#!/usr/bin/env python3
"""Build a narrow runtime artifact from an applied, approved catalog manifest."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, approval_sha256, file_sha256
from official_catalog_schema import CatalogValidationError, validate_catalog


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products/launch/official/dtb_official_catalog.include-gaps.json"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-enrichment/compatibility/compatibility-projection-artifact.json"
COMPATIBLE = "Meta: _dtb_compatible_tool_skus"
REPLACEMENT = "Meta: _dtb_replacement_part_for"
ALLOWED_FIELDS = {COMPATIBLE}
TRUTHY = {"1", "true", "yes", "y"}


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = list(reader.fieldnames or [])
        rows = list(reader)
    return fields, rows


def decode_list(raw: str, label: str) -> list[str]:
    if not raw:
        return []
    try:
        values = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise CatalogValidationError(f"{label}: relationship data is not valid JSON") from exc
    if not isinstance(values, list) or any(not isinstance(value, str) or not value.strip() for value in values):
        raise CatalogValidationError(f"{label}: relationship data must be a string array")
    normalized = [value.strip() for value in values]
    if len({value.upper() for value in normalized}) != len(normalized):
        raise CatalogValidationError(f"{label}: relationship data contains duplicate SKUs")
    return normalized


def is_part(row: dict[str, str]) -> bool:
    return (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part" or (row.get("Meta: _dtb_is_parts") or "").strip().casefold() in TRUTHY


def read_manifest(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if tuple(reader.fieldnames or ()) != MANIFEST_FIELDS:
            raise CatalogValidationError("manifest header does not match the reviewed-manifest schema")
        return list(reader)


def build_artifact(catalog_path: Path, manifest_path: Path, apply_report_path: Path) -> dict[str, object]:
    catalog_fields, rows = read_csv(catalog_path)
    if not {"SKU", "Type", "Published", COMPATIBLE, REPLACEMENT}.issubset(catalog_fields):
        raise CatalogValidationError("canonical catalog is missing relationship or identity columns")
    catalog: dict[str, dict[str, str]] = {}
    for row in rows:
        sku = (row.get("SKU") or "").strip().upper()
        if sku:
            if sku in catalog:
                raise CatalogValidationError(f"canonical catalog contains duplicate SKU identity: {sku}")
            catalog[sku] = row

    try:
        apply_report = json.loads(apply_report_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise CatalogValidationError("catalog apply report is missing or invalid JSON") from exc
    current_catalog_sha = file_sha256(catalog_path)
    current_manifest_sha = file_sha256(manifest_path)
    if apply_report.get("mode") != "apply" or apply_report.get("applied") is not True:
        raise CatalogValidationError("projection requires a completed apply-mode catalog report")
    if apply_report.get("catalog_sha256_after", "").casefold() != current_catalog_sha:
        raise CatalogValidationError("canonical catalog changed after its reported apply")
    if apply_report.get("manifest_sha256", "").casefold() != current_manifest_sha:
        raise CatalogValidationError("review manifest differs from the one used by catalog apply")

    manifest = read_manifest(manifest_path)
    by_key: dict[tuple[str, str], dict[str, str]] = {}
    for item in manifest:
        sku = (item.get("sku") or "").strip().upper()
        field = (item.get("field") or "").strip()
        if (item.get("review_status") or "").strip().casefold() == "approved":
            if item.get("approval_sha256", "").casefold() != approval_sha256(item):
                raise CatalogValidationError(f"{sku}: approval payload digest is invalid")
        workflow = item.get("workflow") or ""
        if workflow not in {"compatibility", "compatibility_remove"} or field not in ALLOWED_FIELDS:
            raise CatalogValidationError(f"{sku}: only compatibility SKU fields may be projected")
        by_key[(sku, field)] = item

    applied = apply_report.get("approved_changes")
    if not isinstance(applied, list) or len(applied) != apply_report.get("approved_change_count"):
        raise CatalogValidationError("catalog apply report lacks its approved change inventory")
    seen_changes: set[tuple[str, str]] = set()
    grouped: dict[str, dict[str, object]] = {}
    for change in applied:
        if not isinstance(change, dict):
            raise CatalogValidationError("catalog apply report contains an invalid change row")
        sku = str(change.get("sku", "")).strip().upper()
        field = str(change.get("field", "")).strip()
        workflow = str(change.get("workflow", "")).strip()
        if not sku or field not in ALLOWED_FIELDS:
            raise CatalogValidationError("catalog apply report contains a field outside the fitment projection")
        if workflow not in {"compatibility", "compatibility_remove"} or (sku, field) in seen_changes:
            raise CatalogValidationError(f"{sku}: apply report contains an unsupported or duplicate fitment change")
        seen_changes.add((sku, field))
        item = by_key.get((sku, field))
        if (
            not item
            or change.get("approval_sha256") != item.get("approval_sha256")
            or workflow != item.get("workflow")
            or str(change.get("current_value", "")) != str(item.get("current_value", ""))
            or str(change.get("proposed_value", "")) != str(item.get("proposed_value", ""))
        ):
            raise CatalogValidationError(f"{sku}: apply report cannot be tied to the approved manifest row")
        owner = catalog.get(sku)
        if owner is None or not is_part(owner):
            raise CatalogValidationError(f"{sku}: projection owner is missing or is not a part")
        if (owner.get("Type") or "").strip().casefold() == "variation" or (owner.get("Published") or "").strip().casefold() not in TRUTHY:
            raise CatalogValidationError(f"{sku}: projection owner must be a published parent product")
        expected_source = str(change.get("proposed_value", ""))
        if (owner.get(field) or "") != expected_source:
            raise CatalogValidationError(f"{sku}: applied change does not match the current canonical catalog")
        targets = decode_list(expected_source, f"{sku} {field}")
        if len(targets) > 50:
            raise CatalogValidationError(f"{sku}: compatibility target field exceeds the 50-SKU bound")
        before_targets = decode_list(str(change.get("current_value", "")), f"{sku} current {field}")
        before_value = before_targets
        before_set = {value.upper() for value in before_targets}
        after_set = {value.upper() for value in targets}
        if workflow == "compatibility" and not before_set.issubset(after_set):
            raise CatalogValidationError(f"{sku}: additive projection cannot remove existing fitment targets")
        if workflow == "compatibility_remove" and (not after_set.issubset(before_set) or after_set == before_set):
            raise CatalogValidationError(f"{sku}: reviewed removal must remove at least one target and cannot add targets")
        if workflow == "compatibility_remove":
            legacy_values = decode_list(owner.get(REPLACEMENT) or "", f"{sku} legacy replacement field")
            removed = before_set - after_set
            still_legacy = removed.intersection({value.upper() for value in legacy_values})
            if still_legacy:
                raise CatalogValidationError(f"{sku}: removed target(s) remain in the legacy replacement field: {sorted(still_legacy)}")
        for target_sku in targets:
            target = catalog.get(target_sku.upper())
            if target is None or is_part(target):
                raise CatalogValidationError(f"{sku}: target {target_sku!r} is absent or classified as a part")
            if (target.get("Type") or "").strip().casefold() == "variation":
                raise CatalogValidationError(f"{sku}: target {target_sku!r} is a variation; resolve its parent family")
            if (target.get("Published") or "").strip().casefold() not in TRUTHY:
                raise CatalogValidationError(f"{sku}: target {target_sku!r} is not published")
        group = grouped.setdefault(sku, {"sku": sku, "before": {}, "after": {}, "approvals": []})
        group["before"][field] = before_value  # type: ignore[index]
        group["after"][field] = targets  # type: ignore[index]
        group["approvals"].append({"field": field, "workflow": workflow, "approval_sha256": item["approval_sha256"]})  # type: ignore[union-attr]

    result: dict[str, object] = {
        "schema_version": 1,
        "artifact_type": "dtb_compatibility_projection",
        "catalog_sha256": current_catalog_sha,
        "manifest_sha256": current_manifest_sha,
        "catalog_apply_report_sha256": file_sha256(apply_report_path),
        "rows": [grouped[sku] for sku in sorted(grouped)],
        "excluded_fields": ["SKU", "MPN", "GTIN", "part_number", "Upsells", "Cross-sells", "price", "stock", "taxonomy", "media"],
    }
    return result


def partition_rows(rows: list[dict[str, object]], max_parts: int = 25, max_targets: int = 50) -> list[list[dict[str, object]]]:
    batches: list[list[dict[str, object]]] = []
    pending: list[dict[str, object]] = []
    pending_targets: set[str] = set()
    for row in rows:
        row_targets = {
            str(sku).upper()
            for targets in row["after"].values()
            for sku in targets
        }
        if len(row_targets) > max_targets:
            raise CatalogValidationError(f"{row['sku']}: one part exceeds the {max_targets}-unique-tool batch bound")
        if pending and (len(pending) >= max_parts or len(pending_targets | row_targets) > max_targets):
            batches.append(pending)
            pending = []
            pending_targets = set()
        pending.append(row)
        pending_targets.update(row_targets)
    if pending:
        batches.append(pending)
    return batches


def canonical_sha256(value: object) -> str:
    payload = json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--catalog-apply-report", type=Path, required=True)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    catalog = args.catalog.resolve()
    validate_catalog(catalog, args.include_gap_audit.resolve())
    artifact = build_artifact(catalog, args.manifest.resolve(), args.catalog_apply_report.resolve())
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    batches = partition_rows(artifact["rows"])
    batch_inventory = [
        {"batch_index": index, "part_skus": [str(row["sku"]) for row in batch], "rows_sha256": canonical_sha256(batch)}
        for index, batch in enumerate(batches, start=1)
    ]
    batch_inventory_sha256 = canonical_sha256(batch_inventory)
    release_id_payload = "|".join((str(artifact["catalog_sha256"]), str(artifact["manifest_sha256"]), str(artifact["catalog_apply_report_sha256"]), batch_inventory_sha256))
    release_id = hashlib.sha256(release_id_payload.encode("utf-8")).hexdigest()[:24]

    written = []
    for index, batch in enumerate(batches, start=1):
        batch_artifact = {**artifact, "release_id": release_id, "batch_index": index, "batch_count": len(batches), "batch_inventory": batch_inventory, "batch_inventory_sha256": batch_inventory_sha256, "rows": batch}
        batch_output = output.with_name(f"{output.stem}-batch-{index:03d}{output.suffix}")
        payload = json.dumps(batch_artifact, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        temporary = batch_output.with_name(f".{batch_output.name}.tmp")
        temporary.write_text(payload, encoding="utf-8")
        temporary.replace(batch_output)
        written.append({"artifact": str(batch_output), "sha256": file_sha256(batch_output), "rows": len(batch)})
    release_summary = {"schema_version": 1, "release_id": release_id, "catalog_sha256": artifact["catalog_sha256"], "manifest_sha256": artifact["manifest_sha256"], "catalog_apply_report_sha256": artifact["catalog_apply_report_sha256"], "batch_inventory": batch_inventory, "batch_inventory_sha256": batch_inventory_sha256, "batch_count": len(written), "artifacts": written, "status": "prepared", "catalog_mutated": False}
    release_path = output.with_name(f"{output.stem}-release-{release_id}.json")
    release_path.write_text(json.dumps(release_summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({**release_summary, "release_manifest": str(release_path)}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error) as exc:
        raise SystemExit(f"ERROR: {exc}")

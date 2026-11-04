from __future__ import annotations

import csv
import importlib.util
import json
import sys
from pathlib import Path


CATALOG_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(CATALOG_DIR))


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


manifest_lib = load_module("catalog_change_manifest", CATALOG_DIR / "catalog_change_manifest.py")
builder = load_module("build_compatibility_projection_artifact", CATALOG_DIR / "build_compatibility_projection_artifact.py")


def test_artifact_contains_only_approved_compatibility_values_and_digests(tmp_path: Path) -> None:
    fields = ["SKU", "Type", "Published", "Meta: _dtb_product_kind", "Meta: _dtb_is_parts", "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for", "Upsells", "Cross-sells"]
    part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1", "Meta: _dtb_compatible_tool_skus": '["TOOL-1"]', "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": ""}
    tool = {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": ""}
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows([part, tool])

    current_catalog_digest = manifest_lib.file_sha256(catalog_path)
    source_part = {**part, "Meta: _dtb_compatible_tool_skus": ""}
    item = {
        "catalog_sha256": "before-apply-digest",
        "row_sha256": manifest_lib.row_sha256(source_part),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": "Meta: _dtb_compatible_tool_skus",
        "current_value": "",
        "proposed_value": '["TOOL-1"]',
        "evidence": '[{"type":"exact_schematic_occurrence"}]',
        "review_status": "approved",
        "reviewer": "Reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    manifest_path = tmp_path / "manifest.csv"
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=manifest_lib.MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(item)

    apply_report = {
        "mode": "apply",
        "applied": True,
        "catalog_sha256_after": current_catalog_digest,
        "manifest_sha256": manifest_lib.file_sha256(manifest_path),
        "approved_change_count": 1,
        "approved_changes": [{"sku": "PART-1", "field": item["field"], "workflow": item["workflow"], "current_value": "", "proposed_value": '["TOOL-1"]', "approval_sha256": item["approval_sha256"]}],
    }
    report_path = tmp_path / "apply-report.json"
    report_path.write_text(json.dumps(apply_report), encoding="utf-8")

    artifact = builder.build_artifact(catalog_path, manifest_path, report_path)
    assert len(artifact["rows"]) == 1
    assert artifact["rows"][0]["before"] == {item["field"]: []}
    assert artifact["rows"][0]["after"] == {item["field"]: ["TOOL-1"]}
    assert "Upsells" in artifact["excluded_fields"]
    assert "Cross-sells" in artifact["excluded_fields"]


def test_artifact_refuses_merchandising_changes(tmp_path: Path) -> None:
    fields = ["SKU", "Type", "Published", "Meta: _dtb_product_kind", "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for"]
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerow({"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part"})
    manifest_path = tmp_path / "manifest.csv"
    source = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part"}
    item = {
        "catalog_sha256": "catalog-before",
        "row_sha256": manifest_lib.row_sha256(source),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": "Upsells",
        "current_value": "",
        "proposed_value": "TOOL-1",
        "evidence": "[]",
        "review_status": "approved",
        "reviewer": "Reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=manifest_lib.MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(item)
    report_path = tmp_path / "apply-report.json"
    report_path.write_text(json.dumps({"mode": "apply", "applied": True, "catalog_sha256_after": manifest_lib.file_sha256(catalog_path), "manifest_sha256": manifest_lib.file_sha256(manifest_path), "approved_change_count": 1, "approved_changes": [{"sku": "PART-1", "field": "Upsells", "workflow": "compatibility", "current_value": "", "proposed_value": "TOOL-1", "approval_sha256": item["approval_sha256"]}]}), encoding="utf-8")

    try:
        builder.build_artifact(catalog_path, manifest_path, report_path)
    except builder.CatalogValidationError as error:
        assert "compatibility SKU fields" in str(error)
    else:
        raise AssertionError("Native merchandising fields must not enter a fitment artifact.")


def test_projection_rows_split_by_part_and_unique_target_bounds() -> None:
    rows = [
        {"sku": f"PART-{index:02d}", "after": {"Meta: _dtb_compatible_tool_skus": [f"TOOL-{index:02d}"]}}
        for index in range(26)
    ]
    batches = builder.partition_rows(rows)
    assert [len(batch) for batch in batches] == [25, 1]

    repeated_targets = [
        {"sku": f"PART-{index:02d}", "after": {"Meta: _dtb_compatible_tool_skus": [f"TOOL-{index % 50:02d}"]}}
        for index in range(25)
    ]
    assert [len(batch) for batch in builder.partition_rows(repeated_targets)] == [25]

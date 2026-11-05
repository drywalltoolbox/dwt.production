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


def test_release_id_uses_the_shared_versioned_projection_contract() -> None:
    assert builder.release_id_for_inventory("a" * 64, "b" * 64, "c" * 64, "d" * 64) == "7082784e675fe4bc40da89cc"


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
    historical_catalog_digest = "a" * 64
    source_part = {**part, "Meta: _dtb_compatible_tool_skus": ""}
    item = {
        "catalog_sha256": historical_catalog_digest,
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
        "catalog_sha256_before": historical_catalog_digest,
        "catalog_sha256_after": current_catalog_digest,
        "manifest_sha256": manifest_lib.file_sha256(manifest_path),
        "manifest_archive_sha256": manifest_lib.file_sha256(manifest_path),
        "approved_change_count": 1,
        "approved_changes": [{"sku": "PART-1", "field": item["field"], "workflow": item["workflow"], "current_value": "", "proposed_value": '["TOOL-1"]', "approval_sha256": item["approval_sha256"]}],
    }
    archived_manifest = tmp_path / f"manifest.approved-{apply_report['manifest_sha256'][:16]}.csv"
    archived_manifest.write_bytes(manifest_path.read_bytes())
    apply_report["manifest_archive"] = str(archived_manifest)
    report_path = tmp_path / "apply-report.json"
    report_path.write_text(json.dumps(apply_report), encoding="utf-8")

    artifact = builder.build_artifact(catalog_path, manifest_path, report_path)
    assert len(artifact["rows"]) == 1
    assert artifact["rows"][0]["before"] == {item["field"]: []}
    assert artifact["rows"][0]["after"] == {item["field"]: ["TOOL-1"]}
    assert "Upsells" in artifact["excluded_fields"]
    assert "Cross-sells" in artifact["excluded_fields"]


def test_refresh_uses_current_catalog_digest_only_when_approved_source_row_is_unchanged(tmp_path: Path) -> None:
    field = "Meta: _dtb_compatible_tool_skus"
    fields = ["SKU", "Type", "Published", "Name", "Meta: _dtb_product_kind", field, "Meta: _dtb_replacement_part_for", "Upsells", "Cross-sells"]
    part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Name": "Part", "Meta: _dtb_product_kind": "part", field: "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": ""}
    tool = {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Name": "Tool", "Meta: _dtb_product_kind": "tool", field: "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": ""}
    catalog_path = tmp_path / "catalog.csv"

    def write_catalog(rows: list[dict[str, str]]) -> None:
        with catalog_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields)
            writer.writeheader()
            writer.writerows(rows)

    write_catalog([part, tool])
    before_catalog_sha = manifest_lib.file_sha256(catalog_path)
    item = {
        "catalog_sha256": before_catalog_sha,
        "row_sha256": manifest_lib.row_sha256(part),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": field,
        "current_value": "",
        "proposed_value": '["TOOL-1"]',
        "evidence": '[{"type":"manufacturer_document","evidence_role":"supports"}]',
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

    applied_part = {**part, field: item["proposed_value"]}
    write_catalog([applied_part, tool])
    historical_after_sha = manifest_lib.file_sha256(catalog_path)
    apply_report = {
        "mode": "apply",
        "applied": True,
        "catalog_sha256_before": before_catalog_sha,
        "catalog_sha256_after": historical_after_sha,
        "manifest_sha256": manifest_lib.file_sha256(manifest_path),
        "manifest_archive_sha256": manifest_lib.file_sha256(manifest_path),
        "approved_change_count": 1,
        "approved_changes": [{"sku": "PART-1", "field": field, "workflow": "compatibility", "current_value": "", "proposed_value": item["proposed_value"], "approval_sha256": item["approval_sha256"]}],
    }
    archived_manifest = tmp_path / f"manifest.approved-{apply_report['manifest_sha256'][:16]}.csv"
    archived_manifest.write_bytes(manifest_path.read_bytes())
    apply_report["manifest_archive"] = str(archived_manifest)
    report_path = tmp_path / "apply-report.json"
    report_path.write_text(json.dumps(apply_report), encoding="utf-8")

    write_catalog([applied_part, {**tool, "Name": "Tool name enriched later"}])
    refreshed_catalog_sha = manifest_lib.file_sha256(catalog_path)
    manifest_path.write_text("a newer review manifest replaced the working file\n", encoding="utf-8")
    try:
        builder.build_artifact(catalog_path, manifest_path, report_path)
    except builder.CatalogValidationError as error:
        assert "changed after its reported apply" in str(error)
    else:
        raise AssertionError("Normal artifact creation must refuse a stale post-apply catalog digest.")

    artifact = builder.build_artifact(catalog_path, manifest_path, report_path, refresh_approved_source=True)
    assert artifact["catalog_sha256"] == refreshed_catalog_sha
    assert artifact["source_refresh"] == "approved_historical_row"
    assert artifact["rows"][0]["before"] == {field: []}
    assert artifact["rows"][0]["after"] == {field: ["TOOL-1"]}

    write_catalog([{**applied_part, "Name": "Part identity changed after approval"}, {**tool, "Name": "Tool name enriched later"}])
    try:
        builder.build_artifact(catalog_path, manifest_path, report_path, refresh_approved_source=True)
    except builder.CatalogValidationError as error:
        assert "non-relationship fields differ" in str(error)
    else:
        raise AssertionError("Refresh must refuse a product row changed outside the approved relationship field.")


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
        "catalog_sha256": "a" * 64,
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
    report_path.write_text(json.dumps({"mode": "apply", "applied": True, "catalog_sha256_before": "a" * 64, "catalog_sha256_after": manifest_lib.file_sha256(catalog_path), "manifest_sha256": manifest_lib.file_sha256(manifest_path), "approved_change_count": 1, "approved_changes": [{"sku": "PART-1", "field": "Upsells", "workflow": "compatibility", "current_value": "", "proposed_value": "TOOL-1", "approval_sha256": item["approval_sha256"]}]}), encoding="utf-8")

    try:
        builder.build_artifact(catalog_path, manifest_path, report_path)
    except builder.CatalogValidationError as error:
        assert "compatibility SKU fields" in str(error)
    else:
        raise AssertionError("Native merchandising fields must not enter a fitment artifact.")


def test_projection_artifact_rejects_case_only_rewrite_of_existing_target(tmp_path: Path) -> None:
    field = "Meta: _dtb_compatible_tool_skus"
    fields = ["SKU", "Type", "Published", "Meta: _dtb_product_kind", field, "Meta: _dtb_replacement_part_for"]
    before_part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", field: '["tool-1"]', "Meta: _dtb_replacement_part_for": ""}
    after_part = {**before_part, field: '["TOOL-1"]'}
    tool = {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", field: "", "Meta: _dtb_replacement_part_for": ""}
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows([after_part, tool])

    item = {
        "catalog_sha256": "a" * 64,
        "row_sha256": manifest_lib.row_sha256(before_part),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": field,
        "current_value": before_part[field],
        "proposed_value": after_part[field],
        "evidence": "[]",
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
        "catalog_sha256_before": "a" * 64,
        "catalog_sha256_after": manifest_lib.file_sha256(catalog_path),
        "manifest_sha256": manifest_lib.file_sha256(manifest_path),
        "approved_change_count": 1,
        "approved_changes": [{"sku": "PART-1", "field": field, "workflow": "compatibility", "current_value": before_part[field], "proposed_value": after_part[field], "approval_sha256": item["approval_sha256"]}],
    }
    report_path = tmp_path / "apply-report.json"
    report_path.write_text(json.dumps(apply_report), encoding="utf-8")

    try:
        builder.build_artifact(catalog_path, manifest_path, report_path)
    except builder.CatalogValidationError as error:
        assert "cannot remove or rewrite existing fitment target identifiers" in str(error)
    else:
        raise AssertionError("Projection artifacts must not turn a case-only SKU rewrite into an additive edge.")


def test_artifact_target_lookup_rejects_case_only_sku_mismatch() -> None:
    catalog = {"TOOL-1": {"SKU": "TOOL-1", "Type": "simple", "Published": "1"}}
    assert builder.resolve_exact_target(catalog, "PART-1", "TOOL-1") == catalog["TOOL-1"]
    for candidate in ("tool-1", " TOOL-1 "):
        try:
            builder.resolve_exact_target(catalog, "PART-1", candidate)
        except builder.CatalogValidationError as error:
            assert "exact canonical SKU" in str(error)
        else:
            raise AssertionError("Projection artifact preparation must reject non-exact target SKUs.")


def test_exact_variation_part_owner_requires_exact_published_part_parent() -> None:
    parent = {"SKU": "PART-FAMILY", "Type": "variable", "Published": "1", "Meta: _dtb_product_kind": "part"}
    variation = {"SKU": "PART-CHILD", "Type": "variation", "Published": "1", "Parent": "PART-FAMILY"}
    assert builder.is_part_owner(variation, {"PART-FAMILY": parent, "PART-CHILD": variation})
    for invalid_parent in (
        {**parent, "Published": "0"},
        {**parent, "Type": "simple"},
        {**parent, "Meta: _dtb_product_kind": "tool"},
        {**parent, "SKU": "part-family"},
    ):
        assert not builder.is_part_owner(variation, {"PART-FAMILY": invalid_parent, "PART-CHILD": variation})
    try:
        builder.decode_list('[" TOOL-1 "]', "PART-1 compatibility")
    except builder.CatalogValidationError as error:
        assert "surrounding whitespace" in str(error)
    else:
        raise AssertionError("Projection artifact preparation must not silently trim a relationship SKU.")


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


def test_projection_batch_paths_are_scoped_to_release_identity(tmp_path: Path) -> None:
    output = tmp_path / "compatibility-projection-artifact.json"
    first = builder.release_batch_path(output, "a" * 24, 1)
    second = builder.release_batch_path(output, "b" * 24, 1)

    assert first.name == f"compatibility-projection-artifact-release-{'a' * 24}-batch-001.json"
    assert second.name == f"compatibility-projection-artifact-release-{'b' * 24}-batch-001.json"
    assert first != second

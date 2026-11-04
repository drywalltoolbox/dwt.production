from __future__ import annotations

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
applier = load_module("apply_official_catalog_changes", CATALOG_DIR / "apply_official_catalog_changes.py")


def test_approval_hash_binds_proposal_and_evidence_payload() -> None:
    part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""}
    catalog = {"PART-1": part, "TOOL-1": {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"}}
    item = {
        "catalog_sha256": "catalog-digest",
        "row_sha256": manifest_lib.row_sha256(part),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": "Meta: _dtb_compatible_tool_skus",
        "current_value": "",
        "proposed_value": '["TOOL-1"]',
        "evidence": '[{"source":"schematic.pdf"}]',
        "review_status": "approved",
        "reviewer": "Reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    changes, _ = applier.prepare_changes([item], "catalog-digest", catalog)
    assert len(changes) == 1

    changed = dict(item, proposed_value='["TOOL-2"]')
    try:
        applier.prepare_changes([changed], "catalog-digest", catalog)
    except applier.CatalogValidationError as error:
        assert "payload changed after review" in str(error)
    else:
        raise AssertionError("An edited approved proposal must be rejected.")


def test_compatibility_apply_rejects_variation_and_unpublished_targets() -> None:
    owner = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part"}
    for target in (
        {"SKU": "TOOL-VAR", "Type": "variation", "Published": "1"},
        {"SKU": "TOOL-DRAFT", "Type": "simple", "Published": "0"},
    ):
        try:
            applier.validate_compatibility_value(owner, json.dumps([target["SKU"]]), {target["SKU"]: target})
        except applier.CatalogValidationError:
            continue
        raise AssertionError("Variation and unpublished fitment targets must be rejected.")


def test_native_merchandising_fields_are_not_in_any_manifest_workflow() -> None:
    assert all(
        field not in allowed
        for allowed in applier.WORKFLOW_FIELDS.values()
        for field in ("Upsells", "Cross-sells")
    )


def test_explicit_compatibility_removal_is_reviewed_and_cannot_add_targets() -> None:
    field = "Meta: _dtb_compatible_tool_skus"
    part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", field: '["TOOL-1","TOOL-2"]'}
    catalog = {
        "PART-1": part,
        "TOOL-1": {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"},
        "TOOL-2": {"SKU": "TOOL-2", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"},
        "TOOL-3": {"SKU": "TOOL-3", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"},
    }
    item = {
        "catalog_sha256": "catalog-digest",
        "row_sha256": manifest_lib.row_sha256(part),
        "sku": "PART-1",
        "workflow": "compatibility_remove",
        "field": field,
        "current_value": '["TOOL-1","TOOL-2"]',
        "proposed_value": '["TOOL-2"]',
        "evidence": '{"reference":"schematic page 3"}',
        "review_status": "approved",
        "reviewer": "Reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    changes, _ = applier.prepare_changes([item], "catalog-digest", catalog)
    assert len(changes) == 1

    invalid = dict(item, proposed_value='["TOOL-1","TOOL-2","TOOL-3"]')
    invalid["approval_sha256"] = manifest_lib.approval_sha256(invalid)
    try:
        applier.prepare_changes([invalid], "catalog-digest", catalog)
    except applier.CatalogValidationError:
        pass
    else:
        raise AssertionError("A reviewed removal workflow must not add targets.")

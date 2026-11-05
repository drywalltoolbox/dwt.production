from __future__ import annotations

import importlib.util
import csv
import hashlib
import json
import shutil
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
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


def test_catalog_apply_archives_the_exact_approved_manifest_bytes(tmp_path: Path) -> None:
    manifest_path = tmp_path / "review.csv"
    payload = b"catalog_sha256,row_sha256,sku,workflow,field,current_value,proposed_value,evidence,review_status,reviewer,reviewed_at,approval_sha256\r\n"
    digest = hashlib.sha256(payload).hexdigest()
    archive_path = applier.archive_manifest_snapshot(manifest_path, payload, digest)

    assert archive_path.name == f"review.approved-{digest[:16]}.csv"
    assert archive_path.read_bytes() == payload
    manifest_path.write_bytes(b"later review contents")
    assert applier.archive_manifest_snapshot(manifest_path, payload, digest) == archive_path
    assert hashlib.sha256(archive_path.read_bytes()).hexdigest() == digest


def test_apply_report_keeps_manifest_snapshot_usable_after_review_file_is_regenerated(tmp_path: Path) -> None:
    source_catalog = CATALOG_DIR.parents[1] / "products/launch/official/dtb_official_catalog.csv"
    source_gaps = CATALOG_DIR.parents[1] / "products/launch/official/dtb_official_catalog.include-gaps.json"
    catalog_path = tmp_path / "catalog.csv"
    shutil.copyfile(source_catalog, catalog_path)
    fields, rows, catalog = applier.load_catalog(catalog_path)
    part = next(
        row for row in rows
        if applier.is_part(row)
        and (row.get("Published") or "").strip().casefold() in applier.TRUTHY
        and not (row.get("Meta: _dtb_compatible_tool_skus") or "").strip()
    )
    target = next(
        row for row in rows
        if not applier.is_part(row)
        and (row.get("Published") or "").strip().casefold() in applier.TRUTHY
        and (row.get("Type") or "").strip().casefold() != "variation"
    )
    catalog_digest = manifest_lib.file_sha256(catalog_path)
    item = {
        "catalog_sha256": catalog_digest,
        "row_sha256": manifest_lib.row_sha256(part),
        "sku": part["SKU"],
        "workflow": "compatibility",
        "field": "Meta: _dtb_compatible_tool_skus",
        "current_value": part.get("Meta: _dtb_compatible_tool_skus") or "",
        "proposed_value": json.dumps([target["SKU"]], separators=(",", ":")),
        "evidence": '[{"type":"test_fixture"}]',
        "review_status": "approved",
        "reviewer": "Test reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    manifest_path = tmp_path / "review.csv"
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=manifest_lib.MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(item)
    report_path = tmp_path / "apply-report.json"
    result = subprocess.run(
        [
            sys.executable,
            str(CATALOG_DIR / "apply_official_catalog_changes.py"),
            "--catalog", str(catalog_path),
            "--include-gap-audit", str(source_gaps),
            "--manifest", str(manifest_path),
            "--report", str(report_path),
            "--apply",
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    report = json.loads(report_path.read_text(encoding="utf-8"))
    archive_path = Path(report["manifest_archive"])
    assert report["applied"] is True
    assert archive_path.is_file()
    assert hashlib.sha256(archive_path.read_bytes()).hexdigest() == report["manifest_sha256"]
    assert report["manifest_archive_sha256"] == report["manifest_sha256"]

    manifest_path.write_text("working review manifest regenerated\n", encoding="utf-8")
    projection = load_module(
        "build_compatibility_projection_artifact_for_archive_test",
        CATALOG_DIR / "build_compatibility_projection_artifact.py",
    )
    artifact = projection.build_artifact(catalog_path, manifest_path, report_path)
    assert artifact["rows"][0]["sku"] == part["SKU"]
    assert artifact["rows"][0]["after"][item["field"]] == [target["SKU"]]


def test_concurrent_manifest_approvals_use_digest_guard_and_do_not_lose_updates(tmp_path: Path) -> None:
    manifest_path = tmp_path / "review.csv"
    row = {
        "catalog_sha256": "a" * 64,
        "row_sha256": "b" * 64,
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": "Meta: _dtb_compatible_tool_skus",
        "current_value": "",
        "proposed_value": '["TOOL-1"]',
        "evidence": '[{"type":"manufacturer_document","evidence_role":"supports"}]',
        "review_status": "needs_review",
        "reviewer": "",
        "reviewed_at": "",
        "approval_sha256": "",
    }
    second_row = {**row, "sku": "PART-2", "row_sha256": "c" * 64, "proposed_value": '["TOOL-2"]'}
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=manifest_lib.MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerows((row, second_row))
    digest = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
    command = [
        sys.executable,
        str(CATALOG_DIR / "approve_catalog_change_manifest_row.py"),
        "--manifest",
        str(manifest_path),
        "--expected-manifest-sha256",
        digest,
        "--reviewed-at",
        "2026-11-04T12:00:00Z",
    ]

    def approve(reviewer: str, sku: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [*command, "--reviewer", reviewer, "--sku", sku],
            capture_output=True,
            text=True,
            check=False,
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda pair: approve(*pair), (("Reviewer A", "PART-1"), ("Reviewer B", "PART-2"))))
    assert sum(result.returncode == 0 for result in results) == 1
    assert sum(result.returncode != 0 and "manifest changed since review" in result.stderr for result in results) == 1
    with manifest_path.open("r", encoding="utf-8", newline="") as handle:
        updated_rows = list(csv.DictReader(handle))
    approved_rows = [item for item in updated_rows if item["review_status"] == "approved"]
    pending_rows = [item for item in updated_rows if item["review_status"] == "needs_review"]
    assert len(approved_rows) == len(pending_rows) == 1
    approved = approved_rows[0]
    assert approved["reviewer"] in {"Reviewer A", "Reviewer B"}
    assert approved["approval_sha256"] == manifest_lib.approval_sha256(approved)


def test_compatibility_apply_accepts_exact_variation_with_published_tool_parent() -> None:
    owner = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part"}
    variation = {"SKU": "TOOL-VAR", "Type": "variation", "Published": "1", "Parent": "TOOL-PARENT"}
    parent = {"SKU": "TOOL-PARENT", "Type": "variable", "Published": "1", "Meta: _dtb_product_kind": "tool"}
    applier.validate_compatibility_value(owner, json.dumps([variation["SKU"]]), {"TOOL-VAR": variation, "TOOL-PARENT": parent})
    for target, catalog in (
        ({"SKU": "TOOL-VAR", "Type": "variation", "Published": "1"}, {"TOOL-VAR": {"SKU": "TOOL-VAR", "Type": "variation", "Published": "1"}}),
        ({"SKU": "TOOL-DRAFT", "Type": "simple", "Published": "0"}, {"TOOL-DRAFT": {"SKU": "TOOL-DRAFT", "Type": "simple", "Published": "0"}}),
    ):
        try:
            applier.validate_compatibility_value(owner, json.dumps([target["SKU"]]), catalog)
        except applier.CatalogValidationError:
            continue
        raise AssertionError("Variation and unpublished fitment targets must be rejected.")

    case_mismatched_parent = {
        "TOOL-VAR": {"SKU": "TOOL-VAR", "Type": "variation", "Published": "1", "Parent": "tool-parent"},
        "TOOL-PARENT": parent,
    }
    try:
        applier.validate_compatibility_value(owner, json.dumps([variation["SKU"]]), case_mismatched_parent)
    except applier.CatalogValidationError as error:
        assert "valid published tool parent" in str(error)
    else:
        raise AssertionError("A variation parent reference must preserve its exact canonical SKU.")


def test_compatibility_apply_rejects_case_only_target_sku_mismatch() -> None:
    owner = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part"}
    catalog = {"TOOL-1": {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"}}
    for candidate in ("tool-1", " TOOL-1 "):
        try:
            applier.validate_compatibility_value(owner, json.dumps([candidate]), catalog)
        except applier.CatalogValidationError as error:
            assert "exact canonical SKU" in str(error)
        else:
            raise AssertionError("A non-exact target SKU must be rejected before catalog apply.")


def test_compatibility_apply_rejects_case_only_rewrite_of_existing_target() -> None:
    field = "Meta: _dtb_compatible_tool_skus"
    part = {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", field: '["tool-1"]'}
    catalog = {
        "PART-1": part,
        "TOOL-1": {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool"},
    }
    item = {
        "catalog_sha256": "catalog-digest",
        "row_sha256": manifest_lib.row_sha256(part),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": field,
        "current_value": '["tool-1"]',
        "proposed_value": '["TOOL-1"]',
        "evidence": "[]",
        "review_status": "approved",
        "reviewer": "Reviewer",
        "reviewed_at": "2026-11-04T12:00:00Z",
        "approval_sha256": "",
    }
    item["approval_sha256"] = manifest_lib.approval_sha256(item)
    try:
        applier.prepare_changes([item], "catalog-digest", catalog)
    except applier.CatalogValidationError as error:
        assert "cannot remove or rewrite existing target identifiers" in str(error)
    else:
        raise AssertionError("Additive fitment approval must not rewrite an existing SKU's spelling.")


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

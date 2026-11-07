from __future__ import annotations

import csv
import importlib.util
import sys
from pathlib import Path


CATALOG_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(CATALOG_DIR))
spec = importlib.util.spec_from_file_location(
    "audit_product_relationship_coverage",
    CATALOG_DIR / "audit_product_relationship_coverage.py",
)
assert spec and spec.loader
audit_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit_module)


def test_canonical_audit_reports_raw_target_sku_identity_issues(tmp_path: Path) -> None:
    fields = [
        "SKU",
        "Type",
        "Published",
        "Visibility in catalog",
        "Brands",
        "Meta: _dtb_product_kind",
        "Meta: _dtb_is_parts",
        "Meta: _dtb_compatible_tool_skus",
        "Meta: _dtb_replacement_part_for",
        "Cross-sells",
    ]
    rows = [
        {
            "SKU": "PART-1",
            "Type": "simple",
            "Published": "1",
            "Visibility in catalog": "hidden",
            "Brands": "Test",
            "Meta: _dtb_product_kind": "part",
            "Meta: _dtb_is_parts": "1",
            "Meta: _dtb_compatible_tool_skus": '["tool-1", " TOOL-1 ", "TOOL-2"]',
            "Meta: _dtb_replacement_part_for": '[""]',
            "Cross-sells": "",
        },
        {
            "SKU": "TOOL-1",
            "Type": "simple",
            "Published": "1",
            "Visibility in catalog": "visible",
            "Brands": "Test",
            "Meta: _dtb_product_kind": "tool",
            "Meta: _dtb_is_parts": "0",
            "Meta: _dtb_compatible_tool_skus": "",
            "Meta: _dtb_replacement_part_for": "",
            "Cross-sells": "",
        },
        {
            "SKU": "TOOL-2",
            "Type": "simple",
            "Published": "1",
            "Visibility in catalog": "catalog",
            "Brands": "Test",
            "Meta: _dtb_product_kind": "tool",
            "Meta: _dtb_is_parts": "0",
            "Meta: _dtb_compatible_tool_skus": "",
            "Meta: _dtb_replacement_part_for": "",
            "Cross-sells": "",
        },
        {
            "SKU": "PART-2",
            "Type": "simple",
            "Published": "1",
            "Visibility in catalog": "visible",
            "Brands": "Test",
            "Meta: _dtb_product_kind": "part",
            "Meta: _dtb_is_parts": "1",
            "Meta: _dtb_compatible_tool_skus": "",
            "Meta: _dtb_replacement_part_for": "",
            "Cross-sells": "",
        },
    ]
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)

    unresolved_path = tmp_path / "unresolved.csv"
    unresolved_fields = [
        "raw_part_identifier", "source_sku", "source_part_id", "source_display_id",
        "source_part_number", "source_file", "raw_schematic_id", "status",
    ]
    with unresolved_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=unresolved_fields)
        writer.writeheader()
        writer.writerows([
            {"raw_part_identifier": "PART-2", "source_file": "Asgard/Schematics/tool-a.json", "raw_schematic_id": "unresolved-a", "status": "unresolved_schematic_identity"},
            {"raw_part_identifier": "PART-2", "source_file": "Asgard/Schematics/tool-b.json", "raw_schematic_id": "unresolved-b", "status": "unresolved_schematic_identity"},
            {"raw_part_identifier": "PART-2", "source_file": "Other/tool-c.json", "raw_schematic_id": "other-c", "status": "part_identifier_not_in_catalog"},
        ])

    report = audit_module.audit(
        catalog_path,
        tmp_path / "missing-proposals.json",
        tmp_path / "missing-schematic-manifest.csv",
        tmp_path / "missing-documented-manifest.csv",
        unresolved_path,
    )
    fitment = report["canonical_fitment"]
    assert report["schema_version"] == 13
    assert report["schematic_proposals"]["cross_brand_identity_candidates"] == 0
    assert fitment["noncanonical_target_sku_references"] == 2
    assert fitment["target_sku_whitespace_references"] == 1
    assert fitment["blank_target_references"] == 1
    assert fitment["max_published_part_owner_fanout_for_exact_target_sku"] == 1
    assert fitment["exact_target_skus_at_max_owner_fanout"] == ["TOOL-2"]
    assert fitment["malformed_relationship_parts"] == 1
    assert fitment["parts_by_catalog_visibility"] == {
        "hidden": {"published_parts": 1, "published_part_variations": 0, "with_structured_fitment": 1, "without_structured_fitment": 0},
        "visible": {"published_parts": 1, "published_part_variations": 0, "with_structured_fitment": 0, "without_structured_fitment": 1},
    }
    assert report["coverage_pipeline"]["missing_fitment_parts_without_current_review_candidate"] == 1
    assert report["coverage_pipeline"]["uncovered_part_skus"] == ["PART-2"]
    assert report["coverage_pipeline"]["uncovered_parts_with_exact_source_identity_but_unresolved_schematic"] == [{
        "part_sku": "PART-2",
        "occurrence_count": 2,
        "source_files": ["Asgard/Schematics/tool-a.json", "Asgard/Schematics/tool-b.json"],
        "schematic_ids": ["unresolved-a", "unresolved-b"],
        "interpretation": "The exact part SKU appears in schematic source rows, but the tool schematic identity is unresolved; no fitment target is proposed.",
    }]


def test_coverage_pipeline_matches_missing_parts_to_current_review_candidates(tmp_path: Path) -> None:
    from catalog_change_manifest import MANIFEST_FIELDS, file_sha256, row_sha256

    fields = [
        "SKU", "Type", "Published", "Visibility in catalog", "Brands",
        "Meta: _dtb_product_kind", "Meta: _dtb_is_parts",
        "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for",
        "Cross-sells",
    ]
    part_one = {
        "SKU": "PART-1", "Type": "simple", "Published": "1", "Visibility in catalog": "visible",
        "Brands": "Test", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1",
        "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": "",
    }
    part_two = {**part_one, "SKU": "PART-2"}
    tool = {**part_one, "SKU": "TOOL-1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0"}
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows((part_one, part_two, tool))

    manifest_path = tmp_path / "review.csv"
    review_row = {
        "catalog_sha256": file_sha256(catalog_path),
        "row_sha256": row_sha256(part_one),
        "sku": "PART-1",
        "workflow": "compatibility",
        "field": "Meta: _dtb_compatible_tool_skus",
        "current_value": "",
        "proposed_value": '["TOOL-1"]',
        "evidence": '[{"type":"exact_schematic_occurrence"}]',
        "review_status": "needs_review",
        "reviewer": "",
        "reviewed_at": "",
        "approval_sha256": "",
    }
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(review_row)

    report = audit_module.audit(
        catalog_path,
        tmp_path / "missing-proposals.json",
        manifest_path,
        tmp_path / "missing-documented-manifest.csv",
    )
    pipeline = report["coverage_pipeline"]
    assert pipeline["published_parts_missing_fitment"] == 2
    assert pipeline["missing_fitment_parts_with_current_review_candidate"] == 1
    assert pipeline["missing_fitment_parts_without_current_review_candidate"] == 1
    assert pipeline["uncovered_part_skus"] == ["PART-2"]
    assert pipeline["candidate_owner_count_by_manifest"] == {"documented_evidence": 0, "schematic": 1}

    stale_row = {**review_row, "catalog_sha256": "0" * 64}
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerow(stale_row)
    stale_report = audit_module.audit(
        catalog_path,
        tmp_path / "missing-proposals.json",
        manifest_path,
        tmp_path / "missing-documented-manifest.csv",
    )
    stale_pipeline = stale_report["coverage_pipeline"]
    assert stale_report["review_manifests"]["schematic"]["stale_rows"] == 1
    assert stale_pipeline["candidate_owner_count_by_manifest"]["schematic"] == 0
    assert stale_pipeline["missing_fitment_parts_without_current_review_candidate"] == 2
    assert stale_pipeline["uncovered_part_skus"] == ["PART-1", "PART-2"]


def test_variation_part_owners_are_counted_separately_and_keep_exact_identity(tmp_path: Path) -> None:
    fields = ["SKU", "Type", "Published", "Parent", "Visibility in catalog", "Brands", "Meta: _dtb_product_kind", "Meta: _dtb_is_parts", "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for", "Cross-sells"]
    rows = [
        {"SKU": "PART-FAMILY", "Type": "variable", "Published": "1", "Visibility in catalog": "visible", "Brands": "Test", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": ""},
        {"SKU": "PART-CHILD-A", "Type": "variation", "Published": "1", "Parent": "PART-FAMILY", "Visibility in catalog": "visible", "Brands": "Test", "Meta: _dtb_product_kind": "", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": ""},
        {"SKU": "PART-CHILD-B", "Type": "variation", "Published": "1", "Parent": "PART-FAMILY", "Visibility in catalog": "hidden", "Brands": "Test", "Meta: _dtb_product_kind": "", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": '["TOOL-1"]', "Meta: _dtb_replacement_part_for": "", "Cross-sells": ""},
        {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Brands": "Test", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": ""},
    ]
    catalog_path = tmp_path / "catalog.csv"
    with catalog_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    report = audit_module.audit(catalog_path, tmp_path / "missing-proposals.json", tmp_path / "missing-schematic.csv", tmp_path / "missing-documented.csv")
    fitment = report["canonical_fitment"]
    assert report["denominators"]["published_part_variations_with_exact_published_part_parent"] == 2
    assert fitment["part_relationship_owners_by_scope"] == {
        "parent": {"with_structured_fitment": 0, "without_structured_fitment": 1},
        "variation": {"with_structured_fitment": 1, "without_structured_fitment": 1},
    }
    assert report["coverage_pipeline"]["uncovered_part_skus"] == ["PART-CHILD-A", "PART-FAMILY"]

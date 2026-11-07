import csv
from pathlib import Path

import pytest

from scripts.catalog.export_product_relationship_tree import build, sku_list


CATALOG_FIELDS = [
    "SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent",
    "Meta: _dtb_product_kind", "Meta: _dtb_is_parts",
    "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for",
    "Upsells", "Cross-sells", "Meta: _dtb_brand_label",
    "Meta: _includes_0_sku", "Meta: _includes_0_name",
]


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def test_parses_native_woocommerce_comma_separated_relationship_skus() -> None:
    assert sku_list("PART-A, PART-B") == ["PART-A", "PART-B"]


def test_exports_distinct_exact_edges_and_complete_entity_coverage(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    rows = [
        {"SKU": "PART-1", "Name": "Part", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1", "Meta: _dtb_compatible_tool_skus": '["TOOL-1"]', "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "TOOL-1", "Name": "Tool", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "KIT-1", "Name": "Kit", "Type": "variable", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "toolset", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "KIT-1-A", "Name": "Kit option A", "Type": "variation", "Published": "1", "Visibility in catalog": "visible", "Parent": "KIT-1", "Meta: _dtb_product_kind": "toolset", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "TOOL-1", "Meta: _includes_0_name": "1x Tool"},
        {"SKU": "UNMAPPED", "Name": "No relationships", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Upsells": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
    ]
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used", "TOOL-1"], [
        {"PartNumber": "PART-1", "Description": "Part", "Number of Tools Where Part Is Used": "1", "TOOL-1": "n"},
    ])

    edges, coverage, family_memberships, summary = build(catalog, tapetech)
    assert [(edge["relationship_type"], edge["source_sku"], edge["target_sku"]) for edge in edges] == [
        ("kit_contains", "KIT-1-A", "TOOL-1"),
        ("part_fits_tool", "PART-1", "TOOL-1"),
    ]
    fitment = edges[1]
    assert fitment["evidence_status"] == "manufacturer_matrix_exact_match"
    assert "TapeTech" in fitment["evidence_source"]
    assert len(coverage) == len(rows)
    assert next(row for row in family_memberships if row["sku"] == "PART-1")["family_metadata_review_state"] == "no_family_metadata"
    assert next(row for row in coverage if row["sku"] == "UNMAPPED")["coverage_state"] == "no_relationship_recorded"
    assert summary["runtime_projection_audited"] is False


def test_rejects_declared_tapetech_count_that_disagrees_with_markers(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    rows = [{field: "" for field in CATALOG_FIELDS}]
    rows[0].update({"SKU": "PART-1", "Name": "Part", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1"})
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used", "TOOL-1"], [
        {"PartNumber": "PART-1", "Description": "Part", "Number of Tools Where Part Is Used": "2", "TOOL-1": "n"},
    ])
    with pytest.raises(ValueError, match="disagrees"):
        build(catalog, tapetech)


def test_rejects_duplicate_canonical_sku(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    row = {field: "" for field in CATALOG_FIELDS}
    row.update({"SKU": "DUP", "Name": "Item", "Type": "simple", "Published": "1", "Visibility in catalog": "visible"})
    write_csv(catalog, CATALOG_FIELDS, [row, row.copy()])
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used", "TOOL-1"], [])
    with pytest.raises(ValueError, match="duplicate catalog SKU"):
        build(catalog, tapetech)

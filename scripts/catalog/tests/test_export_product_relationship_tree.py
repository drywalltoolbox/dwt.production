import csv
from pathlib import Path

import pytest

from scripts.catalog.export_product_relationship_tree import build, sku_list


CATALOG_FIELDS = [
    "SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent",
    "Meta: _dtb_product_kind", "Meta: _dtb_is_parts",
    "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_replacement_part_for",
    "Cross-sells", "Meta: _dtb_brand_label",
    "Meta: _dtb_brand_key", "Meta: _dtb_brand_label", "Meta: product_family", "Meta: series", "Meta: model", "Categories",
    "Meta: _dtb_display_category_key",
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
        {"SKU": "PART-1", "Name": "Part", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1", "Meta: _dtb_compatible_tool_skus": '["TOOL-1"]', "Meta: _dtb_replacement_part_for": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "TOOL-1", "Name": "Tool", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "KIT-1", "Name": "Kit", "Type": "variable", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "toolset", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
        {"SKU": "KIT-1-A", "Name": "Kit option A", "Type": "variation", "Published": "1", "Visibility in catalog": "visible", "Parent": "KIT-1", "Meta: _dtb_product_kind": "toolset", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "TOOL-1", "Meta: _includes_0_name": "1x Tool"},
        {"SKU": "UNMAPPED", "Name": "No relationships", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_is_parts": "0", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_replacement_part_for": "", "Cross-sells": "", "Meta: _dtb_brand_label": "TapeTech", "Meta: _includes_0_sku": "", "Meta: _includes_0_name": ""},
    ]
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used", "TOOL-1"], [
        {"PartNumber": "PART-1", "Description": "Part", "Number of Tools Where Part Is Used": "1", "TOOL-1": "n"},
    ])

    edges, coverage, family_memberships, part_family_affiliations, summary = build(catalog, tapetech)
    assert [(edge["relationship_type"], edge["source_sku"], edge["target_sku"]) for edge in edges] == [
        ("kit_contains", "KIT-1-A", "TOOL-1"),
        ("variation_of", "KIT-1-A", "KIT-1"),
        ("part_fits_tool", "PART-1", "TOOL-1"),
    ]
    fitment = edges[2]
    assert fitment["evidence_status"] == "manufacturer_matrix_exact_match"
    assert "TapeTech" in fitment["evidence_source"]
    assert len(coverage) == len(rows)
    assert next(row for row in family_memberships if row["sku"] == "PART-1")["family_metadata_review_state"] == "no_family_metadata"
    assert next(row for row in coverage if row["sku"] == "UNMAPPED")["coverage_state"] == "no_relationship_recorded"
    assert next(row for row in coverage if row["sku"] == "KIT-1-A")["variation_parent_count"] == "1"
    assert next(row for row in coverage if row["sku"] == "KIT-1")["variation_child_count"] == "1"
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


def test_promotes_exact_manufacturer_callout_evidence_on_recorded_fitment(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    evidence = tmp_path / "manufacturer-evidence.csv"
    rows = []
    for sku, kind, compatible in (("PART-1", "part", '["TOOL-1"]'), ("TOOL-1", "tool", "")):
        row = {field: "" for field in CATALOG_FIELDS}
        row.update({
            "SKU": sku, "Name": sku, "Type": "simple", "Published": "1",
            "Visibility in catalog": "visible", "Meta: _dtb_product_kind": kind,
            "Meta: _dtb_is_parts": "1" if kind == "part" else "0",
            "Meta: _dtb_compatible_tool_skus": compatible,
        })
        rows.append(row)
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])
    write_csv(evidence, ["part_sku", "target_tool_sku", "source_url", "source_title", "source_locator", "evidence_note"], [{
        "part_sku": "PART-1", "target_tool_sku": "TOOL-1",
        "source_url": "https://manufacturer.example/schematic.pdf",
        "source_title": "Tool 1 manufacturer schematic", "source_locator": "Page 1, callout PART-1",
        "evidence_note": "The manufacturer schematic names TOOL-1 and prints exact part callout PART-1.",
    }])

    edges, _coverage, _families, _affiliations, summary = build(catalog, tapetech, evidence)
    fitment = next(edge for edge in edges if edge["relationship_type"] == "part_fits_tool")
    assert fitment["evidence_status"] == "manufacturer_document_exact_callout"
    assert fitment["evidence_source"] == "Tool 1 manufacturer schematic"
    assert "callout PART-1" in fitment["evidence_ref"]
    assert summary["manufacturer_evidence"]["verified_exact_part_tool_pairs"] == 1


def test_rejects_manufacturer_evidence_without_a_canonical_fitment_edge(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    evidence = tmp_path / "manufacturer-evidence.csv"
    rows = []
    for sku, kind in (("PART-1", "part"), ("TOOL-1", "tool")):
        row = {field: "" for field in CATALOG_FIELDS}
        row.update({
            "SKU": sku, "Name": sku, "Type": "simple", "Published": "1",
            "Visibility in catalog": "visible", "Meta: _dtb_product_kind": kind,
            "Meta: _dtb_is_parts": "1" if kind == "part" else "0",
        })
        rows.append(row)
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])
    write_csv(evidence, ["part_sku", "target_tool_sku", "source_url", "source_title", "source_locator", "evidence_note"], [{
        "part_sku": "PART-1", "target_tool_sku": "TOOL-1",
        "source_url": "https://manufacturer.example/schematic.pdf",
        "source_title": "Tool 1 manufacturer schematic", "source_locator": "Page 1, callout PART-1",
        "evidence_note": "Exact callout.",
    }])

    with pytest.raises(ValueError, match="no matching canonical fitment edge"):
        build(catalog, tapetech, evidence)


def test_rejects_duplicate_canonical_sku(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    row = {field: "" for field in CATALOG_FIELDS}
    row.update({"SKU": "DUP", "Name": "Item", "Type": "simple", "Published": "1", "Visibility in catalog": "visible"})
    write_csv(catalog, CATALOG_FIELDS, [row, row.copy()])
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used", "TOOL-1"], [])
    with pytest.raises(ValueError, match="duplicate catalog SKU"):
        build(catalog, tapetech)


def test_exports_broad_same_product_family_for_same_kind_tools_and_toolsets(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    rows = []
    for sku, kind, category in (
        ("TOOL-A", "tool", "Automatic Taping Tools > Flat Boxes"),
        ("TOOL-B", "tool", "Automatic Taping Tools > Handles"),
        ("PART-A", "part", "Replacement Parts"),
    ):
        row = {field: "" for field in CATALOG_FIELDS}
        row.update({
            "SKU": sku, "Name": sku, "Type": "simple", "Published": "1",
            "Visibility in catalog": "visible", "Meta: _dtb_product_kind": kind,
            "Meta: _dtb_brand_key": "brand-a", "Meta: product_family": "Family A",
            "Meta: series": "Series A" if sku == "TOOL-A" else "Series B", "Categories": category,
        })
        rows.append(row)
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])

    edges, coverage, _families, _affiliations, summary = build(catalog, tapetech)
    family_edges = [edge for edge in edges if edge["relationship_type"] == "same_product_family"]
    assert {(edge["source_sku"], edge["target_sku"]) for edge in family_edges} == {
        ("TOOL-A", "TOOL-B"), ("TOOL-B", "TOOL-A"),
    }
    assert all("not fitment" in edge["evidence_note"].lower() for edge in family_edges)
    assert next(row for row in coverage if row["sku"] == "TOOL-A")["family_related_count"] == "1"
    assert summary["same_product_family"]["groups"] == 1


def test_derives_part_family_affiliation_without_writing_part_family_metadata(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    rows = []
    tool = {field: "" for field in CATALOG_FIELDS}
    tool.update({
        "SKU": "TOOL-A", "Name": "Tool A", "Type": "simple", "Published": "1",
        "Meta: _dtb_product_kind": "tool", "Meta: _dtb_brand_key": "brand-a",
        "Meta: _dtb_brand_label": "Brand A", "Meta: product_family": "Family A",
        "Meta: series": "Series A", "Meta: model": "Model A",
    })
    part = {field: "" for field in CATALOG_FIELDS}
    part.update({
        "SKU": "PART-A", "Name": "Part A", "Type": "simple", "Published": "1",
        "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["TOOL-A"]',
    })
    rows.extend([tool, part])
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])

    _edges, _coverage, families, affiliations, summary = build(catalog, tapetech)
    assert len(affiliations) == 1
    affiliation = affiliations[0]
    assert affiliation["part_sku"] == "PART-A"
    assert affiliation["target_brand_key"] == "brand-a"
    assert affiliation["product_family"] == "Family A"
    assert affiliation["series_subfamily"] == "Series A"
    assert affiliation["supporting_tool_skus_json"] == '["TOOL-A"]'
    assert "does not assign product_family or series to the part" in affiliation["evidence_note"]
    assert next(row for row in families if row["sku"] == "PART-A")["product_family"] == ""
    assert summary["part_family_affiliations"]["rows"] == 1


def test_exports_same_category_discovery_separately_from_family_and_fitment(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    rows = []
    for sku, kind, brand, category, published, visibility in (
        ("TOOL-A", "tool", "brand-a", "flat_boxes", "1", "visible"),
        ("TOOL-B", "tool", "brand-a", "flat_boxes", "1", "visible"),
        ("TOOL-C", "tool", "brand-b", "flat_boxes", "1", "visible"),
        ("TOOL-HIDDEN", "tool", "brand-a", "flat_boxes", "1", "hidden"),
        ("PART-A", "part", "brand-a", "flat_boxes", "1", "visible"),
    ):
        row = {field: "" for field in CATALOG_FIELDS}
        row.update({
            "SKU": sku, "Name": sku, "Type": "simple", "Published": published,
            "Visibility in catalog": visibility, "Meta: _dtb_product_kind": kind,
            "Meta: _dtb_brand_key": brand, "Meta: _dtb_display_category_key": category,
        })
        rows.append(row)
    write_csv(catalog, CATALOG_FIELDS, rows)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])

    edges, coverage, _families, _affiliations, summary = build(catalog, tapetech)
    category_edges = [edge for edge in edges if edge["relationship_type"] == "same_catalog_category"]
    assert {(edge["source_sku"], edge["target_sku"]) for edge in category_edges} == {
        ("TOOL-A", "TOOL-B"), ("TOOL-B", "TOOL-A"),
    }
    assert all("discovery only" in edge["evidence_note"].lower() for edge in category_edges)
    assert next(row for row in coverage if row["sku"] == "TOOL-A")["category_related_count"] == "1"
    assert summary["same_catalog_category"]["directed_edges"] == 2

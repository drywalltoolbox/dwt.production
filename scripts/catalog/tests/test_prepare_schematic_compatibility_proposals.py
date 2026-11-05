from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parents[1]
if str(CATALOG_DIR) not in sys.path:
    sys.path.insert(0, str(CATALOG_DIR))

from prepare_schematic_compatibility_proposals import (
    build_tool_index,
    build_cross_brand_identity_candidates,
    canonical_schematic_id,
    canonical_tool_sku,
    prepare_proposals,
    resolve_part_sku,
)


def row(
    sku: str,
    *,
    name: str = "Product",
    type_: str = "simple",
    product_kind: str = "",
    is_part: str = "0",
    published: str = "1",
    parent: str = "",
    compatible: str = "",
    replacement: str = "",
) -> dict[str, str]:
    return {
        "SKU": sku,
        "Name": name,
        "Type": type_,
        "Published": published,
        "Meta: _dtb_product_kind": product_kind,
        "Meta: _dtb_is_parts": is_part,
        "Meta: _dtb_parent_product_sku": parent,
        "Meta: _dtb_compatible_tool_skus": compatible,
        "Meta: _dtb_replacement_part_for": replacement,
    }


def write_master(path: Path, rows: list[dict[str, str]]) -> None:
    fields = ["brand", "schematic_id", "product_sku", "schematic_tool", "schematic_tool_path", "sku", "part_id", "display_id", "part_number", "part_name", "source_file_from_brands"]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def test_variation_tool_keeps_exact_sku_when_parent_is_valid() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
    }
    assert canonical_tool_sku("TOOL-10", catalog) == "TOOL-10"


def test_legacy_part_flag_is_never_a_tool_target() -> None:
    catalog = {"PART-1": row("PART-1", is_part="1")}
    assert canonical_tool_sku("PART-1", catalog) is None


def test_product_kind_part_is_never_a_tool_target() -> None:
    catalog = {"PART-1": row("PART-1", product_kind="part")}
    assert canonical_tool_sku("PART-1", catalog) is None


def test_tool_index_preserves_exact_variation_targets() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
        "TOOL-12": row("TOOL-12", type_="variation", parent="TOOL-PARENT"),
    }
    links = {
        "TOOL-PARENT": {"schematicId": "schematic-a"},
        "TOOL-10": {"schematicId": "schematic-a"},
        "TOOL-12": {"schematicId": "schematic-a"},
    }
    assert build_tool_index(links, catalog) == {"schematic-a": ["TOOL-10", "TOOL-12"]}


def test_variable_parent_is_kept_when_no_exact_child_variation_is_linked() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
    }
    links = {"TOOL-PARENT": {"schematicId": "schematic-a"}}
    assert build_tool_index(links, catalog) == {"schematic-a": ["TOOL-PARENT"]}


def test_unpublished_or_invalid_parent_variation_is_not_a_tool_target() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable", published="0"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
        "ORPHAN-10": row("ORPHAN-10", type_="variation", parent="MISSING-PARENT"),
    }
    assert canonical_tool_sku("TOOL-10", catalog) is None
    assert canonical_tool_sku("ORPHAN-10", catalog) is None


def test_verbose_master_schematic_resolves_to_known_canonical_id() -> None:
    aliases = {"columbiaangleheadsch": "columbia-angle-head"}
    assert canonical_schematic_id(
        "columbia-angle-head-sch", aliases, {"columbia-angle-head"}
    ) == "columbia-angle-head"


def test_part_resolution_uses_part_columns_not_product_sku() -> None:
    catalog = {
        "TOOL-A": row("TOOL-A", product_kind="drywall-finishing-tool"),
        "PART-1": row("PART-1", product_kind="part"),
    }
    source = {
        "product_sku": "TOOL-A",
        "sku": "PART-1",
        "part_number": "PART-1",
    }
    assert resolve_part_sku(source, catalog) == ("PART-1", "exact_part_identifier")


def test_exact_shared_schematic_produces_single_tool_proposal_from_product_kind(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(
        master,
        [
            {
                "brand": "Columbia Taping Tools",
                "schematic_id": "columbia-hydra-handle",
                "product_sku": "HYDRA",
                "sku": "HH19",
                "part_number": "HH19",
                "source_file_from_brands": "Columbia/Handles/Hydra.pdf",
            }
        ],
    )
    catalog = {
        "HH19": row("HH19", name="Cap", product_kind="part"),
        "HYDRA": row("HYDRA", name="Hydra-Reach Handle", product_kind="drywall-finishing-tool"),
    }
    proposals = prepare_proposals(
        catalog=catalog,
        master_path=master,
        tool_index={"columbia-hydra-handle": ["HYDRA"]},
        brand_filter="Columbia",
    )
    assert len(proposals) == 1
    assert proposals[0]["status"] == "proposal_exact"
    assert json.loads(proposals[0]["target_tool_skus"]) == ["HYDRA"]
    assert proposals[0]["source_file"] == "Columbia/Handles/Hydra.pdf"


def test_multi_tool_schematic_is_review_only(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(
        master,
        [
            {
                "brand": "Columbia Tools",
                "schematic_id": "shared-schematic",
                "product_sku": "TOOL-A",
                "sku": "PART-1",
                "part_number": "PART-1",
                "source_file_from_brands": "Columbia/shared.pdf",
            }
        ],
    )
    catalog = {"PART-1": row("PART-1", product_kind="part")}
    proposals = prepare_proposals(
        catalog=catalog,
        master_path=master,
        tool_index={"shared-schematic": ["TOOL-A", "TOOL-B"]},
        brand_filter="Columbia",
    )
    assert proposals[0]["status"] == "review_multi_tool"


def test_existing_relationship_does_not_hide_missing_exact_schematic_target(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(master, [{
        "brand": "Columbia",
        "schematic_id": "schematic-b",
        "product_sku": "",
        "sku": "PART-1",
        "part_number": "",
        "source_file_from_brands": "Columbia/Parts/Part-1.pdf",
    }])
    proposals = prepare_proposals(
        catalog={"PART-1": row("PART-1", product_kind="part", compatible='["TOOL-A"]')},
        master_path=master,
        tool_index={"schematic-b": ["TOOL-B"]},
        brand_filter="Columbia",
    )
    assert len(proposals) == 1
    assert proposals[0]["status"] == "proposal_exact"
    assert json.loads(proposals[0]["target_tool_skus"]) == ["TOOL-B"]


def test_existing_relationship_is_not_reproposed(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(
        master,
        [
            {
                "brand": "Columbia Tools",
                "schematic_id": "schematic-a",
                "product_sku": "TOOL-A",
                "sku": "PART-1",
                "part_number": "PART-1",
                "source_file_from_brands": "Columbia/a.pdf",
            }
        ],
    )
    catalog = {"PART-1": row("PART-1", product_kind="part", compatible="TOOL-A")}
    proposals = prepare_proposals(
        catalog=catalog,
        master_path=master,
        tool_index={"schematic-a": ["TOOL-A"]},
        brand_filter="Columbia",
    )
    assert proposals[0]["status"] == "already_populated"


def test_unknown_part_sku_is_not_invented(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(
        master,
        [
            {
                "brand": "Columbia Tools",
                "schematic_id": "schematic-a",
                "product_sku": "TOOL-A",
                "sku": "NOT-IN-CATALOG",
                "part_number": "NOT-IN-CATALOG",
                "source_file_from_brands": "Columbia/a.pdf",
            }
        ],
    )
    proposals = prepare_proposals(
        catalog={},
        master_path=master,
        tool_index={"schematic-a": ["TOOL-A"]},
        brand_filter="Columbia",
    )
    assert proposals == []


def test_unresolved_diagnostic_preserves_raw_identity_and_source_provenance(tmp_path: Path) -> None:
    master = tmp_path / "master.csv"
    write_master(master, [{
        "brand": "Asgard",
        "schematic_id": "  Asgard-SCH  ",
        "product_sku": "FA01-AD",
        "schematic_tool": "FA01-AD",
        "schematic_tool_path": "Adapters/FA01-AD",
        "sku": "  ab-704040 ",
        "part_id": "704040",
        "display_id": "704040",
        "part_number": "4641",
        "part_name": "Filler Tube Assembly",
        "source_file_from_brands": "Asgard\\Schematics\\Adapters\\FA01-AD\\schematic_data.json",
    }])
    diagnostics: list[dict[str, str]] = []
    assert prepare_proposals(
        catalog={}, master_path=master, tool_index={}, brand_filter="",
        diagnostics=diagnostics,
    ) == []
    assert diagnostics == [{
        "brand": "Asgard",
        "raw_schematic_id": "  Asgard-SCH  ",
        "raw_part_identifier": "  ab-704040 ",
        "source_file": "Asgard/Schematics/Adapters/FA01-AD/schematic_data.json",
        "source_product_sku": "FA01-AD",
        "source_schematic_tool": "FA01-AD",
        "source_schematic_tool_path": "Adapters/FA01-AD",
        "source_sku": "  ab-704040 ",
        "source_part_id": "704040",
        "source_display_id": "704040",
        "source_part_number": "4641",
        "source_part_name": "Filler Tube Assembly",
        "status": "unresolved_schematic_identity",
        "reason": "master schematic identity does not resolve to a canonical tool schematic",
    }]


def test_cross_brand_exact_identity_candidate_is_review_only() -> None:
    diagnostics = [{
        "brand": "Asgard",
        "raw_schematic_id": "asgard-schematic",
        "raw_part_identifier": "704040",
        "source_file": "Asgard/schematic.json",
        "source_product_sku": "FA01-AD",
        "source_schematic_tool": "FA01-AD",
        "source_schematic_tool_path": "Adapters/FA01-AD",
        "source_sku": "",
        "source_part_id": "704040",
        "source_display_id": "704040",
        "source_part_number": "",
        "source_part_name": "Filler Tube Assembly",
        "status": "unresolved_schematic_identity",
        "reason": "schematic unresolved",
    }]
    candidates = build_cross_brand_identity_candidates(diagnostics, [
        {**row("704040", name="TapeTech Filler Tube Assembly", product_kind="part"),
         "Meta: _dtb_brand_label": "TapeTech"},
        {**row("704040-TOOL", name="Unrelated tool", product_kind="tool"),
         "Meta: _dtb_brand_label": "TapeTech"},
        {**row("COL-PART", name="Columbia part", product_kind="part"),
         "Meta: _dtb_brand_label": "Columbia Tools"},
    ])
    assert len(candidates) == 1
    assert candidates[0]["candidate_part_sku"] == "704040"
    assert candidates[0]["candidate_brand"] == "TapeTech"
    assert json.loads(candidates[0]["matched_source_fields"]) == [
        "raw_part_identifier", "source_display_id", "source_part_id"
    ]
    assert candidates[0]["status"] == "cross_brand_exact_identity_conflict"
    assert "not an identity mapping or fitment claim" in candidates[0]["reason"]


def test_known_schematic_brand_alias_is_not_reported_as_cross_brand() -> None:
    candidates = build_cross_brand_identity_candidates([{
        "brand": "Columbia",
        "raw_schematic_id": "columbia-schematic",
        "raw_part_identifier": "CT109",
        "source_file": "Columbia/schematic.json",
        "source_part_id": "CT109",
        "status": "unresolved_schematic_identity",
    }], [{**row("CT109", product_kind="part"), "Meta: _dtb_brand_label": "Columbia Tools"}])
    assert candidates == []

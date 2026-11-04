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
    parent: str = "",
    compatible: str = "",
    replacement: str = "",
) -> dict[str, str]:
    return {
        "SKU": sku,
        "Name": name,
        "Type": type_,
        "Meta: _dtb_product_kind": product_kind,
        "Meta: _dtb_is_parts": is_part,
        "Meta: _dtb_parent_product_sku": parent,
        "Meta: _dtb_compatible_tool_skus": compatible,
        "Meta: _dtb_replacement_part_for": replacement,
    }


def write_master(path: Path, rows: list[dict[str, str]]) -> None:
    fields = ["brand", "schematic_id", "product_sku", "sku", "part_number", "source_file_from_brands"]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def test_variation_tool_collapses_to_non_part_parent() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
    }
    assert canonical_tool_sku("TOOL-10", catalog) == "TOOL-PARENT"


def test_legacy_part_flag_is_never_a_tool_target() -> None:
    catalog = {"PART-1": row("PART-1", is_part="1")}
    assert canonical_tool_sku("PART-1", catalog) is None


def test_product_kind_part_is_never_a_tool_target() -> None:
    catalog = {"PART-1": row("PART-1", product_kind="part")}
    assert canonical_tool_sku("PART-1", catalog) is None


def test_tool_index_deduplicates_variations_to_parent() -> None:
    catalog = {
        "TOOL-PARENT": row("TOOL-PARENT", type_="variable"),
        "TOOL-10": row("TOOL-10", type_="variation", parent="TOOL-PARENT"),
        "TOOL-12": row("TOOL-12", type_="variation", parent="TOOL-PARENT"),
    }
    links = {
        "TOOL-10": {"schematicId": "schematic-a"},
        "TOOL-12": {"schematicId": "schematic-a"},
    }
    assert build_tool_index(links, catalog) == {"schematic-a": ["TOOL-PARENT"]}


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

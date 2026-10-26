from __future__ import annotations

import sys
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parents[1]
if str(CATALOG_DIR) not in sys.path:
    sys.path.insert(0, str(CATALOG_DIR))

from catalog_taxonomy_policy import expected_taxonomy, taxonomy_state
from normalize_official_taxonomy import build_changes, parse_assignments


def test_toolset_product_kind_is_deterministic_across_brands() -> None:
    expected = expected_taxonomy(product_kind="toolset", display_category_key="toolsets")
    assert expected is not None
    assert expected.category_key == "taping"
    assert expected.display_category_key == "toolsets"


def test_part_policy_is_identical_for_every_brand() -> None:
    expected = expected_taxonomy(product_kind="part", display_category_key="parts")
    assert expected is not None
    assert expected.category_key == "parts"
    assert expected.display_category_key == "parts"


def test_only_functional_display_categories_derive_broad_category() -> None:
    cases = {
        "automatic_tapers": "taping",
        "finishing_boxes": "finishing",
        "handles": "handles",
        "pumps": "mudboxes",
        "corner_finishers": "corner",
        "compound_tubes": "corner",
        "parts": "parts",
        "stilts": "stilts",
    }
    for display, broad in cases.items():
        expected = expected_taxonomy(product_kind="drywall-finishing-tool", display_category_key=display)
        assert expected is not None
        assert expected.category_key == broad
        assert expected.display_category_key == display


def test_cross_cutting_display_categories_do_not_invent_broad_taxonomy() -> None:
    for display in ("predator_family", "accessories"):
        assert expected_taxonomy(
            product_kind="drywall-finishing-tool",
            display_category_key=display,
        ) is None
        state = taxonomy_state(
            product_kind="drywall-finishing-tool",
            category_key="handles",
            display_category_key=display,
        )
        assert state["disposition"] == "ambiguous_review"
        assert state["expected_category_key"] is None


def test_unknown_display_category_does_not_invent_taxonomy() -> None:
    state = taxonomy_state(
        product_kind="drywall-finishing-tool",
        category_key="custom",
        display_category_key="unknown_future_class",
    )
    assert state["disposition"] == "unknown"
    assert state["consistent"] is True


def test_normalizer_writes_complete_canonical_tuples_and_reports_unknown_paths() -> None:
    rows = [
        {
            "SKU": "BROAD",
            "Type": "simple",
            "Brands": "Brand A",
            "Categories": "Taping & Finishing Tools > Automatic Taping Tools > Tool Sets",
            "Meta: _dtb_product_kind": "toolset",
            "Meta: _dtb_category_key": "toolsets",
            "Meta: _dtb_display_category_key": "automatic_tool_sets",
        },
        {
            "SKU": "DISPLAY-ONLY",
            "Type": "simple",
            "Brands": "Brand B",
            "Categories": "Taping & Finishing Tools > Automatic Taping Tools > Tool Sets",
            "Meta: _dtb_product_kind": "toolset",
            "Meta: _dtb_category_key": "automatic_taping_tools",
            "Meta: _dtb_display_category_key": "",
        },
        {
            "SKU": "AMBIGUOUS",
            "Type": "simple",
            "Brands": "Brand C",
            "Categories": "Tools",
            "Meta: _dtb_product_kind": "drywall-finishing-tool",
            "Meta: _dtb_category_key": "handles",
            "Meta: _dtb_display_category_key": "predator_family",
        },
    ]
    changes, unresolved = build_changes(rows)
    changes_by_sku = {
        sku: {(change["field"], change["expected"]) for change in changes if change["sku"] == sku}
        for sku in ("BROAD", "DISPLAY-ONLY")
    }
    assert changes_by_sku["BROAD"] == {
        ("Categories", "Taping & Finishing Tools > Tool Sets & Kits"),
        ("Meta: _dtb_category_key", "taping"),
        ("Meta: _dtb_display_category_key", "toolsets"),
    }
    assert changes_by_sku["DISPLAY-ONLY"] == {
        ("Categories", "Taping & Finishing Tools > Tool Sets & Kits"),
        ("Meta: _dtb_category_key", "taping"),
        ("Meta: _dtb_display_category_key", "toolsets"),
    }
    assert [item["sku"] for item in unresolved] == ["AMBIGUOUS"]


def test_reviewed_assignment_requires_an_exact_owner_path() -> None:
    owner = {"SKU": "OWNER", "Type": "variable"}
    by_sku = {"OWNER": owner}
    path = "Automatic Taping Tools > Handles"
    assert parse_assignments([f"OWNER={path}"], by_sku) == {"OWNER": path}


def test_new_catalog_paths_resolve_to_complete_taxonomy_tuples() -> None:
    cases = {
        "Taping & Finishing Tools > Mud Pans": ("finishing", "mud_pans"),
        "Corner Bead Tools > Bead Hoppers": ("corner", "bead_hoppers"),
        "Materials & Consumables > Sealants": ("materials", "sealants"),
        "Hanging Tools > Drywall Saws": ("hanging", "drywall_saws"),
        "Taping & Finishing Tools > Goosenecks": ("mudboxes", "automatic_goosenecks_box_fillers"),
    }
    for path, (category_key, display_key) in cases.items():
        row = {
            "SKU": path,
            "Type": "simple",
            "Categories": path,
            "Meta: _dtb_product_kind": "tool",
            "Meta: _dtb_category_key": "",
            "Meta: _dtb_display_category_key": "",
        }
        changes, unresolved = build_changes([row])
        assert not unresolved, path
        expected_changes = {
            ("Meta: _dtb_category_key", category_key),
            ("Meta: _dtb_display_category_key", display_key),
        }
        if path.endswith("Goosenecks"):
            expected_changes.add(("Categories", r"Automatic Taping Tools > Goosenecks\, Box Fillers & Adapters"))
        assert {(change["field"], change["expected"]) for change in changes} == expected_changes


def test_taxonomy_state_reports_raw_and_normalized_values() -> None:
    state = taxonomy_state(
        product_kind="Toolset",
        category_key=" Tool-Sets ",
        display_category_key="Tool Sets",
    )
    assert state["disposition"] == "deterministic_mismatch"
    assert state["raw_category_key"] == "Tool-Sets"
    assert state["raw_display_category_key"] == "Tool Sets"
    assert state["category_key"] == "tool_sets"
    assert state["display_category_key"] == "tool_sets"
    assert state["expected_category_key"] == "taping"
    assert state["expected_display_category_key"] == "toolsets"
    assert "brand" not in state

from __future__ import annotations

import sys
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parents[1]
if str(CATALOG_DIR) not in sys.path:
    sys.path.insert(0, str(CATALOG_DIR))

from catalog_taxonomy_policy import expected_taxonomy, taxonomy_state


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

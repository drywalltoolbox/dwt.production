from __future__ import annotations

import sys
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parents[1]
if str(CATALOG_DIR) not in sys.path:
    sys.path.insert(0, str(CATALOG_DIR))

from catalog_taxonomy_policy import canonical_values, navigation_for_row, split_category_paths, taxon_for_path, taxons_for_path
from official_catalog_schema import CatalogValidationError, validate_taxonomy_rows


def row(*, sku="SKU", type_="simple", kind="tool", categories="", category="", display="", parent=""):
    return {"SKU": sku, "Type": type_, "Meta: _dtb_product_kind": kind, "Categories": categories, "Meta: _dtb_category_key": category, "Meta: _dtb_display_category_key": display, "Meta: _dtb_parent_product_sku": parent}


def test_cross_brand_flat_boxes_share_universal_navigation_identity():
    expected = {"Categories": "Automatic Taping Tools > Flat Boxes", "Meta: _dtb_category_key": "finishing", "Meta: _dtb_display_category_key": "flat_boxes"}
    assert canonical_values(row(categories=expected["Categories"])) == expected
    assert canonical_values(row(categories="Drywall Finishing Tools > Automatic Taping Tools > Flat Boxes")) == expected


def test_legacy_labels_normalize_to_current_customer_labels():
    cases = {
        "Drywall Finishing Tools > Automatic Taping Tools > Finishing Boxes": "Flat Boxes",
        "Drywall Finishing Tools > Automatic Taping Tools > Corner Boxes": "Angle Boxes & Corner Applicators",
        "Drywall Finishing Tools > Automatic Taping Tools > Fixed Handles": "Handles",
        "Drywall Finishing Tools > Automatic Taping Tools > Tool Cases": "Tool Cases",
    }
    for legacy, leaf in cases.items():
        taxon = taxon_for_path(legacy)
        assert taxon is not None
        assert taxon.path.endswith(leaf)


def test_angle_heads_and_corner_finishers_normalize_to_one_taxon():
    angle_head = taxon_for_path("Taping & Finishing Tools > Automatic Taping Tools > Angle Heads")
    corner_finisher = taxon_for_path("Taping & Finishing Tools > Automatic Taping Tools > Corner Finishers")
    assert angle_head and angle_head.display_key == "corner_finishers"
    assert corner_finisher and corner_finisher.display_key == "corner_finishers"
    assert angle_head == corner_finisher
    assert angle_head.path == "Automatic Taping Tools > Corner Finishers"


def test_legacy_handle_paths_collapse_to_handles_identity():
    automatic = taxon_for_path("Taping & Finishing Tools > Automatic Taping Tools > Handles & Extensions")
    assert automatic and automatic.display_key == "handles"
    assert automatic.path == "Automatic Taping Tools > Handles"


def test_duplicate_legacy_handle_paths_resolve_once():
    raw = "Taping & Finishing Tools > Automatic Taping Tools > Handles & Extensions, Drywall Finishing Tools > Automatic Taping Tools > Fixed Handles"
    assert [taxon.display_key for taxon in taxons_for_path(raw)] == ["handles"]
    assert taxon_for_path(raw) is not None


def test_escaped_comma_remains_inside_one_woocommerce_category_term():
    raw = r"Taping & Finishing Tools > Goosenecks\, Box Fillers & Adapters"
    assert split_category_paths(raw) == [["Taping & Finishing Tools", "Goosenecks, Box Fillers & Adapters"]]
    values = canonical_values(row(categories=raw))
    assert values and values["Categories"] == r"Automatic Taping Tools > Goosenecks\, Box Fillers & Adapters"


def test_legacy_semi_automatic_toolset_path_migrates_to_unified_toolsets():
    values = canonical_values(row(kind="toolset", categories="Taping & Finishing Tools > Semi-Automatic Taping Tools > Tool Sets"))
    assert values and values["Categories"] == "Taping & Finishing Tools > Tool Sets & Kits"
    assert values["Meta: _dtb_category_key"] == "taping"
    assert values["Meta: _dtb_display_category_key"] == "toolsets"


def test_part_and_stilt_domains_are_separate():
    part = canonical_values(row(kind="part", categories="Taping & Finishing Tools > Flat Boxes"))
    stilt = canonical_values(row(kind="stilt", categories="Stilts & Accessories > Stilts"))
    assert part and part["Categories"] == "Replacement Parts"
    assert stilt and stilt["Categories"] == "Drywall Stilts"


def test_variation_inherits_parent_taxonomy():
    parent = row(sku="PARENT", type_="variable", categories="Taping & Finishing Tools > Compound Tubes")
    child = row(sku="CHILD", type_="variation", kind="variation", parent="PARENT")
    assert canonical_values(child, parent) == {"Categories": "Automatic Taping Tools > Compound Tubes", "Meta: _dtb_category_key": "corner", "Meta: _dtb_display_category_key": "compound_tubes"}


def test_ambiguous_legacy_compound_applicator_path_is_not_guessed():
    assert taxon_for_path("Taping & Finishing Tools > Automatic Taping Tools > Compound Applicators") is None


def test_unknown_leaf_never_guesses():
    assert navigation_for_row(row(categories="Taping & Finishing Tools > Future Mystery Tool")) is None


def test_taxonomy_validator_rejects_variation_drift():
    parent = row(sku="PARENT", type_="variable", categories="Taping & Finishing Tools > Flat Boxes", category="finishing", display="flat_boxes")
    child = row(sku="CHILD", type_="variation", kind="variation", categories="", category="finishing", display="flat_boxes", parent="PARENT")
    child["Parent"] = "PARENT"
    try:
        validate_taxonomy_rows([parent, child])
    except CatalogValidationError as exc:
        assert "CHILD: Categories" in str(exc)
    else:
        raise AssertionError("variation taxonomy drift was not rejected")


def test_validator_accepts_multiple_explicit_categories_without_reclassification():
    categories = "Automatic Taping Tools > Corner Rollers, Corner Bead Tools > Corner Bead Rollers"
    parent = row(sku="PARENT", type_="variable", categories=categories, category="custom", display="custom")
    child = row(sku="CHILD", type_="variation", categories=categories, category="custom", display="custom", parent="PARENT")
    assert validate_taxonomy_rows([parent, child])["rows"] == 2
    assert parent["Categories"] == categories


def test_validator_rejects_unknown_assigned_category():
    try:
        validate_taxonomy_rows([row(categories="Hanging Tools > Imaginary Tools")])
    except CatalogValidationError as exc:
        assert "unknown category path" in str(exc)
    else:
        raise AssertionError("unknown category was not rejected")

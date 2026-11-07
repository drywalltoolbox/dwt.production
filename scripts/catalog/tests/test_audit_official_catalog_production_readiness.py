from __future__ import annotations

import sys
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parents[1]
if str(CATALOG_DIR) not in sys.path:
    sys.path.insert(0, str(CATALOG_DIR))

from audit_official_catalog_production_readiness import direct_findings, veeqo_comparison, workflow_safety_findings
from official_catalog_schema import EXPECTED_COLUMNS


def row(**overrides: str) -> dict[str, str]:
    value = {field: "" for field in EXPECTED_COLUMNS}
    value.update({
        "Type": "simple", "SKU": "SKU-1", "Name": "Product", "Published": "1",
        "Visibility in catalog": "visible", "Brands": "Brand", "Slug": "product",
        "Regular price": "10.00", "Cost of goods": "5.00", "Images": "https://drywalltoolbox.com/image.webp",
        "Meta: _dtb_product_kind": "tool", "Meta: _dtb_commerce_mode": "purchasable",
    })
    value.update(overrides)
    return value


def codes(rows: list[dict[str, str]]) -> set[str]:
    findings, _ = direct_findings(rows, "digest")
    return {item["finding_code"] for item in findings}


def test_staging_image_is_a_production_blocker() -> None:
    findings, _ = direct_findings([row(Images="https://elliottm4.sg-host.com/image.webp")], "digest")
    item = next(item for item in findings if item["finding_code"] == "staging_or_local_url")
    assert item["release_gate"] == "blocker"
    assert item["field"] == "Images"


def test_quote_only_priced_item_is_a_production_blocker() -> None:
    findings, _ = direct_findings([row(**{"Meta: _dtb_commerce_mode": "quote_only"})], "digest")
    item = next(item for item in findings if item["finding_code"] == "quote_only_with_price")
    assert item["release_gate"] == "blocker"


def test_legacy_commerce_mode_requires_policy_mapping() -> None:
    assert "unsupported_commerce_mode" in codes([row(**{"Meta: _dtb_commerce_mode": "standard-catalog"})])


def test_valid_priced_row_has_no_objective_price_blocker() -> None:
    observed = codes([row()])
    assert "invalid_regular_price" not in observed
    assert "nonpositive_gross_margin" not in observed
    assert "regular_price_below_map" not in observed


def test_below_cost_margin_is_excluded_from_readiness_findings() -> None:
    assert "nonpositive_gross_margin" not in codes([row(**{"Regular price": "4.00", "Cost of goods": "5.00"})])


def test_inventory_projection_review_is_excluded_from_readiness_findings() -> None:
    observed = codes([row(Stock="10")])
    assert "inventory_projection_runtime_verification_required" not in observed


def test_duplicate_slugs_are_blocked() -> None:
    observed = codes([row(SKU="A"), row(SKU="B")])
    assert "duplicate_slug" in observed


def test_case_insensitive_sku_collision_is_blocked() -> None:
    observed = codes([row(SKU="part-a", Slug="part-a"), row(SKU="PART-A", Slug="part-a-2")])
    assert "case_insensitive_sku_collision" in observed


def test_missing_include_target_is_blocked() -> None:
    observed = codes([row(**{"Meta: _includes_0_name": "Missing", "Meta: _includes_0_sku": "ABSENT"})])
    assert "include_target_absent" in observed


def test_oem_claim_enters_accuracy_review() -> None:
    findings, _ = direct_findings([row(Description="Genuine OEM replacement part")], "digest")
    item = next(item for item in findings if item["finding_code"] == "claim_needs_evidence:oem_or_genuine")
    assert item["release_gate"] == "review"


def test_parent_default_must_be_exact_option() -> None:
    parent = row(Type="variable", SKU="PARENT", Slug="parent", **{
        "Attribute 1 value(s)": "Small, Large", "Attribute 1 default": "Medium",
    })
    child_small = row(Type="variation", SKU="SMALL", Slug="small", Parent="PARENT", **{"Attribute 1 value(s)": "Small"})
    child_large = row(Type="variation", SKU="LARGE", Slug="large", Parent="PARENT", **{"Attribute 1 value(s)": "Large"})
    assert "invalid_parent_attribute_default" in codes([parent, child_small, child_large])


def test_inheritance_flag_on_simple_product_requires_review() -> None:
    assert "inherit_parent_image_on_nonvariation" in codes([row(**{"Meta: _dtb_inherit_parent_image": "1"})])


def test_parent_equal_variation_gallery_requires_explicit_inheritance() -> None:
    parent = row(Type="variable", SKU="PARENT", Slug="parent", **{
        "Attribute 1 value(s)": "Small", "Attribute 1 default": "Small",
    })
    child = row(Type="variation", SKU="SMALL", Slug="small", Parent="PARENT", **{
        "Attribute 1 value(s)": "Small",
    })
    assert "variation_gallery_equals_parent_without_inheritance" in codes([parent, child])


def test_inherited_variation_gallery_must_exactly_match_parent_order() -> None:
    parent = row(Type="variable", SKU="PARENT", Slug="parent", Images="https://drywalltoolbox.com/parent.webp", **{
        "Attribute 1 value(s)": "Small", "Attribute 1 default": "Small",
    })
    child = row(Type="variation", SKU="SMALL", Slug="small", Parent="PARENT", Images="https://drywalltoolbox.com/child.webp", **{
        "Attribute 1 value(s)": "Small", "Meta: _dtb_inherit_parent_image": "1",
    })
    assert "variation_inheritance_gallery_mismatch" in codes([parent, child])


def test_matching_inherited_gallery_clears_variation_media_findings() -> None:
    images = "https://drywalltoolbox.com/parent.webp"
    parent = row(Type="variable", SKU="PARENT", Slug="parent", Images=images, **{
        "Attribute 1 value(s)": "Small, Large", "Attribute 1 default": "Small",
    })
    children = [
        row(Type="variation", SKU="SMALL", Slug="small", Parent="PARENT", Images=images, **{
            "Attribute 1 value(s)": "Small", "Meta: _dtb_inherit_parent_image": "1",
        }),
        row(Type="variation", SKU="LARGE", Slug="large", Parent="PARENT", Images=images, **{
            "Attribute 1 value(s)": "Large", "Meta: _dtb_inherit_parent_image": "1",
        }),
    ]
    observed = codes([parent, *children])
    assert "variation_gallery_equals_parent_without_inheritance" not in observed
    assert "sibling_variations_share_explicit_gallery" not in observed
    assert "variation_inheritance_gallery_mismatch" not in observed


def test_invalid_gtin_check_digit_is_blocked() -> None:
    assert "invalid_gtin_check_digit" in codes([row(**{"GTIN, UPC, EAN, or ISBN": "123456789013"})])


def test_part_rows_do_not_treat_descriptive_part_number_as_identity() -> None:
    observed = codes([row(**{
        "Meta: _dtb_product_kind": "part", "Meta: _dtb_mpn": "MPN-1",
        "Meta: _dtb_specs_json": '[{"label":"Part Number","value":"DESCRIPTIVE-1"}]',
    })])
    assert "structured_part_number_identity_mismatch" not in observed


def test_simple_tool_part_number_identity_mismatch_requires_review() -> None:
    observed = codes([row(**{
        "Meta: _dtb_product_kind": "tool", "Meta: _dtb_mpn": "MPN-1",
        "Meta: _dtb_specs_json": '[{"label":"Part Number","value":"DIFFERENT"}]',
    })])
    assert "structured_part_number_identity_mismatch" in observed


def test_workflow_safety_findings_include_required_backup_gap() -> None:
    observed = {item["finding_code"] for item in workflow_safety_findings("digest")}
    assert "mutation_runner_backup_contract_gap" in observed
    assert "manifest_blank_erasure_risk" in observed


def test_veeqo_comparison_uses_effective_sale_price_and_preserves_blank_gtin(tmp_path: Path) -> None:
    veeqo = tmp_path / "veeqo.csv"
    veeqo.write_text(
        "sku_code,sales_price,cost_price,upc_code,image_url\n"
        "SKU-1,8.0,5.0,012345678905,https://drywalltoolbox.com/image.webp\n",
        encoding="utf-8",
    )
    summary, differences = veeqo_comparison(
        [row(**{"Sale price": "8.00", "GTIN, UPC, EAN, or ISBN": ""})], veeqo
    )
    assert summary["field_difference_rows"] == 0
    assert differences == []

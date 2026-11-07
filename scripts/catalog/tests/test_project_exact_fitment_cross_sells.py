from __future__ import annotations

import importlib.util
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "project_exact_fitment_cross_sells.py"
spec = importlib.util.spec_from_file_location("exact_fitment_cross_sells", SCRIPT)
assert spec and spec.loader
projection = importlib.util.module_from_spec(spec)
spec.loader.exec_module(projection)


def row(sku: str, kind: str, product_type: str, *, published: str = "1", visibility: str = "visible", parent: str = "", fitment: str = "") -> dict[str, str]:
    return {
        "SKU": sku,
        "Type": product_type,
        "Parent": parent,
        "Published": published,
        "Visibility in catalog": visibility,
        "Meta: _dtb_product_kind": kind,
        "Meta: _dtb_compatible_tool_skus": fitment,
        "Cross-sells": "",
    }


def test_projection_uses_exact_visible_simple_parts_and_all_variation_intersection() -> None:
    rows = [
        row("PART-A", "part", "simple", fitment='["SIMPLE","VAR-A","VAR-B"]'),
        row("PART-B", "part", "simple", fitment='["VAR-A"]'),
        row("PART-HIDDEN", "part", "simple", visibility="hidden", fitment='["SIMPLE"]'),
        row("SIMPLE", "tool", "simple"),
        row("VARIABLE", "tool", "variable"),
        row("VAR-A", "tool", "variation", parent="VARIABLE"),
        row("VAR-B", "tool", "variation", parent="VARIABLE"),
    ]

    proposals, counts = projection.build_projection(rows)
    by_sku = {item["sku"]: item for item in proposals}

    assert by_sku["SIMPLE"]["proposed_cross_sells"] == ["PART-A"]
    assert by_sku["VARIABLE"]["proposed_cross_sells"] == ["PART-A"]
    assert by_sku["VARIABLE"]["evidence_tool_skus"] == ["VAR-A", "VAR-B"]
    assert "PART-B" not in by_sku["VARIABLE"]["proposed_cross_sells"]
    assert "PART-HIDDEN" not in by_sku["SIMPLE"]["proposed_cross_sells"]
    assert counts["cross_sell_edge_additions"] == 2


def test_projection_is_idempotent_and_preserves_existing_cross_sells() -> None:
    rows = [
        row("PART-A", "part", "simple", fitment='["TOOL"]'),
        row("PART-B", "part", "simple", fitment='["TOOL"]'),
        {**row("TOOL", "tool", "simple"), "Cross-sells": "PART-B"},
    ]

    proposals, _ = projection.build_projection(rows)

    assert proposals[0]["current_cross_sells"] == ["PART-B"]
    assert proposals[0]["proposed_cross_sells"] == ["PART-B", "PART-A"]
    rows[-1]["Cross-sells"] = ", ".join(proposals[0]["proposed_cross_sells"])
    second_run, counts = projection.build_projection(rows)
    assert second_run == []
    assert counts["cross_sell_edge_additions"] == 0


def test_projection_rejects_unresolved_exact_fitment_targets() -> None:
    rows = [row("PART-A", "part", "simple", fitment='["MISSING"]')]

    try:
        projection.build_projection(rows)
    except projection.ProjectionError as error:
        assert "not an exact catalog SKU" in str(error)
    else:
        raise AssertionError("Unresolved fitment target must fail closed")

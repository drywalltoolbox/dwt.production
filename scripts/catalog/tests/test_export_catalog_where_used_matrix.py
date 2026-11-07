import csv
import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "export_catalog_where_used_matrix.py"
SPEC = importlib.util.spec_from_file_location("export_catalog_where_used_matrix", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(MODULE)


FIELDS = [
    "SKU", "Name", "Type", "Published", "Visibility in catalog", "Parent",
    "Meta: _dtb_compatible_tool_skus", "Meta: schema_mpn",
    "Meta: _dtb_manufacturer_sku", "Meta: _dtb_brand_label",
    "Meta: _dtb_is_parts", "Meta: _dtb_product_kind",
]


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def test_matrix_includes_all_catalog_tool_skus_and_keeps_variants_distinct(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    tapetech = tmp_path / "tapetech.csv"
    products = [
        {"SKU": "TOOL-SIMPLE", "Name": "Visible Simple Tool", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_brand_label": "Brand A", "Meta: _dtb_is_parts": "0", "Meta: _dtb_product_kind": "tool"},
        {"SKU": "TOOL-VARIABLE", "Name": "Draft Variable Tool", "Type": "variable", "Published": "0", "Visibility in catalog": "hidden", "Parent": "", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_brand_label": "Brand A", "Meta: _dtb_is_parts": "0", "Meta: _dtb_product_kind": "tool"},
        {"SKU": "TOOL-VARIANT", "Name": "Private Tool Variant", "Type": "variation", "Published": "0", "Visibility in catalog": "hidden", "Parent": "TOOL-VARIABLE", "Meta: _dtb_compatible_tool_skus": "", "Meta: _dtb_brand_label": "Brand A", "Meta: _dtb_is_parts": "0", "Meta: _dtb_product_kind": "tool"},
        {"SKU": "PART-1", "Name": "Part", "Type": "simple", "Published": "1", "Visibility in catalog": "visible", "Parent": "", "Meta: _dtb_compatible_tool_skus": '["TOOL-VARIANT"]', "Meta: _dtb_brand_label": "Brand A", "Meta: _dtb_is_parts": "1", "Meta: _dtb_product_kind": "part"},
    ]
    write_csv(catalog, FIELDS, products)
    write_csv(tapetech, ["PartNumber", "Description", "Number of Tools Where Part Is Used"], [])

    fields, rows, summary = MODULE.build(catalog, tapetech)
    columns = set(fields)
    assert {"Tool: TOOL-SIMPLE", "Tool: TOOL-VARIABLE", "Tool: TOOL-VARIANT"}.issubset(columns)
    assert "Tool: PART-1" not in columns
    part = rows[0]
    assert part["Tool: TOOL-VARIABLE"] == ""
    assert part["Tool: TOOL-VARIANT"] == "1"
    assert summary["tool_target_scope"]["catalog_tool_like_skus"] == 3
    assert summary["tool_target_scope"]["by_product_type"] == {"simple": 1, "variable": 1, "variation": 1}
    assert summary["tool_target_scope"]["by_published_state"] == {"0": 2, "1": 1}

from __future__ import annotations

import csv
import importlib.util
import sys
from pathlib import Path


CATALOG_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(CATALOG_DIR))

spec = importlib.util.spec_from_file_location("build_variation_fitment_review_queue", CATALOG_DIR / "build_variation_fitment_review_queue.py")
assert spec and spec.loader
queue_builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(queue_builder)


def write_catalog(path: Path) -> None:
    fields = [
        "SKU", "Name", "Type", "Published", "Parent", "Attribute 1 name", "Attribute 1 value(s)",
        "Meta: _dtb_product_kind", "Meta: _dtb_is_parts", "Meta: _dtb_compatible_tool_skus",
        "Meta: _dtb_schematic_id", "Meta: _dtb_schematic_brand", "Meta: _dtb_schematic_group",
        "Meta: _dtb_schematic_page", "Meta: _dtb_schematic_variant",
    ]
    rows = [
        {"SKU": "PART-1", "Name": "Part", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["VAR-1"]', "Meta: _dtb_schematic_id": "schem-1", "Meta: _dtb_schematic_brand": "Brand", "Meta: _dtb_schematic_group": "Group", "Meta: _dtb_schematic_page": "2", "Meta: _dtb_schematic_variant": "A"},
        {"SKU": "PART-DRAFT", "Name": "Draft part", "Type": "simple", "Published": "0", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["VAR-1"]'},
        {"SKU": "TOOL-1", "Name": "Parent tool", "Type": "variable", "Published": "1"},
        {"SKU": "VAR-1", "Name": "Parent tool - 10 inch", "Type": "variation", "Published": "1", "Parent": "TOOL-1", "Attribute 1 name": "Size", "Attribute 1 value(s)": "10 inch"},
    ]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def test_review_queue_suggests_exact_parent_without_approving_or_mutating(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    write_catalog(catalog)
    digest_before = queue_builder.file_sha256(catalog)

    rows, summary = queue_builder.build_queue(catalog)

    assert len(rows) == 1
    assert rows[0]["part_sku"] == "PART-1"
    assert rows[0]["target_variation_sku"] == "VAR-1"
    assert rows[0]["target_parent_sku"] == "TOOL-1"
    assert rows[0]["target_parent_name"] == "Parent tool"
    assert rows[0]["suggested_decision"] == "review_parent_family"
    assert rows[0]["review_status"] == "needs_review"
    assert rows[0]["decision_parent_sku"] == ""
    assert '"1 value(s)": "10 inch"' in rows[0]["variation_attributes_json"]
    assert summary["published_part_variation_edges"] == 1
    assert summary["catalog_mutated"] is False
    assert queue_builder.file_sha256(catalog) == digest_before

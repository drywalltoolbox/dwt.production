from __future__ import annotations

import csv
import importlib.util
import sys
from pathlib import Path


CATALOG_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(CATALOG_DIR))


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


removal = load_module("build_compatibility_removal_review_manifest", CATALOG_DIR / "build_compatibility_removal_review_manifest.py")


def test_removal_manifest_requires_exact_existing_target_and_keeps_review_pending(tmp_path: Path) -> None:
    catalog = tmp_path / "catalog.csv"
    with catalog.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["SKU", "Type", "Published", "Meta: _dtb_product_kind", "Meta: _dtb_is_parts", removal.FIELD])
        writer.writeheader()
        writer.writerow({"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_is_parts": "1", removal.FIELD: '["TOOL-1","TOOL-2"]'})

    row = removal.build_row(catalog, "PART-1", "TOOL-1", "Manufacturer schematic page 3")
    assert row["review_status"] == "needs_review"
    assert row["workflow"] == "compatibility_remove"
    assert row["proposed_value"] == '["TOOL-2"]'
    assert row["reviewer"] == ""

    try:
        removal.build_row(catalog, "PART-1", "TOOL-3", "Manufacturer schematic page 3")
    except removal.CatalogValidationError as error:
        assert "must occur once" in str(error)
    else:
        raise AssertionError("A removal must identify an existing exact target.")

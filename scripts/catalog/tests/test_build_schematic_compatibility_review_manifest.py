from __future__ import annotations

import csv
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "build_schematic_compatibility_review_manifest.py"
sys.path.insert(0, str(SCRIPT.parent))
SPEC = importlib.util.spec_from_file_location("compatibility_review_manifest", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class BuildReviewManifestTests(unittest.TestCase):
    def test_builds_digest_bound_unapproved_manifest_and_preserves_existing_targets(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["TOOL-OLD"]'},
                {"SKU": "TOOL-1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-OLD", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [
                {"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-1"]', "brand": "Brand", "schematic_id": "S-1", "source_schematic_id": "S-1", "source_file": "source.csv", "reason": "exact IDs"},
                {"status": "review_multi_tool", "part_sku": "PART-1", "target_tool_skus": '["TOOL-2"]', "brand": "Brand", "schematic_id": "S-2", "source_schematic_id": "S-2", "source_file": "source.csv", "reason": "ambiguous"},
            ]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            manifest = MODULE.build_manifest(catalog, proposals)
            self.assertEqual(1, len(manifest))
            self.assertEqual("needs_review", manifest[0]["review_status"])
            self.assertEqual("", manifest[0]["reviewer"])
            self.assertEqual('["TOOL-1","TOOL-OLD"]', manifest[0]["proposed_value"])
            self.assertEqual(1, len(json.loads(manifest[0]["evidence"])))

    def test_rejects_part_as_compatible_tool(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            with catalog.open("w", newline="", encoding="utf-8") as handle:
                writer = csv.DictWriter(handle, fieldnames=["SKU", "Meta: _dtb_product_kind", "Meta: _dtb_compatible_tool_skus"])
                writer.writeheader()
                writer.writerows([
                    {"SKU": "PART-1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                    {"SKU": "PART-2", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                ])
            with proposals.open("w", newline="", encoding="utf-8") as handle:
                writer = csv.DictWriter(handle, fieldnames=["status", "part_sku", "target_tool_skus"])
                writer.writeheader()
                writer.writerow({"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["PART-2"]'})
            with self.assertRaisesRegex(ValueError, "target is a part"):
                MODULE.build_manifest(catalog, proposals)


if __name__ == "__main__":
    unittest.main()

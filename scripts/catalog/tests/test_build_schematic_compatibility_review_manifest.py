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
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["TOOL-OLD"]'},
                {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-2", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-OLD", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
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
            self.assertEqual('["TOOL-1","TOOL-2","TOOL-OLD"]', manifest[0]["proposed_value"])
            self.assertEqual(["exact_schematic_occurrence", "multi_tool_schematic_review"], [item["type"] for item in json.loads(manifest[0]["evidence"])])

    def test_manifest_keeps_exact_variation_tool_sku(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Parent": "", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-PARENT", "Type": "variable", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-VAR", "Type": "variation", "Parent": "TOOL-PARENT", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [
                {"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-VAR"]', "brand": "Brand", "schematic_id": "S-1", "source_schematic_id": "S-1", "source_file": "source.csv", "reason": "exact variation SKU"},
            ]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            manifest = MODULE.build_manifest(catalog, proposals)
            self.assertEqual('["TOOL-VAR"]', manifest[0]["proposed_value"])

    def test_rejects_noncanonical_existing_target_instead_of_rewriting_its_identity(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["tool-old"]'},
                {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-OLD", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-1"]'}]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            with self.assertRaisesRegex(ValueError, "current compatibility target must match its exact canonical SKU"):
                MODULE.build_manifest(catalog, proposals)

    def test_rejects_case_changed_candidate_sku_in_proposal_input(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["tool-1"]'}]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            with self.assertRaisesRegex(ValueError, "proposal target must match its exact canonical SKU"):
                MODULE.build_manifest(catalog, proposals)

    def test_rejects_case_changed_variation_parent_identity(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-PARENT", "Type": "variable", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-VAR", "Type": "variation", "Parent": "tool-parent", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-VAR"]'}]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    fieldnames = list(dict.fromkeys(key for row in rows for key in row))
                    writer = csv.DictWriter(handle, fieldnames=fieldnames)
                    writer.writeheader()
                    writer.writerows(rows)
            with self.assertRaisesRegex(ValueError, "variation target lacks a published non-part parent"):
                MODULE.build_manifest(catalog, proposals)

    def test_merges_supplemental_manufacturer_source_into_exact_review_row(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            supplemental = root / "supplemental.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-1"]', "brand": "Brand", "schematic_id": "S-1", "source_schematic_id": "S-1", "source_file": "source.csv", "reason": "exact IDs"}]
            supplemental_rows = [
                {"part_sku": "PART-1", "target_tool_sku": "TOOL-1", "evidence_role": "supports", "source_url": "https://manufacturer.example/tool.pdf", "source_title": "Tool schematic", "source_locator": "Page 2, callout 4", "evidence_note": "The manufacturer diagram identifies the exact part on this tool."},
                {"part_sku": "PART-1", "target_tool_sku": "TOOL-1", "evidence_role": "context_only", "source_url": "https://manufacturer.example/related-tool.pdf", "source_title": "Related tool schematic", "source_locator": "Page 1, item 8", "evidence_note": "This source concerns a different tool and does not prove the proposed fitment."},
            ]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows), (supplemental, supplemental_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            manifest = MODULE.build_manifest(catalog, proposals, supplemental)
            evidence = json.loads(manifest[0]["evidence"])
            self.assertEqual(["exact_schematic_occurrence", "manufacturer_document", "manufacturer_document"], [item["type"] for item in evidence])
            self.assertEqual(["supports", "context_only"], [item.get("evidence_role") for item in evidence[1:]])
            self.assertEqual("needs_review", manifest[0]["review_status"])

    def test_rejects_supplemental_source_without_matching_exact_proposal(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            supplemental = root / "supplemental.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-1"]'}]
            supplemental_rows = [{"part_sku": "PART-1", "target_tool_sku": "TOOL-OTHER", "evidence_role": "supports", "source_url": "https://manufacturer.example/tool.pdf", "source_title": "Tool schematic", "source_locator": "Page 2, callout 4", "evidence_note": "The manufacturer diagram identifies the exact part on this tool."}]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows), (supplemental, supplemental_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            with self.assertRaisesRegex(ValueError, "does not match a schematic review candidate"):
                MODULE.build_manifest(catalog, proposals, supplemental)

    def test_ignores_supplemental_evidence_for_exact_relationship_already_in_catalog(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            supplemental = root / "supplemental.csv"
            catalog_rows = [
                {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": '["TOOL-VAR"]'},
                {"SKU": "TOOL-PARENT", "Type": "variable", "Parent": "", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                {"SKU": "TOOL-VAR", "Type": "variation", "Parent": "TOOL-PARENT", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            ]
            proposal_rows = [{"status": "already_populated", "part_sku": "PART-1", "target_tool_skus": '["TOOL-VAR"]'}]
            supplemental_rows = [{
                "part_sku": "PART-1",
                "target_tool_sku": "TOOL-VAR",
                "evidence_role": "supports",
                "source_url": "https://manufacturer.example/tool.pdf",
                "source_title": "Tool schematic",
                "source_locator": "Page 2, callout 4",
                "evidence_note": "The manufacturer diagram identifies this exact part on this exact tool variation.",
            }]
            for path, rows in ((catalog, catalog_rows), (proposals, proposal_rows), (supplemental, supplemental_rows)):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    fieldnames = list(dict.fromkeys(key for row in rows for key in row))
                    writer = csv.DictWriter(handle, fieldnames=fieldnames)
                    writer.writeheader()
                    writer.writerows(rows)
            self.assertEqual([], MODULE.build_manifest(catalog, proposals, supplemental))

    def test_rejects_missing_supplemental_evidence_file(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            missing = root / "missing-supplemental.csv"
            for path, rows in (
                (catalog, [
                    {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                    {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
                ]),
                (proposals, [{"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["TOOL-1"]'}]),
            ):
                with path.open("w", newline="", encoding="utf-8") as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                    writer.writeheader()
                    writer.writerows(rows)
            with self.assertRaisesRegex(FileNotFoundError, "supplemental manufacturer evidence file is missing"):
                MODULE.build_manifest(catalog, proposals, missing)

    def test_rejects_part_as_compatible_tool(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            catalog = root / "catalog.csv"
            proposals = root / "proposals.csv"
            with catalog.open("w", newline="", encoding="utf-8") as handle:
                writer = csv.DictWriter(handle, fieldnames=["SKU", "Type", "Published", "Meta: _dtb_product_kind", "Meta: _dtb_compatible_tool_skus"])
                writer.writeheader()
                writer.writerows([
                    {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                    {"SKU": "PART-2", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
                ])
            with proposals.open("w", newline="", encoding="utf-8") as handle:
                writer = csv.DictWriter(handle, fieldnames=["status", "part_sku", "target_tool_skus"])
                writer.writeheader()
                writer.writerow({"status": "proposal_exact", "part_sku": "PART-1", "target_tool_skus": '["PART-2"]'})
            with self.assertRaisesRegex(ValueError, "target is a part"):
                MODULE.build_manifest(catalog, proposals)


if __name__ == "__main__":
    unittest.main()

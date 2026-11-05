from __future__ import annotations

import csv
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "build_documented_compatibility_review_manifest.py"
sys.path.insert(0, str(SCRIPT.parent))
SPEC = importlib.util.spec_from_file_location("documented_compatibility_review_manifest", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class BuildDocumentedCompatibilityManifestTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.catalog = self.root / "catalog.csv"
        self.evidence = self.root / "evidence.csv"
        self.catalog_rows = [
            {"SKU": "PART-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "part", "Meta: _dtb_compatible_tool_skus": ""},
            {"SKU": "TOOL-1", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            {"SKU": "TOOL-VAR", "Type": "variable", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
            {"SKU": "TOOL-VAR-RED", "Type": "variation", "Published": "1", "Parent": "TOOL-VAR", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""},
        ]
        self.evidence_rows = [{
            "part_sku": "PART-1",
            "target_tool_sku": "TOOL-1",
            "source_url": "https://example.test/manual.pdf",
            "source_title": "Manufacturer manual",
            "source_locator": "Page 4, model table",
            "evidence_note": "The manual lists this part for this tool.",
        }]
        self.write_csv(self.catalog, self.catalog_rows)
        self.write_csv(self.evidence, self.evidence_rows)

    def tearDown(self) -> None:
        self.temp.cleanup()

    @staticmethod
    def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
        with path.open("w", newline="", encoding="utf-8") as handle:
            fields = list(dict.fromkeys(key for row in rows for key in row))
            writer = csv.DictWriter(handle, fieldnames=fields)
            writer.writeheader()
            writer.writerows(rows)

    def build(self) -> list[dict[str, str]]:
        return MODULE.build_manifest(self.catalog, self.evidence)

    def test_creates_digest_bound_review_only_proposal(self) -> None:
        manifest = self.build()
        self.assertEqual(1, len(manifest))
        self.assertEqual("needs_review", manifest[0]["review_status"])
        self.assertEqual("", manifest[0]["reviewer"])
        self.assertEqual("", manifest[0]["approval_sha256"])
        self.assertEqual('["TOOL-1"]', manifest[0]["proposed_value"])
        evidence = json.loads(manifest[0]["evidence"])
        self.assertEqual("TOOL-1", evidence[0]["target_tool_sku"])
        self.assertEqual("supports", evidence[0]["evidence_role"])
        self.assertEqual(64, len(manifest[0]["catalog_sha256"]))

    def test_preserves_existing_compatibility_and_skips_already_present_edge(self) -> None:
        self.catalog_rows[0]["Meta: _dtb_compatible_tool_skus"] = '["TOOL-OLD","TOOL-1"]'
        self.catalog_rows.append({"SKU": "TOOL-OLD", "Type": "simple", "Published": "1", "Meta: _dtb_product_kind": "tool", "Meta: _dtb_compatible_tool_skus": ""})
        self.write_csv(self.catalog, self.catalog_rows)
        self.assertEqual([], self.build())

    def test_rejects_noncanonical_existing_target_instead_of_rewriting_its_identity(self) -> None:
        self.catalog_rows[0]["Meta: _dtb_compatible_tool_skus"] = '["tool-1"]'
        self.write_csv(self.catalog, self.catalog_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "current compatibility target must match its exact canonical SKU"):
            self.build()

    def test_rejects_noncanonical_evidence_skus_instead_of_normalizing_them(self) -> None:
        self.evidence_rows[0]["target_tool_sku"] = "tool-1"
        self.write_csv(self.evidence, self.evidence_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "target must match its exact canonical SKU"):
            self.build()

    def test_accepts_exact_part_variation_owner_but_rejects_unpublished_owner(self) -> None:
        self.catalog_rows[0]["Published"] = "0"
        self.write_csv(self.catalog, self.catalog_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "compatibility owner must be published"):
            self.build()

        self.catalog_rows[0]["Published"] = "1"
        self.catalog_rows[0]["Type"] = "variable"
        self.catalog_rows[0]["SKU"] = "PART-FAMILY"
        self.catalog_rows.append({
            "SKU": "PART-1",
            "Type": "variation",
            "Published": "1",
            "Parent": "PART-FAMILY",
            "Meta: _dtb_product_kind": "",
            "Meta: _dtb_compatible_tool_skus": "",
        })
        self.evidence_rows[0]["part_sku"] = "PART-1"
        self.write_csv(self.catalog, self.catalog_rows)
        self.write_csv(self.evidence, self.evidence_rows)
        manifest = self.build()
        self.assertEqual(["PART-1"], [item["sku"] for item in manifest])
        self.assertEqual("needs_review", manifest[0]["review_status"])

    def test_rejects_unpublished_part_target_and_variation_without_valid_parent(self) -> None:
        self.catalog_rows[1]["Published"] = "0"
        self.write_csv(self.catalog, self.catalog_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "target is not published"):
            self.build()

        self.evidence_rows[0]["target_tool_sku"] = "TOOL-VAR-RED"
        self.catalog_rows[1]["Published"] = "1"
        self.catalog_rows[2]["Meta: _dtb_product_kind"] = "part"
        self.write_csv(self.catalog, self.catalog_rows)
        self.write_csv(self.evidence, self.evidence_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "variation target lacks a published non-part parent"):
            self.build()

    def test_rejects_duplicate_pairs_and_non_https_evidence(self) -> None:
        self.evidence_rows.append(dict(self.evidence_rows[0]))
        self.write_csv(self.evidence, self.evidence_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "empty or duplicate evidence relationship"):
            self.build()

        self.evidence_rows = [dict(self.evidence_rows[0], source_url="http://example.test/manual.pdf")]
        self.write_csv(self.evidence, self.evidence_rows)
        with self.assertRaisesRegex(MODULE.CatalogValidationError, "absolute HTTPS URL"):
            self.build()


if __name__ == "__main__":
    unittest.main()

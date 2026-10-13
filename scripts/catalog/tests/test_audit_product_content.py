#!/usr/bin/env python3
"""Regression tests for the non-mutating product content classifier."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "audit_product_content.py"
spec = importlib.util.spec_from_file_location("audit_product_content", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ContentAuditTests(unittest.TestCase):
    def test_semantic_features_kit_and_overview(self):
        original = "<p>Overview of the tool.</p><h3>Features</h3><ul><li><strong>Durable:</strong> Metal case.</li></ul><h3>Kit Includes</h3><ul><li>1× Filler tube</li></ul>"
        found, reasons = module.classify(original)
        self.assertEqual(found["description"], ["Overview of the tool."])
        self.assertEqual(found["features"], ["Durable: Metal case."])
        self.assertEqual(found["kit_contents"], ["1× Filler tube"])
        self.assertEqual(reasons, [])

    def test_bold_paragraph_heading(self):
        source = "<p>Overview.</p><p><strong>Features</strong></p><ul><li>Easy to clean</li></ul>"
        found, reasons = module.classify(source)
        self.assertEqual(found["features"], ["Easy to clean"])
        self.assertEqual(reasons, [])

    def test_specs_flagged_for_reconciliation(self):
        found, reasons = module.classify("<p>A tool.</p><h3>Specifications</h3><ul><li>Weight: 7 lb</li></ul>")
        self.assertEqual(found["specifications"], ["Weight: 7 lb"])
        self.assertIn("specs_in_description_require_reconciliation", reasons)

    def test_unknown_heading_not_discarded(self):
        found, reasons = module.classify("<h3>Features</h3><ul><li>Heavy-duty</li></ul><h3>Warnings</h3><p>Use gloves.</p>")
        self.assertIn("Warnings", " ".join(found["features"]))
        self.assertIn("unmapped_subheading", reasons)

    def test_empty_and_variation_review(self):
        record = module.audit_row({"SKU": "ABC", "Type": "variation", "Name": "Child", "Description": "", "Short description": ""}, 2)
        self.assertEqual(record["status"], "missing_supporting_data")
        self.assertIn("variation_inheritance_review", record["reason_codes"])

    def test_preserves_input_and_output_is_deterministic(self):
        import csv
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "catalog.csv"
            with source.open("w", newline="", encoding="utf-8") as fh:
                writer = csv.DictWriter(fh, fieldnames=["Type", "SKU", "Name", "Description", "Short description"])
                writer.writeheader()
                writer.writerow({"Type": "simple", "SKU": "00012", "Name": "Part", "Description": "<p>Some part.</p>", "Short description": "A part."})
            before = source.read_bytes()
            module.run(source, root / "out")
            outputs = {p.name: p.read_bytes() for p in (root / "out").iterdir()}
            module.run(source, root / "out")
            self.assertEqual(before, source.read_bytes())
            self.assertEqual(outputs, {p.name: p.read_bytes() for p in (root / "out").iterdir()})
            self.assertIn(b"00012", outputs["product-content-audit.csv"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""V2 column migration and feature metadata contract checks."""
import csv
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/catalog/preview_content_schema_v2.py"
spec = importlib.util.spec_from_file_location("v2_preview", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class V2CatalogTests(unittest.TestCase):
    def test_preview_preserves_every_existing_field(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source = root / "source.csv"
            cols = ["Type", "SKU", "Description", "Meta: _dtb_specs_json", "Name"]
            with source.open("w", encoding="utf-8-sig", newline="") as fh:
                writer = csv.DictWriter(fh, fieldnames=cols, lineterminator="\r\n")
                writer.writeheader()
                writer.writerow({"Type": "simple", "SKU": "00012", "Description": '<p>Quoted "content", preserved</p>', "Meta: _dtb_specs_json": '[{"label":"Length","value":"12 in"}]', "Name": "Tool"})
                writer.writerow({"Type": "variation", "SKU": "00012-V", "Description": "", "Name": "Option"})
            original = source.read_bytes()
            result = mod.transform(source, root / "preview.csv")
            self.assertEqual(result["rows"], 2)
            self.assertEqual(source.read_bytes(), original)
            with (root / "preview.csv").open(encoding="utf-8-sig", newline="") as fh:
                rows = list(csv.DictReader(fh))
            self.assertEqual(rows[0]["SKU"], "00012")
            self.assertEqual(rows[0]["Description"], '<p>Quoted "content", preserved</p>')
            self.assertEqual(rows[0]["Meta: _dtb_features_json"], "")
            self.assertEqual(rows[1]["SKU"], "00012-V")
            with self.assertRaises(FileExistsError):
                mod.transform(source, root / "preview.csv")

    def test_canonical_header_contains_single_ordered_features_pair(self):
        schema = (ROOT / "scripts/catalog/official_catalog_schema.py").read_text(encoding="utf-8")
        self.assertIn('"Meta: _dtb_specs_json", "Meta: _dtb_features_json", "Meta: _dtb_content_schema_version"', schema)
        with (ROOT / "products/launch/official/dtb_official_catalog.csv").open(encoding="utf-8-sig", newline="") as fh:
            reader = csv.DictReader(fh)
            headers = reader.fieldnames
            i = headers.index("Meta: _dtb_specs_json")
            self.assertEqual(headers[i+1:i+3], ["Meta: _dtb_features_json", "Meta: _dtb_content_schema_version"])
            rows = list(reader)
        self.assertEqual(len(rows), 1134)
        self.assertEqual(len({r["SKU"] for r in rows}), 1134)
        populated = 0
        legacy = 0
        for row in rows:
            features_json = row["Meta: _dtb_features_json"]
            content_version = row["Meta: _dtb_content_schema_version"]
            if not features_json and not content_version:
                legacy += 1
                continue

            self.assertTrue(features_json, f"{row['SKU']}: v2 version without features")
            self.assertEqual(content_version, "2", f"{row['SKU']}: populated features need v2 marker")
            doc = json.loads(features_json)
            self.assertIsInstance(doc, dict, row["SKU"])
            self.assertEqual(doc.get("schema_version"), 1, row["SKU"])
            self.assertIsInstance(doc.get("features"), list, row["SKU"])
            for feature in doc["features"]:
                self.assertIsInstance(feature, dict, row["SKU"])
                self.assertIsInstance(feature.get("label"), str, row["SKU"])
                self.assertIsInstance(feature.get("description"), str, row["SKU"])
            populated += 1

        self.assertEqual(populated + legacy, len(rows))
        self.assertGreater(populated, 0, "canonical v2 content must not be silently cleared")


if __name__ == "__main__":
    unittest.main()

"""Regression tests for opt-in USG supplier evidence exports."""

from __future__ import annotations

import csv
import tempfile
import unittest
from pathlib import Path

from scrape_tsw_catalog import (
    DEFAULT_SOURCES,
    FIELDNAMES,
    OUTPUT_FIELDS,
    ScrapeError,
    load_brands,
    normalize_supplier_sku,
    write_output_atomic,
)


class TestTswExports(unittest.TestCase):
    def test_usg_brand_source_and_namespace(self) -> None:
        sources = [source for source in load_brands(DEFAULT_SOURCES) if source.source_name == "USG Sheetrock Tools"]
        self.assertEqual(len(sources), 1)
        self.assertEqual(normalize_supplier_sku(sources[0].name, "USG340800"), "340800")
        self.assertEqual(normalize_supplier_sku(sources[0].name, "USGSRS48"), "SRS48")
        with self.assertRaises(ScrapeError):
            normalize_supplier_sku(sources[0].name, "TTT340800")

    def test_full_evidence_includes_image_and_provenance(self) -> None:
        row = {name: "" for name in FIELDNAMES}
        row.update({
            "brand": "USG Sheetrock® Tools",
            "sku": "340800",
            "supplier_cost": "83.15",
            "product_name": "USG Dimpler",
            "product_description_html": "<p>Setting tool</p>",
            "image_url": "https://s3.amazonaws.com/tswfastcomfiles/product/MD-USG_340800.jpg",
            "product_url": "https://www.tswfast.com/product/USG340800",
        })
        with tempfile.TemporaryDirectory() as directory:
            dest = Path(directory) / "data.csv"
            write_output_atomic(dest, [row], FIELDNAMES)
            with dest.open(encoding="utf-8", newline="") as handle:
                reader = csv.DictReader(handle)
                self.assertEqual(reader.fieldnames, FIELDNAMES)
                exported = next(reader)
                self.assertEqual(exported["supplier_cost"], "83.15")
                self.assertEqual(exported["image_url"], row["image_url"])

            write_output_atomic(dest, [row])
            with dest.open(encoding="utf-8", newline="") as handle:
                reader = csv.DictReader(handle)
                self.assertEqual(reader.fieldnames, OUTPUT_FIELDS)
                self.assertNotIn("image_url", next(reader))


if __name__ == "__main__":
    unittest.main()

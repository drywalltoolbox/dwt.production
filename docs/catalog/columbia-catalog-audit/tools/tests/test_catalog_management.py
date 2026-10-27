from __future__ import annotations

import collections
import csv
import importlib.util
import json
import sys
import unittest
from pathlib import Path

TOOLS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(TOOLS))
import build_catalog_management as manager


class CatalogManagementTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data, cls.hashes = manager.build()
        with manager.CHECKPOINT.open(encoding='utf-8-sig', newline='') as handle:
            cls.rows = list(csv.DictReader(handle))

    def test_every_product_and_populated_field_survives(self):
        self.assertEqual(len(self.data['products']), len(self.rows))
        for source, product in zip(self.rows, self.data['products']):
            self.assertEqual((product['sku'], product['name'], product['type']),
                             (source['SKU'].strip(), source['Name'], source['Type']))
            config = {key: value for group in product['configuration'].values() for key, value in group.items()}
            for field, value in source.items():
                if field not in {'SKU', 'Name', 'Type'}:
                    self.assertEqual(config.get(field, ''), value, (source['SKU'], field))

    def test_duplicate_skus_do_not_collapse(self):
        counts = collections.Counter(x['SKU'].strip().casefold() for x in self.rows if x['SKU'].strip())
        for sku, count in counts.items():
            if count < 2:
                continue
            duplicated = [x for x in self.data['products'] if x['sku'].casefold() == sku]
            self.assertEqual(len(duplicated), count)
            self.assertTrue(all('duplicate_sku' in x['status']['review_flags'] for x in duplicated))

    def test_ambiguous_duplicate_is_not_arbitrarily_joined(self):
        row = {'Images': ''}
        choices = [{'source_image_files': ''}, {'source_image_files': ''}]
        value, basis = manager.match_disposition(row, choices)
        self.assertIsNone(value)
        self.assertEqual(basis, 'duplicate_sku_ambiguous')

    def test_summary_is_derived_and_no_platform_metadata_leaks(self):
        summary = self.data['summary']
        self.assertEqual(summary['product_rows'], len(self.rows))
        self.assertEqual(summary['images_populated'], sum(bool(x['Images'].strip()) for x in self.rows))
        self.assertEqual(summary['weights_populated'], sum(bool(x['Weight (lbs)'].strip()) for x in self.rows))
        self.assertIn(summary['matching_review_evidence'], ('current', 'stale'))
        serialized = json.dumps(self.data, ensure_ascii=False)
        for field in ('shopify_payload', 'shopify_product_id', 'shopify_variant_id', 'created_at', 'updated_at', 'generated_at'):
            self.assertNotIn('"' + field + '":', serialized)

    def test_field_review_statuses_are_aggregated_without_marking_completion(self):
        expected = collections.defaultdict(collections.Counter)
        with manager.MATCH_FIELDS.open(encoding='utf-8-sig', newline='') as handle:
            for row in csv.DictReader(handle):
                expected[row['manufacturer_sku'].strip().casefold()][row['review_status'] or 'unspecified'] += 1
        for product in self.data['products']:
            if product['field_review']['status'] == 'available':
                self.assertEqual(product['field_review']['field_status_counts'], dict(expected[product['sku'].casefold()]))

    def test_supplier_shipping_evidence_does_not_replace_csv_values(self):
        with_evidence = [x for x in self.data['products'] if x['shipping_evidence']]
        for product in with_evidence:
            config = product['configuration'].get('shipping', {})
            for item in product['shipping_evidence']:
                self.assertEqual(item['matches_current_csv'], config.get(item['field'], '') == item['source_value'])

    def test_build_is_deterministic(self):
        again, hashes = manager.build()
        self.assertEqual(self.hashes, hashes)
        self.assertEqual(self.data, again)


if __name__ == '__main__':
    unittest.main()

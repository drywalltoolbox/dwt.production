#!/usr/bin/env python3
"""List punctuation-normalized image filename leads for manual review only."""
import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / 'docs/catalog/columbia-catalog-audit/evidence/manufacturer'
DIRS = [ROOT / 'products/launch/media/media', ROOT / 'columbia-catalog-export/images']
OFFICIAL = ROOT / 'products/launch/official/dtb_official_catalog.csv'
URL = 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/'

def normalized(value):
    return re.sub(r'[^a-z0-9]', '', value.lower())

def read(path):
    with path.open(encoding='utf-8-sig', newline='') as f:
        return list(csv.DictReader(f))

def main():
    images = {}
    for directory in DIRS:
        for path in directory.glob('columbia_tools_*.webp'):
            stem = re.sub(r'_\d+$', '', path.stem[len('columbia_tools_'):])
            images.setdefault(normalized(stem), {}).setdefault(path.name, []).append(path)
    linked = {}
    for row in read(OFFICIAL):
        for url in (row.get('Images') or '').split(','):
            name = url.strip().rsplit('/', 1)[-1]
            if name:
                linked.setdefault(name, []).append(f"{row.get('SKU', '')}: {row.get('Name', '')}")
    results = []
    for row in read(AUDIT / 'empty-gallery-products.csv'):
        if row['product_assessment'] != 'no_local_candidate':
            continue
        for name, paths in images.get(normalized(row['manufacturer_sku']), {}).items():
            results.append({
                'source_row': row['source_row'], 'source_product_id': row['source_product_id'],
                'manufacturer_sku': row['manufacturer_sku'], 'manufacturer_name': row['manufacturer_name'],
                'image_basename': name, 'local_paths': ' | '.join(str(p.relative_to(ROOT)).replace('\\', '/') for p in paths),
                'intended_media_url': URL + name, 'current_dtb_products_using_filename': ' | '.join(linked.get(name, [])),
                'evidence': 'SKU and filename match only after punctuation removal; this can combine different products',
                'assessment': 'review_only_never_auto_assign',
            })
    fields = ['source_row', 'source_product_id', 'manufacturer_sku', 'manufacturer_name', 'image_basename', 'local_paths', 'intended_media_url', 'current_dtb_products_using_filename', 'evidence', 'assessment']
    with (AUDIT / 'empty-gallery-normalized-filename-leads.csv').open('w', encoding='utf-8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(results)
    print(f'{len(results)} review-only filename leads')

if __name__ == '__main__':
    main()

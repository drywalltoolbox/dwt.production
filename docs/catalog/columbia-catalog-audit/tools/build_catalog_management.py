from __future__ import annotations

import argparse
import collections
import csv
import hashlib
import json
import os
import tempfile
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / 'docs/catalog/columbia-catalog-audit'
CHECKPOINT = AUDIT / 'current-checkpoint/dtb_columbia_catalog_official.csv'
CHECKPOINT_MANIFEST = AUDIT / 'current-checkpoint/checkpoint-manifest.json'
DISPOSITIONS = AUDIT / 'review/manufacturer-rebuild-dispositions.csv'
CATEGORIES = AUDIT / 'review/shared-category-manufacturer-assessment.csv'
SOURCE_AUDIT = AUDIT / 'evidence/manufacturer/columbia-products-json-audit.csv'
MATCH_REVIEW = AUDIT / 'review/columbia-matched-review-summary.json'
MATCH_FIELDS = AUDIT / 'review/columbia-matched-fields-review.csv'
SHIPPING_CHANGES = AUDIT / 'evidence/tsw/tsw-manufacturer-shipping-changes.csv'
MANUFACTURER_BASELINE = AUDIT / 'temporary-reference/columbia-manufacturer-woocommerce-reference.csv'
DTB_REFERENCE = AUDIT / 'temporary-reference/dtb-current-columbia-woocommerce-reference.csv'
FUZZY_REVIEW = AUDIT / 'archive/previous-audits/fuzzy-price-changes.csv'
OFFICIAL = ROOT / 'products/launch/official/dtb_official_catalog.csv'
OUTPUT = AUDIT / 'current-checkpoint/catalog_management.json'
MANIFEST = AUDIT / 'review/catalog_management-manifest.json'
INPUTS = [Path(__file__).resolve(), CHECKPOINT, CHECKPOINT_MANIFEST, DISPOSITIONS, CATEGORIES, SOURCE_AUDIT, MATCH_REVIEW, MATCH_FIELDS, SHIPPING_CHANGES, MANUFACTURER_BASELINE, DTB_REFERENCE, FUZZY_REVIEW, OFFICIAL]
csv.field_size_limit(10_000_000)


def digest(path: Path) -> str:
    result = hashlib.sha256()
    with path.open('rb') as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open(encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        fields = list(reader.fieldnames or [])
        if not fields or len(fields) != len(set(fields)):
            raise ValueError(f'Invalid CSV header: {path}')
        rows = list(reader)
        if any(None in row for row in rows):
            raise ValueError(f'Extra CSV fields: {path}')
        return fields, rows


def field_group(name: str) -> str:
    if name in {'GTIN, UPC, EAN, or ISBN', 'Brands', 'Meta: schema_brand', 'Meta: schema_mpn',
                'Meta: _dtb_manufacturer_sku', 'Meta: _dtb_mpn', 'Meta: _dtb_brand_key',
                'Meta: _dtb_brand_label', 'Meta: _dtb_brand', 'Meta: schema_condition',
                'Meta: _veeqo_sellable_id', 'Meta: _veeqo_mapped_sku', 'Meta: _veeqo_product_id'}:
        return 'identifiers_and_brand'
    if name in {'Short description', 'Description', 'Purchase note'}:
        return 'content'
    if name in {'Sale price', 'Regular price', 'Cost of goods', 'Meta: _brikpanel_cogs',
                'Meta: _dtb_map_price', 'Date sale price starts', 'Date sale price ends', 'Tax status', 'Tax class'}:
        return 'pricing_and_tax'
    if name in {'Weight (lbs)', 'Length (in)', 'Width (in)', 'Height (in)', 'Shipping class'}:
        return 'shipping'
    if name in {'Published', 'Is featured?', 'Visibility in catalog', 'In stock?', 'Stock',
                'Low stock amount', 'Backorders allowed?', 'Sold individually?', 'Allow customer reviews?', 'Position',
                'Meta: _dtb_commerce_mode'}:
        return 'publication_and_stock'
    if name in {'Categories', 'Tags', 'Meta: _dtb_product_kind', 'Meta: _dtb_category_key',
                'Meta: _dtb_display_category_key', 'Meta: _dtb_is_parts', 'Meta: product_family',
                'Meta: series', 'Meta: model'}:
        return 'classification'
    if name in {'Images', 'Meta: _dtb_inherit_parent_image'}:
        return 'media'
    if name in {'Meta: _dtb_specs_json', 'Meta: _dtb_features_json', 'Meta: _dtb_content_schema_version'}:
        return 'specifications_and_features'
    if name.startswith('Attribute ') or name.startswith('Meta: _dtb_variation_') or name in {
            'Parent', 'Meta: _dtb_parent_product_sku', 'Meta: _dtb_default_variation_sku'}:
        return 'variations'
    if name.startswith('Meta: _dtb_schematic_') or name in {'Meta: _dtb_replacement_part_for', 'Meta: _dtb_compatible_tool_skus'}:
        return 'schematics_and_compatibility'
    if name.startswith('Meta: _includes_') or name in {'Grouped products', 'Upsells', 'Cross-sells'}:
        return 'product_relationships'
    if name.startswith(('Meta: _dtb_seo_', 'Meta: _yoast_', 'Meta: rank_math_',
                        'Meta: _seopress_', 'Meta: surerank_', 'Meta: _wds_')):
        return 'seo'
    return 'other_configuration'


def names_from_image_paths(value: str) -> tuple[str, ...]:
    # The manufacturer-image reference uses comma-separated URLs with no literal commas in filenames.
    return tuple(unquote(Path(urlsplit(item.strip()).path).name).casefold()
                 for item in value.split(',') if item.strip())


def manufacturer_files(value: str) -> tuple[str, ...]:
    return tuple(Path(item.strip()).name.casefold() for item in value.split('|') if item.strip())


def match_disposition(row: dict[str, str], choices: list[dict[str, str]]) -> tuple[dict[str, str] | None, str]:
    if len(choices) == 1:
        return choices[0], 'unique_sku'
    if not choices:
        return None, 'no_review_record'
    names = names_from_image_paths(row['Images'])
    comparable = [choice for choice in choices if manufacturer_files(choice['source_image_files']) == names]
    if len(comparable) == 1:
        return comparable[0], 'duplicate_sku_distinguished_by_source_image_list'
    return None, 'duplicate_sku_ambiguous'


def number(value: str) -> Decimal | None:
    if not value.strip():
        return None
    try:
        value_decimal = Decimal(value.strip())
        return value_decimal if value_decimal.is_finite() else None
    except InvalidOperation:
        return None


def json_field(raw: str) -> object | None:
    if not raw.strip():
        return None
    try:
        return json.loads(raw)
    except (ValueError, TypeError):
        return None


def build() -> tuple[dict, dict[str, str]]:
    before = {str(path.relative_to(ROOT)).replace('\\', '/'): digest(path) for path in INPUTS}
    checkpoint_info = json.loads(CHECKPOINT_MANIFEST.read_text(encoding='utf8'))
    checkpoint_hash = before[str(CHECKPOINT.relative_to(ROOT)).replace('\\', '/')]
    if checkpoint_info['checkpoint_sha256'] != checkpoint_hash:
        raise RuntimeError('Checkpoint CSV differs from its manifest; reconcile the checkpoint before generating the tracker.')
    fields, rows = read_csv(CHECKPOINT)
    _, dispositions = read_csv(DISPOSITIONS)
    _, categories = read_csv(CATEGORIES)
    _, source_audit = read_csv(SOURCE_AUDIT)
    if not {'SKU', 'Name', 'Type', 'Images', 'Sale price', 'Regular price', 'Cost of goods'} <= set(fields):
        raise ValueError('Checkpoint is missing required catalog columns.')
    by_sku: dict[str, list[dict[str, str]]] = collections.defaultdict(list)
    for value in dispositions:
        by_sku[value['source_sku'].strip().casefold()].append(value)
    by_row = {value['source_row']: value for value in categories}
    if len(by_row) != len(categories):
        raise ValueError('Duplicate source rows in category assessment.')
    by_product = {value['source_product_id']: value for value in source_audit}
    if len(by_product) != len(source_audit):
        raise ValueError('Duplicate product identifiers in manufacturer audit.')
    field_reviews: dict[str, dict[str, object]] = {}
    with MATCH_FIELDS.open(encoding='utf-8-sig', newline='') as handle:
        for entry in csv.DictReader(handle):
            key = entry['manufacturer_sku'].strip().casefold()
            current = field_reviews.setdefault(key, {'match_id': entry['match_id'], 'counts': collections.Counter(), 'decisions': []})
            if current['match_id'] != entry['match_id']:
                raise ValueError('Ambiguous manufacturer SKU in the field review: ' + key)
            current['counts'][entry['review_status'] or 'unspecified'] += 1
            if entry['review_status'] not in ('pending', 'no_action', ''):
                current['decisions'].append({'field': entry['field'], 'review_status': entry['review_status'],
                                             'recommended_source': entry['recommended_source'],
                                             'proposed_value': entry['proposed_value']})
    shipping_evidence: dict[str, list[dict[str, str]]] = collections.defaultdict(list)
    for entry in read_csv(SHIPPING_CHANGES)[1]:
        shipping_evidence[entry['manufacturer_sku'].strip().casefold()].append(entry)
    sku_counts = collections.Counter(row['SKU'].strip().casefold() for row in rows if row['SKU'].strip())
    products = []
    types: collections.Counter[str] = collections.Counter()
    matches: collections.Counter[str] = collections.Counter()
    category_status: collections.Counter[str] = collections.Counter()
    manufacturer_types: collections.Counter[str] = collections.Counter()
    publication_status: collections.Counter[str] = collections.Counter()
    field_status: collections.Counter[str] = collections.Counter()
    reviewed_product_count = 0
    images = weights = dimensions = below_cost = unknown_cost = flagged_below_cost = price_mismatch = 0
    display_skus: dict[str, str] = {}
    identity_warnings = []
    for row in rows:
        sku = row['SKU'].strip()
        typ = row['Type'].strip() or 'not_configured'
        types[typ] += 1
        publication_status[row['Published'].strip() or 'not_configured'] += 1
        if sku:
            display_skus.setdefault(sku.casefold(), sku)
        source, basis = match_disposition(row, by_sku[sku.casefold()]) if sku else (None, 'missing_sku')
        category = by_row.get(source['source_row']) if source else None
        audited = by_product.get(source['source_product_id']) if source else None
        flags = []
        if not sku:
            flags.append('missing_sku')
        elif sku_counts[sku.casefold()] > 1:
            flags.append('duplicate_sku')
        if source:
            flags.extend(x.strip() for x in source['flags'].split('|') if x.strip())
            disposition = source['disposition']
            manufacturer_types[source['source_type'].strip() or 'not_specified'] += 1
            if 'sale_below_catalog_cost_supplier_verification_required' in source['flags']:
                flagged_below_cost += 1
        else:
            disposition = 'identity_review_required'
            manufacturer_types['not_assessed'] += 1
            flags.append(basis)
            identity_warnings.append({'sku': sku, 'name': row['Name'], 'reason': basis})
        matches[disposition] += 1
        category_value = category['category_assessment'] if category else 'not_assessed'
        category_status[category_value] += 1
        has_image = bool(row['Images'].strip())
        has_weight = bool(row['Weight (lbs)'].strip())
        has_dims = all(row[name].strip() for name in ('Length (in)', 'Width (in)', 'Height (in)'))
        images += has_image
        weights += has_weight
        dimensions += has_dims
        sale, cost = number(row['Sale price']), number(row['Cost of goods'])
        regular = number(row['Regular price'])
        price_ok = (regular == (sale * Decimal('1.10')).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)
                    if sale is not None and regular is not None else None)
        if price_ok is False:
            price_mismatch += 1
            flags.append('price_pair_differs_from_10_percent_convention')
        if row['Type'] == 'variation':
            flags.append('variation_parent_not_in_sellable_only_checkpoint' if row['Parent'].strip()
                         else 'variation_parent_missing_in_csv')
        below = sale < cost if sale is not None and cost is not None else None
        if below is True:
            below_cost += 1
        elif below is None:
            unknown_cost += 1
        if not has_image:
            flags.append('no_image_in_csv')
        if not has_weight:
            flags.append('no_weight_in_csv')
        if not has_dims:
            flags.append('incomplete_dimensions_in_csv')
        if below is True:
            flags.append('sale_below_recorded_cost')
        groups: dict[str, dict[str, str]] = {}
        always_show = {'Images', 'Weight (lbs)', 'Length (in)', 'Width (in)', 'Height (in)',
                       'Published', 'Visibility in catalog', 'In stock?', 'Stock', 'Sale price', 'Regular price', 'Cost of goods'}
        for field in fields:
            if field in {'SKU', 'Name', 'Type'}:
                continue
            value = row[field]
            if value or field in always_show:
                groups.setdefault(field_group(field), {})[field] = value
        source_data: dict[str, object] = {}
        if source:
            source_data = {'manufacturer_product_url': source['source_url'],
                           'manufacturer_product_type': source['source_type']}
            if audited:
                try:
                    source_data['manufacturer_collections'] = [x['title'] for x in json.loads(audited['source_collections'])]
                except (ValueError, TypeError, KeyError):
                    flags.append('manufacturer_collections_need_review')
        structured: dict[str, object] = {}
        for name, target in [('Meta: _dtb_specs_json', 'specifications'), ('Meta: _dtb_features_json', 'features')]:
            parsed = json_field(row[name])
            if parsed is not None:
                structured[target] = parsed
            elif row[name].strip():
                flags.append('invalid_structured_data:' + name)
        field_review = field_reviews.get(sku.casefold()) if sku_counts[sku.casefold()] == 1 else None
        review_data = {'status': 'available' if field_review else 'not_compiled'}
        if field_review:
            reviewed_product_count += 1
            field_status.update(field_review['counts'])
            review_data.update({'match_id': field_review['match_id'],
                                'field_status_counts': dict(field_review['counts']),
                                'recorded_decisions': field_review['decisions']})
        measurements = []
        for entry in shipping_evidence.get(sku.casefold(), []) if sku_counts[sku.casefold()] == 1 else []:
            current_value = row.get(entry['field'], '')
            measurements.append({'field': entry['field'], 'supplier_sku': entry['supplier_sku'],
                                 'match_method': entry['match_method'], 'source_value': entry['new_value'],
                                 'matches_current_csv': current_value == entry['new_value']})
        products.append({'sku': sku, 'name': row['Name'], 'type': row['Type'],
                         'manufacturer': source_data, 'field_review': review_data,
                         'shipping_evidence': measurements,
                         'matching': {'disposition': disposition, 'dtb_sku': source['dtb_sku'] if source else '',
                                      'basis': basis},
                         'category_review': {'status': category_value,
                                             'proposed_or_existing_keys': category['category_keys'].split(' | ') if category and category['category_keys'] else []},
                         'status': {'published_csv_value': row['Published'], 'visibility_csv_value': row['Visibility in catalog'],
                                    'sale_below_recorded_cost': below, 'price_pair_matches_convention': price_ok,
                                    'review_flags': list(dict.fromkeys(flags))},
                         'configuration': groups, 'structured_data': structured})
    for path in INPUTS:
        rel = str(path.relative_to(ROOT)).replace('\\', '/')
        if digest(path) != before[rel]:
            raise RuntimeError('Input changed while building: ' + rel)
    duplicate = [{'sku': display_skus[sku], 'records': count} for sku, count in sorted(sku_counts.items()) if count > 1]
    review_manifest = json.loads(MATCH_REVIEW.read_text(encoding='utf8'))
    review_inputs = {
        'columbia-manufacturer-woocommerce-reference.csv': MANUFACTURER_BASELINE,
        'dtb-current-columbia-woocommerce-reference.csv': DTB_REFERENCE,
        'columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv': CHECKPOINT,
        'manufacturer-rebuild-dispositions.csv': DISPOSITIONS,
        'fuzzy-price-changes.csv': FUZZY_REVIEW,
        'dtb_official_catalog.csv': OFFICIAL,
    }
    stale_review_inputs = []
    for historical_path, expected in review_manifest['inputs'].items():
        filename = Path(historical_path).name
        current_path = review_inputs.get(filename)
        if current_path is None or before[str(current_path.relative_to(ROOT)).replace('\\', '/')] != expected:
            stale_review_inputs.append(filename)
    result = {'schema_version': 1,
              'catalog': {'name': 'DTB Columbia manufacturer-first rebuild', 'state': 'working_checkpoint_not_import_ready',
                          'source_csv': str(CHECKPOINT.relative_to(ROOT)).replace('\\', '/'),
                          'source_csv_sha256': checkpoint_hash, 'currency': 'USD',
                          'pricing_convention': 'Sale price is manufacturer price; regular price is sale price plus 10%, rounded to cents.',
                          'csv_columns': fields,
                          'interpretation': 'CSV values are preserved as strings; omitted configuration fields are blank in the source CSV.'},
              'summary': {'product_rows': len(rows), 'product_types': dict(types),
                          'manufacturer_product_types': dict(manufacturer_types),
                          'published_csv_values': dict(publication_status),
                          'match_dispositions': dict(matches),
                          'field_review_products': reviewed_product_count,
                          'field_review_status_counts': dict(field_status),
                          'category_review_status': dict(category_status),
                          'matching_review_evidence': 'stale' if stale_review_inputs else 'current',
                          'matching_review_changed_inputs': stale_review_inputs,
                          'images_populated': images, 'weights_populated': weights, 'complete_dimensions': dimensions,
                          'price_pairs_not_matching_convention': price_mismatch,
                          'sale_below_recorded_cost_in_checkpoint': below_cost,
                          'flagged_for_supplier_cost_review_in_reconciliation': flagged_below_cost,
                          'sale_or_cost_missing_or_invalid': unknown_cost,
                          'duplicate_skus': duplicate, 'identity_warnings': identity_warnings},
              'products': products}
    return result, before


def status() -> dict:
    if not OUTPUT.exists() and not MANIFEST.exists():
        return {'status': 'not_created'}
    if not OUTPUT.is_file() or not MANIFEST.is_file():
        return {'status': 'attention', 'reason': 'management file or manifest is missing'}
    manifest = json.loads(MANIFEST.read_text(encoding='utf8'))
    edited = digest(OUTPUT) != manifest['output_sha256']
    changed = [rel for rel, expected in manifest['input_sha256'].items()
               if not (ROOT / rel).is_file() or digest(ROOT / rel) != expected]
    return {'status': 'attention' if edited or changed else 'ok', 'management_file_edited': edited,
            'changed_inputs': changed, 'products': manifest['product_rows']}


def refresh() -> dict:
    prior = status()
    if prior['status'] == 'attention' and (prior.get('management_file_edited') or prior.get('reason')):
        raise RuntimeError('The generated management file was edited or its manifest is missing; preserve and reconcile it before refreshing.')
    data, hashes = build()
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    temp = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf8', newline='\n', dir=OUTPUT.parent,
                                         prefix='.catalog-management-', suffix='.tmp', delete=False) as handle:
            temp = Path(handle.name)
            json.dump(data, handle, ensure_ascii=False, indent=2)
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        final = status()
        if prior['status'] == 'not_created' and final['status'] != 'not_created':
            raise RuntimeError('The management output appeared while preparing the refresh.')
        if prior['status'] != 'not_created' and (final.get('management_file_edited') or final.get('reason')):
            raise RuntimeError('The management output changed while preparing the refresh.')
        for rel, expected in hashes.items():
            if digest(ROOT / rel) != expected:
                raise RuntimeError('Input changed before publishing the management file: ' + rel)
        os.replace(temp, OUTPUT)
        manifest = {'schema_version': 1, 'product_rows': len(data['products']), 'input_sha256': hashes,
                    'output_sha256': digest(OUTPUT),
                    'purpose': 'Generated read-only management view; the CSV checkpoint remains the source.'}
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf8', newline='\n', dir=OUTPUT.parent,
                                         prefix='.catalog-management-manifest-', suffix='.tmp', delete=False) as handle:
            metadata_temp = Path(handle.name)
            json.dump(manifest, handle, ensure_ascii=False, indent=2)
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(metadata_temp, MANIFEST)
    finally:
        if temp is not None:
            temp.unlink(missing_ok=True)
    return {'status': 'refreshed', 'products': len(data['products']), 'duplicate_skus': data['summary']['duplicate_skus']}


def main() -> None:
    parser = argparse.ArgumentParser(description='Inspect or generate the Columbia catalog management view.')
    parser.add_argument('--refresh', action='store_true', help='Generate the JSON view from the checkpoint and review evidence')
    args = parser.parse_args()
    print(json.dumps(refresh() if args.refresh else status(), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()

# Structured Product Features — Schema v2 Foundation

**Status:** opt-in, backward compatible, no catalog publication approved.

## Authority
- Existing official WooCommerce CSV remains authoritative for launch source content.
- WooCommerce owns persisted runtime products and metadata.
- DTB Catalog Platform validates and projects normalized customer-facing content.
- Frontend PDP and Quick View read the same validated product DTO.

## Canonical CSV v2 columns (added, empty pending editorial approval)
Located after `Meta: _dtb_specs_json`:
- `Meta: _dtb_features_json`: JSON text, schema `{"schema_version":1,"features":[{"label":"...","description":"..."}]}`
- `Meta: _dtb_content_schema_version`: text `2` only once the content has been reviewed and imported.

No feature data is currently migrated; blank fields mean legacy content stays in force. Existing `_dtb_specs_json`, `_includes_*_name/sku`, compatibility and schematic metadata remain unchanged. The version distinguishes the overall product-content contract from Features JSON schema version.

## Runtime
`DTB_ProductMeta` defines the two keys and registers them as string metadata. `DTB_CatalogProductNormalizer` exposes `content: {schemaVersion,features,structured}` only for a version-2 content record with a valid Features document. Frontend product DTO translates this into `structured_content`; the shared ProductDetail view prefers it over legacy Features extraction for both PDP and Quick View.

## Verification gates

Completed on the feature branch:
- Ordered CSV updated with two empty fields and original cells compared for all 1,134 rows.
- Structural schema contract updated, preserving existing three trailing Veeqo mapping columns.
- WooCommerce importer filter and native metadata exporter filter implemented.
- Deterministic PHP importer, Python preview and canonical CSV validation tests added.
- Shared PDP and Quick View consume the normalized structured Features representation with legacy fallback.

Still required before generated Features publication:
- Actual import/export round trip on an isolated WooCommerce instance with custom metadata export enabled.
- Browser-level responsive, variation, and accessible Quick View checks on a runtime that cannot modify live commerce.
- Product-by-product evidence review and lossless content migration with rollback and batch audit records.

The preview script operates on a legacy v1 source copy only; the canonical CSV is already at the v2 column layout. No blank template rows should be reimported to clear existing data.

**Schema migration is not publication:** WooCommerce/runtime records remain untouched.

## Implementation update (October 13, 2026)

The branch now includes the two empty metadata columns in the official CSV, and the ordered schema recognizes all existing three trailing Veeqo mapping columns. The migration retained 1,134 rows; original values were compared field by field in the migration operation. No generated Features content was inserted.

The WooCommerce importer uses the supported `woocommerce_product_import_pre_insert_product_object` hook with validation and WC CRUD `update_meta_data`. Its readback/export path uses the native CSV exporter with metadata export enabled and `woocommerce_product_export_meta_value` for scalar output. The exporter does not include metadata unless the WooCommerce **Export custom meta** option is enabled. Blank or absent content metadata does not intentionally overwrite existing populated values; malformed JSON is rejected. Dedicated CI runs PHP lint, a PHP importer contract regression, Python canonical schema validation, and a CSV migration regression. Runtime importer/exporter round-trip against WooCommerce remains required before content publication.

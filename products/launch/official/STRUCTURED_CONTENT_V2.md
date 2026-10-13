# Structured Product Features — Schema v2 Foundation

**Status:** opt-in, backward compatible, no catalog publication approved.

## Authority
- Existing official WooCommerce CSV remains authoritative for launch source content.
- WooCommerce owns persisted runtime products and metadata.
- DTB Catalog Platform validates and projects normalized customer-facing content.
- Frontend PDP and Quick View read the same validated product DTO.

## New proposed CSV columns
Insert after `Meta: _dtb_specs_json`:
- `Meta: _dtb_features_json`: JSON text, schema `{"schema_version":1,"features":[{"label":"...","description":"..."}]}`
- `Meta: _dtb_content_schema_version`: text `2` only once the content has been reviewed and imported.

No feature data is currently migrated; blank fields mean legacy content stays in force. Existing `_dtb_specs_json`, `_includes_*_name/sku`, compatibility and schematic metadata remain unchanged. The version distinguishes the overall product-content contract from Features JSON schema version.

## Runtime
`DTB_ProductMeta` defines the two keys and registers them as string metadata. `DTB_CatalogProductNormalizer` exposes `content: {schemaVersion,features,structured}` only for a version-2 content record with a valid Features document. Frontend product DTO translates this into `structured_content`; the shared ProductDetail view prefers it over legacy Features extraction for both PDP and Quick View.

## Safety / required next implementation before publication
1. Implement a WooCommerce CSV importer extension using supported importer APIs/hooks with explicit row-level error handling, validation, scalar JSON normalization, missing-vs-empty semantics, and Woo CRUD persistence.
2. Add export/readback symmetry, real import/export fixtures, variation inheritance rules and vendor-safe validation tests.
3. Update the enforced `EXPECTED_COLUMNS` and write the canonical CSV **in one reviewed atomic migration** only after downstream readers/importers support both fields. Do not change the ordered schema prematurely.
4. Run `python3 scripts/catalog/preview_content_schema_v2.py` to create a **non-importable preview** under `products/dev/catalog-content-normalization/`. It preserves every existing cell and leaves new metadata blank.
5. Review content-only AI Studio proposals against original content hashes and product-specific evidence. Never populate the new Features column from unsupported generative claims.
6. Test full PDP, Quick View, simple products, variable parents/children, missing Features and malformed metadata in a runtime confirmed not to share live commerce.
7. Add permissions, replay-safe bounded batch operations, rollback/backups and audit evidence before any product updates.

**No production CSV, WooCommerce product or staging/production runtime mutation is performed by this foundation.**

# Google for WooCommerce catalog field contract

The official catalog is SKU-keyed and follows the current WooCommerce export
column order, excluding the runtime `ID` and the unsupported `Upsells` column.
The latter remains excluded by the catalog-merchandising contract. The catalog
adds the native Google for WooCommerce product attribute meta keys supported by
the active integration. The ordered schema is enforced by
`scripts/catalog/official_catalog_schema.py`.

## Product attribute mapping

- `Meta: _wc_gla_mpn` equals `SKU` on `simple` and `variation` rows. Parent
  `variable` SKUs are DTB family identifiers and are not MPNs; all MPN fields
  remain blank on variable parents.
- `Meta: _wc_gla_brand` uses the verified WooCommerce `Brands` value on simple
  products and variable parents. The active integration does not accept a
  product-level Brand attribute on variation rows; the verified `Brands`
  taxonomy remains present on those rows for taxonomy-based feed mapping.
- `Meta: _wc_gla_condition` is `new` on simple products and variations. It is
  not set on variable parents.
- `Meta: _wc_gla_isBundle` is `yes` for sellable simple/variation toolset rows
  with a canonical `Meta: _dtb_product_kind` of `toolset`, and `no` for other
  sellable simple/variation rows. Variable parents are left unset because the
  active integration only supports the attribute on simple products and
  variations.
- Google GTIN mirrors the verified WooCommerce GTIN. The current catalog has
  no GTIN values, so Google GTIN remains blank.
- Google Size is populated only from an explicitly named existing `Size`
  product attribute. Google Material is populated only from an explicit
  structured `Material` specification. Unsupported or irrelevant attributes
  (including apparel fields) remain blank rather than receiving guessed values.

The four `_wc_gla_sync_status`, `_wc_gla_visibility`, `_wc_gla_synced_at`, and
`_wc_gla_mc_status` columns are included to preserve the current export
structure. They are integration-owned runtime state copied from the supplied
WooCommerce snapshot; catalog editors must not derive or manually alter them.

Toolset component relationships remain the canonical `_includes_N_sku` and
`_includes_N_name` fields. Every referenced SKU must resolve exactly in the
catalog. They are separate from Google's `isBundle` signal: both are validated,
and the Google flag does not create, replace, or infer a component relationship.

The live catalog remains WooCommerce-owned after import. A CSV edit does not
change live WooCommerce products or Merchant Center until an explicit reviewed
import/sync is performed.

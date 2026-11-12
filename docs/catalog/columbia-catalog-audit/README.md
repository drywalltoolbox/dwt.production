# Columbia catalog audit and rebuild

Start here for the manufacturer-first Columbia catalog rebuild. The canonical source remains [`products/launch/official/dtb_official_catalog.csv`](../../../products/launch/official/dtb_official_catalog.csv). Files in this directory are review materials, not approved WooCommerce imports.

## Current checkpoint

[Current Columbia catalog checkpoint](current-checkpoint/dtb_columbia_catalog_official.csv) is the current CSV. Its [checkpoint manifest](current-checkpoint/checkpoint-manifest.json) sits beside the CSV and is updated on refresh. This is a snapshot of the manufacturer-first working catalog, not an approved WooCommerce import. The matched reviews, dispositions and other evidence remain in their folders below.

From the repository root, check freshness with `python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py` and update the snapshot and manifest with `python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py --refresh`. Refresh refuses to overwrite a checkpoint that has been independently edited.

The current manufacturer-first review file has one WooCommerce-import-only `Upsells` column beyond the 150-column canonical schema. The checkpoint refresh accepts that one extra field only while every value is blank and the remaining canonical columns keep their exact order. This preserves the working review file's shape; the checkpoint remains explicitly non-importable.

## Current working files

- [Manufacturer-first working catalog](temporary-reference/columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv): the current proposed product lines, with manufacturer facts and provisionally carried DTB data.
- [Manufacturer baseline](temporary-reference/columbia-manufacturer-woocommerce-reference.csv): manufacturer products arranged in the official CSV field structure.
- [Current DTB Columbia reference](temporary-reference/dtb-current-columbia-woocommerce-reference.csv): existing sellable rows. Parents are excluded from this reference only; WooCommerce variation structure is retained.
- [Manufacturer-first provenance](temporary-reference/columbia-manufacturer-first-provenance.csv): source IDs, DTB matches and field origins.

The current manufacturer export contains 1,056 products and 1,060 variant records. The 1,006-row working catalog is a historical selection: it omits 46 parts kits and two knife packs that have been reopened as inclusion candidates. Two merchandise products with no SKUs remain explicitly out of scope. See the [complete current source accounting](review/current-manufacturer-source-accounting.csv), [source provenance](review/current-manufacturer-source-provenance.csv), [exception queue](review/current-manufacturer-source-exceptions.csv), and [summary with input hashes](review/current-manufacturer-source-accounting-summary.json). The per-variant accounting includes exact canonical SKU and protected-identifier evidence, plus exact-SKU matches against the local TSW cost snapshot. Current additional cost checks are recorded in the [QuickBooks item-list readback](review/current-qbo-product-service-list-cost-check.json) and [follow-up TSW email search](review/current-tsw-email-cost-search.json). The 48 reopened items have a [taxonomy disposition review](review/current-reopened-kit-pack-taxonomy-review.csv), a current [exact-SKU WooCommerce readback](review/current-reopened-kit-pack-live-readback.csv), and a separate [Veeqo authorization readback](review/current-reopened-kit-pack-veeqo-auth-readback.json). The [DTB native-kit workflow readiness review](review/current-veeqo-native-kit-workflow-readiness.json) records current integration health and the limits of the kit-conversion ability. The 46 manufacturer `Parts Kit` records map to the existing universal `part` / `parts` taxonomy semantics; the two `Joint Knife` blister packs remain taxonomy review cases because no matching display class exists. Their exact manufacturer-to-local-pixel media audit is [here](review/current-reopened-kit-pack-media-review.csv), with [public target reachability](review/current-reopened-kit-pack-media-public-reachability.csv). Current supplier cost remains unverified, WooCommerce reports all 48 SKUs absent, and Veeqo identity/configuration could not be read because the connected account returned `UNAUTHORIZED`. None of these review files is an approved import.

## Catalog management view

- [Catalog management JSON](current-checkpoint/catalog_management.json): a searchable summary and product-by-product view generated from the current checkpoint.
- [Management JSON guide](review/catalog_management_README.md) and [generation manifest](review/catalog_management-manifest.json). Refreshing the checkpoint also refreshes the JSON when its source and generated files are safe to update.

## Product and field review

- [Matched products side by side](review/columbia-matched-products-side-by-side.csv): 434 exact SKU matches and 11 strong formatting candidates; the 11 aliases still require identity review.
- [Matched field review](review/columbia-matched-fields-review.csv): one row per product field, with preliminary suggestions and pending review status.
- [How to use the matched review](review/columbia-matched-review-readme.md) and its [input hashes](review/columbia-matched-review-summary.json).
- [Manufacturer dispositions](review/manufacturer-rebuild-dispositions.csv) and [DTB dispositions](review/dtb-rebuild-dispositions.csv): the complete selected manufacturer and existing DTB sellable scopes.
- [Category assessment](review/shared-category-manufacturer-assessment.csv), [variation dependencies](review/existing-variation-dependencies.csv), and [exclusion scope](review/manufacturer-exclusion-scope.md).
- [Rebuild review status](review/rebuild-review-status.md): remaining decisions and validation issues. Historical counts inside the report are labeled.

## Supporting evidence

- [Export image library and gallery reconciliation](evidence/manufacturer/export-library-gallery-audit.md): current file integrity, selected-product coverage, excluded files and remaining publication limits.
- [Empty gallery audit](evidence/manufacturer/empty-gallery-audit-report.md): pre-update audit, approved images, remaining gaps, identity risks and public URL checks.
- [Manufacturer image and JSON audit](evidence/manufacturer/manufacturer-image-and-json-audit-report.md), [image manifest](evidence/manufacturer/columbia-manufacturer-image-manifest.csv), and [product JSON audit](evidence/manufacturer/columbia-products-json-audit.csv). The JSON audit includes the earlier, wider product selection; the image manifest reflects current exclusions.
- [TSW shipping review](evidence/tsw/tsw-manufacturer-shipping-update-report.md), [shipping changes](evidence/tsw/tsw-manufacturer-shipping-changes.csv), and [flagged matches](evidence/tsw/tsw-manufacturer-shipping-review.csv). Earlier shipping audit counts are historical after exclusions.
- [TSW cost verification](evidence/tsw/tsw-cost-verification-report.md) and [73-product cost cross-reference](evidence/tsw/tsw-cost-cross-reference.csv).
- [TSW prefixed-SKU identity review](evidence/tsw/tsw-prefixed-identity-review-report.md) and [prefix-stripped data review](evidence/tsw/tsw-prefix-file-audit-report.md).

## Directory guide

| Folder | Purpose |
| --- | --- |
| `current-checkpoint/` | The current catalog CSV, catalog management JSON and checkpoint manifest. |
| `temporary-reference/` | Current manufacturer and DTB working/reference CSVs. |
| `review/` | Product matching, field decisions, taxonomy and exclusion work. |
| `evidence/manufacturer/` | Manufacturer JSON and media evidence. |
| `evidence/tsw/` | Supplier identity, cost, shipping and prefix evidence. |
| `archive/previous-audits/` | Older comparisons, proposals, price migrations and reports; not current catalog candidates. |
| `archive/rollback-snapshots/` | Preserved earlier working CSV snapshots. |
| `tools/` | Audit and preparation scripts. Some write review artifacts or update the working reference; inspect their effects before running. |

CSV and JSON evidence was moved without changing its content. Earlier JSON manifests retain original path strings as historical evidence; use this index to find the files at their current locations.

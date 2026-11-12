# Columbia rebuild: current reconciliation and staging status

**Status: incomplete; no complete manufacturer rebuild or production import is approved.** This status reflects the current manufacturer export and the narrow canonical identity correction documented below. Historical snapshots remain unchanged.

## Complete source accounting

The manufacturer export contains 1,056 product records and 1,060 variant records. Of these, 1,054 variant records have SKUs and six do not; the six blank-SKU variants belong to two merchandise products. The current row-by-row accounting, provenance, exceptions, and input hashes are in [current-manufacturer-source-accounting.csv](current-manufacturer-source-accounting.csv), [current-manufacturer-source-provenance.csv](current-manufacturer-source-provenance.csv), [current-manufacturer-source-exceptions.csv](current-manufacturer-source-exceptions.csv), and [current-manufacturer-source-accounting-summary.json](current-manufacturer-source-accounting-summary.json). Regenerate them with `python scripts/catalog/build_columbia_source_accounting.py`.

The previous 1,006-product selection and its status counts remain review history. Current dispositions are generated from the complete export and refreshed evidence: 434 exact matches, 461 new-product candidates, 85 possible matches, 11 alternate-identifier cases, 10 identifier conflicts, two catalog SKU collisions, two duplicate-source SKU rows, and one explicit canonical identity correction. The official 2026 catalog lists the Columbia One regular, sawed-off, and tall-boy Predator tapers, three distinct bodies, and removable head as separate item numbers. Six of those modular-family rows are now treated as distinct new-product candidates; similar names for the existing taper, handle, pump, regular sawed-off taper, Tall Boy Gooseneck, and Tall Boy Box Filler are not identity proof. The source export also has two `CT45-BLACK` product records with different prices ($16.95 and $29.80); neither is selected as authoritative.

The 46 manufacturer-classified parts kits and two knife packs are reopened as inclusion candidates. They are absent from the canonical catalog and require exact product-kind/category, price, cost, and commercial review before publication. The two merchandise products (`Black T-Shirt`, product `10261614297386`; `Trucker Hat`, product `10261618098474`) are explicitly excluded from the contractor tools/parts/service scope; their six variants have no SKU.

The earlier DTB crosswalk covers 515 sellable Columbia rows, including 160 variations and 51 retained variable parents. Parent exclusion applied only to the reference file; the current canonical catalog retains its parent/variation rows. No existing Columbia product is marked obsolete or removed.

## Canonical identity correction

The current Columbia catalog source and the official 2026 manufacturer catalog identify the Columbia One Predator Taper as `C1PTAPER`. The older manufacturer product page still shows `PTAPER`; the identity direction is explicit in the current user request. The canonical catalog now uses `C1PTAPER` on the existing product row and retains its slug, price, cost, category, visibility, media, and product kind. Seventy-six exact Columbia compatibility references now point to `C1PTAPER`. `PTAPER` remains an alias in the active schematic SKU map for historical lookups.

The live WooCommerce product remains product ID `31523`, SKU `PTAPER`, because Veeqo verification is blocked: the Veeqo MCP connection returned `UNAUTHORIZED` and requires reauthentication. WooCommerce shows Veeqo mapping metadata for sellable `838819389`, mapped SKU `PTAPER`, product `411436016`. Those external values were not changed. The live product also retains its current price, cost, stock, and category. See [PTAPER/C1PTAPER correction evidence and release status](c1ptaper-identity-correction.md).

The remote `/dtb/v1/products-csv` and `/dtb/v1/import-catalog` routes are explicitly not registered in the active tracked `ProxyRoutes.php`. The tracked admin importer page calls `dtb_catalog_import_csv`, but the repository contains no implementation for that function. No complete or hash-pinned catalog import path is available in the inspected code. No live WooCommerce or Veeqo product was changed.

## Validation and remaining blockers

The canonical catalog validator passes: 150 columns, 1,133 rows, 312 variations, 821 taxonomy owners, no duplicate canonical SKUs, and no taxonomy errors. The 1,006-row working reference is not an import artifact. The official catalog has not been replaced by a full Columbia rebuild.

The 461 new-product candidates, 85 possible-match cases, 11 alternate-identifier cases, 10 identity conflicts, two catalog collisions, two source conflicts, 48 reopened kits/packs, and the `CT45-BLACK` duplicate-source conflict remain in the generated exception/review data. The 48 reopened products have neither an exact canonical COGS row nor an exact-SKU row in the local TSW cost file. That file was last committed September 9, 2026; the Columbia cost audit found no live quote, invoice, or current purchase order, so it is historical evidence only. Across all source SKUs, 244 have an exact TSW snapshot row; 18 of those belong to new-product candidates, but none establishes current cost. No price, cost, stock, fulfillment, GTIN, fitment, or dimensions have been inferred or changed for the reopened kits/packs.

The 2026 manufacturer source export distinguishes the regular taper, sawed-off taper, tall-boy taper, three separate bodies, the head, and the taper bag. Their SKUs and exact manufacturer titles are recorded separately in the current source accounting. No new product was created or merged into a variation.

## Release state

- Repository started clean on `main`; the current local diff is not committed or pushed.
- Canonical backup and validation evidence are recorded in the identity-correction report and its JSON receipt.
- Production WooCommerce remains unchanged. No stock or fulfillment authority was changed.
- No deployment occurred. The full catalog candidate and production import remain incomplete.

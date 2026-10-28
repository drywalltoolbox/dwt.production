# Roller family catalog refactor

This checkpoint records the official catalog CSV refactor. No WooCommerce import or runtime product mutation was performed. WooCommerce remains the owner of runtime product records; the CSV is the prepared source for the user's planned catalog reset and manual import.

## Manufacturer evidence

- TapeTech identifies 15TTE as its inside corner roller and 17TT/17TTE as outside corner rollers, with 17TTE designed for Flex Tape: [TapeTech product catalog](https://tapetech.com/wp-content/uploads/2026/01/TapeTech-Product-Catalog_ENG_web.pdf), [15TTE product page](https://tapetech.com/product/inside-corner-roller/), [17TT product page](https://tapetech.com/product/outside-corner-roller/), [17TTE product page](https://tapetech.com/product/flex-tape-outside-corner-roller/).
- Platinum lists its standard and ECO inside rollers separately and identifies them as inside rollers: [PT-CR](https://platinumdrywalltools.com/product/inside-corner-roller/), [PT-CRE](https://platinumdrywalltools.com/product/eco-inside-corner-roller/). Its manufacturer listings distinguish inside and outside corner rollers: [Platinum corner roller listings](https://platinumdrywalltools.com/product-tag/corner-roller/).

## Official CSV changes

`products/launch/official/dtb_official_catalog.csv` was refactored without changing manufacturer sellable SKU rows:

| Brand | Family structure in CSV | Category mapping |
| --- | --- | --- |
| TapeTech | 15TTE is a standalone simple inside roller. Synthetic variable parent `TT-OUTSIDE-CORNER-ROLLER` owns 17TT (standard) and 17TTE (Flex Tape). | Inside: `Automatic Taping Tools > Corner Rollers`. Outside parent and variations: both `Corner Bead Tools > Corner Bead Rollers` and `Automatic Taping Tools > Corner Rollers`. |
| Platinum | Synthetic variable parent `PT-INSIDE-CORNER-ROLLER` owns PT-CR (standard) and PT-CRE (ECO). PT-OCR is a standalone simple outside roller. | Inside: `Automatic Taping Tools > Corner Rollers`. Outside: both `Corner Bead Tools > Corner Bead Rollers` and `Automatic Taping Tools > Corner Rollers`. |
| Columbia | Corrected the obvious `COBCR` title typo and aligned the outside-profile variants' `product_family` label. | Existing outside-family dual-category mapping retained. |

The two legacy synthetic parent SKUs were replaced with precise parent SKUs. All six sellable manufacturer SKUs (`15TTE`, `17TT`, `17TTE`, `PT-CR`, `PT-CRE`, `PT-OCR`) remain present. Parent descriptions, model options, images, default variations, product-family metadata, and SEO fields were updated to reflect the split.

The CSV retains its 1,134 rows, 151 columns, UTF-8 BOM, and CRLF line endings. Structural/taxonomy validation passed after the refactor. A byte-for-byte pre-edit backup is stored beside the CSV as `dtb_official_catalog.csv.pre-manufacturer-family-refactor-20261028.bak`.

## Runtime migration still required

- `dtb-schematics/Data/SkuSchematicMap.php` still associates the TapeTech and Platinum outside schematic family IDs with the former mixed product-family SKUs. Reconcile those mappings against the new CSV families before importing the rebuilt catalog.
- Historical WooCommerce product/variation IDs, external links and provider projections are not changed by this CSV edit. Rebuild/import must be rehearsed against the installed importer and followed by SKU, variation, category, purchasability, and schematic reconciliation.
- No production-readiness claim is made for the full 1,134-row CSV. The broader read-only catalog audit still reports unrelated commerce, URL, media, inventory-projection, compatibility, and content-review findings.

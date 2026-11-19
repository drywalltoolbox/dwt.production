# Official catalog comprehensive audit — 2026-11-19

## Scope and evidence

Read-only audit of `products/launch/official/dtb_official_catalog.csv`, the November 19 WooCommerce export `products/launch/official/wc-product-export-19-11-2026.csv`, the prior catalog revision (`0620df6`), the active importer schema/validator, catalog contracts, SEO source, and storefront/sitemap implementation. Catalog SHA-256: `9a713b8518272b279f682fb3d278bec67148445a301c7aa8d835a36a127de0cc`.

This is source-level evidence. It does not establish that a future WooCommerce import, Google feed, live sitemap, crawler response, or rendered page behaves as expected. No CSV or WordPress changes were made.

## Executive disposition

The file is structurally valid and has strong stable identity coverage, but **should not be imported as a complete replacement snapshot until the MPN mapping and 61 removed cross-sell relationships have an explicit disposition**. The most consequential issue is treating every retailer SKU as an MPN: this makes the validator pass while contradicting manufacturer MPN values in the November 19 Woo export. Preserve SKU as the importer match key; treat manufacturer MPN as separate, evidence-backed data.

### Proven structural and snapshot facts

- 1,133 rows, 173 ordered columns, UTF-8 BOM and CRLF; 715 simple, 106 variable parents, 312 variations.
- The canonical schema validator passes. All 1,133 SKUs are unique and match one row in the same-day Woo export; no catalog SKU is missing from that export.
- Woo export `ID` and empty `Upsells` are intentionally absent under the source contract. Woo importer updates by SKU are supported, so omission of `ID` is acceptable for SKU-keyed updates; do not use product names as identity.
- Core type, published status, visibility, regular/sale price and stock values match the same-day export. All active prices were present and aligned. 106 variable parents have blank price as expected because sellable variations own prices.
- Catalog-level description, brand, category, structured specifications and image field are populated on all 994 published rows. This is field presence, not proof of factual accuracy or media validity.
- The 53 blank commerce-mode fields and 21 blank-price findings belong to draft products; these need resolution before publication, not as currently-live product errors. Woo `Published=-1` means draft, `0` private, `1` published. A separate audit's `-1` enum findings were false positives.

## Priority findings and required work

### P0 — MPN values are not safely inferred from SKU

The new schema rule requires `Meta: schema_mpn`, `Meta: _dtb_mpn`, and Google for WooCommerce `_wc_gla_mpn` to equal SKU for every simple and variation, and blanks parent MPNs. That rule is internally consistent but not evidence of manufacturer assignment. The November 19 export itself has non-SKU MPN values and blank MPNs:

| Field | Same as export | Catalog value where export differs | Export value where catalog differs |
|---|---:|---:|---:|
| `Meta: schema_mpn` | 707 | 341 rows filled where export blank | 60 rows blanked, 25 rows changed |
| `Meta: _dtb_mpn` | 552 | 562 rows filled where export blank | 17 rows blanked, 2 rows changed |
| `Meta: _dtb_manufacturer_sku` | 1,116 | 0 | 17 rows blanked |
| `Meta: _wc_gla_mpn` | 106 | 1,027 populated for simple/variation rows | source meta column absent in export |

Google defines MPN as the manufacturer's identifier and warns against retailer-specific internal SKUs when a manufacturer identifier exists. The retailer-SKU exception applies when DTB is the manufacturer/only seller and no assigned MPN exists; that condition has not been established across this catalog. The export conflict is decisive evidence that blanket SKU substitution is unsafe even before manufacturer-site checking. Examples include FA3 → FA308, FA2 → FA208, CR-3 → CR3, BH9-42 → BH9, HFFB14 size variants → HFFB14, and PT-GN → GN.

**Disposition:** do not let the equality validator force the MPN to SKU. Keep SKU as importer identity. Separate source-confirmed manufacturer MPN from Woo/Google feed mapping. For each row, preserve a manufacturer MPN only with authoritative evidence; otherwise leave MPN blank. Verify whether Google for WooCommerce's own exporter supports a distinct fallback field and whether GTIN/identifier-exists is configured appropriately. Validate actual generated feed output before syncing Merchant Center. Do not infer GTIN.

### P1 — 61 live cross-sell links are removed

The catalog removes cross-sells from 61 products that had values in the same-day Woo export; no new cross-sells were introduced. All 61 are currently published. Examples include 32/42/55-inch compound tubes and finishing boxes linking to compatible tools/parts, and 8 TapeTech handle SKUs linking to `804003F`. Since cross-sells are present in the official import structure, importing these blanks can erase live product relationships. Reconcile the 61 exact-SKU rows against the intended relationship authority, then restore supported relationships or document intentional removals before import. `Upsells` being excluded does not imply `Cross-sells` should be cleared.

### P1 — Product data quality work still open

- **Images:** 2,619 assignments across 1,725 unique URLs; 111 rows use placeholder imagery (61 published), including 47 TapeTech rows (42 published). URL presence does not verify HTTP response, image content, correct SKU, or production availability. A 100% nonblank image-field result is therefore not image coverage. Review the 42 TapeTech placeholder rows in bounded batches, then other placeholder rows. Twenty catalog basenames are absent from the local media tree, but remote URLs were not checked; do not call these broken URLs without HTTP/media evidence.
- **Fitment/compatibility:** 1,607 target references resolve exactly (125 product targets and 1,482 published-variation targets), with no malformed/ambiguous/unresolved target references. Yet 198/618 published parts (32.0%) lack structured compatibility or replacement links; 43/73 published part variations lack fitment. Missing links are discovery gaps, not proof of incompatibility. Review the 25 known missing fitment mappings by exact SKU and authoritative schematic/manufacturer evidence; do not generate fuzzy links.
- **TapeTech discoverability:** the earlier 201 hidden target is now 189 visible/index-eligible and 12 retained hidden with noindex. The 12 are one duplicate `90TT` plus 11 complete tools/accessories currently classified in parts taxonomy. Resolve the duplicate and taxonomy classification with exact product evidence. The 189 visible rows are not thereby proven to be valid replacement parts. Do not block noindex pages in `robots.txt`.
- **Classification:** product-kind counts are 745 parts, 331 tools, 36 stilts, 17 toolsets, 3 accessories, and 1 blank. The structural check has no taxonomy mismatch, but structural consistency does not prove business classification. Resolve the 11 TapeTech misclassifications and the blank product-kind row.
- **Price/publication:** 21 purchasable rows have blank prices, all drafts. 53 draft simple products have blank commerce mode. Decide price and commerce mode before publishing; do not expose them merely to remove validator findings. Variable-parent blank prices are expected.
- **Shipping specs:** 304 published non-variable purchasable rows lack weight; 554 published simple/variation rows lack at least one dimension. Research only where required by the actual carrier/product policy. Shipping class is blank catalog-wide and its requirement is unconfirmed. Do not invent measurements or make catalog stock a competing Veeqo inventory authority.
- **Copy accuracy:** a bounded claim audit produced 1,192 evidence-review flags across 637 SKUs (including OEM/genuine, guarantee, material, performance, precision and universal-fit wording). These are review flags, not proof that claims are false. Review against manufacturer/DTB evidence before rewriting. SEO title/description lengths and uniqueness pass local heuristics; these are not Google ranking requirements. Four near-duplicate descriptions (FA379/FA380 and TTCFS/TTCFS-M) merit distinctness review.
- **SEO/plugin state:** 324 `_dtb_seo_noindex=1` rows comprise 312 variations and 12 hidden products. 673 published product owners are visible and eligible by local source rules. Yoast/RankMath/SEOPress/SureRank/WebSEO product metadata columns are blank. Do not map or clear them until the active SEO owner and importer behavior are verified. Sitemap inclusion is only a weak canonicalization signal, not an indexing switch.

### P1 — Google feed bundle and attribute mapping

GLA `isBundle=yes` is assigned to toolset simple/variation rows based on DTB product kind. Google says `yes` is for a retailer-created bundle combining a main product with different accessories/add-ons for one package price; it must not label manufacturer-defined bundles. Verify each of the 17 toolset rows' bundle provenance, main item, offer/package, price and feed representation. Taxonomy label alone does not prove the Google attribute. Parent variable toolset row is blank while sellable children are marked; verify the plugin emits the intended value for each item.

The product-level attributes do not configure the plugin's global attribute-mapping rules. Confirm mapping and generated feed values in the Google for WooCommerce integration. Blank GTIN is correct when no GTIN evidence exists; this does not establish that products lack manufacturer-assigned GTINs.

## Revision and importer reconciliation

- Against prior catalog commit `0620df6`, current has no SKU additions/removals, but 8,572 field values changed across all 1,133 SKUs. There are 1,024 protected-identifier field changes, concentrated in MPN/Google attributes, plus visibility changes for 189 products. Each protected-field change needs source evidence, not just schema consistency.
- New Google integration state columns (`_wc_gla_mc_status`, `_wc_gla_sync_status`, `_wc_gla_synced_at`, `_wc_gla_visibility`) are snapshot/integration-owned state according to the new catalog contract. Do not import these as writable product truth or blank them. Map only the intended product attributes after inspecting Woo importer behavior and plugin field ownership.
- `_brikpanel_cogs` differs from the Woo export on 271 rows, while native `Cost of goods` matches the export. The official source README uses `Cost of goods` as the Veeqo projection. Reconcile the 271 DTB meta values with authoritative cost sources; do not assume the new values are a safe mirror or a replacement for native cost.
- All rows have `Tax status=taxable`; tags are blank on 691 rows. Neither pattern is automatically wrong. Confirm tax policy and intended tag taxonomy; do not add generic SEO tags.
- Categories changed on 312 rows, mostly variation rows; descriptions changed on 312 rows. Check variation inheritance/import behavior so imported children do not create duplicate taxonomy or contradictory PDP copy.

## Google SEO and active storefront implications

Google's ecommerce guidance prioritizes links through menus/categories to product pages; a sitemap/feed can supplement discovery when direct links do not cover all products. Product pages should be reachable through crawlable anchors. Sitemap inclusion is a weak canonical signal, and a URL omitted from the sitemap is not automatically noindex. The hidden-product policy must therefore align Woo catalog visibility, `_dtb_seo_noindex`, canonical URL, internal links and sitemap output.

Source review of active storefront code found a specific pagination risk: `/parts` pagination uses buttons/client state rather than sequential crawlable `<a href>` URLs; current page-state canonical handling resolves to the base route. Google recommends sequential links and distinct self-canonical URLs for paginated pages, and recommends containing faceted/sort URLs. The WordPress taxonomy route renderer does emit anchors but currently canonicalizes pagination to the route root and marks pagination noindex. `/products` server output provides a separate linked directory/sitemap discovery path, so this does not prove products are undiscoverable. It does merit an architecture decision and live rendered/source-response verification before SEO changes.

Relevant Google references:

- [Ecommerce site structure and crawlable product links](https://developers.google.com/search/docs/specialty/ecommerce/help-google-understand-your-ecommerce-site-structure)
- [Pagination, incremental loading, and unique page URLs](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)
- [Canonical signals and sitemap strength](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [MPN and unique product identifier rules](https://support.google.com/merchants/answer/6324482?hl=en), [UPIs](https://support.google.com/merchants/answer/160161?hl=en)
- [Google bundle definition](https://support.google.com/merchants/answer/6324449?hl=en)
- [WooCommerce CSV importer/exporter](https://woocommerce.com/document/product-csv-importer-exporter/)

## Import gate and sequencing

1. Resolve MPN/source semantics; revise the validator/contract so a false SKU=MPN invariant cannot silently pass.
2. Reconcile all 61 cross-sell deletions and 17 manufacturer SKU field blanks by exact SKU.
3. Confirm Woo importer mapping for 173 columns, blank-cell behavior, protected identifiers, variation categories/descriptions, and GLA integration-owned state. Generate a dry-run field diff against current Woo data before import.
4. Research the 25 missing fitments and placeholder imagery using manufacturer/schematic evidence; separately verify production image URLs and correct product image content.
5. Complete TapeTech duplicate/classification decisions and verify visible parts links, canonical URLs, sitemap membership, and live `noindex` directives.
6. Validate Google global attribute mapping, representative feed output (including MPN, brand, GTIN presence, condition and bundles), and Merchant Center diagnostics before sync.
7. Review remaining factual copy, physical shipping data, draft prices/modes, and SEO pagination/canonical source behavior.

Do not interpret the earlier readiness scan's headline “1,354 blockers” as 1,354 real catalog blockers. It counted all 1,133 normal omitted-slug cases as blockers, treated Woo's valid `-1` draft code as invalid, and counted current-state proposals/review queues as import blockers. It is a useful candidate queue, but its aggregate verdict is not accepted.

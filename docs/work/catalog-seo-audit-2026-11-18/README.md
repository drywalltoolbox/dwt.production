# Official catalog SEO and technical audit — 2026-11-18

## Scope and evidence

Reviewed the official WooCommerce-format source `products/launch/official/dtb_official_catalog.csv` as the catalog authority and reconciled its 1,133 SKU rows against the latest available WooCommerce export (`wc-product-export-17-11-2026-1794964801030.csv`): every catalog SKU matched. The catalog has 150 ordered columns. The audit covered identity, product/variation structure, visibility, SEO fields, category and tags, identifiers, images, structured specifications/features, relationships, and the official validator/enrichment findings. Runtime facts below come from direct live checks performed during this audit session; catalog-only findings are labelled as such.

## Changes made

- Refined SEO title, description, and focus-keyword values on visible, published, non-variation products only. Exact row/field before-and-after values are recorded in `catalog-seo-change-manifest.json`.
- Changed 34 visible product SKUs across 42 metadata fields: 20 titles, 8 descriptions, and 14 focus phrases.
- Shortened the 20 titles that were exactly 60 characters to fewer than 60 while retaining product/model/size terms supported by their rows.
- Revised the 8 descriptions over 158 characters to 140–158 characters, using source-row facts. No descriptions were generated for every row wholesale, and no SEO plugin fields were populated.
- Disambiguated repeated focus phrases using existing SKU, size, product family, or model facts.
- Corrected product structured-data Offer URLs in `frontend/src/utils/schema.js` to use the public `/products/{slug}` route instead of potentially stale WooCommerce `/product/{slug}` permalinks. The product-page canonical contract remains the source of truth.

No product identity, price, stock, visibility, taxonomy, image, GTIN, compatibility, fitment, variation, or bundle relationship values were changed. No WordPress content or runtime data was mutated.

## Validation

- Official `scripts/catalog/run-official-catalog-enrichment.ps1`: **passed** after catalog edits. Catalog SHA was stable during that validation; the pipeline reported 994 published-scope rows, 685 SEO-eligible parent/simple products, and zero blocking SEO findings.
- Production frontend `npm run build`: **passed**, including frontend contracts, webpack compilation, schematic release preparation, SiteGround routing assertion, and public environment checks.
- `git diff --check`: **passed**.
- A pre-edit catalog backup and row-level manifest were created before editing; the manifest is retained here. The backup is not part of the release.

## Catalog findings that remain intentionally unresolved

- 1,133 rows total: 715 simple, 106 variable parents, and 312 variations. The published sitemap contains 484 product routes, matching the 484 visible published non-variation catalog rows. Variations are represented through their parent route.
- 201 published TapeTech simple parts are hidden from catalog visibility and omitted from the sitemap, but live product checks showed a sampled hidden part directly resolves with `index, follow`. Decide whether these parts should be publicly discoverable/indexable or explicitly noindexed. No setting was changed pending owner direction.
- 484 visible products have no GTIN. The official catalog policy treats absent GTIN as an informational metric unless an authoritative source/requirement exists; none was established for this audit, so no values were invented.
- 42 visible products have blank tags. Tags are not a substitute for taxonomy or compatibility, and no evidence-based tag source was available for safe blanket completion.
- The relationship audit found 198 published part owners without structured fitment/replacement relationships, including 25 of the 201 hidden TapeTech parts. Existing exact relationships validated; missing relationships need manufacturer or reviewed internal evidence and were not inferred.
- The catalog media field was 100% populated in the published-scope audit, but the actionable catalog audit still identified 19 visible products using placeholder media. The latest WooCommerce export contains the same placeholders, so replacing them needs verified manufacturer imagery or product-specific photography.
- Current SEO coverage among the 484 visible published product routes: zero blank title/description/focus values; zero duplicate titles; all titles under 60 characters after this change; all descriptions at or below 158 characters. 338 descriptions are 140–158 characters; the remaining 146 are shorter but were not padded without clear content need and evidence.
- Compatibility review found 545 research rows and 1,607 existing exact compatible-tool references in the audited scope. Remaining cases stay in the official remediation/research workflow; there were no current evidence manifests to support filling gaps automatically.

## Live technical state and baseline

- Live `/products/platinum-drywall-starter-finishing-set` and `/products/columbia-automatic-taper?variant=33784` checks returned 200. Product canonicals pointed to `/products/{slug}`; the variation canonical pointed to the parent product route. Robots and social metadata were present, with product imagery in social previews.
- The live custom sitemap `/sitemap.xml` returned 200 and exposed product, category, brand, and static sitemaps. Default WordPress sitemap endpoints returned 404; they are not the configured sitemap authority.
- Live Search Console query for **2026-10-20 through 2026-11-16**: 10 impressions, 0 clicks, 0% CTR, average position 9.3. Returned page rows were homepage (6), `/parts`, `/products`, `/products/brands`, and `/repairs` (1 each); this window had no product-detail rows. Treat this as a low-volume baseline, not evidence of product ranking performance.
- Sampled legacy `/product/{slug}` URLs returned 404, while corresponding `/products/{slug}` routes returned 200. Repo documentation intentionally defines `/products/` as the only canonical public product route and old `/product/` paths as non-aliases. Review Search Console coverage and legacy backlinks before deciding whether a bounded 301 map is warranted; no broad redirect was added.
- A live direct request from this audit environment to public contact/repair pages was denied (403), so no service-area/location wording was verified. Do not add Plymouth/Twin Cities claims until confirmed by the business owner or an accessible authoritative source.
- The schema URL correction is in the local frontend build and has not been published by this task. New WordPress deployment was unnecessary because no WordPress source was changed.
- Earlier code review found no active SEO plugin in the connected WordPress plugin inventory. This is an MCP-observed plugin list, not a fresh wp-admin screenshot.

## Follow-up

Capture Search Console coverage and query performance again around 7 and 30 days after frontend deployment and catalog import, recording exact dates and route-level changes. Import the revised catalog only through the approved WooCommerce/catalog workflow after the owner resolves the 201 hidden-part indexation decision and verifies normal import safeguards. Do not import the audit packet or intermediary generated files.

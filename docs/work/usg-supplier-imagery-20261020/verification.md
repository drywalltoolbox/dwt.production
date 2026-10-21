# USG Sheetrock Tools image enrichment — verification (2026-10-20)

## Scope
- Runtime owner: WooCommerce on https://drywalltoolbox.com. Canonical source: `products/launch/official/dtb_official_catalog.csv`.
- 81 exact brand-scoped simple draft SKUs received new WebP WordPress media attachments. The existing variable parent `USG-MAGNESIUM-STILTS` and variations 340925/340926 retain their existing galleries.
- Nine SKUs (340310, 340311, 340401, 340405, 340413, 340414, 340416, 340417, 340800) received higher-resolution official manufacturer assets from `sheetrocktools.com`, converted from AVIF to WebP at native pixel dimensions (approximately 786–800 px).
- The other 72 retained supplier-sourced pictures from TSW Fast but were re-encoded at native resolution into quality-90 WebP, saving 1,961,849 bytes in aggregate. None were synthetically upscaled. SKU-based filenames and descriptive alt text were set.

## Evidence and safety
- All 81 optimized full-size image URLs returned HTTP 200, `image/webp`, and the expected dimensions/byte lengths during live GET verification. Initial high-concurrency checks produced transient 503 responses; sequential retry verified 81/81.
- All nine higher-resolution products had working 300×300 WordPress-generated mobile thumbnails, plus live originals.
- WooCommerce readback confirmed image IDs and draft status for representative products; an initial SKU-image batch association error was corrected and directly verified for every affected product (IDs 18352, 18351–18347, and 18344).
- The official catalog projection changed exactly 81 `Images` cells across 1,134 rows. Every other field was compared row-by-row and confirmed unchanged. The original CSV is retained in an ignored, SHA-256 verified local backup.
- No product SKUs, MPNs, retail prices, costs, inventory quantities, statuses, parent-variation associations, or other commerce fields were intentionally modified.
- The authenticated supplier session had expired; no claim is made that supplier costs were freshly scraped. Prior cost evidence is unrelated to this image pass.

## Residual risk / next quality milestone
- **71/81** final primary images are still under 600 px on their shorter side. WebP reduces transfer size but does not recover photographic detail. Obtain genuine high-resolution licensed manufacturer/dealer source photos before claiming full desktop zoom/retina quality across the catalog.
- Nine manufacturer's images are near 800 px, not a universal 1200 px target.
- URL and WordPress thumbnail verification were performed; full browser-rendered visual QA across device viewports was not.
- Legacy media attachments were intentionally retained for rollback; clean up only after a separate media-reference audit.
- Private evidence, processing scripts and manifests remain under ignored `scripts/supplier-catalog/results/cost/usg*`. Do not commit supplier account prices or auth cookies.

## Rollback
Use the previous WordPress attachment ID to revert an individual product image. Restore its `Images` CSV value from the verified pre-optimization backup when necessary. Do not delete old attachments before checking references.

## Follow-on upscale — 2026-10-20

- User authorized scaling the remaining **71** sub-600 px original supplier pictures. Derived images were created from the **existing supplier photographs**, not generated or retouched representations of different replacement parts.
- Applied source-preserving Lanczos resize with a bounded 4x magnification and mild unsharp masking (radius 1.25, percent 78, threshold 3). Output: **63 images at 1200×1200**, **7 at 1222×1200**, and **1 at 1279×1200**, retaining native image aspect ratios.
- Output encoded as WebP (quality 88, method 6); created 71 SKU-named image files totaling ~1.32 MB. **These are computationally upscaled images, not genuine 1200px photographic originals**; claims of recovered original optical detail would be incorrect.
- All 71 processed image attachments were uploaded and assigned to their corresponding simple WooCommerce **draft** products using exact mapped product IDs; the prior native-resolution WebP attachments remain available for rollback.
- Canonical catalog source: precisely 71 additional `Images` cells were updated in `products/launch/official/dtb_official_catalog.csv`. A SHA-256 checked backup was made and a full row-by-row comparison confirmed no other column changed (1,134 catalog rows).
- Raw inputs, source provenance, pixel dimensions, per-file SHA-256 digests, and output manifest are retained locally under the ignored `scripts/supplier-catalog/results/cost/usg*` paths. No confidential supplier costs/cookies are committed.
- **Quality caveat:** upscaling increases display dimensions and may improve interpolation/edge rendition, but cannot replace missing actual product detail from original 300×300 imagery. Keep the original photos and treat manufacturer-native high-resolution replacements as the ultimate quality objective.

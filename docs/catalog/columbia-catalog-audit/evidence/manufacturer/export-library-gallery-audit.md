# Columbia export image library and gallery reconciliation

Audit date: 2026-10-28. This is a local reconciliation of the manufacturer export against the manufacturer-first working catalog. It is not an import approval or a live storefront verification.

## Findings

- All 1,563 files in `columbia-catalog-export/images` are referenced by the supplied `product_variants.csv`; every referenced basename exists locally. Pillow identified each file as WebP and verified its file structure without errors.
- The 1,006 selected working rows reconcile to the export by the existing provenance source-row reference and exact SKU. The selected source rows explicitly associate 1,396 distinct local images with 531 products. Every one of those images is already present in its corresponding working `Images` field, in source order.
- The remaining 167 distinct files are associated only with export rows outside the current working selection. They were not assigned to different products based on similar filenames. One shirt image is referenced by multiple excluded export rows.
- An additional 111 working rows have images beyond their explicit manufacturer-export image lists, reflecting the earlier identity-reviewed gallery remediation. In total, 642 working rows have a populated gallery and 364 have an empty `Images` field. A blank variation gallery may still use its parent image at runtime.
- There are 92 groups of byte-identical files with different filenames. Identical bytes do not establish that separate product identities or size-specific photos are interchangeable, so they were not used to infer additional associations.

The existing [manufacturer image manifest](columbia-manufacturer-image-manifest.csv) records the selected manufacturer associations, source IDs, source URLs, local files and intended public URLs. The [empty gallery audit](empty-gallery-audit-report.md) documents additional approved links and unresolved identity risks. Manufacturer descriptions were not used to establish identity in this reconciliation.

No gallery fields needed changing for the explicit manufacturer associations. The intended `drywalltoolbox.com` upload URLs in the working catalog are links, not evidence that every local image has been uploaded or is reachable. The earlier public checks covered only a subset and found unavailable or indeterminate responses; this audit did not upload files, recheck every URL, inspect each photo for visual accuracy, or verify live WooCommerce galleries. The catalog remains a review working file and must not be imported as-is.

The checkpoint freshness check reported that its working source and checkpoint were unchanged, while the management view reported an updated official-catalog input hash. That status requires the existing checkpoint workflow's separate reconciliation; it is not evidence of an image mismatch.

# Columbia manufacturer image gallery rebuild

Generated 2026-11-01 from the existing manufacturer image manifest. Each image is mapped through the manifest's source SKU to one unique Columbia catalog row using an exact match in catalog SKU, manufacturer SKU, MPN, or schema MPN. Fuzzy matching and cross-assignment are excluded.

## Applied scope

- Columbia simple, variation, and variable rows: 566
- One-to-one catalog rows mapped: 275
- Galleries replaced with manufacturer image order: 112
- Manufacturer images linked: 731
- Catalog rows held unchanged without an exact match: 291
- Source SKUs with no exact catalog identity: 251
- Ambiguous source SKUs held back: 5
- Source bytes: 702,142,666; optimized bytes: 264,840,112; reduction: 62.3%

New WebP assets preserve their aspect ratios, are capped at 1600px on the longest edge, and use lossless WebP encoding after high-quality Lanczos resizing. They use the standard normalized production filenames, replacing the matching existing gallery assets. The image manifest records source and destination hashes, dimensions, source IDs, match field, old gallery, and URL.

## Validation limits

Source identity and image order are evidenced by the existing manufacturer product export and image manifest. This run does not independently certify every photograph against a physical product. The output folder is the repository upload package; this script does not transfer files to production or mutate live WooCommerce. After upload, verify each public URL and live gallery before considering the deployment complete.

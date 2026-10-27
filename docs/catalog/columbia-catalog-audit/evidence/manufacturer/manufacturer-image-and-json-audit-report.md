> **Current scope:** The manufacturer references now contain 1,006 products after excluding 46 manufacturer-classified part kits and two product packs. Counts below describe the earlier 1,054-product audit and are retained as historical evidence; see [manufacturer-exclusion-scope.md](../../review/manufacturer-exclusion-scope.md) for current counts.

# Columbia manufacturer images and product JSON audit

Snapshot audit date: 2026-10-26. `products.json` was evaluated against the 1,054 non-merchandise variants in the manufacturer-first reference by source product ID and variant ID. Names, SKUs and prices reconciled across the product JSON, variant CSV and reference. Of their descriptions, 834 were identical and 220 differed only in whitespace; the reference CSVs now use the exact JSON description HTML.

## Image mappings

- 573 products have one or more linked local manufacturer images; 481 have none and now have an empty `Images` field in both manufacturer reference CSVs. No image is inferred from a similar SKU or an existing DTB image.
- The source CSV explicitly associates 1,561 local WebP files with those 573 products. Every mapped file exists directly under `columbia-catalog-export/images`; all 1,561 file paths are unique to a source product. The remaining two of the 1,563 local files belong to excluded merchandise.
- Each populated `Images` entry uses `https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/` plus the exact local basename in source order. [columbia-manufacturer-image-manifest.csv](columbia-manufacturer-image-manifest.csv) records source product and variant IDs, filename, local file size, intended public URL and manufacturer image URL. Mapping establishes the intended target path, not that the image is already uploaded.
- A 12-URL HTTP HEAD spot check produced three HTTP 200 image/webp responses (`FA280`, `10FBB`, `GN`), four 404 responses (`14-4.7-0.4-S-C-C`, `FFBR-9-5.5`, `OPPK8`, `SF5`), and five 503 responses (`3.5SF`, `FFBR-40-7`, `CFB5B`, `COBCR`, `CF3B`). A 503 does not establish whether a file exists. The other URLs were not individually checked. No media was uploaded or deployed.
- In the working CSV, 24 existing variation rows with explicit parent-image inheritance were changed to `0` so explicitly mapped variation galleries are not intentionally replaced by parent imagery. The storefront code can nevertheless fall back to parent images when a variation has no selected image, so an empty CSV field does not prove that a live product page will display no image. There are 23 existing variation rows in the working CSV without a manufacturer image; runtime/parent behavior needs separate testing. No parent products or storefront code were changed.

## Manufacturer JSON data

[columbia-products-json-audit.csv](columbia-products-json-audit.csv) records all 1,054 included products with source product/variant IDs, source URL, vendor, type, tags, collections, variant title, availability, price, options, source description HTML/text, local images and intended URLs. The JSON product names, SKUs, descriptions and prices are the reference facts in the manufacturer-first files. The source availability flag has not been used to set DTB inventory: Veeqo owns inventory. Variant `grams` is recorded as supplied for review; it is not assumed to be an accurate shipping weight or used to overwrite dimensions. Technical facts are retained in manufacturer text, but structured specifications are not fabricated from descriptive prose.

The manufacturer-first CSV remains a non-import-ready working reference: unresolved SKU collisions, categories, inherited relationships, prices below recorded cost and other field reconciliation remain. The canonical official catalog CSV was not changed.

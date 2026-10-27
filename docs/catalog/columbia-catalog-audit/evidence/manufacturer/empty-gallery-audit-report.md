# Columbia checkpoint: empty image gallery audit

**Update:** The audit below describes the pre-implementation snapshot. After subsequent identity review, 112 image URLs were added to 111 product galleries; 364 checkpoint product rows remain empty. See [approved images](empty-gallery-approved-images.csv), [candidate decisions](empty-gallery-implementation-decisions.csv), and the [current missing-images list](products-missing-images.csv). Selection used exact unique SKU, product name and size, existing DTB image link, historical gallery mapping, local filename and file evidence, and public URL response. Product descriptions were not used for approval. Name conflicts, low-visibility images and other flagged candidates remain on hold. These checks do not independently establish manufacturer authorship of the photographs.

This audit covers the 1,006 product rows in the manufacturer-first checkpoint. **475 rows have an empty `Images` field**; 531 have at least one value. The empty rows include 452 simple products and 23 variations. A blank variation field does not establish that the live storefront has no image: a parent image may be displayed as a fallback. No product images or catalog fields were changed by this audit.

## Findings

| Assessment | Products | Meaning |
| --- | ---: | --- |
| Existing DTB link and local image candidate | 128 | The existing matching DTB SKU links a locally available file. The product photo still needs visual and identity review. |
| Candidate requires additional review | 23 | A local candidate exists, but identity, image naming, shared content or other evidence requires extra attention. |
| No direct candidate found | 324 | No candidate was identified using the explicit manufacturer image mapping, current matching DTB gallery link, or strict SKU-derived Columbia filename checks. |

These are product counts; the first two groups have **152 candidate image records for 151 products**. A separate search found **six punctuation-normalized filename leads inside the 324**. These are not verified matches or additional confirmed images. Other images without recognizable identifiers could still exist in either directory.

Columbia's supplied variant data and `products.json` do not provide a linked image for these 475 source rows. The manufacturer export therefore cannot, by itself, establish that a local image depicts one of these products. Existing DTB links and the historical gallery manifest are useful evidence, but neither proves current manufacturer identity. The historical manifest corroborates 151 of the 152 candidate records.

## Public media checks

The 152 candidate records contain 147 distinct intended public URLs. HTTP HEAD returned an image response for 146 and 404 for one. These checks establish response availability, not that the image is correct. Six representative files were additionally downloaded and their bytes matched their local files. The missing URL belongs to `CT45-BLACK`; the manufacturer export assigns that filename to a different product with the same SKU, so it must not be assigned to the image-less product.

Contact sheets of all 152 candidate records were visually inspected at thumbnail scale. This helps identify apparent product types, but cannot establish part dimensions or fit. A simple visibility heuristic flagged 13 images with very little non-white content for further inspection; narrow parts can legitimately appear this way.

## Identity risks to resolve

- `FFB11-12`, a 12-inch spring, is linked in the existing DTB data to a file named for `FFB11-8`. `FFB25-12` and `FFB25-10` share a file named for the 10-inch item. Do not assume these size-specific photos are interchangeable.
- Multiple `BH9` strap lengths share one image. It may be a representative photo, but that use needs confirmation.
- `FFB38` and `HFFB3` have existing links whose filenames reference different identifiers; review the actual parts before adoption.
- For `HNS4-3`, the existing DTB URL names a WordPress `-scaled` derivative while the local candidate has the original basename. The filenames are different; substituting one requires review.
- Punctuation removal produces unsafe collisions: manufacturer `AH12` is a reduction spring, while existing `AH1-2` is a head casting; manufacturer `HNS1-2` and `HNS1-3` are rear plates, while existing `HNS12` and `HNS13` are different parts. Never assign those photos on normalized SKU alone.
- `MP36` has conflicting material descriptions between sources. Confirm product identity before adopting its image.
- Seventeen empty checkpoint products have only a placeholder in the existing DTB catalog. Placeholders were not proposed as product images.

## Review files and repeatability

- [All 475 empty-gallery products](empty-gallery-products.csv), with disposition, existing DTB references and review flags.
- [152 local image candidates](empty-gallery-image-candidates.csv), including filenames, local paths, intended URLs, image hashes and public check results.
- [Six punctuation-normalized filename leads](empty-gallery-normalized-filename-leads.csv), all explicitly marked review-only.
- [URL checks](empty-gallery-url-checks.csv), [sample byte comparisons](empty-gallery-public-content-sample.csv), [historical gallery evidence](empty-gallery-historical-gallery-evidence.csv), and [thumbnail visibility review](empty-gallery-visual-review.csv).
- [Audit summary and source hashes](empty-gallery-audit-summary.json). The checkpoint SHA-256 is `4fb8af579b797aad5da20e073a53470e5990b344773d2b6b62bf289fd8e641ff`; the current official catalog SHA-256 is `b43573f88f6f1c33b4862fff7a1363f9fb3c64e7c5506ba6d0565c56ed3ba4c0`.

Review the 128 existing-link candidates against actual product identity and dimensions before linking them. Resolve the 23 flagged candidates and six normalized-only leads individually. Leave unverified images empty. For approved image-only updates to existing WooCommerce records, prepare a separate narrow `Type,SKU,Images` update file after confirming variation relationships and the destination URLs.

The checkpoint management status currently flags a changed official-catalog input hash. Comparing the current official catalog with the stored DTB Columbia reference found all 515 Columbia sellable rows unchanged; the tracker was not refreshed during this image audit.

The local audit can be regenerated with `python docs/catalog/columbia-catalog-audit/tools/audit_empty_galleries.py`; its output marks public checks as not run until `python docs/catalog/columbia-catalog-audit/tools/check_empty_gallery_urls.py` is run. Regenerate normalized-only leads with `python docs/catalog/columbia-catalog-audit/tools/audit_normalized_gallery_leads.py`. These scripts write audit evidence, not the checkpoint or official catalog.

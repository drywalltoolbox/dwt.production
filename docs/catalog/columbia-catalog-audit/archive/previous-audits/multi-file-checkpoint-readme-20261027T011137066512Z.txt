# Current Columbia rebuild checkpoint

This folder is the review snapshot of the latest Columbia rebuild work. The files are **not import-ready** and are not the canonical catalog. The [checkpoint manifest](checkpoint-manifest.json) records the capture time, source paths, row counts, SHA-256 hashes and whether the matched-review inputs were current at capture.

## Start here

| File | What it shows |
| --- | --- |
| [01 Manufacturer-first working catalog](01-manufacturer-first-working.csv) | Current proposed manufacturer-based product lines, including provisionally adopted DTB fields and TSW shipping values. |
| [02 Manufacturer baseline](02-manufacturer-baseline.csv) | Columbia's source data in the WooCommerce CSV field structure. |
| [03 Existing DTB Columbia products](03-existing-dtb-columbia.csv) | Current DTB sellable product rows used for comparison; variable parents are omitted from this reference only. |
| [04 Matched products side by side](04-matched-products-side-by-side.csv) | Manufacturer, DTB and working values for 445 pairs: 434 exact matches and 11 format candidates requiring identity review. |
| [05 Matched field review](05-matched-field-review.csv) | Field-level proposals and pending decisions for those 445 pairs. |
| [06 Manufacturer dispositions](06-manufacturer-dispositions.csv) | Manufacturer-side match and review dispositions. |
| [07 DTB dispositions](07-dtb-dispositions.csv) | Existing DTB sellable-product dispositions. |
| [08 Category assessment](08-category-assessment.csv) | Existing and proposed shared-category mappings. |
| [09 Variation dependencies](09-variation-dependencies.csv) | Existing variation-to-parent relationships to preserve. |
| [10 Excluded part kits](10-excluded-part-kits.csv) | Manufacturer products excluded as part kits. |
| [11 Excluded packs](11-excluded-packs.csv) | Manufacturer products excluded as packs. |

The checkpoint preserves the 1,006-product scope, excluding merchandise, apparel, part kits and packs. Existing WooCommerce parents and variation structure remain untouched. Suggestions are not approved data, and unresolved identifiers, category assignments, costs, shipping measurements and media still require review.

## Keep the checkpoint current

These are checkpoint **copies** of the working files under `temporary-reference/` and `review/`. Make authoritative work changes in those source files, not in these copies. From the repository root:

```powershell
python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py
```

That command checks whether any checkpoint copy was edited, whether source files changed, and whether the matched review still reflects its input snapshots. To take a new snapshot after updating the source work, run:

```powershell
python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py --refresh
```

Refresh refuses to overwrite edited or missing checkpoint copies. Preserve and reconcile such edits first. Refresh records if the matched-review inputs are stale; it does not automatically regenerate the review or apply anything to the official catalog. See the [audit directory index](../README.md) for source files and evidence.

> **Current scope:** The manufacturer references now contain 1,006 products after excluding 46 manufacturer-classified part kits and two product packs. Counts below describe the earlier 1,054-product audit and are retained as historical evidence; see [manufacturer-exclusion-scope.md](manufacturer-exclusion-scope.md) for current counts.

# Columbia rebuild: reconciliation and staging status

The two requested reference CSVs are in [temporary-reference/](../temporary-reference/). The manufacturer reference has 1,054 non-merchandise product rows. The current DTB reference has 515 sellable Columbia rows, including all 160 variations; the existing 51 parents are excluded from the reference only. The user directed that the existing WooCommerce variation structure be retained. [existing-variation-dependencies.csv](existing-variation-dependencies.csv) records each variation's parent.

The source crosswalk accounts for all 1,054 manufacturer products: 434 exact existing matches, 504 new-product *candidates*, 91 possible title matches needing review, 10 unresolved identifier conflicts, two existing catalog SKU collisions, 11 possible alternate identifiers, and two source identity conflicts. Candidate status is not proof that a product is new or that different SKUs are interchangeable. Name-similarity scores are not calibrated probabilities and do not establish identity.

The DTB crosswalk accounts for all 515 current sellable rows: 434 exact matches, 15 possible source links requiring review, and 66 without a confirmed source link. Additional name candidates may exist; no product is designated obsolete or scheduled for deletion. Every below-cost product is flagged on the source crosswalk, including the five priced through alternate identifier candidates.

[exact-matches-field-review.csv](exact-matches-field-review.csv) compares source and current DTB fields for all 434 exact matches. Blank manufacturer fields are source gaps, not instructions to erase DTB data. Manufacturer collections, remote images, prices, attributes and descriptions require correct field/ownership interpretation. [tsw-prefixed-73-identity-review.csv](../evidence/tsw/tsw-prefixed-73-identity-review.csv) and [tsw-cost-verification-report.md](../evidence/tsw/tsw-cost-verification-report.md) retain supplier identity, pack and cost conflicts.

[rebuild-WORKING-NOT-FOR-IMPORT.csv](../archive/previous-audits/rebuild-WORKING-NOT-FOR-IMPORT.csv) is an incomplete reference combining manufacturer rows with existing unmatched DTB rows, and it excludes parent rows by user instruction for references. It retains existing relationship values for variations. It is NOT a completed official catalog or an import file. The validator reports 3,881 structural errors, including duplicate SKUs CT45-BLACK, FA208 and FA308, missing required values on source-only rows, and variation-parent dependencies. The separate existing official catalog had 17 pre-existing taxonomy errors.

The official CSV is unchanged from SHA-256 `c4a9b708d8e9f334845ce037fb9cc5bb3ff74e522443cd3d791c5b25617cc645`. No WooCommerce or Veeqo import, product publication, SKU rewrite, stock assumption, image synchronization, or parent removal has been performed.

## Remaining work before an official catalog candidate

- Review every proposed new/alternate product against identity, actual manufacturer variant data and DTB catalog rows, including duplicate source SKU CT45-BLACK and FA208/FA308 collisions.
- Review the source/TSW discrepancies, including FA379/FA380, MP36, FA262 pack quantity, CR1/CT1 and unsupported COGS.
- Reconcile all available manufacturer claims with existing descriptions, specifications, metadata, taxonomy, images, SEO, schematics and compatibility; keep independently verified DTB facts and flag unsupported conflicts.
- Populate actual operational catalog requirements only with valid evidence or authorized configuration, including unique identities, product state, taxonomy and stock behavior. Restore the retained existing variable parents when constructing any complete official catalog; reference-only parent exclusion does not authorize removing them.
- Build an integrated catalog candidate that retains non-Columbia rows unchanged and passes schema, taxonomy, relationship, price, cost and media checks; resolve pre-existing taxonomy errors through the owning taxonomy contract rather than silently changing categories.
- Before any official replacement, back up the exact current catalog and verify the backup; validate the candidate and report any remaining unresolved facts.

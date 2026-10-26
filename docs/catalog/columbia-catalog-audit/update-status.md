# Current pricing update — supersedes the earlier price status below

Following the user's catalog-wide pricing convention, the Columbia manufacturer price is now the **sale price** and the regular price is that amount multiplied by 1.10, rounded to cents with ROUND_HALF_UP. Applied and verified for 434 exact-SKU matches and 11 strong format-only matches supported by product names and sizes. Their source and DTB SKUs remain separately recorded; no SKU identity was rewritten.

The current official catalog SHA-256 is `c4a9b708d8e9f334845ce037fb9cc5bb3ff74e522443cd3d791c5b25617cc645`. The sibling backup is the preceding 434-match pricing version; earlier catalog/backup versions were retained with timestamped names. `price-convention-changes.csv` and `fuzzy-price-changes.csv` provide field-level evidence.

73 of the 445 resulting sale prices are below the catalog's recorded cost; see `price-below-cost-review.csv`. This is an observation, not proof of live margin or current supplier costs. The repository pricing manager documents a WooCommerce cost-floor policy, so importing these prices does not establish that WooCommerce will retain them unchanged. The unchanged validator baseline is 17 taxonomy errors. No import or deployment was performed.

The broader product metadata, specification and identifier migration remains incomplete. The earlier status below is historical; its pricing and backup descriptions have been superseded by this update.

---

# Columbia catalog update status

The official CSV was updated using the manufacturer snapshot and the user-confirmed USD currency. The original input hash and the replacement hash are recorded below. The sibling .bak is a hash-verified copy of the pre-update CSV; the previously existing .bak was preserved separately.

- Original SHA-256: `1bc44bf5f1dd397cef02113ac244aaf391961e6f61cc88523ecd0ae730d36cc6`.
- Updated SHA-256: `860e750db92ab2cb5b1632e1f1dd1e589227060dc214555c0459d575578f3cd4`.
- 434 unique exact-SKU matches now carry source numeric USD regular prices. 422 pre-existing sale prices were cleared so the requested source price is effective, pending clarification of the requested sale-price field behavior.
- Manufacturer ID fields were filled where existing metadata did not conflict. Sixteen existing conflicting identifier groups remain untouched.
- Eight exact-SKU products with directly evidenced conflicting product names were corrected in title, description, short description and DTB SEO fields: FA380, FA379, FA299, FA279, FA294, CT109, FA225 and FA222. Source-derived length conflicts in FA279 and FA222 were corrected in structured specs.
- `applied-changes.csv` contains old and new values. `columbia-catalog-proposed.csv` and `proposed-changes.csv` are a broader, unpromoted proposal based on the pre-update snapshot; do not import or apply them as-is. They require reconciliation of existing specifications, features, SEO, parent/variation presentation and other metadata first.
- `identifier-candidate-review.csv` distinguishes 11 strong punctuation-format candidates, several materially different parts, and identifier collisions. These candidates are not yet applied.
- The original baseline and updated CSV both fail the existing 17-item taxonomy validation. No new validator error was observed. The update has not been imported to WooCommerce, Veeqo, or a live site.

The broader request is incomplete: existing source-unmatched products, source details not yet propagated, related metadata, and all SKU-identity migrations still require review. The original audit files and hashes describe the pre-update snapshot, not the present official CSV.

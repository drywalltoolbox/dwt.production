# Product Relationships rebuild: audit and target

Status: source implementation in progress; no production code, product relationships, identifiers, options, or configuration have been changed. The approved fitment proposal manifest is generated and remains entirely `needs_review`.

## Objective

Consolidate the operator experience for product relationships and parts discovery while preserving distinct domain authorities. The outcome should make relationship meaning, supporting evidence, review state, coverage, conflicts, and downstream projections understandable and auditable. SKU, MPN, GTIN, part number, brand/taxonomy IDs, schematic IDs, and external IDs remain protected identities.

## Evidence captured on 2026-11-04

### Production snapshot (MCP DTB reads)

- MCP DTB reports `drywalltoolbox.com` as production, WordPress 7.1.4, PHP 8.2.35, DTB MCP 1.3.1, and 18 plugins.
- WooCommerce reports 580 simple and 105 variable products (685 product records in its type report). The nonempty `Replacement Parts` category (term 6178) reports 344 products.
- Product 32979, SKU `340317`, is a published part in `Replacement Parts`. Its live WooCommerce `upsell_ids` and `cross_sell_ids` are empty; `related_ids` contains five products. Its DTB `COMPATIBLE_TOOL_SKUS`, `REPLACEMENT_PART_FOR`, and schematic mapping fields are empty. Its description/features make compatibility prose claims, but those fields are not structured fitment evidence.
- MCP managed-file reads matched local SHA-256 for `ProductRelationshipRepository.php`, `ProductMappingActions.php`, `PartsManagerPage.php`, and `UniversalPartsProjectionService.php`.
- Production `CompatiblePartsController.php` did not match local source: live 10,351 bytes / SHA-256 `7d889653bfbd8d335a48fb76932428e5517a6d9a40a25189f30c7231222d68c0`; local 14,894 bytes / SHA-256 `14e085a10c53109517117314d01a90444156b22b4442bcd425789e4c8af90431`. Treat local behavior as unverified for that controller until reconciled. The live file read confirms production compatibility routes read `_dtb_compatible_tool_skus` and `_dtb_replacement_part_for`, not the admin editor's `_dtb_compatible_tools` / `_dtb_compatible_parts` ID lists.

### Repository snapshot

- Product Mapping page combines variable-product/variation editing, compatibility editing, and WooCommerce upsell/cross-sell editing in three tabs: `drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/ProductMappingRenderer.php`.
- The compatibility editor saves bidirectional product-ID arrays (`_dtb_compatible_tools`, `_dtb_compatible_parts`). The public compatibility API reads SKU arrays (`_dtb_compatible_tool_skus`, `_dtb_replacement_part_for`). Those are separate relationship representations and the admin write is not projected into the API's read model.
- Product Mapping's parts query finds categories by a name fragment and explicitly discards its `brand` filter. The add UI searches simple/variable products, not an explicitly validated tool/product kind. The write path does not validate that the selected IDs identify a published part and tool pair.
- Compatibility persistence updates each side independently. There is no transaction, optimistic version check, or post-write consistency check in this repository method. Invalid `mapping_action` values take the remove branch.
- Upsell/cross-sell persistence uses WooCommerce CRUD, preserving WooCommerce as owner, but parses IDs and saves them without explicit self-link, duplicate, product-status/type, or cross-type conflict validation in this repository method.
- The canonical `products/launch/official/dtb_official_catalog.csv` has 1,133 rows in the inspected checkout. Its `Upsells` and `Cross-sells` columns are blank on all rows. `_dtb_compatible_tool_skus` is populated on 166 rows (432 values); `_dtb_replacement_part_for` is blank; `_dtb_schematic_id` is populated on 173 rows; `_dtb_is_parts` on 1,118 rows. These counts describe this file snapshot, not production coverage.
- Universal-part seeds in `products/launch/universal_parts/` contain 42 part groups (32 active, 9 review, 1 quarantine), 116 member rows (88 active, 26 review, 2 quarantine), and 42 compatibility rows (32 active, 9 review, 1 quarantine). The review audit explicitly preserves ambiguous hardware groups when dimensions/source evidence conflict.
- `UniversalPartsProjectionService::data_dir()` currently constructs `products/Production/launch/universal_parts`, while the inspected repository path is `products/launch/universal_parts`. This is a source-path discrepancy. Whether production's deployed filesystem has a matching `Production` tree has not been verified; do not treat it as a confirmed runtime failure yet.
- Product Mapping's renderer includes variation creation/edit/delete, including a variation SKU field. That is outside a relationship editor's safe mutation scope and needs to be removed from the consolidated relationship workflow; existing variation operations need an explicit separate owner/workflow.
- The adjacent Parts Manager can also edit part SKU and manufacturer SKU through `dtb-catalog-platform/Admin/PartsManagerActions.php` save/import actions. The current save path writes WooCommerce `_sku` post meta directly. Since these are protected identifiers, the rebuilt relationship workflow must never expose them; the Parts Manager save/import path also needs an exact-identity guard so an existing record cannot be silently re-keyed.
- The frontend PDP compatibility hook calls the compatibility endpoint; the existing merchandising contract says canonical exact compatibility can populate the rail before WooCommerce generic related-product fallback. The fallback must never be presented as verified fitment.

## Findings and implications

1. **Relationship read/write mismatch (high).** The admin's compatibility writes do not feed the compatibility API's canonical SKU-based inputs. Operators can believe a part/tool fitment has been linked while storefront/repair consumers continue to see no structured fitment. The two representations need a single designated writer and explicit projections or a migration with reconciliation.
2. **Relationship semantics are conflated (high).** WooCommerce generic related products, fitment metadata, schematic membership, set contents, universal equivalence, and merchandising links have different claims and evidence. They cannot safely share one undifferentiated edge type or one bidirectional rule.
3. **No real conflict-review engine (high).** The current “conflict” drawer reports failed AJAX requests; it does not inventory competing records, show provenance, distinguish conflict from missing data, or record review decisions.
4. **Weak mutation validation and concurrency (high).** Compatibility writes can create invalid pairings and can leave one side stale if only one metadata update succeeds. Relationship saves need type-aware validation, stable identity resolution, idempotency, and read-back.
5. **Catalog coverage is not measurable from current tooling (high).** The inspected canonical export has no editorial upsells/cross-sells, while fitment, schematic, and universal-part records live in separate schemas. A coverage report must define its denominator by eligible published product/family and relationship type, and count evidence-backed, review-needed, missing, and excluded records separately.
6. **Protected identity exposure in scope (medium/high).** Variation SKU editing is co-located with relationships. The new relationship surface must not accept SKU/MPN/GTIN/part-number edits or infer identity from labels.
   The adjacent Parts Manager has a broader current exposure: `dtb_ajax_parts_save()` and the CSV import path accept SKU/manufacturer SKU values and directly update `_sku` plus manufacturer-SKU metadata for an existing product. Existing product updates must require exact identifier equality; a mismatch should be rejected into an explicit catalog identity review flow.
7. **Runtime/source parity is incomplete (high).** Production and checkout source differ for the compatibility controller. No change should be deployed based on the local controller alone; first reconcile the source authority and identify deployed commit/version evidence.
8. **Universal seed path may be broken (unverified).** The code path and checkout path differ. Verify the actual production filesystem/seed summary using the owning managed read-only operation before relying on seed counts or planning projection writes.

## Target architecture constraints

- Keep implementation in `dtb-catalog-platform` unless a relationship type belongs to a specialized owner. Keep canonical catalog source in `products/`; WooCommerce remains authority for runtime products and native upsell/cross-sell fields; `dtb-schematics` remains schematic lifecycle owner; Veeqo remains inventory authority.
- Consolidate the operator experience, not the underlying authorities. Use one workspace with focused queues/views for Fitment, Schematic parts, Universal equivalence, and Merchandising. Keep variable-product administration out of this surface.
- Every relationship must identify source product, target product, type, direction/symmetry, evidence reference, confidence, review status, reviewer/review time, and source/projection status. Exact compatibility requires authoritative evidence. Fuzzy matching may rank review candidates only.
- First attempt a typed domain service with existing canonical stores/projections. Add persistence only if provenance, review history, conflict state, atomicity, and coverage cannot be safely represented in current owners; that choice requires architecture review and documented migration/rollback.
- No bulk apply before dry-run inventory, stable-identity resolution, conflict adjudication, before/after export, and deterministic projection verification.

## Architecture decision for the rebuild

Keep the current domain owners and persistence mechanisms; do not add a generic relationship table or a new relationship module.

- `products/` remains the canonical authority for compatibility. Exact schematic proposals are converted into a catalog/row-digest-bound manifest with evidence references and `needs_review` status. Only human-approved rows may be applied through `apply_official_catalog_changes.py`; proposal generation never mutates source.
- WooCommerce `_dtb_compatible_tool_skus` and `_dtb_replacement_part_for` values are runtime projections. The wp-admin relationship workspace reads them together, labels their source, and exposes numeric legacy ID links as unverified. It does not directly publish fitment claims or convert legacy IDs.
- WooCommerce upsell/cross-sell edges remain stored through WooCommerce product CRUD. They are directional merchandising and stay separate from fitment.
- Schematic occurrence/mapping lifecycle remains in `dtb-schematics`; the relationship workspace links to its owner but does not edit schematic records.
- Universal-part identity/equivalence remains in `products/launch/universal_parts/*.csv` with Woo product metadata as its projection. The workspace may link to Parts Manager for sync/readiness; it does not rewrite seed identity or activate review/quarantine rows.
- The admin workspace is one operator surface over owner-specific adapters. Typed candidates and conflict results are read models; they do not become a second catalog authority.

The design removes the variable-product editor and direct fitment mutation from the relationship surface, and hardens the separate parts save/import path against identifier changes. WooCommerce CRUD remains the writer for native upsell/cross-sell. No checkout, product identity, schematic ownership, universal-part identity, inventory, or commerce-persistence authority moves.

## Acceptance criteria

1. One premium, simple Product Relationships workspace presents fitment, WooCommerce merchandising, relationship integrity, and links to schematic/universal owners without duplicate tools or confusing fitment with generic related products.
2. Fitment review reads both SKU-list projections and displays legacy numeric IDs as unverified. Fitment source changes are digest-bound, exact-SKU, evidence-carrying manifest rows; the workbench cannot directly publish a fitment claim.
3. Upsell/cross-sell edits use WooCommerce CRUD and reject self-links, duplicates, missing/unpublished targets, and incompatible catalog states. Upsell and cross-sell remain distinct, directional types.
4. Audit/coverage views distinguish verified/structured, legacy-unverified, missing, and conflicting inputs. “Missing” is not rendered as “incompatible.” Candidate scoring never auto-publishes fitment.
5. Product Relationship operations expose no SKU/MPN/GTIN/part-number or other protected-identity mutation. Parts Manager existing-record save/import rejects identifier mismatches and uses WooCommerce CRUD for product identity fields.
6. Admin write endpoints require the owning capability and nonce, validate allowlisted inputs, guard concurrent edits with a version/hash, and read back saved values. No unbounded product scan or N+1 search is introduced.
7. The production controller/source mismatch is reconciled before any production code write. Live product mappings are changed only after a reviewed canonical source apply and an explicit projection dry-run identifies exact targets and preserves rollback evidence.

## Remaining audit and delivery gates

1. Run the new production read-only relationship audit from the Product Relationships admin page, export its report, and reconcile it with the canonical coverage report. Runtime route success alone is not catalog-wide parity evidence.
2. Review the 394 exact schematic-derived proposals against their cited evidence. None is approved; the 403 variation-SKU target occurrences need family-level resolution before they can become canonical tool fitment.
3. Complete the projector release gate. Local source now includes a digest-bound dry-run/apply/rollback WP-CLI projector, deterministic shards, exact unique SKU matching, update-existing writes, relationship read-back, internal route checks, per-batch release reconciliation, and private rollback reports. Focused tests and PHP lint pass. It is not ready to execute in production until architecture/code review is closed, `DTB_RELATIONSHIP_PRIVATE_DIR` is configured and verified outside the production web root, and deployed source parity plus a read-only dry-run are confirmed.
4. Add a machine-enforced catalog release guard so generated update artifacts cannot import blank `Upsells`/`Cross-sells` over WooCommerce-owned merchandising. No general full-catalog import may be used for fitment-only releases.
5. Verify the admin screen in an authenticated browser across loading, empty, conflict, save, audit, and narrow-width states. Source checks and a public REST call do not establish rendered UI quality.

## Implementation progress: 2026-11-04

Source work replaces the variation-centric Product Mapping screen with a focused, responsive relationship workspace. Fitment is read-only in wp-admin: it displays both SKU-based runtime fields, unresolved SKU identities, and part-side/reverse legacy numeric links as unverified. It does not write or promote fitment. Upsell/cross-sell remains a WooCommerce CRUD operation with current targets kept visible, stale-revision rejection, a connection-scoped per-product database lock, product/status/type validation, post-save read-back, and visible overlap conflicts. Variation write hooks were removed from the relationship page. Parts Manager rejects changes to existing SKU/manufacturer SKU, rejects non-part records across save, import, schematic, and universal sync paths, rejects SKU reuse including variation-owned SKUs, catches WooCommerce write exceptions, and keeps CSV failures row-scoped.

The source proposal workflow now proposes missing exact targets even when a part already has other compatibility values. The latest checkout snapshot reports:

- 1,133 catalog rows; 545 published part records and 140 published non-part product records in the scoped family denominator.
- 162 published parts with structured compatibility fields and 383 without; missing means no recorded structured relationship, not incompatibility.
- 646 exact schematic proposals across 394 unique parts; 2 exact target occurrences already populated; 2,498 unresolved source rows (1,397 part identifiers not in catalog and 1,101 schematic identities unresolved).
- 394 digest-bound review-manifest rows, all `needs_review`; none are approved or applied.
- The local canonical CSV has no native upsell/cross-sell values, no missing or part-to-part fitment targets, and 403 fitment target occurrences use variation SKUs that require family-level review before canonical projection.
- Runtime product relationship data reconciliation remains unaudited. The production compatibility controller was older than checkout and has now been updated to repository source; a live public route request succeeds, but this is not a catalog-wide projection or drift audit.

Derived artifacts are `products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv` and `product-relationship-coverage.json`. The manifest approval payload now has a dedicated SHA-256 binding proposal, evidence, reviewer, and timestamp. On 2026-11-04 the following eight existing production MU-plugin files were updated through managed writes with expected-SHA guards and read-back SHA verification: `Admin/PartsManagerActions.php`, `Admin/ProductMappingActions.php`, `Admin/ProductMappingRenderer.php`, `Infrastructure/ProductRelationshipRepository.php`, `Rest/CompatiblePartsController.php`, `Services/CompatiblePartsService.php`, `Services/ProductRelationshipService.php`, and `Validation/ProductMappingValidator.php`. Backup IDs, in the same order, are `20261104-070359-hhsO1T4z`, `20261104-070354-IyEYITya`, `20261104-070412-zX8m2I46`, `20261104-070335-W9Si0zxv`, `20261104-070321-7y0RJPoi`, `20261104-070338-PIb0Ol1B`, `20261104-070345-mpYE2MBG`, and `20261104-070351-57KtMbUt`. No product or relationship data was written. The public compatibility route for SKU `340317` returned successfully with an empty `products` list. Unrelated pre-existing dirty frontend typography/repair files were left untouched.

The wp-admin merchandising relationship editor is WooCommerce-owned. The canonical CSV's `Upsells` and `Cross-sells` fields are designated non-authoritative placeholders for import; any future full-catalog release must omit them or hydrate them from a fresh WooCommerce export and verify preservation. The source compatibility manifest does not itself establish the runtime projection; the production projector remains gated on review, private-path configuration, deployment parity, and a read-only runtime dry-run. The approved manifest currently contains zero approved rows, and 403 variation-SKU targets still require family-level evidence review.

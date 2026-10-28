# Catalog reset readiness checkpoint

This is a preparation checkpoint, not a reset or an import authorization. The intended rebuilt catalog is not yet complete, and no reset has been performed.

## Observed state

On 2026-10-28 the connected WooCommerce order report showed one processing order, four completed orders, two failed orders and one checkout draft. The report does not establish whether those orders are tests, whether they reference any particular product, or whether every order is included. Treat existing order history and product references as live until proven otherwise.

The same report showed 579 published simple products and 105 published variable products. This count does not include a complete inventory of drafts, variations, media, or all other statuses.

The local official CSV contains 1,134 rows. `Stock`, `Meta: _veeqo_sellable_id` and `Meta: _veeqo_mapped_sku` are populated on 1,028 rows; `Meta: _veeqo_product_id` on 465 rows. Every row contains `In stock?` and `Images`. A blind full-file import could overwrite runtime stock or integration projections. Do not treat CSV quantities as inventory authority; Veeqo owns inventory and allocation.

## Concrete preparation required

1. Complete the family redesign and reconcile schematics, links, SKUs, images, categories and parent/variation relationships in the official CSV and owning sources. The mixed roller families remain unfinished; this CSV is not yet a complete rebuilt import.
2. Snapshot the database, product/media files and relevant integration configuration using verified, restorable backups. Export a private, complete pre-reset mapping of WooCommerce product/variation IDs, SKUs, parent IDs, status and external IDs; do not include secrets in the artifact.
3. Audit order line-item, refund, cart, integration, schematic and permalink references to those IDs. Define a tested reconciliation for changed IDs without silently rewriting historical order or event identities.
4. Produce an explicit import artifact and column mapping, with inventory and externally owned fields excluded unless independently authorized and reconciled. Determine whether existing product media can be reused by attachment identity; do not delete or duplicate media blindly.
5. Rehearse on an isolated copy of the actual installation and data. Test import order, parent/variation links, Veeqo mapping, stock projection, schematic and product links, cart/checkout behavior, repeated import, failure recovery and restoration. Record a complete reconciliation.
6. Only after the rehearsal and verified recovery path, present the exact reset scope, artifact hashes and release sequence for the production action. A reset is separate from local catalog edits and is not accomplished by editing the CSV.

The reset must not truncate order, payment, refund, customer, fulfillment or event history. Product deletion may still affect the way historical order items resolve product references; preserve and verify those relationships rather than assuming the stored order line snapshots suffice.

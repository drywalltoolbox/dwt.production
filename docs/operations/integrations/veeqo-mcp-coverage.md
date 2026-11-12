# Veeqo MCP — authority, coverage, and activation

**Status:** Proposed on PR #240, not deployed. **Observed:** 2026-11-12.  
**Owning source:** `drywalltoolbox/wp/wp-content/mu-plugins/dtb-integrations/Veeqo/`

## Distinct connectivity surfaces

1. **Direct Veeqo MCP:** 105 Veeqo-provider tools are already available through ChatGPT's connected Veeqo MCP. Operations are governed by Veeqo's authenticated provider permissions and the client host's action policies. This is the authoritative interface for Veeqo inventory, shipment, purchase-order, warehouse, supplier and catalog management.
2. **Easy MCP AI:** A WordPress-hosted MCP endpoint. Dynamic tools appear only when registered through WordPress Abilities and explicitly enabled in `easy_mcp_ai_enabled_abilities`. Connecting Veeqo MCP separately **does not import its tools** into Easy MCP AI.
3. **DTB integration:** WooCommerce is storefront order, refund and catalog authority; Veeqo is inventory/fulfillment authority; DTB owns the event ledger and idempotent Action Scheduler jobs. This code is deliberately subject to launch, credential, webhook-authentication and inventory reconciliation policies.

A 1:1 generic 105-tool WordPress proxy is not part of this patch. It would duplicate the provider MCP and create dangerous independent write authority. Use the direct Veeqo MCP for full provider functionality and expand DTB Abilities only when cross-system business logic genuinely belongs in WordPress.

## PR #240 additions

12 DTB-owned WordPress Abilities, under `dtb-veeqo-operations/...`:

| Operation | Mode | Source |
| --- | --- | --- |
| overview, settings | Read | Veeqo control-center redacted read model |
| inventory, inventory-item | Read | DTB WooCommerce inventory projection |
| orders, fulfillment | Read | DTB order/fulfillment projection |
| operations, operation | Read | DTB operations audit/read model |
| sku | Read | Exact SKU lookup |
| mapping-diagnostics | Read | Historical unmapped SKU classification against current WooCommerce |
| inventory-preview | Guarded write (transient manifest only) | Existing operator-bound bulk preview |
| inventory-apply | Guarded WooCommerce metadata write | Existing preview-hash bound apply; no provider stock write |

Existing four `dtb-veeqo-kits/*` tools remain separate and unchanged.

All abilities require authenticated `manage_woocommerce`. The adapter does **not** expose arbitrary Veeqo API calls, payment creation, order creation, shipping-label purchases, unsafe webhooks, or stock writes.

## Live findings and blockers

- The production integration bootstrap defines `DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED = true`. Never remove that launch policy to make MCP tools appear.
- DTB Veeqo health: `enabled=false`, credentials from server constant present, configured warehouse/channel and readiness signals present, `webhooks_enabled=false`.
- Inventory synchronization last completed **2026-10-23**, with `108` unmapped SKUs and `0` updated; the result is stale.
- Spot-check on **2026-11-12**: WooCommerce contains `TT-MUDRUNNER` as a variable parent product, while Veeqo has an exact SKU match. Thus the historical 108 does **not** prove 108 current missing products. A type-aware current mapping audit and new provider dry-run are necessary before sync activation.
- The WordPress Veeqo runtime explicitly retires historical WP-Cron and disables inbound webhooks until their upstream verification contract is established.
- Veeqo permissions alone do not override WordPress, DTB, or MCP-host authorization and confirmation requirements.

## Activation gates

1. **Code and tests:** Run `php -l` on each new or modified PHP file and `php scripts/tests/test-veeqo-mcp-abilities.php`. Add WooCommerce integration/permission tests, preview/apply negative-path tests and static security review. No real provider mutation during tests.
2. **Review:** Confirm source file is loaded exactly once via canonical `dtb-integrations/bootstrap.php`, with no third-party plugin edits. Check annotation accuracy, schemas, permission failures, privacy and pagination.
3. **Staging:** Deploy only to a verified safe environment with no shared live commerce side effects. Confirm WordPress Abilities registry contains exactly the proposed names, and each read routes to the expected DTB owner. Verify unauthorized calls fail.
4. **Easy MCP:** Append the 12 exact `dtb-veeqo-operations/*` slugs to the existing `easy_mcp_ai_enabled_abilities` option; **preserve the entire existing list**. Read back the option and `tools/list`. Do not add unknown/unregistered slugs.
5. **Production deployment:** Use the established guarded build/deploy process and verify the runtime. Merge, deployment and active tool registration are separate states.
6. **Inventory:** Re-run a bounded source-to-WooCommerce reconciliation in dry-run mode. Review variable-parent versus variation identity, mismatches, duplicates, warehouse coverage, and product statuses. Do not automatically coerce missing inventory to zero or overwrite invalid stock.
7. **Webhooks:** Establish the real upstream authentication/signature and replay-protection contract, test negative cases, then explicitly enable only if trustworthy. Do not invent HMAC support in Veeqo.
8. **Commerce protection:** Preserve WooCommerce order/refund authority, captured-payment gating, one `dtb-orders` queue, event identity, retries, reconciliation, refunds and shipping-label cost confirmation.
9. **Provider parity:** For each of the 105 native Veeqo tools, record whether it is already available via the direct MCP, intentionally DTB-mapped, or inapplicable to WordPress. Do not claim all 105 are implemented in Easy MCP unless `tools/list` and provider read/write contracts prove it.

## Acceptance criteria

- All 12 proposed DTB Abilities appear only for appropriately authorized users; anonymous and under-privileged calls are denied.
- Exact schemas enforce bounded arguments; write annotations are correct; preview/apply requires matching hash, actor and changes.
- The existing four kit abilities and unrelated Easy MCP tools still appear.
- Integration remains disabled until separately verified production readiness is established.
- No new order, refund, shipment, inventory change, purchase, payment, or webhook callback was produced during validation.
- Actual direct Veeqo MCP still provides the provider-native tool coverage with existing authorization.

## Rollback

Remove only the newly added 12 ability slugs from the Easy MCP enabled list (retain previous entries), reverse the bootstrap reference and new adapter in an audited release, confirm the registry no longer advertises them, and verify the original four kit abilities and checkout flow remain unchanged. Rollback must not change Veeqo stock, fulfillment or WooCommerce orders.

## Current verification state

PR source and live settings inspected. New adapter and standalone contract test committed on a draft feature branch. **PHP lint, test execution, staging, deployment and production activation have not been verified**. Do not mark these complete without execution evidence.

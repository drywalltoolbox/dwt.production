# DTB Veeqo Integration

Veeqo owns sellable inventory, warehouse availability, allocation, fulfillment, shipment execution, carrier, and tracking. WooCommerce owns products, storefront orders, payments, and refunds. DTB owns exact mapping, queueing, projections, idempotency, diagnostics, recovery, and operator workflows.

## Canonical production tree

The production `Veeqo/` directory must exactly match this manifest. Deploy it as a complete replacement, not as an additive overlay.

```text
Veeqo/
├── Admin/
│   └── VeeqoAdminPage.php
├── Rest/
│   ├── VeeqoAdminController.php
│   └── VeeqoCompatibilityController.php
├── Services/
│   ├── VeeqoAdminReadModel.php
│   └── VeeqoOperationStore.php
├── assets/
│   ├── veeqo-admin.css
│   └── veeqo-admin.js
├── README.md
├── VeeqoClient.php
├── VeeqoConfig.php
├── VeeqoHealthCheck.php
├── VeeqoInventoryBoundary.php
├── VeeqoInventoryCoverageService.php
├── VeeqoInventoryProjectionServiceV3.php
├── VeeqoInventorySchedulePolicy.php
├── VeeqoInventoryService.php
├── VeeqoOrderProjectionContract.php
├── VeeqoProductionConfiguration.php
├── VeeqoRuntimePolicy.php
├── VeeqoShippingService.php
└── VeeqoSyncJob.php
```

No `Infrastructure/` directory is part of the canonical module.

## Composition and ownership

```text
VeeqoClient.php                         compatibility API/payload/log helpers only
VeeqoConfig.php                         normalized configuration facade
VeeqoProductionConfiguration.php       resource discovery and readiness
VeeqoInventoryService.php              inventory compatibility service
VeeqoInventoryProjectionServiceV3.php  canonical warehouse-scoped projection
VeeqoInventorySchedulePolicy.php       recurring Action Scheduler trigger
VeeqoInventoryCoverageService.php      mapping/coverage diagnostics
VeeqoRuntimePolicy.php                 legacy retirement and fail-closed policy
Services/VeeqoOperationStore.php       durable inventory operation state
Services/VeeqoAdminReadModel.php       batched/redacted operator projections
Rest/VeeqoAdminController.php          canonical protected admin REST API
Rest/VeeqoCompatibilityController.php  bounded aliases for known callers
Admin/VeeqoAdminPage.php               wp-admin application shell
assets/veeqo-admin.css                 scoped responsive presentation
assets/veeqo-admin.js                  operator interaction client
VeeqoOrderProjectionContract.php       exactly-once order projection policy
VeeqoInventoryBoundary.php             checkout-facing stock boundary
VeeqoShippingService.php               DTB shipping-policy adapter + live domestic rate shopping
VeeqoSyncJob.php                       synchronization timestamps/state
VeeqoHealthCheck.php                   redacted health diagnostics
```

`VeeqoClient.php` remains compatibility infrastructure because active order payload, API request, shipping, repair, logging, and webhook code still depends on its functions. It does not own production admin routes, inventory scheduling, product-save mapping, settings UI, or webhook registration. New domain behavior does not belong in that file.

## Retired source

The following paths must not exist in a production replacement directory:

```text
VeeqoInventoryProjectionService.php
VeeqoInventoryProjectionServiceV2.php
VeeqoInventoryAdminController.php
VeeqoOperationsAdmin.php
VeeqoLegacyAdminRegistrationGuard.php
Infrastructure/
Services/VeeqoAdminInventoryReadService.php
Services/VeeqoAdminOrderReadService.php
```

Retired files can redeclare functions, register obsolete routes, or restore superseded synchronization authority. Their absence is a deployment requirement, not optional cleanup.

## Admin surface

```text
wp-admin -> Veeqo
/wp-admin/admin.php?page=dtb-veeqo-control-center
```

Every control-center route requires native WordPress authentication, REST nonce validation, and `manage_woocommerce`.

Canonical route prefix:

```text
/dtb/v1/veeqo/admin/control-center
```

Supported workflows:

- inventory overview, search, filters, mappings, and exact-SKU comparison
- WooCommerce order/Veeqo projection visibility
- fulfillment/tracking projection visibility
- inventory dry run and reconciliation
- order retry through the canonical `dtb-orders` queue
- resource discovery, selection, and connection validation
- exceptions, queue state, and operation history

The control center intentionally does not expose unverified direct stock-adjustment, picking/packing, label-purchase, allocation, or shipment-write endpoints. Those provider mutations require independently verified upstream contracts, dedicated idempotency and compensation, and controlled acceptance testing.

## Connection configuration diagnostics

The Control Center validates the server-only `DTB_VEEQO_API_KEY` and discovers Direct channels, warehouses, and delivery methods. An authenticated request returning an incomplete setup is a successful **diagnostic request**, not a completed connection: the protected connection-test route responds with HTTP 200 and `success: false` plus redacted findings. Transport and unavailable-service failures remain errors.

When discovery returns no eligible Direct channels, configuration remains incomplete and the operator must verify the Direct channel in Veeqo. An empty or failed resource discovery must not erase a previously saved channel, warehouse, or delivery-method ID. It also cannot count as verification of that existing ID. The Control Center must show actionable findings and keep real inventory reconciliation gated by inventory readiness.

Only validated resource identities should be used for order projection. A verified API key alone is not evidence that orders, inventory projections, or background synchronization are operational.

## Channel discovery and order-export authorization

The operator connection test discovers and classifies available Veeqo sales channels, including `woocommerce` channels. Discovery success is not order-export authorization. The existing DTB `POST /orders` contract is limited to a previously discovered `direct` channel. A WooCommerce-type Veeqo channel may be displayed in the Control Center but must not be silently promoted to an API order-creation channel. A positive, unverified channel ID is insufficient to enable queued order export.

The server-side Veeqo API key, warehouse mapping, and delivery-method identity remain separate from channel classification. Discovery failures retain existing resource IDs for later verification. The Control Center shows the provider channel type and prevents selecting unsupported channels for order export. The order worker also fails closed when its configured channel lacks verified Direct classification.

The WooCommerce store URL in Veeqo is provider-managed and should be reviewed for canonical HTTPS configuration separately; this change never modifies it. Before enabling WooCommerce-channel order creation, independently verify the upstream order-creation contract and duplicate-order safeguards.

## Inventory projection

Veeqo inventory is authoritative only for the explicitly configured warehouse. The canonical worker:

```text
VeeqoInventoryProjectionServiceV3.php
```

It:

- reads Veeqo products in bounded pages;
- requires exact unique SKU identity;
- prefers `available_stock_level` and accepts `available_stock` only as a compatibility alias;
- rejects missing, null, and non-numeric warehouse stock;
- never converts unknown stock to zero;
- updates only changed simple products and variations;
- synchronizes affected variable parents;
- supports write-free dry runs;
- uses a token-owned lease with heartbeat refresh;
- persists partial diagnostics and continuation cursors;
- retries bounded transient failures through Action Scheduler.

## Queue contracts

```text
Operator inventory hook:  dtb_veeqo_inventory_operation
System reconcile hook:    dtb_veeqo_inventory_reconcile
Recurring trigger hook:   dtb_veeqo_inventory_reconcile_recurring
Inventory queue group:    dtb-integrations
Order projection hook:    dtb_order_sync_veeqo
Order queue group:        dtb-orders
```

No full-catalog write or external order mutation runs in an interactive REST request.

## Security and runtime policy

- Never expose or persist Veeqo credentials in browser code, REST responses, logs, or WordPress options.
- Never convert unknown stock to zero.
- Never sum warehouse inventory for checkout projection.
- Never bypass exact SKU, order, customer, or allocation ownership validation.
- Never restore `dtb_veeqo_inventory_sync` WP-Cron authority.
- Never activate inbound webhooks until Veeqo authentication and replay protection are explicitly verified.
- Never describe DTB's Standard/free-shipping merchant policy as a live Veeqo carrier quote. Synthetic Express/Overnight fallbacks are prohibited. Actual paid service options require verified single-carton packing data and an eligible carrier quote (`POST /shipping/api/v1/rates`, `DTB_VeeqoShippingService::live_domestic_rates()`). Unknown or multi-carton packing fails closed to merchant Standard.
- Live rate shopping only ever *quotes* Veeqo (`GET`-equivalent, read-only against `/shipping/api/v1/rates`); it never books a shipment or purchases a label from checkout. Label purchase/booking (`POST /shipping/api/v1/shipments`) stays entirely inside Veeqo's own native fulfillment workflow.
- Never invent provider write endpoints from UI behavior or historical comments.

`VeeqoRuntimePolicy.php` must remain loaded during rollback. It retires legacy routes, cron, product-save mapping, duplicate settings ownership, and automatic webhook registration. It also removes historical credential fields from WordPress options.

## Validation

Before packaging or deployment:

```powershell
.\scripts\smoke-dtb-veeqo-admin.ps1
.\scripts\smoke-dtb-mu-modules.ps1
```

The Veeqo smoke script validates the complete file manifest, rejects retired files/directories, checks bootstrap wiring, scans duplicate canonical symbols, lints every Veeqo PHP file when PHP is installed, and validates the admin JavaScript when Node is installed.

## Production replacement procedure

1. Back up the current live `dtb-integrations/Veeqo/` directory and `dtb-integrations/bootstrap.php`.
2. Build the replacement from one immutable repository commit.
3. Run the Veeqo and global MU-plugin smoke checks against that commit.
4. Upload the complete replacement directory to a temporary sibling path.
5. Verify every manifest file exists, retired paths are absent, and PHP permissions are normally `0644` with directories `0755`.
6. Replace the live `Veeqo/` directory as one unit. Do not copy into the existing directory without first removing or renaming it.
7. Deploy the matching `dtb-integrations/bootstrap.php` last.
8. Purge PHP OPcache, SiteGround dynamic cache, and CDN cache.
9. Confirm `/wp-admin/` loads before opening the Control Center.
10. Run connection validation and a dry reconciliation before any real inventory write.

WordPress options, Action Scheduler records, WooCommerce product metadata, and Veeqo external state are database/provider-owned and are not contained in this directory. File replacement does not erase or roll back those states.

## Rollback

Restore the previous complete `Veeqo/` directory and matching bootstrap from the same backup. Keep `VeeqoRuntimePolicy.php` or an equivalent retirement guard active. Never restore legacy WP-Cron inventory projection, public bulk inventory, synchronous product-save mapping, or automatic webhook registration.

Full architecture and rollout contract:

```text
docs/veeqo-operations-admin.md
docs/architecture/veeqo-woocommerce-integration-audit.md
docs/architecture/veeqo-control-center-deployment.md
```


## Native kit MCP abilities

The DTB composition root loads `Services/VeeqoKitImportService.php`, `Rest/VeeqoKitReadController.php`, and `Rest/VeeqoKitMcpAbilities.php` (all three are required in the deployed MU-plugin tree). WordPress 6.9+ registers the category `dtb-veeqo-kits` and abilities:

- `dtb-veeqo-kits/import-preview`: read-only exact-ID/SKU and BOM validation; returns a fingerprint.
- `dtb-veeqo-kits/import-convert`: one-shot destructive conversion, requiring `approved_fingerprint` and `confirm_conversion: true`.
- `dtb-veeqo-kits/read`: read-only `/kits/{kit_id}` inspection.
- `dtb-veeqo-kits/integration-health`: redacted read-only readiness, inventory projection freshness, coverage counters and component registration via the existing Veeqo health service.

Each ability requires authenticated WordPress `manage_woocommerce`. The convert ability delegates entirely to the existing guarded service, which independently requires `DTB_VEEQO_KIT_WRITES_ENABLED === true`, reserves a parent-specific operation before provider POST, prevents retries of uncertain outcomes, and checks the resulting kit against the intended BOM. An MCP client must obtain explicit user approval before conversion. A fingerprint is an exact BOM integrity check, **not** proof that a particular operator approved a specific conversion; the caller's authenticated approval workflow supplies that control.

Do not expose the Veeqo API key, circumvent this service through the native Veeqo MCP connector, or convert an unreviewed manifest. A failure after upstream POST requires reconciliation, not retry. The Veeqo MCP API-key bearer authentication is separate from WordPress ability authentication.

Operational verification: discover the three abilities through the WordPress Abilities API; inspect each schema; test invalid preview payloads for a 400 rejection; preview a reviewed, known-good parent and its components; obtain explicit approval; convert exactly once; inspect the resulting native kit and its component quantities in Veeqo. Neither discovery nor an invalid-payload test proves conversion readiness or authorizes a provider write.

The official Veeqo MCP's separately connected tool namespace is consumed directly by ChatGPT for Veeqo-owned operations; it is not technically possible for a WordPress Abilities registration to re-publish third-party MCP tools automatically. Do not route official provider mutations through a generic forwarding endpoint. The extracted tool catalog and DTB ownership policy are documented in `docs/integrations/official-veeqo-mcp-tool-routing.md`.

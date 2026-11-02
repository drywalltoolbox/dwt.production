---
status: derived
owner: repository-governance
scope: connected-system-interface-map
source_paths:
  - AGENTS.md
  - .agents/skills/dtb-integration-engineering/SKILL.md
  - docs/integrations/official-veeqo-mcp-tool-routing.md
  - docs/integrations/quickbooks-integration.md
  - docs/deployment/siteground-staging.md
review_triggers:
  - connected-interface-change
  - system-ownership-change
  - integration-contract-change
  - deployment-boundary-change
---
# Connected Systems Context

This is an interface-routing aid, not a credential store, permission grant, API
specification, or second source of business truth. Connector names and
capabilities vary by agent session. Confirm the needed tool is actually exposed,
its account/environment is correct, and its current schema before relying on it.
Use the current owning system for live facts; local repository state and a
connector's cached/projection state are not substitutes.

## Connection map

| Concern | Authority | Preferred interface when available | Boundary |
| --- | --- | --- | --- |
| Live WordPress, WooCommerce and DTB operations | WordPress/WooCommerce and the owning DTB module | MCP DTB WordPress Abilities API; use purpose-built DTB abilities for DTB operations | This repository's MCP DTB connection was verified against the production site on 2026-11-02. Recheck `dtb-mcp-admin/get-status`, `core/get-environment-info`, and `core/get-site-info` before relying on the target or runtime. Tool access does not bypass capability, ownership, or change authorization. |
| DTB-managed PHP/source files on the live site | The active module/theme source and its reviewed release artifact | MCP DTB managed-file read/write abilities | For a live file change, read the current file and SHA, make a narrow reviewed replacement guarded by the expected SHA, then read back and compare. Preserve the integration's backup/rollback. Do not use generic WordPress CRUD or an unguarded file path as a shortcut. |
| WooCommerce runtime products, customers, cart, orders, payments, refunds, shipping and tax | WooCommerce, subject to DTB domain policy | MCP DTB purpose-built WooCommerce abilities for permitted operations; otherwise inspect owning repository/runtime interfaces | Never create a parallel order, payment, refund, pricing, tax, or shipping authority. Live production mutations require task-specific authorization, preflight, idempotency where relevant, and independent readback. |
| Inventory, allocation, fulfillment, labels, shipment and tracking | Veeqo | Official Veeqo connector for provider-native operations; MCP DTB diagnostics for DTB projections/readiness | Veeqo owns these facts. Do not mutate WooCommerce-owned business data through Veeqo or bypass DTB identity, queue, and correlation contracts. Apply the operation-specific preflight and approval rules in `docs/integrations/official-veeqo-mcp-tool-routing.md`; confirm uncertain writes by readback rather than blind retry. |
| Accounting records and company accounting data | QuickBooks Online | QuickBooks connector for explicitly requested, correctly scoped accounting work; DTB read-only diagnostics where implemented | `docs/integrations/quickbooks-integration.md` defines the WooCommerce-to-QBO writer boundary. The direct connector is not authorization to create a competing sales/refund writer. Do not enable or restore DTB sales exports from context guidance. |
| Source code, issues, pull requests, CI and staging release evidence | Git repository and GitHub workflows | Local repository plus GitHub connector/workflow evidence | Review the exact commit and workflow artifact. The documented SiteGround workflow publishes the React frontend to `/staging/` only; it is not proof of WordPress staging isolation or production deployment. Follow `docs/deployment/siteground-staging.md`. |
| External ecommerce market evidence | The named research source (for example, Particl) | Connected research connector when available | Research is evidence about the measured market/source, not DTB catalog, order, inventory, or financial truth. Record methodology, date/window, scope, and source limits. |

## MCP DTB live connection

The MCP DTB connection used in this workspace conversation is supplied by the
connected ChatGPT app/plugin session, not by a repository-local MCP endpoint
configuration. No `.vscode/mcp.json` or equivalent endpoint file was found in
the `.vscode/` directory. Do not add credentials or duplicate the live server
configuration to repository settings; check the connected app/tool surface for
availability and account targeting.

The production WordPress connection exposes several distinct capability groups:

- Core site/environment information for identifying the target and runtime.
- `dtb-mcp-admin` diagnostics and narrowly scoped managed-file/cache/admin
  operations.
- `dtb-build` status/artifact/publish capabilities, whose readiness and publish
  gates must be queried for each release task.
- DTB-specific and WooCommerce abilities for supported domain operations.

Use read-only status before acting. For deployment questions, query
`dtb-build/info` and the matching GitHub workflow; repository configuration or
staging success alone does not prove live publishing is enabled. On
2026-11-02, the live `dtb-build/info` response reported no configured build
workspace and production publishing disabled. This is a dated observation, not
a permanent setting; query again before any deployment decision.

The same date's read-only checks reported WordPress 7.1.4 on PHP 8.2.35. The
DTB Veeqo integration-health ability reported its bridge disabled, webhooks
disabled, and projected inventory stale, with the last reported sync on
2026-10-23. Direct Veeqo tools being exposed in a session do not enable the DTB
bridge or make that projection fresh. These operational readings are snapshots;
recheck the owning source immediately before a decision or mutation.

MCP DTB reports WordPress runtime and integration projections. It is not the
authority for Git source state, Veeqo's current stock/shipments, QuickBooks
accounting records, or payment-provider status unless a purpose-built read
explicitly returns that provider's evidence. Do not treat a generic `ok`,
configured credential, or module-present flag as proof of end-to-end operation.

## Operational sequence

1. Resolve the task through `.agents/registry.json`; name the domain owner and
   system of record before choosing a connector.
2. Inspect active source and owning docs for the code path and contract. Use
   connected tools only for facts that must come from the live/external system.
3. Confirm the target/account/environment with a read-only status call. Redact
   sensitive values and avoid reading secret-bearing options or user/payment
   records unless essential and explicitly in scope.
4. Separate observation from mutation. For external/live writes, apply the
   appropriate task-specific authorization, stable identity, duplicate
   containment, bounded retry, failure handling, and readback/reconciliation.
5. Keep source changes, staging frontend publication, WordPress production file
   changes, and provider/account mutations as distinct operations. Report which
   system was changed and the evidence actually verified.

Never persist API keys, OAuth tokens, webhook secrets, private customer/payment
data, or raw provider payloads here. Do not copy volatile connector inventories
into canonical context; use the session's exposed tools and owning integration
docs for current schemas. The Veeqo tool list in
`docs/integrations/official-veeqo-mcp-tool-routing.md` is explicitly a dated
discovery snapshot.

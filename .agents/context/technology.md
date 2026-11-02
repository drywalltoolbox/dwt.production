---
status: derived
owner: repository-governance
scope: technology-and-runtime-summary
source_paths:
  - frontend/package.json
  - frontend/webpack.config.cjs
  - drywalltoolbox/wp/wp-content/mu-plugins/00-dtb-loader.php
  - AGENTS.md
  - .agents/context/connected-systems.md
review_triggers:
  - major-stack-change
  - build-system-change
  - async-execution-change
  - runtime-composition-change
---
# DTB Technology Context

Use this as a technology-family index, not as a dependency lock, runtime
inventory, or proof of deployment. Confirm actual imports, composition, and
execution paths before making implementation claims.

## Active application families

- **Storefront:** React and JavaScript/JSX, React Router, and Webpack. The
  frontend package scripts define development, production-root, staging, lint,
  responsive-media generation, and frontend contract-validation commands.
  Styling uses CSS with Tailwind/PostCSS in the build pipeline. The active
  package manifest and `frontend/webpack.config.cjs` are authoritative for
  current declared dependencies and build behavior.
- **Commerce/backend:** WordPress and WooCommerce, with DTB PHP must-use plugins
  under `drywalltoolbox/wp/wp-content/mu-plugins/` and tracked theme integration
  under `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/`. The active
  `00-dtb-loader.php` controls MU-plugin composition and load order. Use
  WooCommerce APIs and HPOS-compatible access for runtime commerce entities.
- **Asynchronous backend work:** Action Scheduler is the DTB backend execution
  mechanism for queued work. Keep slow provider effects out of latency-sensitive
  checkout/webhook acknowledgements and make consumers retry-safe.
- **External systems:** Provider-specific transport and payload handling belong
  in dedicated integration adapters. WooCommerce, Veeqo, QuickBooks, payment
  providers, and marketplace systems retain their separate authorities as
  defined in `AGENTS.md`.
- **Repository tooling:** `scripts/` contains operational and validation tools.
  AI routing and governance validation live under `scripts/ai/`; resolve tasks
  with `.agents/registry.json` instead of introducing a second routing system.

## Engineering constraints

- Follow the established language, framework, build, and module patterns. Do not
  add a framework, state-management system, queue, datastore, or parallel
  authority without a demonstrated need and appropriate architecture review.
- Keep browser state presentational for server-owned commerce and operational
  data. Use bounded reads, explicit validation, cancellation/cleanup where
  applicable, and accessible semantic UI.
- Keep backend transports, domain behavior, persistence/event boundaries, and
  provider adapters separated according to their module ownership.
- Treat environment values exposed to browser builds as public. Never expose or
  persist credentials, payment data, signing keys, or other secrets in client
  code, logs, or repository context.

Exact dependency versions, installed extensions, PHP/WordPress/WooCommerce
runtime versions, enabled providers, server topology, and deployment status are
deliberately not asserted here: they are mutable and must be verified from the
current lockfiles, active configuration, runtime diagnostics, or deployment
records that own those facts. Session-specific MCP availability and operational
checks are mapped in [connected systems](connected-systems.md); connector
availability is not proof of authorization or a change in system ownership.

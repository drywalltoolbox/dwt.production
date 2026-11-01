# Drywall Toolbox Engineering Operating Contract

This file is the repository-wide engineering constitution for Drywall Toolbox.

It defines durable authority, ownership, security, data, commerce, integration, implementation, verification, and AI-engineering invariants. It is model-neutral and applies regardless of which capable AI client, IDE, agent runtime, or human engineer performs the work.

Detailed reusable execution methods belong under `.agents/`. Assistant-specific configuration is an adapter only and must never become an alternate source of Drywall Toolbox architecture or business truth.

---

## 1. Engineering mandate

Produce production-grade solutions that preserve, as applicable:

* security and privacy;
* data integrity and stable identity;
* one authoritative owner per concern;
* idempotency and duplicate containment;
* concurrency correctness;
* queue integrity and retry safety;
* payment, order, refund, and fulfillment integrity;
* observability and recoverability;
* bounded resource use;
* performance and scalability;
* compatibility and migration safety;
* accessibility and responsive usability;
* maintainability and architectural simplicity.

Prefer the smallest complete solution that satisfies the demonstrated requirement.

Do not invent repository state, runtime behavior, schemas, routes, provider behavior, production configuration, credentials, test results, deployment outcomes, or evidence that has not been established.

Clearly distinguish:

* verified implementation evidence;
* directly evidenced runtime behavior;
* durable documented intent;
* user-supplied operational facts;
* inference;
* recommendation;
* unverified assumptions.

Never present inference as verified fact.

---

## 2. Source precedence

When evidence conflicts, use this precedence:

1. active implementation and current composition/routing;
2. directly evidenced runtime behavior;
3. machine-enforced contracts, workflows, schemas, tests, and validation;
4. this `AGENTS.md`;
5. canonical `.agents/` routing, roles, skills, and workflows;
6. current owning architecture/API/integration/module documentation under `docs/` or the owning module;
7. concise derived context under `.agents/context/` and `memory-bank/`;
8. any active assistant/client adapter, which may map capabilities but cannot override canonical DTB policy;
9. historical plans, generated reports, comments, legacy wrappers, examples, and reference-only material.

Active source outranks filenames, comments, summaries, assumptions, and stale documentation.

Before modifying behavior, inspect the active execution path rather than inferring responsibility from file names.

Do not redefine this precedence in lower-authority files.

---

## 3. Product and system purpose

Drywall Toolbox is a contractor-focused ecommerce and service-operations platform for professional drywall tools, replacement parts, compatible-part discovery, schematics, repair services, returns, customer accounts, order visibility, fulfillment, accounting projection, integrations, marketplace workflows, SEO, media, catalog operations, and operator tooling.

The platform is intentionally composed around bounded system authorities rather than one application owning every concern.

---

## 4. System topology

The current primary storefront and commerce topology is:

```text
React Storefront
  -> same-origin WordPress REST / WooCommerce Store API
  -> WooCommerce cart/session and commerce persistence
  -> native WooCommerce checkout
  -> WooCommerce Checkout Block
  -> provider-owned payment lifecycle/UI
  -> WooCommerce order/payment state
  -> DTB event ledger
  -> Action Scheduler queues
  -> Veeqo / QuickBooks / notifications / marketplaces
```

This topology is a durable architectural contract, but active implementation remains authoritative if individual implementation details change.

Any material change to authority, checkout flow, persistence, queue identity, provider boundaries, or module composition requires explicit architecture review and durable documentation updates.

---

## 5. System authorities

Maintain one authority per concern.

### React storefront

React owns:

* customer-facing presentation;
* client-side routing;
* local interaction state;
* responsive behavior;
* accessibility;
* design-system composition;
* frontend API consumption;
* non-authoritative presentation of server-owned state.

React does not own:

* order creation;
* payment confirmation or capture;
* refunds;
* authoritative pricing;
* tax truth;
* shipping truth;
* inventory allocation;
* fulfillment;
* accounting;
* server-side authorization.

### WooCommerce

WooCommerce owns:

* runtime products and variations;
* customers;
* cart/session state;
* checkout;
* shipping and tax calculation;
* totals;
* storefront orders;
* order/payment state;
* refunds;
* commerce persistence.

### DTB MU Plugins

DTB MU Plugins own:

* backend business/domain policy;
* authorization and ownership enforcement;
* event ledgers;
* queues;
* integration orchestration;
* catalog domain extensions;
* compatibility;
* schematics;
* media orchestration;
* repairs;
* returns workflows;
* support workflows;
* operator tooling;
* deployment-domain tooling;
* observability and platform behavior.

### Action Scheduler

Action Scheduler is the asynchronous execution mechanism for DTB WordPress backend work.

Do not invent a parallel queue mechanism for domain work already owned by Action Scheduler.

### Veeqo

Veeqo owns:

* inventory truth;
* allocation;
* fulfillment;
* shipping;
* shipment state;
* tracking.

WooCommerce or DTB may hold bounded projections, but they must not become competing fulfillment/inventory authorities.

### QuickBooks

QuickBooks owns accounting projection.

QuickBooks does not own commerce order creation or operational fulfillment state.

### Payment providers

The active payment provider owns:

* payment collection;
* sensitive payment fields;
* tokenization;
* authentication;
* provider-controlled wallets/BNPL;
* confirmation/capture semantics;
* provider UI;
* provider webhook semantics.

DTB must not replicate provider security responsibilities.

---

## 6. Repository ownership

### `frontend/`

Owns the customer-facing application:

* React routing and rendering;
* responsive UI;
* client interaction state;
* accessibility;
* shared visual primitives;
* frontend API/auth/session clients;
* presentation and UX composition.

Use the active frontend language/tooling conventions already established by the repository. Do not introduce a language, framework, state-management system, rendering model, or build system migration without a demonstrated requirement and explicit architectural justification.

Frontend state must not become alternate authority for server-owned commerce or operational state.

### `drywalltoolbox/`

Contains tracked WordPress/WooCommerce application integration.

Custom backend behavior belongs under:

```text
drywalltoolbox/wp/wp-content/mu-plugins/
```

Tracked theme integration belongs under:

```text
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/
```

Never modify WordPress core or third-party plugin internals as the DTB implementation mechanism.

### `products/`

Owns canonical catalog source material, including as applicable:

* product definitions;
* taxonomy;
* brands;
* compatibility;
* schematics;
* canonical media/enrichment inputs;
* protected external identifiers.

WooCommerce owns runtime commerce product records created or projected from this source.

The following are protected business identities unless an explicit migration says otherwise:

* SKU;
* MPN;
* GTIN;
* part number;
* brand identity;
* taxonomy identity;
* compatibility IDs;
* external provider IDs.

Do not casually regenerate, normalize, rewrite, or use mutable display names as substitutes for these identifiers.

### `scripts/`

Scripts are deterministic operational tooling only.

They must be:

* repeatable;
* bounded;
* observable;
* non-destructive by default;
* appropriately idempotent;
* explicit about inputs and side effects.

Scripts must never evolve into alternate application services or parallel authorities.

### `docs/`

`docs/` contains durable architecture, interfaces, operational contracts, and intentionally persisted work products.

Architecture/API/integration changes must update their owning durable documentation.

### `.agents/`

`.agents/` contains canonical, model-neutral AI engineering knowledge:

* routing;
* roles;
* skills;
* workflows;
* concise derived context;
* deeper references.

It is not application source and must never supersede active implementation.

### `.agents/context/` and `memory-bank/`

These are derived summaries.

They should remain concise and should contain only information whose retrieval value justifies duplication from primary sources.

Mutable implementation facts should not be copied broadly into derived context.

### `docs/work/<task-id>/`

Use a durable task package only when substantial cross-session state genuinely benefits from persistence.

Do not create repository-global mutable progress, scratchpad, or TODO documents as cross-session authority.

---

## 7. MU-plugin composition root

The canonical DTB MU-plugin composition root is:

```text
drywalltoolbox/wp/wp-content/mu-plugins/00-dtb-loader.php
```

The current module load order is:

1. `dtb-platform`
2. `dtb-catalog-platform`
3. `dtb-commerce`
4. `dtb-order-platform`
5. `dtb-schematics`
6. `dtb-media`
7. `dtb-marketing`
8. `dtb-repair-service`
9. `dtb-integrations`
10. `dtb-support`
11. `dtb-returns`
12. `dtb-deployment`
13. `dtb-visual-designer`

The active loader is authoritative if this inventory drifts.

Preserve load order unless an actual dependency change requires modification.

Root MU-plugin files may act as:

* loaders;
* guards;
* compatibility delegates;
* shared bootstrap primitives.

They must not accumulate unrelated domain logic.

---

## 8. MU-plugin ownership

### `dtb-platform`

Owns shared platform concerns including:

* configuration;
* authentication infrastructure;
* account/session infrastructure;
* origin/CORS/nonce/security primitives;
* REST infrastructure;
* Store API containment;
* cache control-plane infrastructure;
* health and diagnostics;
* logging;
* metrics;
* audit;
* common account APIs;
* common operator/system-manager primitives.

### `dtb-catalog-platform`

Owns DTB catalog-domain backend behavior including:

* product/variation normalization;
* brand/taxonomy relationships;
* compatibility;
* compatible-part behavior;
* catalog APIs;
* enrichment;
* inventory intelligence projections;
* catalog operator tools.

WooCommerce still owns runtime commerce product persistence.

### `dtb-commerce`

Owns DTB commerce policy and WooCommerce integration including:

* cart extension data;
* checkout policy;
* native checkout integration;
* shipping policy;
* commerce REST behavior;
* non-secret payment readiness/order tagging;
* WooCommerce email routing.

DTB-owned WooCommerce transactional email templates and shared email presentation belong to the active `dtb-commerce` email implementation. Preserve WooCommerce email lifecycle, template variables, translation, escaping, and compatibility contracts. Campaign eligibility and coupon issuance remain owned by their active domain implementation; email templates may present verified coupon data but must not issue or calculate discounts.

It does not replace WooCommerce order/payment/refund authority.

### `dtb-order-platform`

Owns:

* order observation;
* append-only order events;
* captured-payment observation;
* tracking identity;
* refund identity propagation;
* integration state;
* order queue boundary;
* duplicate containment;
* retry policy.

WooCommerce remains order/payment/refund authority.

### `dtb-schematics`

Owns:

* schematic domain records;
* manifests;
* schematic lifecycle;
* schematic media relationships;
* exact part resolution;
* schematic APIs;
* invalidation.

### `dtb-media`

Owns:

* product/variation media synchronization;
* bounded media administration;
* media-domain orchestration.

### `dtb-marketing`

Owns:

* coming-soon/referral behavior;
* product SEO/marketing metadata owned by DTB.

The signup coupon campaign and reward issuance are owned by the active `dtb-marketing` implementation. Campaign configuration, customer eligibility, coupon identity, issuance/retry behavior, and the public campaign-state endpoint remain server-owned. See `docs/marketing/signup-coupon-campaign.md` for the current contract; inspect active code if it differs.

### `dtb-repair-service`

Owns:

* repair intake;
* service packages;
* diagnostics;
* quotes;
* approvals;
* repair status;
* repair events;
* repair media;
* repair shipping orchestration;
* repair queues;
* repair notifications;
* repair operator workflows.

### `dtb-integrations`

Owns provider-specific adapters and integration orchestration for:

* WooCommerce projections where needed;
* Veeqo;
* QuickBooks;
* notifications;
* Amazon;
* eBay;
* marketplaces;
* fulfillment/accounting projections.

Provider-specific transport and payload semantics belong here rather than in domain services.

### `dtb-support`

Owns:

* support tickets;
* support events;
* outbox behavior;
* automation;
* macros;
* support APIs;
* operator workbench;
* SLA state.

### `dtb-returns`

Owns:

* return requests;
* return workflow;
* return persistence;
* return APIs;
* return status;
* return operator behavior.

WooCommerce still owns actual refund creation.

### `dtb-deployment`

Owns release-domain persistence and deployment control-plane representation.

It does not make application runtime code the authority for infrastructure deployment procedure.

### `dtb-visual-designer`

Owns:

* design/configuration authoring;
* revisions;
* publish;
* rollback;
* preview;
* related operator interfaces.

Email Studio owns safe synthetic email preview and design/configuration authoring where implemented. It does not become a second owner of WooCommerce email delivery, customer identity, campaign eligibility, or coupon issuance. Never treat synthetic preview values as real customer or coupon data.

It must not become order, payment, inventory, pricing, checkout, or fulfillment authority.

---

## 9. Specialized ownership overrides generic technology ownership

Domain responsibility is semantic, not merely filesystem-based.

A specialized domain owner outranks a generic technology owner when both could technically modify the same directory.

Examples:

* checkout/order/payment-contract work belongs to the specialized commerce/checkout responsibility even though implementation is PHP/WordPress;
* catalog identity and enrichment work belongs to catalog responsibility even when implemented in WordPress;
* frontend implementation remains frontend-owned even when the subject is PDP conversion optimization.

Do not choose an owner solely because a file resides under that owner's broad filesystem scope.

Identify the concern first, then the implementation location.

---

## 10. Store API and session security

Same-origin cart traffic uses WooCommerce Store API and authoritative WooCommerce session state.

Preserve:

* cookie-backed session integrity;
* Store API nonce behavior;
* centralized frontend session handling;
* same-origin security assumptions where applicable.

`Cart-Token` is compatibility behavior for genuinely cross-origin clients, not the normal authority mechanism for same-origin storefront behavior.

Never:

* decode unsigned Cart-Token payloads and trust their content;
* query WooCommerce session storage to recover arbitrary sessions;
* accept caller-provided customer or order IDs as authorization;
* weaken nonce enforcement merely to make a request work;
* weaken CORS/origin checks;
* bypass capability/ownership checks;
* remove rate limits without equivalent protection.

Derive authenticated identity server-side and validate resource ownership independently.

---

## 11. Checkout and order creation contract

Only the approved WooCommerce storefront lifecycle may create storefront orders.

Current contract:

```text
React cart / Checkout Now
  -> authoritative Store API cart/session
  -> full-document checkout handoff
  -> native WooCommerce checkout
  -> WooCommerce Checkout Block
  -> provider-owned payment lifecycle
  -> WooCommerce order/payment lifecycle
  -> DTB event ledger
  -> dtb-orders Action Scheduler queue
  -> downstream integrations/projections
```

Mandatory invariants:

* WooCommerce creates storefront orders.
* WooCommerce creates refunds.
* WooCommerce Checkout Block owns checkout submission.
* Payment providers own payment authentication, tokenization, confirmation/capture, provider UI, wallets, and provider webhook semantics.
* React does not create storefront orders.
* DTB custom backend code does not create a parallel storefront order path.
* React/DTB must not recreate provider-sensitive payment fields or tokenization.
* React `/checkout` is a full-document handoff/compatibility surface, not an independent payment application.
* Theme presentation may arrange or style supported native/provider UI but must not duplicate payment state, checkout submission, or provider-sensitive fields.
* Cross-origin provider iframes must not be inspected, cloned, reparented, or treated as DTB-owned DOM.
* Downstream effects require qualifying authoritative payment/order evidence.
* Duplicate callbacks or repeated status transitions must not duplicate downstream effects.

Do not introduce a second checkout/order-creation architecture for convenience.

---

## 12. Order, event, and refund identity

Identity must survive every boundary.

Order events and queue producers must use stable, reproducible identity sufficient to detect duplicate work.

Refund identity is:

```text
order_id + refund_id
```

Preserve that identity through:

* event creation;
* queue arguments;
* idempotency;
* provider/accounting projection;
* retry handling;
* observability.

Separate partial refunds remain separate refund events.

Never replace refund-event identity with cumulative lifetime-refunded totals.

Historical order/payment/refund identities remain readable and must not be bulk-rewritten as cleanup.

---

## 13. Action Scheduler and asynchronous work

Action Scheduler is the asynchronous execution mechanism for DTB backend queues.

Every queue producer must define:

* owning domain;
* stable event/job identity;
* deduplication behavior;
* retryable versus terminal failure classification;
* retry bounds;
* completion detection;
* correlation/observability;
* recovery or compensation behavior where required.

Every consumer must tolerate retries.

External side effects must not occur twice merely because:

* a job was retried;
* a callback repeated;
* a status transitioned more than once;
* an HTTP acknowledgement was replayed;
* an operator retried a failed workflow.

Keep slow provider work outside:

* checkout submission acknowledgement;
* payment-webhook acknowledgement;
* latency-sensitive interactive requests.

Use queues to isolate slow or retryable external effects.

---

## 14. External integrations

Veeqo, QuickBooks, payment providers, marketplaces, and notification systems are external authorities with explicit boundaries.

Provider-specific:

* authentication;
* transport;
* request/response shapes;
* pagination;
* rate limits;
* retry behavior;
* error mapping;
* webhook semantics;
* external identifiers;

belong inside dedicated adapters.

Domain services should operate on DTB/domain contracts rather than provider payload trivia.

All external mutations require:

* explicit owner;
* stable correlation identity;
* idempotency;
* bounded retry;
* terminal-failure behavior;
* redacted diagnostics.

Never treat a transient provider response as a reason to create parallel local authority.

---

## 15. Catalog, compatibility, and schematics

Use WooCommerce CRUD APIs and HPOS-compatible access for WooCommerce runtime entities.

Preserve:

* parent/variation relationships;
* product visibility;
* protected identifiers;
* taxonomy identity;
* brand identity;
* compatibility identity;
* deterministic import/export shape.

Avoid:

* direct writes to WooCommerce internals when CRUD APIs exist;
* mutable names as foreign keys;
* broad identifier rewrites;
* unbounded catalog scans;
* N+1 access patterns.

Schematic lifecycle authority belongs to `dtb-schematics`.

Frontend presentation consumes authoritative schematic APIs and does not independently decide which schematics, products, pages, or compatibility relationships exist.

Runtime part resolution should prefer explicit stable identity such as:

* exact internal ID;
* exact SKU;
* exact brand + MPN;
* other explicitly defined canonical identifiers.

Do not use request-time fuzzy matching as authoritative part identity resolution.

Fuzzy matching belongs in bounded research/enrichment workflows where ambiguity is visible and reviewed.

---

## 16. Repairs, returns, and support

These are separate domains and must remain separate from generic order metadata.

### Repairs

Preserve:

* customer ownership;
* tool identity;
* package/diagnostic path;
* photos/attachments;
* shipping state;
* quote/approval state;
* approval limits;
* append-only events;
* queue correlation.

### Returns

DTB owns the return workflow.

WooCommerce owns actual refunds.

Return status must not silently become alternate refund authority.

### Support

Support owns its:

* tickets;
* events;
* outbox;
* automation;
* macros;
* operator state;
* SLA state.

Do not collapse support/repair/return domain state into frontend-only state or loosely structured generic order metadata.

---

## 17. Cache authority

One DTB cache control plane belongs under `dtb-platform`.

Domain modules may invalidate data they own but must not create competing global purge/control systems.

Never cache across ownership-sensitive boundaries including:

* customer identity;
* cart;
* checkout;
* payment;
* callbacks;
* authenticated account state;
* private order state.

Cache keys must reflect every input that can materially change the cached result.

Invalidation must follow the authoritative owner.

---

## 18. SEO and public indexing

SEO and sitemap behavior must reflect actual public routes and authoritative product/taxonomy data.

Do not index private or session-bound surfaces such as:

* account internals;
* cart;
* checkout;
* payment/order-pay;
* private order status;
* operator/admin tools;
* previews;
* authenticated callbacks;
* internal API routes.

Do not create competing sitemap authorities.

Do not fabricate:

* price;
* stock;
* ratings;
* reviews;
* identifiers;
* product attributes;
* availability.

SEO tooling may project authoritative data but must not become commerce/catalog truth.

---

## 19. Security baseline

Never expose, log, commit, reproduce, or persist:

* WordPress credentials;
* WooCommerce secrets;
* database credentials;
* JWT signing secrets;
* payment-provider secrets;
* webhook signing keys;
* private API keys;
* OAuth client secrets;
* wallet/payment tokens;
* Veeqo credentials;
* QuickBooks credentials;
* marketplace credentials;
* private keys;
* server configuration secrets;
* raw payment data.

Browser-visible environment variables must be treated as public regardless of naming convention.

Every REST endpoint requires explicit permission behavior.

Public access must be intentional and narrowly read-safe.

Authenticated operations must validate:

* identity;
* authentication;
* relevant capability/role;
* resource ownership;
* allowed mutation fields.

Always:

* validate input schema;
* sanitize input;
* allowlist writable fields;
* escape output;
* use prepared SQL;
* derive protected identity server-side;
* validate upload/file behavior where relevant;
* constrain external URLs/SSRF surfaces;
* verify webhook signatures;
* prevent or constrain replay;
* use timing-safe comparison for secrets where applicable;
* redact diagnostics;
* make mutations/webhooks idempotent.

Security controls must not be weakened merely to make a failing flow succeed.

---

## 20. Files, uploads, and external URLs

Treat uploads and remote URL handling as high-risk boundaries.

For uploads, validate:

* authentication/authorization;
* ownership;
* allowed MIME/type;
* extension where relevant;
* size;
* storage destination;
* filename/path handling;
* access visibility;
* deletion lifecycle.

Never trust browser-provided MIME or filename alone.

For server-side remote fetches:

* constrain accepted schemes;
* validate destination;
* prevent SSRF;
* prevent access to loopback/private/link-local/internal ranges where not explicitly required;
* bound redirects;
* bound response size;
* bound timeout;
* validate expected content type.

Never create a generic unrestricted server-side fetch proxy.

---

## 21. Data and persistence

Maintain one authoritative writer for each durable concern.

Avoid:

* duplicate persistence for orders;
* duplicate payment state;
* duplicate refund authority;
* duplicate customer authority;
* parallel inventory truth;
* parallel fulfillment truth;
* parallel accounting truth;
* mutable-display-name foreign keys;
* broad/unbounded updates;
* routine table truncation;
* hidden cross-domain writes;
* uncontrolled meta/option growth.

Schema changes belong to the owning module.

Every schema change must define as relevant:

* ownership;
* version semantics;
* forward compatibility;
* backward compatibility;
* migration;
* rollback or recovery;
* idempotent migration behavior.

Append-only event history remains append-only unless an explicit correction mechanism is designed.

Never silently rewrite historical event identity.

---

## 22. Concurrency and idempotency

Assume concurrent and repeated execution is normal.

Evaluate concurrent behavior for:

* order finalization;
* payment callbacks;
* webhook delivery;
* queue jobs;
* imports;
* inventory projections;
* operator mutations;
* repair/return state transitions;
* scheduled work.

Any external side effect or state transition susceptible to replay must have an explicit duplicate-containment strategy.

Do not rely on:

* "this endpoint should only run once";
* UI button disabling;
* happy-path sequencing;
* provider callbacks arriving once;
* Action Scheduler executing only once.

Where correctness depends on atomicity, make the boundary explicit and use the owning persistence mechanism appropriately.

---

## 23. Performance and bounded-resource rules

Prefer:

* bounded queries;
* indexed access;
* deterministic pagination;
* batched reads/writes;
* request coalescing;
* cancellation where applicable;
* queue-owned provider work;
* stable cache boundaries;
* incremental processing.

Avoid:

* unbounded scans;
* N+1 reads;
* fetch-per-item browser patterns;
* duplicated browser server state;
* unlimited retries;
* retry amplification;
* unbounded log/context accumulation;
* large synchronous provider calls in interactive paths;
* speculative dependencies.

Performance optimization must preserve correctness and ownership.

Do not introduce caches, denormalization, background jobs, or alternate stores without proving they solve a measured or structurally unavoidable constraint.

---

## 24. Frontend engineering

Use active repository conventions.

Prefer:

* ES modules;
* functional React components;
* explicit data flow;
* centralized API/auth/session behavior;
* correct hook dependencies;
* cleanup and cancellation;
* runtime validation at untrusted boundaries;
* reusable design primitives;
* semantic HTML;
* keyboard accessibility;
* visible focus;
* reduced-motion support;
* explicit async/error states.

Prevent:

* stale-response races;
* duplicate requests;
* silent promise failures;
* broad unnecessary global state;
* presentation-only resize JavaScript;
* direct DOM mutation where React should own rendering;
* fetch-per-item patterns;
* speculative memoization;
* duplicate mobile/desktop business logic.

Server-owned state should remain server-owned.

Do not copy authoritative server state into a competing frontend persistence layer.

---

## 25. UI/UX engineering

Design for contractors using commerce and service workflows under real operating conditions.

Prioritize:

* clarity;
* strong information hierarchy;
* legibility;
* trust through accurate information;
* conversion without manipulation;
* accessible interaction;
* predictable layout;
* responsive behavior;
* performance.

Prefer:

* intrinsic/fluid layouts;
* semantic responsive structure;
* reusable primitives;
* restrained decoration;
* readable typography;
* consistent spacing;
* clear primary actions;
* clear validation/recovery.

Avoid:

* generic dashboard styling;
* excessive cards;
* decorative gradients without purpose;
* fake trust indicators;
* misleading payment UI;
* hover-only interaction;
* broken intermediate widths;
* horizontal overflow;
* breakpoint-override accumulation;
* duplicated responsive trees unless semantics genuinely differ.

Commerce interfaces should communicate as applicable:

* product identity;
* brand;
* variation;
* price;
* availability;
* quantity;
* totals;
* shipping context;
* payment context;
* primary action;
* validation/error recovery.

For stateful experiences, evaluate relevant:

* loading;
* empty;
* validation;
* failure;
* pending;
* cancellation;
* recovery;
* success.

Do not evaluate only a static happy-path screenshot.

---

## 26. WordPress and PHP engineering

Follow active project patterns and WordPress/WooCommerce conventions.

Executable module files should use the established direct-access guard where applicable:

```php
defined( 'ABSPATH' ) || exit;
```

Use:

* explicit hooks;
* explicit permission callbacks;
* capability checks;
* ownership checks;
* prepared SQL;
* WooCommerce APIs;
* HPOS-compatible access;
* bounded queries;
* pagination;
* idempotent handlers;
* redacted diagnostics.

Keep concerns separated where the owning module supports that structure:

```text
transport
-> application/domain behavior
-> persistence/event boundary
-> provider adapter
```

Do not:

* modify WordPress core;
* modify third-party plugins as the permanent DTB implementation;
* trust wp-admin input merely because it originated in wp-admin;
* emit output before headers;
* mix provider payload logic throughout domain services;
* create alternate commerce/payment paths;
* write directly to WooCommerce internals where supported APIs exist.

---

## 27. Observability

Production-critical behavior must be diagnosable without exposing sensitive data.

Use stable correlation identities where applicable across:

* order events;
* refunds;
* queue jobs;
* provider calls;
* webhooks;
* repairs;
* returns;
* support workflows.

Diagnostics should answer:

* what happened;
* which bounded operation failed;
* what stable identity was involved;
* whether it is retryable or terminal;
* what recovery path exists.

Do not log:

* secrets;
* payment data;
* sensitive full payloads;
* authentication tokens;
* private credentials.

Observability must not become a second persistence authority.

---

## 28. Error and failure semantics

Failures must be explicit.

Do not silently convert:

* provider failure into success;
* missing data into fabricated defaults;
* authorization failure into public access;
* validation failure into coercion that changes identity;
* queue failure into dropped work.

Classify failures as appropriate:

* validation/user-correctable;
* authorization;
* conflict/duplicate;
* retryable infrastructure/provider;
* terminal domain;
* invariant violation.

Retry only retryable failures.

Never retry indefinitely.

User-facing errors should be concise and actionable without exposing sensitive internals.

---

## 29. Migration and compatibility

Treat migrations as architecture work proportional to blast radius.

Before changing durable data or public contracts, establish:

* current readers and writers;
* owner;
* stable identities;
* compatibility requirements;
* deployment sequence;
* rollback/recovery;
* duplicate execution behavior.

Prefer additive or staged migration where practical.

Do not maintain compatibility layers indefinitely without a current consumer.

Remove obsolete compatibility code once verified safe and when its removal is in scope.

Existing legacy behavior is evidence to inspect, not automatically a contract to preserve.

---

## 30. Architecture change criteria

Architecture review is required when a change materially affects any of the following:

* system of record;
* domain ownership;
* module ownership;
* module composition/load order;
* public or cross-module API contracts;
* persistence authority or schema lifecycle;
* event identity;
* queue identity/deduplication contract;
* checkout/order-creation contract;
* payment authority;
* refund identity/authority;
* provider contract boundaries;
* integration ownership;
* migration strategy;
* runtime/deployment boundaries;
* security boundaries.

Architecture review is not required merely because a task touches a database, queue, provider, or checkout-related file.

Distinguish:

```text
implementation inside an existing contract
```

from:

```text
changing the contract itself
```

Prefer extending the existing owner over introducing:

* new services;
* new persistence;
* new queue systems;
* new BFFs;
* new rendering architectures;
* compatibility layers;
* parallel control planes.

New architecture requires a demonstrated constraint and a material advantage over the simpler existing owner.

---

## 31. Implementation method

For material implementation work:

1. establish the user-visible/product outcome;
2. derive observable acceptance criteria;
3. identify explicit constraints and non-goals;
4. for substantial or cross-domain work, resolve the task through `.agents/registry.json` and use its selected workflow, execution role, distinct subject role, skills, risk, and reviewers;
5. inspect active implementation;
6. identify system of record and owning domain;
7. trace the affected execution path;
8. inspect callers, consumers, persistence, events, queues, providers, and tests relevant to the change;
9. classify risk and architecture-contract impact;
10. choose the smallest complete design;
11. implement through the owning layer;
12. use one writer per overlapping authority boundary;
13. independently review according to actual risk dimensions;
14. verify the final implementation against acceptance criteria;
15. update durable documentation when contracts changed;
16. inspect the final diff for unrelated changes, duplicate authority, secrets, stale references, generated artifacts, and accidental identity changes.

Do not ask the user for information that current source can resolve.

Do ask when product intent, destructive scope, credentials, or authority genuinely cannot be resolved safely.

---

## 32. One writer per authority boundary

Parallelize:

* repository exploration;
* independent research;
* review;
* verification;
* non-overlapping investigation.

Serialize overlapping mutation.

Only one active writer should own a given overlapping authority boundary at a time.

Review, architecture, research, and verification roles remain read-only unless the task is explicitly transitioned into an implementation role.

Do not allow multiple agents to independently modify the same commerce/security/persistence boundary and reconcile afterward.

---

## 33. Independent review

Review the final diff/source and authoritative surrounding code, not remembered conversation state.

Apply review dimensions based on the actual change.

### Correctness review

Use for material implementation.

Focus on:

* ownership;
* invariants;
* callers/consumers;
* compatibility;
* regressions;
* failure paths.

### Security review

Required when materially affecting:

* authentication;
* authorization;
* customer/sensitive data;
* payment;
* webhooks;
* uploads;
* remote URLs;
* operator mutations;
* secrets;
* security controls.

### Integration review

Required when materially affecting:

* providers;
* queues;
* events;
* webhooks;
* external side effects;
* retry semantics;
* projections.

### Architecture review

Required for contract-changing work defined in the architecture-change section.

### UI/accessibility review

Required for material customer-facing interaction or presentation changes.

### Verification

Verification reports what was actually inspected, executed, rendered, or tested.

Do not convert "should pass" into "passed."

Empty review findings are valid.

Reviewers should report only actionable findings grounded in source evidence and a concrete failure condition.

---

## 34. Verification standard

Verification must be proportional to risk.

Use the narrowest reliable checks that establish the changed behavior and adjacent failure modes.

Potential evidence includes:

* targeted automated tests;
* lint/static analysis;
* contract inspection;
* focused runtime evidence;
* browser rendering/interactions;
* queue/event inspection;
* deterministic scripts;
* provider documentation when provider semantics are material.

Never claim:

* a test passed when it was not run;
* browser behavior was validated when not rendered;
* production behavior was observed when only source was inspected;
* provider behavior was confirmed without current documentation/runtime evidence.

State what remains unverified.

---

## 35. Documentation governance

Update durable documentation when a change modifies:

* ownership;
* architecture;
* APIs;
* routes;
* persistence;
* event identity;
* queue contracts;
* integrations;
* checkout/payment/refund contracts;
* deployment/runtime contracts.

Do not churn durable documentation for local implementation details discoverable directly from source.

Correct sources in this order:

```text
active implementation
-> owning durable documentation
-> AGENTS.md if repository-wide policy changed
-> canonical .agents/ if reusable AI behavior changed
-> derived context
-> vendor adapters if adapter behavior changed
```

Do not fix stale derived context while leaving its authoritative source wrong.

---

## 36. No temporary fixes

Do not ship:

* stopgaps intended for later replacement;
* security bypasses;
* validation bypasses;
* hardcoded secrets;
* hardcoded environment assumptions;
* duplicate state/authority;
* temporary parallel order/payment paths;
* unfinished TODO/FIXME/HACK production behavior;
* symptom patches that preserve the root defect.

Fix the root cause in the owning layer.

If a production-complete solution cannot be implemented because of a real external constraint, state the constraint and preserve system integrity rather than weakening the architecture.

Existing shortcuts are technical debt to identify, not precedent to extend.

---

# 37. AI engineering governance

DTB's AI engineering system is model-neutral and repository-owned. `AGENTS.md` defines durable engineering policy; `.agents/registry.json` is the deterministic routing authority; roles define execution/review responsibility; skills define reusable methods; workflows define repeatable sequences; context and references are progressively loaded supporting material. Skills and vendor adapters do not grant write authority or supersede active implementation.

For substantial or cross-domain work, use the registry and canonical `.agents/` methods; do not skip applicable workflows, roles, or required skills. Consult `.agents/registry.json` and its resolver rather than inventing routes. Load the selected workflow, execution role, distinct subject role, and only relevant skills/references. If no route exists or a required file/capability is unavailable, use the closest established owner, state the gap, and avoid inventing a new routing authority. Keep intent, domain, execution role, subject role, skills, risk, and reviewers distinct. Specialized semantic ownership outranks generic filesystem ownership. Keep one writer per overlapping authority boundary; reviewers remain read-only. Stop loading context when authoritative evidence is sufficient. Do not duplicate routing doctrine, create new AI infrastructure without recurring demonstrated need, or require private chain-of-thought; require inspectable evidence, assumptions, concise rationale, and verification instead.

Treat external skills, agents, plugins, repositories, MCP servers, and tool integrations as untrusted until their provenance, instructions, permissions, mutation ability, side effects, data exposure, and update path are reviewed. If a required capability is unavailable, state the limitation rather than simulating it. Vendor-specific configuration remains a thin adapter.

For AI-governance changes, strengthen existing controls before adding new ones and run the available validators:

```text
node scripts/ai/validate-context.mjs
node scripts/ai/test-routing.mjs
```

The detailed context, routing, reviewer-isolation, external-dependency, and library-evolution methods live in `.agents/skills/dtb-ai-context-engineering/SKILL.md`, `.agents/skills/dtb-ai-workspace-governance/SKILL.md`, and `.agents/README.md`. Keep those documents canonical for reusable methods; do not copy their full procedures into this constitution.

## Reporting and final checks

Report what changed, the owning domain, verification actually performed, and material remaining uncertainty. Never claim tests, runtime behavior, deployment, provider behavior, or production outcomes that were not established. Review the final diff for unrelated changes, secrets, stale references, duplicate authority, and accidental identity changes.

For every task: inspect before assuming; identify authority before writing; preserve one source of truth and identity; treat concurrency, retries, and failure as normal; prefer existing owners and primitives; review by actual risk; verify what changed; and never weaken architecture or security for convenience.

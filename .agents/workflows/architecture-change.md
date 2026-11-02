# Architecture Change Workflow

Use only when a durable contract materially changes: system/domain ownership, persistence authority/schema lifecycle, public/cross-module API, event or queue identity, checkout/payment/refund boundary, provider contract, composition, migration strategy, or runtime/deployment boundary. Architecture remains read-only until a design is selected.

## Current state
Trace active owner/system of record, entry points, data lifecycle, interfaces, authorization, identities, events/queues, provider boundaries, consumers, failure/retry behavior, observability, and owning docs. Stop once evidence is sufficient to define the real constraint.

When the contract spans live services or external providers, use
`.agents/context/connected-systems.md` to locate the available interface, then
verify the current account/environment and query the authoritative system. A
connector's presence, DTB projection, or deployment capability flag is not proof
that an external operation is enabled or complete.

## Decision frame
State problem and acceptance criteria; define invariants. Compare the smallest viable options against ownership/duplicate truth, security/privacy, protected identities, concurrency/idempotency/replay, failure/recovery, observability, performance/resource cost, migration/backward compatibility, rollback, operations, and maintenance/removal cost.

Prefer changing/extending the existing owner. New modules/services/persistence/BFFs/rendering architectures/control planes require a demonstrated constraint and material advantage over simpler alternatives.

## Required design
Specify current evidence; selected owner/system of record; inputs/outputs/identity/auth contracts; persistence/event semantics; affected systems; security/data/concurrency/performance impact; migration and rollback/recovery; verification; durable docs; and materially credible rejected alternatives.

## Exit
Ready for implementation only when ownership is singular, identities/failure semantics are explicit, no parallel truth is introduced, and the design is the simplest complete response to the demonstrated constraint.

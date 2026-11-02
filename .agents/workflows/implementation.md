# Implementation Workflow

Use for fixes/features/changes where one owning writer modifies repository source inside a selected contract.

## 1. Frame
Extract observable acceptance criteria, constraints, non-goals, and user scope. Resolve source-answerable unknowns before asking the user.

## 2. Establish current behavior
Read `AGENTS.md`, resolve routing, and inspect active implementation. Trace only the entry points, persistence/events/queues/providers, consumers, tests, and docs required to understand the change. Reuse established evidence and stop expanding when evidence is sufficient.

When the task depends on live or external state, identify its system of record
and use the current session's verified connected interface from
`.agents/context/connected-systems.md`. Check the target environment and
connection health before relying on live values or taking action. Treat returned
health/configuration as a timestamped observation; never infer that a connected
tool is authorized for a mutation or that it owns the domain.

## 3. Confirm semantic owner and contract status
Name system of record and owning domain. Specialized semantic ownership outranks generic filesystem ownership. Explicitly decide whether this is implementation inside an existing contract or a contract-changing architecture task. Use contract flags such as `ownership-change`, `persistence-contract`, `queue-identity`, `provider-contract`, `api-contract`, `checkout-contract`, `refund-contract`, `migration`, `composition-change`, or `deployment-boundary` when applicable.

## 4. Design the smallest complete change
Preserve existing contracts unless intentionally changing them. Account for authorization, validation, stable identity, idempotency, concurrency, retries, partial failure, bounds, cleanup/cancellation, compatibility, observability, and recovery where relevant. Prefer existing owners/primitives.

## 5. Implement through one owner
One writer per overlapping authority boundary. Keep provider details in adapters and generated outputs owned by generators. Avoid unrelated cleanup.

## 6. Review and verify
Apply review dimensions from resolved risk/flags. Reviewers use final diff/source and isolated authoritative context. Verify against acceptance criteria with targeted checks; never claim unexecuted checks passed.

## 7. Close
Update durable docs only when durable contracts changed. Inspect final diff for duplicate authority, secrets, protected identifiers, generated-source mistakes, debug artifacts, stale references, and unrelated churn.

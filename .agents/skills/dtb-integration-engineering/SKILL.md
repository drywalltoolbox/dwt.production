---
name: dtb-integration-engineering
description: DTB external-integration engineering for Action Scheduler boundaries, stable identity, provider adapters, idempotency, retries, reconciliation, webhooks and authoritative projections.
---
# DTB Integration Engineering

## Authority first
Identify the authoritative system for every state crossing the boundary. WooCommerce owns commerce; Veeqo fulfillment/inventory; QuickBooks accounting projection; payment providers sensitive payment lifecycle. A DTB projection must never silently become competing truth.

## Connected operations interfaces
Use the current session's connected-system map (`.agents/context/connected-systems.md`) to locate an interface, then confirm that the required tool is present and the target account/environment is correct. A direct provider connector is an operator interface, not a second integration pipeline and not permission to bypass DTB ownership. For source-of-truth state, query the owning system; use DTB diagnostics for DTB projections and readiness only. Keep live status observations dated and recheck them before consequential action. Never persist credentials or copy secret-bearing configuration into context.

## Boundary contract
For each integration define producer/event/command identity, persisted correlation, queue ownership, adapter input/output, provider/external identity, local acknowledgement/projection, and consumers. Provider authentication, transport, payload mapping, pagination/rate limits, mutable API semantics, and webhook details stay inside adapters.

## Delivery semantics
Assume retries, duplicate callbacks, reordering, timeout ambiguity, partial failure, and delayed delivery. External mutations need deterministic idempotency/duplicate containment, bounded retry/backoff, retryable/terminal classification, completion detection, observability, and recovery/reconciliation. Never infer remote non-execution merely from a local timeout.

## Interactive boundaries
Keep slow/retryable provider effects out of checkout/payment-webhook acknowledgement and latency-sensitive interactive requests. Enqueue stable work and acknowledge only according to the owning contract.

## Webhooks
Verify authenticity before effects, constrain replay, preserve external event identity, make repeated delivery safe, and avoid acknowledging success before required durable local acceptance when the provider contract demands it.

## Verification
Exercise/inspect duplicates, retries, timeout ambiguity, partial failure, pagination/bounds, rate limits, identity/correlation, recovery, webhook replay/signatures, and authoritative projection semantics. Use current provider documentation for volatile API behavior.

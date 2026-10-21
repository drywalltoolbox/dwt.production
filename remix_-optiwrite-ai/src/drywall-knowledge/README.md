# Drywall Tools AI Knowledge Base

This package is the deterministic domain-intelligence layer for DTB catalog generation. It teaches a downstream AI editor how professional drywall taping, finishing, corner, compound-delivery, stilt, storage, set, and repair-part domains work without becoming a second product catalog.

## Authority

`products/launch/official/dtb_official_catalog.csv` remains canonical for SKU-level product facts. Official manufacturer research may supplement exact product evidence. This library contains reusable trade/domain knowledge only. Existing copy is reference material, not proof.

## Runtime flow

```text
canonical/pre-generation evidence packet
  -> classifyDrywallDomain()
  -> buildCatalogEditorKnowledge()
  -> buildDrywallDomainContext()
  -> compact domain context + product evidence
  -> AI catalog editor
  -> existing deterministic output QA/application guardrails
```

The package performs no network calls, writes no catalog state, and adds no AI call. Classification is deterministic and context compilation selects one domain family plus one domain record, global evidence/editorial policy, relevant terminology, search intent, and optional brand spelling.

## Design constraints

- Product domain is independent from relationship type and broad content class.
- Generic workflow relationships never establish exact SKU compatibility.
- Mechanism knowledge explains a mechanism only after product evidence establishes its presence.
- Buyer questions prioritize evidence; they are not mandatory headings and must never cause invented answers.
- Description length is an evidence-sufficiency outcome, not a quota.
- Package contents require structured/authoritative evidence.
- Brand records govern spelling/terminology only.
- Source references are provenance for maintainers and are not automatically injected into runtime prompts.

## Integration

A downstream TypeScript AI service should import `buildCatalogEditorKnowledge(packet, options)` from this package and place `result.context.text` in the system/domain portion of the request. The exact `packet` should still be sent separately as product evidence/data. Do not merge domain context into the evidence object.

If a caller already has a verified domain ID, it may call `buildDrywallDomainContext()` directly. If deterministic classification returns `low`, route the item for domain-map review rather than treating the fallback domain as factual product identity.

## Maintenance

When one SKU generates poorly, diagnose evidence, classification, domain knowledge, prompt contract, and QA separately. Add or revise shared knowledge only when the failure exposes a reusable industry-domain deficiency. Do not create per-SKU prompt patches.

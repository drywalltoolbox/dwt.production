# Catalog SEO Pre-Generation Pipeline

## Purpose

`scripts/catalog/catalog_seo_pre_generation.py` is an internal deterministic stage of the official catalog-enrichment run. It does not generate copy and does not mutate `products/launch/official/dtb_official_catalog.csv`.

Use the unified production command rather than a separate SEO wrapper:

```powershell
.\scripts\catalog\run-official-catalog-enrichment.ps1
```

## Ownership

- `products/launch/official/dtb_official_catalog.csv` owns canonical catalog facts and protected identifiers.
- `scripts/catalog/official_catalog_schema.py` owns structural validation.
- `scripts/catalog/catalog_seo_pre_generation.py` owns evidence extraction, editorial classification, and pre-generation QA only.
- `scripts/catalog/drywall-knowledge/` provides reusable domain context, not SKU-specific product truth.
- WooCommerce remains commerce persistence authority.
- Frontend SEO rendering remains owned by `frontend/src/components/shared/SEOHead.jsx` and `frontend/src/utils/schema.js`.

## Stage contract

The stage:

1. validates the canonical catalog;
2. normalizes read-only text;
3. snapshots and hashes protected identity;
4. classifies product complexity;
5. extracts structured product evidence;
6. excludes variations from independent indexable-content authority;
7. evaluates existing description/SEO metadata;
8. routes findings by workflow;
9. emits derived evidence packets and review artifacts.

Generated artifacts are disposable and are not an alternate catalog.

## Finding workflows

- `blocking` — deterministic routing/canonical conflicts;
- `accuracy_review` — claims requiring authoritative evidence;
- `evidence_review` — insufficient structured evidence;
- `editorial_review` — content length, repetition, duplicate copy, and metadata observations.

Only `blocking` findings affect `--fail-on-blocking`. Editorial length guidance never becomes a catalog correctness rule.

## Protected identity

Packets capture and hash protected fields including SKU, GTIN, brand identity, manufacturer identifiers, product-kind/category identity, parent/variation identity, schematic identity, and slug.

Any future generation/application stage must verify `protected_identity_sha256` against the current canonical row before applying generated editorial fields.

## Product classes

Current editorial classes are:

- `commodity_hardware`
- `replacement_component`
- `replacement_assembly`
- `tool_accessory`
- `primary_finishing_tool`
- `automatic_equipment`
- `stilts`
- `kit_set`
- `general_product`

Word ranges are editorial guidance only. `description_long_for_class` means review the extra prose; it does not declare the description incorrect.

## Evidence handling

The packet may retain an internal `evidence_coverage_grade` for downstream packet prioritization, but the official operational run summary does not expose or treat that A/B value as catalog readiness. Operational reporting uses explicit dimensions such as item-MPN, GTIN, structured-spec, media, and compatibility coverage.

Existing copy is reference material, not proof. Precision-manufacturing claims, industrial/professional-grade claims, performance superlatives, fit guarantees, material-quality claims, and productivity/downtime claims require authoritative support before reuse.

Reusable drywall-domain knowledge may explain a verified mechanism; it cannot prove that a particular SKU contains that mechanism.

## SEO normalization

The stage evaluates:

- missing/oversized SEO titles;
- missing/oversized meta descriptions;
- duplicate/near-duplicate metadata;
- focus-keyword gaps;
- canonical overrides against `/products/:slug`.

Normal PDPs should use the deterministic runtime canonical. The unified runner can apply the reviewed safe canonical cleanup with:

```powershell
.\scripts\catalog\run-official-catalog-enrichment.ps1 -ApplySafeFixes
```

The official WooCommerce import CSV does not own the generated product slug. The safe cleanup therefore clears only known legacy `/product/` overrides when no slug is available (or redundant/conflicting overrides when a slug is explicitly supplied). A custom canonical with an unresolved target remains for manual review; the cleanup does not guess a product slug or clear arbitrary routes.

## Variation policy

Variation packets exist only for context. `generation_eligible` remains false for variations, so product SEO authority stays with the parent.

## Outputs

The unified run writes:

```text
products/dev/catalog-enrichment/seo-pre-generation/
  generation-packets.jsonl
  pre-generation-findings.csv
  pre-generation-summary.json
```

These files are ignored by Git.

## Direct developer invocation

The Python stage may still be executed directly for focused development/testing:

```text
python scripts/catalog/catalog_seo_pre_generation.py
python -m unittest scripts.catalog.tests.test_catalog_seo_pre_generation
```

Direct invocation is not a second production workflow.

## Downstream generation contract

A future generation/application stage must:

1. consume only `generation_eligible` packets;
2. preserve protected identity;
3. treat `authoritative_facts` as the fact boundary;
4. treat existing descriptions as reference copy rather than proof;
5. resolve domain context or stop for review;
6. keep domain knowledge separate from product evidence;
7. omit unsupported sections;
8. never expand content to satisfy a minimum word count;
9. independently optimize description, short description, title, and meta description;
10. avoid catalog-wide sentence templates;
11. require research/review when evidence is insufficient; and
12. revalidate canonical identity before applying approved copy.

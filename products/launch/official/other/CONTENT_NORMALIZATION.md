# Product Content Normalization Contract — v1 (audit-only)

**Authorities:** `products/launch/official/dtb_official_catalog.csv` is canonical launch source; WooCommerce owns runtime product records. `Meta: _dtb_specs_json` remains the authoritative structured specification payload. Frontend renderers own presentation, not product facts.

## Normalized model
- **Identity:** keep SKU, MPN, GTIN, brand, taxonomy, compatibility and variation links untouched.
- **Short description:** factual concise summary; never automatically generate one.
- **Description:** meaningful product overview paragraphs only. No duplicated brand/SKU, tables with layout widths, or HTML formatting artifacts.
- **Features:** individual fact-based label/value statements. Include only explicitly sourced claims.
- **Kit contents:** distinct quantities, names and identifiers, preserving order and exact component facts.
- **Specifications:** verified structured attributes from `Meta: _dtb_specs_json`; reconcile descriptions that contain independent technical values before migration.
- **Parts & Schematics:** use verified compatibility and schematic relationships; do not interpret marketing copy as compatibility evidence.
- **Shipping & Returns:** shared policy presentation, with verified product-specific exceptions only.

## HTML and layout
For canonical Description fields, retain semantic `p`, `h3`, `ul`, `ol`, `li`, `strong`, and safe links when needed. Avoid styles, floats, fixed widths, copied Office spans or inline layout attributes. Store semantic fields separately only after a reviewed schema/consumer migration. One frontend renderer applies typography to both full PDP and Quick View.

## Audit and migration review

```sh
python3 scripts/catalog/audit_product_content.py
python3 scripts/catalog/audit_product_content.py --catalog path/to/source.csv --output path/to/review
```

The script **never modifies its input and never calls WooCommerce**. It writes:
1. `product-content-summary.json` — input checksum and disposition counts.
2. `product-content-audit.csv` — per-row SKU/type/status/reason/checksum.
3. `product-content-proposals.jsonl` — original source alongside proposed classified paragraphs/features/kit contents/specifications.

Status values:
- `already_compliant`: no detected migration blocker, usually short overview-only content.
- `auto_classifiable`: clearly structured source that can be classified deterministically, but **still not approved for import**.
- `requires_editorial_review`: ambiguity, legacy markup, variation inheritance or technical facts needing reconciliation.
- `missing_supporting_data`: no description supplied.

Reasons explain the decision. The classifier never hallucinates claims, rewrites protected identifiers or synthesizes features. The classification is a *candidate*, not a content publication. `migration=review_only` is mandatory on every record.

### Review gates before any writeback
- Original CSV checksum is retained and row/column counts match.
- Protected identifiers and every non-description field remain byte-for-byte unaffected by generation.
- Product families and their variations are reviewed together; variation descriptions cannot be dropped because a parent has similar text.
- All existing substantive words, claim values, part numbers, and kit quantities are reconciled against their original; no loss through extraction.
- Structured specifications and source-description specification lists are reconciled without duplication.
- Reviewers approve actual product data changes and the owning import/update path, then run `validate_official_catalog.py`.
- Verify PDP and Quick View on short parts, long kits, variable parents and variations at desktop/mobile widths.
- Import/deploy is a separate, explicit action. **No direct catalog or runtime mutation is authorized by this tool.**

## Known limitations
This first-stage deterministic classifier does not preserve rich HTML structure in proposed section text: it collects meaningful plain text while keeping the original HTML intact alongside it. It does not distinguish arbitrary nested subsections from bona fide features or verify supplier claims. Before a production transformer is introduced, construct lossless semantic block extraction and round-trip tests for embedded lists and headings. Data with unresolved classifications remains in review, never silently published.

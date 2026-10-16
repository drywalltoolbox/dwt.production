# WCSpots hotspot architecture evaluation

Status: evaluated; no data migration approved or performed.

Date: 2026-10-15

## Decision

Do not replace `dtb-schematics` hotspot storage, lifecycle, resolver, or public API with WCSpots. WCSpots is an editor block for product showcases on a single image, not a schematic catalog and lifecycle system. Adopting its storage as canonical would split schematic ownership and lose important DTB data semantics.

Reuse selected WCSpots interaction patterns only if a future DTB-owned authoring improvement benefits from them. Keep the WordPress admin editor, normalized dataset, persistence, reconciliation, product identity rules, and API inside `dtb-schematics`. A future adapter may import/export WCSpots block data as a non-authoritative interchange format, but must be opt-in, dry-run first, lossless for supported records, and never make WCSpots a second runtime source of truth.

## Evidence reviewed

### DTB repository and runtime

- `docs/architecture/schematics-platform.md` defines `dtb-schematics` as the sole authority for schematic identity, lifecycle, pages, hotspots, product relationships, reconciliation, public projections, and the admin control center. WooCommerce remains the product/variation authority; React is a presentation client.
- `Domain/SchematicHotspotDataset.php` normalizes a schematic-wide v2 dataset with a de-duplicated `parts_catalog` and independent physical `hotspots`. Occurrences carry stable `hotspot_id`, `part_ref`, 1-based page, shape, normalized geometry, and optional label. Multiple physical occurrences can reference one catalog part.
- The domain supports point, rectangle, circle, and polygon coordinate forms and validates coordinates against the 0–100 page space. Invalid source geometry is not silently clamped into a plausible location.
- Repository services persist normalized datasets; readers and migrations normalize legacy and v2 source JSON; audit, diagnosis, deterministic product resolution, guarded operations, source reconciliation, and REST projection already exist.
- The live DTB public API was inspected during the audit and returns page-associated normalized hotspot datasets and resolution state.
- Local source corpus sampled from `frontend/public/brands/**/schematic_data.json`: 112 files, 3,925 catalog part rows, and 405 physical hotspot occurrences. These are repository counts, not a claim about all current production records.

### WCSpots implementation and Platinum use

- Platinum's installed WCSpots source identifies itself as a WooCommerce product showcase block. Its editor stores `hotspots` in block attributes associated with one image; each hotspot has a generated UUID, `x`/`y` percentage coordinates, display name, `productId`, `productTitle`, and visual settings.
- The block's saved markup renders only hotspots with a `productId`. This is a materially different behavior from DTB's deliberate support for unresolved/reference-only callouts.
- Its bundled REST API is only a permission-protected product selector endpoint (`wcspots/v1/products`), capped at 100 products per request. It is not a schematic data API or migration interface.
- Platinum's schematic status reports 15 schematics and 360 hotspots, of which 184 are assigned and 176 unassigned; the rendering policy explicitly allows unassigned hotspots. Its viewer also applies Platinum-owned catalog/source-parts mapping around WCSpots. This is evidence that WCSpots is one geometry/editor component in Platinum's system, not the whole architecture to copy.
- Platinum's validation reports structurally valid coordinates for its own WCSpots records, but that does not establish lossless compatibility with DTB's multi-page, catalog/occurrence, unresolved mapping, audit, migration, and lifecycle contracts.

## Capability comparison

| Concern | DTB canonical model | WCSpots block model | Assessment |
|---|---|---|---|
| Owner and lifecycle | Schematic CPT and `dtb-schematics` application services | Individual Gutenberg block content | Keep DTB ownership |
| Scope | Schematic with pages, catalog parts, and occurrences | One showcase image per block | WCSpots requires block-per-page decomposition |
| Callout identity | Stable occurrence IDs preserved through migrations | UUID generated in editor | Both can identify occurrences; mapping still required |
| Catalog vs occurrence | Distinct catalog rows and physical callouts | Product assignment is attached directly to each hotspot | DTB supports repeated callouts to one part and reference-only rows |
| Geometry | Point, rect, circle, polygon in normalized page coordinates | Point coordinates and presentation settings | Mapping is lossy for non-point DTB regions unless WCSpots is extended |
| Unresolved callouts | Retained and reported for operator review | Omitted from saved frontend hotspot rendering when unassigned | Direct adoption risks hiding valid callouts |
| Product identity | Exact Woo ID/SKU/MPN authority and guarded deterministic resolver | Selected Woo product ID/title embedded in hotspot | WC IDs can be a link, but cannot replace protected identity and resolution controls |
| Admin and bulk operations | Dedicated admin workspace, audit, dry-run/apply, migration, and reconciliation | Block editor interactions for one image | WCSpots is simpler locally; DTB is more capable across a catalog |
| Integration contract | Versioned normalized API and persistence | No schematic read/write API; product-selection route only | No safe direct runtime integration without new adapter code |

## Risks of a canonical migration

1. **Authority split:** schematic identity/pages/lifecycle would move into block content while DTB's CPT and reconciliation continue to exist. Two writable sources would invite drift.
2. **Callout loss or hiding:** WCSpots frontend save filters out unassigned hotspots. DTB deliberately retains unresolved callouts and catalog-only rows.
3. **Geometry loss:** point-only WCSpots locations cannot represent DTB rectangle, circle, or polygon hit regions without extending WCSpots or preserving DTB-only sidecar data.
4. **Identity corruption:** product display names/IDs in block attributes cannot safely replace DTB's exact catalog matching, review queue, protected identifier policy, or explicit not-sold decisions.
5. **Operational regression:** replacing DTB's dry-run, fingerprinted apply, lease, audits, migration, and source reconciliation with per-post block edits would weaken batch safety and observability.
6. **Compatibility cost:** a multi-page schematic would become multiple blocks with a separate mapping layer back to the schematic and page identities.

## Recommended target architecture

```text
DTB source datasets / operator tools
  -> dtb-schematics normalization and guarded persistence
  -> schematic-wide v2 dataset (parts_catalog + physical occurrences)
  -> deterministic Woo relationship resolution
  -> audit, readiness, and versioned public API
  -> React schematic viewer and master parts list
```

WCSpots may inspire focused UI affordances such as direct placement, drag-to-adjust, and an accessible product selector. If existing WCSpots blocks ever need to be ingested, implement an isolated importer that maps supported single-image records into a proposed DTB v2 dataset, reports unsupported geometry and ambiguous identities, preserves unassigned records, and requires operator-reviewed dry-run/apply. It should not write WCSpots blocks during normal schematic operation.

## Safe migration gates if an importer is later requested

1. Inventory live WCSpots block records and current DTB source/stored datasets read-only; fingerprint inputs.
2. Define explicit schematic and page mapping; require unambiguous mapping rather than title guessing.
3. Convert point geometry only where the source image/page and coordinate basis are verified. Reject unsupported/lossy geometry.
4. Preserve every callout including unassigned ones; resolve products only by exact approved identities and keep unresolved candidates queued.
5. Produce a complete dry-run report with counts, per-record errors, before/after checksums, and zero writes on ambiguity.
6. Apply only through a DTB capability-protected, nonce-protected, lease-protected operation with a freshness fingerprint and snapshot/rollback plan.
7. Verify API output and frontend parity on staging before any production rollout; retain the existing DTB data projection until verified.

## Result

The more optimal and admin-friendly target is to improve DTB's existing admin workflow using proven WCSpots editor interaction patterns while keeping DTB's richer canonical data architecture. A wholesale WCSpots refactor is not recommended, so no hotspot records, WCSpots blocks, Woo products, or production files were modified as part of this evaluation.

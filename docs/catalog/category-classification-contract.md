# Catalog Category Classification Contract

## Authority

The durable taxonomy/navigation contract is defined in `docs/catalog-navigation-contract.md` and the machine-readable hierarchy is defined in `products/catalog/source/taxonomy.json`.

This document defines only the **classification decision process** for assigning an exact sellable SKU to that hierarchy. It must not duplicate or redefine the taxonomy tree.

WooCommerce `product_cat` is the runtime storefront navigation authority. `products/launch/official/dtb_official_catalog.csv` owns product-to-category assignments for catalog updates.

Historical category paths are migration inputs only. They are not evidence that a product was previously classified correctly.

Reviewed decisions are applied explicitly to the official catalog, not stored in a separate override registry that can rewrite it.

## Functional classification rule

Classify by the physical sellable product and its operating role, not by a generic word in the product name, manufacturer terminology, brand, series, or historical storefront location.

Manufacturer synonyms that describe the same physical/function class normalize to one canonical taxon. For example, `Angle Head` / `Anglehead` normalizes to `Corner Finishers`.

Ambiguous terminology must be resolved using exact SKU evidence. In particular, the retired historical `Compound Applicators` class is not safe to infer from its name because it previously mixed:

- complete powered/pressure-assisted compound-delivery tools; and
- passive applicator/mud heads attached to another delivery tool.

Those products now resolve to `Powered Compound Applicators` or `Applicator Heads` only after exact functional review.

## Evidence order

For a disputed or ambiguous SKU, use this evidence order:

1. current manufacturer product page or current manufacturer catalog;
2. manufacturer operation/maintenance guide or schematic;
3. manufacturer-defined compatible system and required upstream/downstream tools;
4. specialist retailer evidence as secondary confirmation.

Product-title keywords alone are insufficient where terminology overlaps.

## Current reviewed corrections

The reviewed exceptions currently include:

- `4-772` — LEVEL5 MiniShot: `Powered Compound Applicators`.
- `COL-ANGLE-HEAD` — Columbia Angle Head: `Corner Finishers`.
- `COL-THROTTLE-CORNER-FLUSHER-BOX` — Columbia ThrottleBox: `Corner Applicators & Angle Boxes`.
- `CTA01TT` — TapeTech Compound Tube Filler Adapter: `Goosenecks, Box Fillers & Adapters`.
- `LV5-CORNER-APPLICATOR` — LEVEL5 Corner Applicator Box: `Corner Applicators & Angle Boxes`.
- `LV5-CORNER-FINISHER` — LEVEL5 Corner Finisher: `Corner Finishers`.
- `MRX01TT` — TapeTech MudRunner Pro Extension: `Handles & Extensions`.
- `PT-CA8` — Platinum Corner Applicator: `Corner Applicators & Angle Boxes`.
- `PT-CF` — Platinum Angle Head Corner Finisher: `Corner Finishers`.
- `TT-CORNER-APPLICATOR` — TapeTech Corner Applicator: `Corner Applicators & Angle Boxes`.
- `TT-CORNER-FINISHER` — TapeTech Corner Finisher: `Corner Finishers`.
- `TT-MUDRUNNER` — TapeTech MudRunner: `Powered Compound Applicators`.

This list describes previous reviewed decisions; the official catalog contains current assignments. Historical evidence remains in version control.

## Pipeline contract

Edit the official CSV with evidence and preserve protected identifiers. `validate_official_catalog.py` checks category paths against the runtime navigation registry and checks variation inheritance without changing assignments. Import explicitly into WooCommerce and reconcile any runtime edits back to the CSV before a later import.

Runtime PHP and React code must consume the projected/backend-owned classification. They must not repair product category ownership from names.

## Non-goals

This contract does not change SKU, MPN, GTIN, product/variation identity, pricing, inventory, fulfillment, orders, payments, refunds, Veeqo, QuickBooks, schematic identity, or compatibility identity.

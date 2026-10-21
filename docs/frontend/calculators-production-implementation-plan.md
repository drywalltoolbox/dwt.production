# Drywall Toolbox Calculators — Streamlined Production Implementation Blueprint

**Status:** Implemented baseline  
**Date:** 2026-09-03  
**Repository:** `elliotttmiller/launch-dtb`  
**Owning module:** `frontend/`  
**Current-state baseline:** `docs/frontend/calculators-current-architecture-audit.md`  
**Standards disposition baseline:** `docs/frontend/calculators-primary-source-validation.md`

## 1. Product target

The Calculator Hub is a lightweight professional utility embedded inside the Drywall Toolbox e-commerce storefront. It is not intended to become a standalone construction-estimating application, project-management platform, assembly-design engine, CAD/layout optimizer, or code-compliance system.

The production target is:

> Fast enough for a contractor or customer to use in a few minutes, technically defensible for ordinary drywall material planning, explicit about its assumptions, and simple enough to remain subordinate to the store's primary commerce experience.

The Hub keeps the familiar tab structure:

```text
Drywall Sheets
Joint Compound
Joint Tape
Corner Bead
Drywall Screws
Project Summary / PDF
```

Each tab can be used independently. The Sheets tab provides optional upstream geometry to Compound, Tape and Screws when available.

## 2. Architecture

### 2.1 Keep the architecture intentionally small

Authoritative arithmetic is isolated from JSX in one small pure utility module:

```text
frontend/src/lib/calculators/index.js
```

The UI remains under:

```text
frontend/src/components/calculators/
```

No backend calculator service, rules engine, provider abstraction, assembly registry, server persistence, Action Scheduler workflow, WooCommerce authority, or DTB MU-plugin calculator domain is introduced.

### 2.2 Calculation contract

Every applicable calculator follows the same simple contract:

```text
required / installed quantity
        +
explicit user purchasing allowance
        =
purchase quantity
        ->
package rounding
```

This prevents downstream calculators from accidentally consuming another calculator's already waste-adjusted purchase quantity.

### 2.3 Optional cross-tab synchronization

```text
Sheets
├── net finishable area -> Compound
├── field-joint footage / ceiling perimeter -> Tape
└── installed sheet count / sheet size -> Screws
```

Synchronization is helpful, not mandatory. Manual modes remain available when users enter a downstream calculator directly.

## 3. Standards and source policy

The Calculator Hub must distinguish four kinds of values:

1. geometry-derived quantities;
2. installation/finish concepts governed by GA/ASTM or an applicable assembly;
3. manufacturer estimating/coverage data;
4. user-selected purchasing allowances.

The UI and documentation must not label an estimating heuristic as an ASTM, GA, or universal industry requirement.

Generic calculator results are scoped to conventional non-rated work. Fire-rated, sound-rated, multilayer, specialty, proprietary, or tested/listed assemblies require their applicable system documentation.

## 4. Drywall Sheets

### Implemented direction

- Keep 4×8, 4×10 and 4×12 conventional sheet choices.
- Keep horizontal/vertical wall orientation without claiming one is universally required.
- Use a simple rectangular layout estimate rather than a CAD/cut optimizer.
- Keep opening deductions as estimator-entered square footage.
- Opening deductions reduce finishable area but do not automatically create whole-sheet savings.
- Separate `installedSheets` from `purchaseSheets`.
- Keep user-selectable 5%, 10%, 15%, 20%, or custom purchasing allowance.
- Remove the unsupported dynamic seam-count waste heuristic.
- For ceilings, select the lower-sheet-count rectangular orientation as a takeoff optimization only; do not present it as an installation specification.

### Deliberately not implemented

- panel cut maps;
- offcut inventories;
- cross-wall scrap optimization;
- framing-aware CAD placement;
- rated-system layout validation.

Those features would overcomplicate the storefront utility.

## 5. Joint Compound

### Implemented direction

- Keep GA finish Levels 0–5 as finish-specification choices.
- Remove the universal gallons-per-finish-level constants.
- Use small, documented planning profiles derived from manufacturer ready-mix coverage:
  - standard ready-mix: approximately 10 gal / 1,000 sq ft;
  - lightweight ready-mix: approximately 9 gal / 1,000 sq ft.
- Clearly identify these as planning coverage rather than GA/ASTM consumption rules.
- Separate required gallons, purchasing allowance and package rounding.
- Use manufacturer-appropriate package volume rather than assuming every package is 5 gal.

### Level 5

Level 5 is modeled as:

```text
conventional joint finishing
+
separate full-surface skim coat
```

Primary guidance does not define one universal skim thickness or gallons-per-area rate. Therefore the calculator does not invent a universal Level 5 multiplier. A skim material quantity is added only when the estimator has a project/product planning rate to supply.

This is intentionally more conservative than building a large manufacturer product registry.

## 6. Joint Tape

### Implemented direction

- Prefer layout-derived field-joint footage from Sheets.
- If Sheets is not completed, use the manufacturer planning fallback of approximately 370 linear ft per 1,000 sq ft (0.37 lf/sq ft).
- Calculate field joints, vertical inside corners and wall-to-ceiling perimeter separately.
- Remove the unsupported 15% fiberglass-mesh stretch multiplier.
- Keep paper and fiberglass mesh as simple choices.
- Warn that fiberglass mesh must be paired with a compatible setting-type compound where required by the manufacturer.
- Keep user purchasing allowance separate from required footage.

No material-compatibility rules engine is introduced.

## 7. Corner Bead

### Implemented direction

- Straight bead = outside-corner count × height.
- Curved/arch bead uses directly measured linear footage.
- Remove the assumption that every arch is a semicircle or that rise equals radius.
- Keep a simple stock-length purchasing estimate.
- Clearly warn that individual long runs and offcut reuse must be verified before purchase.
- Do not expose one generic attachment spacing rule across metal, vinyl, bullnose and flexible products because manufacturer systems differ.

### Deliberately not implemented

- geometric circular-segment wizard;
- cut-stock/bin-packing optimizer;
- product-specific corner-bead installation registry.

## 8. Drywall Screws

### Implemented direction

The fastener calculator remains a planning utility, not an assembly-design system.

Inputs stay intentionally limited:

- installed sheet count;
- sheet size;
- wall or ceiling application;
- 16-in or 24-in framing spacing;
- package/box quantity;
- purchasing allowance.

Key corrections:

- use installed sheet quantity from Sheets, not waste-adjusted purchase sheets;
- remove the old wall+ceiling arithmetic average;
- remove the old universal edge/field formula;
- use a conventional planning model of 16-in spacing on walls or 12-in spacing on ceilings along framing lines;
- do not claim a universal screw type or length;
- explicitly tell users to verify screw type, length, framing compatibility and specialty/rated assembly requirements.

The calculator intentionally does not ask for UL design, fire rating, acoustic assembly, adhesive system, or proprietary assembly profile.

## 9. UI/UX

Each tab is designed around one purchasing question:

- Sheets: **How many sheets should I buy?**
- Compound: **How much compound should I buy?**
- Tape: **How many rolls of tape do I need?**
- Corner Bead: **How many bead sections do I need?**
- Screws: **How many screws should I buy?**

Each result area prioritizes:

1. primary purchase quantity;
2. required/installed quantity;
3. purchasing allowance or package context;
4. a concise assumptions/scope note.

The redesign avoids dashboard-style over-cardification and advanced configuration walls. Desktop remains fluid and mobile naturally stacks inputs above results.

## 10. Purchasing allowance

The shared allowance selector keeps:

```text
5%
10%
15%
20%
Custom
```

The former labels `simple`, `standard`, `complex`, and `heavy` are removed because they implied an unsupported universal industry classification.

Helper text explicitly identifies the percentage as an optional estimator allowance for cuts, damage, breakage and field conditions.

## 11. Persistence

The existing browser-local persistence model is preserved for compatibility:

```text
dwCalc_state
dwCalc_sheet
dwCalc_mud
dwCalc_tape
dwCalc_bead
dwCalc_screws
dwCalc_presets
```

A new project database, account workspace, server synchronization layer, or WooCommerce customer-project entity is not justified for this utility.

Calculator state remains non-authoritative browser data.

## 12. Summary and PDF

The Summary remains a shopping-oriented material estimate rather than a project-management report.

The canonical report model now displays:

- purchase quantities;
- required/installed quantities where useful;
- user purchasing allowances;
- compound package size;
- Level 5 skim allowance when supplied;
- separated tape geometry;
- required vs purchase fasteners;
- a conventional-planning scope disclaimer.

PDF export remains browser-local through the existing print-preview / Save as PDF workflow.

## 13. Production definition

For this storefront utility, "production ready" means:

- formulas are isolated from JSX and deterministic;
- major researched corrections are implemented;
- unsupported standards claims are removed;
- required and purchase quantities are not conflated;
- estimator allowances are clearly identified;
- downstream calculators do not double-count upstream waste;
- unsupported specialty/rated configurations are scoped clearly;
- local persistence remains compatible;
- report output consumes the same canonical calculator results;
- the Hub remains fast and understandable for ordinary customers and contractors.

It deliberately does **not** mean turning Drywall Toolbox into a full construction-estimating software platform.

## 14. Implemented files

Calculation authority:

```text
frontend/src/lib/calculators/index.js
```

Revised calculator UI/runtime:

```text
frontend/src/components/calculators/SheetCalculator.jsx
frontend/src/components/calculators/MudCalculator.jsx
frontend/src/components/calculators/TapeCalculator.jsx
frontend/src/components/calculators/CornerBeadCalculator.jsx
frontend/src/components/calculators/ScrewCalculator.jsx
frontend/src/components/calculators/shared/WasteSelector.jsx
```

Report contract:

```text
frontend/src/components/calculators/report/calculatorReportModel.js
frontend/src/components/calculators/report/README.md
```

Documentation:

```text
frontend/src/components/calculators/README.md
```

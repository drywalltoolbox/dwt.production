# Drywall Toolbox Calculator Hub

The Calculator Hub is a lightweight storefront utility for conventional drywall material planning. It is intentionally **not** a standalone estimating application, assembly-design system, or code-compliance engine.

## Runtime ownership

- React UI: `frontend/src/components/calculators/`
- Pure calculation utilities: `frontend/src/lib/calculators/index.js`
- Report presentation model: `frontend/src/components/calculators/report/calculatorReportModel.js`

Calculator components collect inputs and render results. Authoritative arithmetic belongs in the pure calculation utilities so it can be regression-tested independently of React. The Summary and PDF report consume calculator outputs and must not recalculate quantities.

## Active calculators

1. Drywall Sheets
2. Joint Compound
3. Joint Tape
4. Corner Bead
5. Drywall Screws
6. Project Summary / PDF

Each calculator remains usable independently. When the Sheets tab has useful upstream data, downstream calculators may synchronize from it:

```text
Sheets
├── net finishable area -> Compound
├── field-joint footage / ceiling perimeter -> Tape
└── installed sheet count / sheet size -> Screws
```

Downstream purchasing calculations must consume **installed/required quantities**, not another calculator's already waste-adjusted purchase quantity.

## Calculation principles

### Required vs purchase quantity

Where applicable the UI distinguishes:

```text
required / installed quantity
        +
user-selected purchasing allowance
        =
purchase quantity
        -> package rounding
```

Purchasing allowances are estimator choices. They are not presented as ASTM or Gypsum Association requirements.

### Sheets

- Supports conventional 4×8, 4×10 and 4×12 sheets.
- Uses a basic rectangular layout estimate for horizontal or vertical wall orientation.
- Opening deductions reduce finishable area but do not automatically create whole-sheet savings.
- Ceiling layout selects the lower-sheet-count rectangular orientation; this is a takeoff optimization, not an installation specification.
- The former seam-count-based "dynamic waste" heuristic has been removed.

### Joint Compound

- GA Levels 0–5 remain finish-specification choices.
- The calculator no longer uses a universal gallons-per-finish-level table.
- Standard ready-mix planning coverage is approximately 10 gal/1,000 sq ft.
- Lightweight ready-mix planning coverage is approximately 9 gal/1,000 sq ft.
- These are manufacturer planning values and actual product coverage varies.
- Level 5 is represented as Level 4 joint treatment plus a separate full-surface skim coat. Because primary guidance does not define one universal skim thickness or gallons-per-area factor, skim material is included only when a project/product planning rate is supplied.

### Joint Tape

- Uses layout-derived field-joint footage when Sheets data is available.
- Manual fallback is approximately 0.37 linear ft per sq ft (370 ft/1,000 sq ft).
- Vertical inside corners and wall-to-ceiling perimeter are calculated separately.
- The former 15% fiberglass-mesh "stretch" multiplier has been removed.
- Fiberglass mesh is accompanied by a compatibility warning for setting-type compound where required by the manufacturer.

### Corner Bead

- Straight outside corners are calculated from count × height.
- Curved/arch bead uses measured linear footage rather than assuming every arch is a semicircle.
- Stock-section output remains a simple purchasing estimate; contractors should verify individual long runs and offcut reuse.
- Attachment rules are deliberately not generalized across metal, vinyl, bullnose and flexible products because manufacturer instructions differ.

### Screws

- Uses **installed sheet count**, not waste-adjusted sheet purchases.
- Supports conventional wall or ceiling planning modes and 16/24-in framing spacing.
- Walls use a conventional 16-in planning spacing along framing lines; ceilings use 12-in planning spacing.
- The former universal edge/field formula and wall+ceiling arithmetic average have been removed.
- Screw type/length is not asserted universally; users must verify panel, framing and assembly requirements.
- Rated, multilayer, specialty and tested/listed assemblies are outside the generic calculator scope.

## Persistence

The Hub currently preserves the existing browser-local storage model for compatibility:

- `dwCalc_state`
- `dwCalc_sheet`
- `dwCalc_mud`
- `dwCalc_tape`
- `dwCalc_bead`
- `dwCalc_screws`
- `dwCalc_presets`

This is intentionally local-only. Calculator state is not an authoritative customer, order, inventory, or project record.

## Report / PDF

`calculatorReportModel.js` is the canonical presentation mapper. The report displays required/purchase distinctions, estimator allowances, synchronization sources, and the Calculator Hub scope disclaimer.

PDF export remains browser-local through the dedicated print preview and browser **Save as PDF** workflow. No calculator data is sent to WordPress or an external PDF service.

## Scope boundary

The Calculator Hub is intended for rapid material planning for ordinary drywall work. It does not replace project drawings, manufacturer instructions, GA/ASTM installation requirements, local code, or tested/listed assembly documentation.

For fire-rated, sound-rated, multilayer, specialty, proprietary or otherwise engineered assemblies, follow the applicable assembly/system specification.

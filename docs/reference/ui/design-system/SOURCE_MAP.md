# Source map and authority notes

This design package was derived from active source on 2026-08-21. It does not replace runtime files.

| Concern | Active authority | Design-package use |
|---|---|---|
| Brand, semantic colors, spacing, type sizes, radii, shadows, motion, z-index | `frontend/src/styles/storefront-tokens.css` | Exact values in `DESIGN.md` and `TOKENS.css` |
| Customer typography | `frontend/src/styles/global-typography.css` | Single Geist Variable family and global hierarchy |
| Font loading | `frontend/src/main.jsx`, `frontend/package.json` | Self-hosted `@fontsource-variable/geist` weight and italic packages |
| Responsive primitives | `frontend/src/styles/responsive-foundation.css` | Containers, stack/cluster/grid/split/sidebar, focus, dynamic viewport |
| Cross-route responsive corrections | `frontend/src/styles/unified-responsive.css` | 360/768/1024 thresholds, gutters, coarse-pointer and reduced-motion behavior |
| Motion | `frontend/src/motion/dtbMotion.js` | Standard/emphasized/exit easing and route/surface transitions |
| Route and shell behavior | `frontend/src/App.jsx` | Screen inventory and checkout minimal-chrome boundary |
| Shared components | `frontend/src/components/ui/`, `frontend/src/components/shared/` | Buttons, accordions, dropdowns, toasts, breadcrumbs, loading, hero patterns |
| Domain components | `frontend/src/components/` and `frontend/src/styles/` feature owners | Product, navigation, account, repair, schematic, cart, and order patterns |
| Official DTB brand mark | `frontend/src/assets/brand/dtb-logo-white.webp`, `dtb-logo-white-mobile.webp` | Primary white mark for the global header; static fallback is `/logos/dtb-logo-white.webp`; shared email chrome uses a pixel-identical PNG export |
| Commerce/payment boundary | Root `AGENTS.md` | Stitch generation guardrails |

## Known documentation drift

The mature DTB design-system skill still describes a two-family Geist/Nunito system in places. Active source has superseded that description: `global-typography.css` explicitly establishes a single-family Geist system, `main.jsx` imports only Geist, and `package.json` contains only `@fontsource-variable/geist`. This package follows active implementation.

`tailwind.config.js` retains older Nunito font-family aliases, but Tailwind v4 and the last-imported global typography authority route customer-facing text to Geist. Do not use the stale Tailwind aliases as visual truth when generating screens.

## Maintenance rule

When active implementation changes, update the relevant section here and refresh `DESIGN.md`/`TOKENS.css`. Do not edit generated bundles or Stitch exports as a substitute for changing the owning frontend source.


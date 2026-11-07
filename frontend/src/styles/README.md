# Frontend stylesheet architecture

## Ownership and location

- `src/styles/` contains shared design tokens, document and responsive foundations, and styles used by multiple routes or shared components.
- Component-only styles live beside their React component. Page-only styles may live in `src/pages/` when that is already the page's established ownership boundary.
- Keep each stylesheet scoped to the component, page, or shared concern it owns. Avoid adding route-specific selectors to global foundations.

## Naming

- Use lowercase kebab case for every stylesheet filename, including component stylesheets.
- Name files for their stable owner and concern, such as `storefront-desktop-navigation.css`, `product-detail-page-desktop.css`, or `account-activity.css`.
- Do not encode review state or implementation history in filenames (`target`, `mockup`, `polish`, `refinements`, `modern`, or version suffixes). Use a feature or breakpoint name only when it is part of the stylesheet's ongoing responsibility.
- Keep component stylesheet names aligned with the component name in lowercase kebab case.

## Cascade and responsive behavior

- `main.jsx` defines the global stylesheet order: utility output, tokens, responsive foundation, shared feature styles, shared motion, shared responsive defaults, then side-sheet behavior.
- Route and component styles are imported by their owning lazy-loaded modules and may load after the global entry CSS. Do not rely on an entry stylesheet being the final cascade authority.
- Prefer one owning rule per selector/property/context. Keep necessary breakpoint changes next to the owning component rules and document unusual cross-file dependencies.
- Scope variants with explicit classes or component roots instead of broad selectors that couple unrelated stylesheets.
- Preserve existing component breakpoint contracts. Consolidate breakpoints only when the owning layouts have the same behavior and verified boundaries.

## Changes

When moving or renaming a stylesheet, update every import and source reference in the same change. Confirm the route/component import path and production build output; visual appearance still requires manual review at the relevant viewport sizes.

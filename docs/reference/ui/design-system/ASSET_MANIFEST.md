# Asset manifest

## Canonical storefront brand asset

- `frontend/src/assets/brand/dtb-logo-white.webp` — canonical desktop mark used by the global storefront header and native checkout.
- `frontend/src/assets/brand/dtb-logo-white-mobile.webp` — matching mobile header variant.
- `frontend/public/logos/email-logo-white.png` — transparent PNG export with pixel-identical artwork for shared email chrome.
- `frontend/public/logos/dtb-logo-white.webp` — static fallback for the global header and checkout.

The SVG files in this reference package are legacy assets; do not use them for new DTB brand compositions.

## Optional visual references already in the repository

Upload only the references relevant to the screen being generated; they are examples, not token authorities.

- `docs/reference/ui/category-page-mockup-desktop.png`
- `docs/reference/ui/category-page-mockup-mobile.png`
- `docs/reference/ui/checkout-targeted-ui-mobile.png`
- `docs/reference/ui/hero-card.PNG`
- `docs/reference/ui/hero-image-auto-tapers-optimized.webp`

For product-led concepts, use the curated category imagery under `docs/reference/ui/hero/` and its `library-manifest.csv`. Preserve source aspect ratios and do not infer product availability or compatibility from an image.

Payment logos under `frontend/public/payment_logos/` are not general design assets. Use them only when backend capability data confirms the corresponding payment method is genuinely available.


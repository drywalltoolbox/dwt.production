# Drywall Toolbox storefront launch film

An 18-second, silent, 16:9 launch promo built around real screenshots of the Drywall Toolbox storefront. The Framer export's sample Ledger invoice screens are not used in the film.

## Deliverables

- `storefront-launch-film.mp4` — 1280 × 720, H.264, 24 fps, 18 seconds, with on-screen copy.
- `storefront-launch-film.srt` — matching caption sidecar.
- `storefront-launch-film-poster.png` — end-card still.
- `render_promo.py` — deterministic renderer using the three real storefront captures in this folder.
- `CAPTURE-NOTES.md` — capture URLs, browser size, and rendering/readiness criteria.

## Story

1. The new standard in drywall tools.
2. Tools, parts, and repairs in one place.
3. Find products by type, brand, or SKU.
4. See the gallery, SKU, price, stock, and product details.
5. Built for the work ahead.
6. Explore DrywallToolbox.com.

## Re-render

Run `python render_promo.py` from this directory. Requirements are listed in `requirements-promo.txt`. On Windows, the renderer uses Segoe UI fonts from `C:/Windows/Fonts`.

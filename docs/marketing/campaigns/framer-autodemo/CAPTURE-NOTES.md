# Storefront capture notes

The screenshots used by the promo were captured from the live Drywall Toolbox React storefront at `https://drywalltoolbox.com/` on November 14, 2026, in Chrome at 1440 × 1000.

- `storefront-home-capture.png` shows the real home hero and storefront navigation. The public signup prompt was dismissed with its “No thanks, I’ll keep browsing” control so it would not obscure the page.
- `storefront-products-overview-capture.png` was reached through the storefront's Shop navigation. Capture waited for the catalog cards to render, their text to appear, all visible product images to finish loading, and the card count to remain stable.
- `storefront-product-quickview-capture.png` shows the real TapeTech 90T Filler Adapter quick view opened from the loaded product listing. It includes the product gallery, brand, SKU, availability, price, and detail sections.

The catalog page rendered 25 product cards. Before the overview capture, all three product images in that viewport had completed loading; card count and image readiness were checked after the interface finished rendering. The capture process used readiness checks rather than a short fixed delay. No cart or account actions were performed.

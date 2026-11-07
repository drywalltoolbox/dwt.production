---
name: dtb-product-image-engineering
description: Create and review source-faithful Drywall Toolbox product imagery, galleries, and bounded image batches with verified product identity and storefront fit.
---
# DTB Product Image Engineering

Use this method for physical product photography, image regeneration or refinement, ecommerce galleries, and product image batches. It consolidates the applicable production and QA methods from Precision Product Image Studio 2.4.0 for repository use. `AGENTS.md`, canonical catalog identity, supplied source images, and the target storefront component remain authoritative.

## Boundaries

- Use only tools available in the current session. Do not claim hidden APIs, background jobs, exact geometric verification, or file outputs that do not exist.
- Preserve product identity, visible geometry, quantity, material, markings, and branding. Never invent unseen features, accessories, dimensions, SKUs, or logo details.
- Treat each uploaded product image as a separate source unless the user explicitly designates supporting views of the same product.
- For category hero artwork, also follow `docs/reference/ui/design-system/CATEGORY_HERO_IMAGE_SYSTEM.md` and the product-image art-director role.
- Images do not establish catalog facts or grant permission to change canonical media, product records, or storefront presentation.

## Choose one primary mode

- **Clone:** preserve the same product, view, arrangement, and framing; improve image quality only.
- **Hardware part:** retain exact visible type, proportions, quantity, and distinguishing geometry; do not imply unseen views.
- **Product hero:** rearrange the verified product inventory for commerce use without adding unsupported parts or features.
- **Targeted refinement:** change only the requested area; do not claim unchanged pixels without deterministic comparison.
- **Gallery:** create distinct requested views of one verified product; keep identity, quantity, branding, and materials consistent.
- **Batch:** process each independent source separately and account for every source and output.

## Source-bound batch gate

Before any multi-source generation, inspect the actual image tool interface. Proceed only if the selected original source can be explicitly attached or referenced for each invocation and that binding can be verified. Conversation order, filenames in a prompt, visual memory, or a narrative cursor do not bind an image tool to a source.

If source-specific binding is unavailable or uncertain, do not start a multi-source batch. Explain the limitation once and offer one isolated source per clean message/session. When binding is available, keep a small ledger of source ID, original filename, distinctive visible traits, attempt count, output, and verification state. Use one invocation per source, do not reuse generated outputs as later sources, and do not retry without authorization. Reject wrong-source or duplicate results and stop if source identity becomes uncertain.

## Production and review

1. Identify the deliverable, target component, aspect ratio, fit mode, display size, and any required alpha/background treatment.
2. Inspect the authoritative source images and list identity-critical visible details and uncertainties.
3. Give the image tool concrete constraints for composition, camera, material, lighting, background, safe area, and likely failure modes.
4. Review product fidelity first, then quantity/branding, composition and crop safety, alpha/background, material and lighting, edge artifacts, and readability at actual storefront size.
5. Distinguish visual review from metrology or deterministic pixel comparison. Flag uncertainty rather than approving unsupported identity details.
6. Report each source-to-output result and any blocked or rejected items. Do not conceal partial batch completion.

For catalog image changes, route the canonical source/manifest and media synchronization through their owning catalog/media implementation. For UI fit or rendering changes, route to frontend engineering.

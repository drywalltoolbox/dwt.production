<div align="center">
<img width="1200" height="475" alt="GHBanner" src="https://ai.google.dev/static/site-assets/images/share-ais-513315318.png" />
</div>

# Run and deploy your AI Studio app

This contains everything you need to run your app locally.

View your app in AI Studio: https://ai.studio/apps/84931f59-ef6a-46ac-99d3-56be96aac756

## Run Locally

**Prerequisites:**  Node.js


1. Install dependencies:
   `npm install`
2. Configure `GEMINI_API_KEY` in the ignored `.env` file. Do not commit the key. The Vite development server reads it and proxies generation requests over loopback, keeping it out of the browser bundle.
3. Run the app:
   `npm run dev`

## Bulk catalog processing

When the imported CSV has a `Published`, `Status`, or `post_status` column, bulk generation targets rows marked published (`1`, `publish`, or `published`) or draft (`-1`, `draft`, or `true`). Rows with other status values remain in the export unchanged and are excluded from bulk generation and individual retry. If the CSV has no recognized status column, the app keeps its legacy behavior and treats every row as eligible.

Bulk generation runs published and draft products directly without an approval prompt. The full imported catalog remains available as context so variation descriptions can use parent product details, while only eligible rows are sent for generation.

Generated catalog fields match the DTB PDP accordions: overview text maps to `Description`, feature records map to `Meta: _dtb_features_json` with content schema version `2`, and specifications map to `Meta: _dtb_specs_json`. Evidence provenance is retained in the app audit and omitted from serialized customer-facing values. Unsupported facts are omitted; automated validation and a constrained repair pass run before generation is marked complete. Sparse source data can therefore produce empty structured arrays rather than fabricated details.

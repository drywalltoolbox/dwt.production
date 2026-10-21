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
2. Set the `GEMINI_API_KEY` in [.env.local](.env.local) to your Gemini API key
3. Run the app:
   `npm run dev`

## Bulk catalog processing

When the imported CSV has a `Published`, `Status`, or `post_status` column, bulk generation targets rows marked published (`1`, `publish`, or `published`) or draft (`-1`, `draft`, or `true`). Rows with other status values remain in the export unchanged and are excluded from bulk generation and individual retry. If the CSV has no recognized status column, the app keeps its legacy behavior and treats every row as eligible.

The bulk action displays the eligible and held row counts and asks for confirmation before making Gemini requests. The full imported catalog remains available as context so variation descriptions can use parent product details, while only eligible rows are sent for generation.

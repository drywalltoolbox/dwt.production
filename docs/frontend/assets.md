# Frontend asset ownership

The storefront has two asset roots with different contracts:

| Directory | Owner and use | Delivery |
| --- | --- | --- |
| `frontend/src/assets/` | Assets imported by React or CSS and owned by the frontend | Webpack fingerprints and emits referenced files under `assets/images/`, `assets/media/`, or `assets/fonts/` |
| `frontend/public/assets/email/` | Stable image/font URLs used by WooCommerce and support email templates | Copied byte-for-byte to the site root so email URLs remain stable |

Keep storefront logos, header/footer artwork, icons, and component media under
`src/assets/` and import them from the consuming module. This gives deployment
content hashes and removes hand-maintained `PUBLIC_URL` asset strings. Do not
put React-only assets in `public/`.

Keep email artwork and fonts in `public/assets/email/`: WordPress email
templates reference those stable root URLs outside the React runtime. Changes
to these files must preserve the paths owned by the active PHP email templates.

`src/assets/media/generated/heroes/` is owned by
`frontend/scripts/generate-responsive-hero-media.cjs`; its generated imports
and preload registry are `responsiveHeroMedia.js` and
`responsiveHeroPreload.json`. Edit the JSON source registry and master media,
not the generated manifests. The generator serializes concurrent runs, writes
assets and manifests atomically, and prunes only generated WebP files after a
complete successful generation.

Before deleting an asset, search both frontend imports and backend/public URL
consumers. Historical filenames in captured diagnostics or old deployment
reports are evidence of past use, not active consumers.

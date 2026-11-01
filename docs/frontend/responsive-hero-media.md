# Responsive hero media

Bundled storefront hero images use one generated responsive-image pipeline.
The policy and source registry live in `frontend/scripts/responsive-hero-media.json`;
`frontend/scripts/generate-responsive-hero-media.cjs` creates the WebP width
variants and `frontend/src/assets/media/responsiveHeroMedia.js` is its generated
Webpack import manifest.

## Delivery policy

- Retain each full-resolution source as the editing/master asset. Generate every
  derivative directly from that source; never derive a variant from another
  variant.
- For width-responsive sets, generate widths at 480, 640, 828, 960, 1280,
  1600, and 1920 pixels where the source is wide enough. Never upscale. Keep
  each source aspect ratio.
- Encode standard derivatives as WebP at quality 92 and effort 6. Homepage
  desktop/mobile sets are single-asset sets and use lossless WebP for both the
  full-size master conversion and browser-delivered image. This keeps each
  art-directed viewport to one optimized hero asset without losing source
  detail.
- Use `<picture>` with width-descriptor `srcSet` values and `sizes="100vw"`.
  Art-directed mobile sources use the shared `(max-width: 47.99rem)` query;
  the desktop source starts at `48rem`.
- Eagerly load above-the-fold/LCP hero media with high fetch priority. Lazy-load
  heroes that are not active or visible. The home page emits responsive preload
  hints using the same candidates and media conditions as its first carousel
  slide.
- Use descriptive `alt` text for meaningful imagery. Use empty alt text and
  decorative hiding for imagery that only supports adjacent hero copy.

## Active bundled sets

The generated manifest supplies the home carousel, repair campaign, toolset
builder, and bundled catalog dropdown hero sets. The home and repair carousel
share their repair image set with the repair landing page. The dropdown asset
registry exposes responsive media descriptors for its renderers. Adding a
bundled hero requires adding its master source and group to the JSON registry,
then running `npm run media:generate` from `frontend/`.

The homepage desktop and mobile PNG masters are maintained in
`frontend/src/assets/media/home/home-hero-desktop.png` and
`frontend/src/assets/media/home/home-hero-mobile.png`. The registry converts
each master directly to one lossless WebP beside it:
`home-hero-desktop.webp` and `home-hero-mobile.webp`. These are the only
homepage hero files delivered to browsers; `<picture>` selects the mobile file
at the shared mobile breakpoint and the desktop file otherwise. The generator
prunes obsolete homepage WebP derivatives from its generated hero folder when
a set changes from width-responsive to single-asset delivery. The desktop art
is 2800 × 1000 px (2.8:1); the desktop hero stage uses that same ratio so
the artwork fills its frame without side cropping or a tall 16:9 viewport.

The generator rejects paths outside the frontend, serializes concurrent
invocations, writes each WebP and generated manifest via an atomic rename, and
updates its fingerprint only after all outputs are complete. This keeps a
cancelled or overlapping run from leaving a manifest that points to partial
files. See [Frontend asset ownership](assets.md) for the `public/` versus
`src/assets/` boundary.

Production and staging builds run generation before Webpack. The generator is
fingerprinted by its policy, source bytes, and Sharp version, so unchanged
assets do not need to be re-encoded on each build.

WordPress-owned catalog/category media remains owned by WordPress and should
use the responsive `srcset` supplied with that media. Product imagery and
non-hero illustrations are outside this bundled hero pipeline.

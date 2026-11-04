# DTB Vector Alphabet Library

Reusable SVG outline libraries generated from the supplied Anton and Saira Condensed font files. The glyphs are actual vector paths, not live SVG text. Each font has its own style set so shapes and spacing remain faithful to the source font.

## Included sets

- `Echelon-Argent-Oblique-Regular`: glyph outlines generated from the user-supplied Mixfont font.
- `Cobalt-Vector-Oblique-Regular`: glyph outlines generated from the user-supplied Mixfont font.
- `Anton-Regular`: Anton Regular.
- `SairaCondensed-Black`: Saira Condensed Black.
- `SairaCondensed-ExtraBold`: Saira Condensed ExtraBold.

Each set contains `alphabet.svg` (reusable symbol sprite), `glyphs/` (one standalone SVG per glyph), `glyphs.json` (code point, outline bounds, side bearing, and advance metrics), and `specimen.svg` (visual comparison sheet). The glyphs cover space, A-Z, a-z, 0-9, and common ASCII punctuation present in the source font.

## Using the paths

Use the glyph symbol ID from `glyphs.json`, set `fill` to a solid color or a gradient defined in the consuming SVG, and position glyphs using their advance widths. Convert font units to display units with `font_size / units_per_em`; add tracking separately. Glyphs share a baseline and line box within each set.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 220" aria-hidden="true">
  <defs><linearGradient id="chrome" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fff"/><stop offset=".5" stop-color="#747b83"/><stop offset=".7" stop-color="#e8ebed"/><stop offset="1" stop-color="#9da4ab"/></linearGradient></defs>
  <!-- Anton A: advance 994; shared line box 2356 font units; scale .1 -->
  <use href="./Anton-Regular/alphabet.svg#anton-regular-upper-A" x="0" y="0" width="99.4" height="235.6" fill="url(#chrome)"/>
</svg>
```

Keep the width-to-height ratio equal to `advance / glyph_line_height`; both dimensions use the same scale factor. Advance the next glyph by `advance * scale + tracking`. Use one semantic HTML heading containing the same words when the SVG is decorative; path artwork itself is not selectable text and screen readers do not read it as copy. The specimen metallic finishes are examples and can be adjusted independently of the paths.

## Source and licensing

The source files were supplied locally. The original SIL Open Font License notices are preserved in `licenses/`. Review those terms before redistributing the outline libraries or incorporating them into a font-like product.

## DTB Racing Refined variants

Each font set now also has a `racing-refined/` directory. It preserves the source glyph folder and adds a custom DTB treatment: forward slant of approximately nine degrees, 1.2% tighter letter advances, modest vector stroke reinforcement, and original shallow chamfer cuts on selected angular terminals. These edits use only Anton/Saira outline geometry; they do not trace or reproduce Speeday glyphs. `kerning.json` supplies a restrained starter pair list. `specimen.svg` previews the treatment in silver and cobalt.

The refined paths retain the original OFL source notices under `licenses/` at the library root. Use the `refined_advance` values and `glyph_line_height` in `glyphs.json` when composing words. Use the provided per-glyph `viewbox_font_units` to place each glyph while preserving its baseline.

### Refined specimen sheets

- Anton Regular: `Anton-Regular/racing-refined/specimen.svg`
- Saira Condensed Black: `SairaCondensed-Black/racing-refined/specimen.svg`
- Saira Condensed ExtraBold: `SairaCondensed-ExtraBold/racing-refined/specimen.svg`

## Echelon tight-cropped glyphs

`Echelon-Argent-Oblique-Regular/specimen.svg` is the primary comparison preview: it places the official logo beside desktop and mobile headline samples using the silver and cobalt material system. The rendered PNG preview is `specimen-preview.png`; the previous glyph-only sheet is retained as `outline-specimen.svg`.

Homepage campaign and repair-page headings use `frontend/src/components/repairs/VectorHeroTitleLine.jsx`. It positions the original outlines from `glyphs.json`, applies the campaign kerning pairs from `kerning.json`, and references the shared SVG sprite. The path layer is decorative inside the real `h1` text and keeps the campaign wording available as selectable semantic text. Silver is used on the first and third lines; the accent line uses the DTB cobalt face anchored to `#2255EE`.

The Echelon standalone SVGs and sprite symbols use the visible outline bounds plus a symmetric 2-unit safety margin. Original outlines are preserved; no stroke expansion or rasterization is applied. SVGs request geometric-precision rendering. Space is intentionally nonvisible and retains its advance width.

Because these crops are now tight, position each visible glyph from its `glyphs.json` `layout_offset_from_pen_svg_units` relative to the line origin, scale the crop width and height by `font_size / units_per_em`, then advance the pen by the original `advance`. The vertical crop offset is relative to the font ascender line origin. This preserves kerning/spacing metrics and baseline alignment while allowing every symbol to have a tight viewBox. Do not size a tight symbol to the full advance width.

## Mixfont-generated glyph sets

The Echelon and Cobalt sets are covered by the Mixfont license supplied by the user, which permits commercial use and modification, including embedding, redistribution, and website use. The license text is included with the Cobalt set. Their source TTF OS/2 tables report `fsType=4` (restricted embedding), which conflicts with the supplied license text; preserve the license alongside these assets and resolve any downstream tooling or compliance concern with the font source if it arises.

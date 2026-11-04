import { useId } from 'react';
import glyphMetrics from '@assets/typography/vector-alphabet/Echelon-Argent-Oblique-Regular/glyphs.json';
import glyphSpriteUrl from '@assets/typography/vector-alphabet/Echelon-Argent-Oblique-Regular/alphabet.svg';
import titleKerning from '@assets/typography/vector-alphabet/Echelon-Argent-Oblique-Regular/kerning.json';

const UNITS_PER_EM = glyphMetrics.units_per_em;
const LINE_HEIGHT = glyphMetrics.glyph_line_height;
const GLYPH_BY_CHARACTER = Object.fromEntries(glyphMetrics.glyphs.map((glyph) => [glyph.char, glyph]));
const KERNING_PAIRS = titleKerning.pairs;

function buildGlyphLayout(text) {
  const items = [];
  let advance = 0;
  let minX = 0;
  let maxX = 0;
  let previousCharacter = '';

  for (const character of text) {
    const glyph = GLYPH_BY_CHARACTER[character];
    if (!glyph) return null;

    advance += KERNING_PAIRS[`${previousCharacter}${character}`] ?? 0;

    if (glyph.tight_ink_bounds_svg_units) {
      const [, , cropWidth, cropHeight] = glyph.crop_viewbox_svg_units;
      const [originX, originY] = glyph.layout_offset_from_pen_svg_units;
      const x = advance + originX;
      const y = originY;

      items.push({
        character,
        symbolId: glyph.symbol_id,
        x,
        y,
        width: cropWidth,
        height: cropHeight,
      });
      minX = Math.min(minX, x);
      maxX = Math.max(maxX, x + cropWidth);
    }

    advance += glyph.advance;
    previousCharacter = character;
  }

  maxX = Math.max(maxX, advance);

  return {
    items,
    viewBoxX: minX,
    width: maxX - minX,
    advance,
  };
}

function GlyphUses({ items, fill, stroke = 'none', strokeWidth = 0, shadowOffset = 0 }) {
  return items.map((glyph, index) => (
    <use
      key={`${glyph.character}-${index}`}
      href={`${glyphSpriteUrl}#${glyph.symbolId}`}
      x={glyph.x}
      y={glyph.y + shadowOffset}
      width={glyph.width}
      height={glyph.height}
      fill={fill}
      stroke={stroke}
      strokeWidth={strokeWidth}
      strokeLinejoin="round"
      strokeLinecap="round"
    />
  ));
}

export default function VectorHeroTitleLine({ text, material = 'silver', className = '' }) {
  const reactId = useId();
  const idPrefix = `echelon-${reactId.replace(/[^a-zA-Z0-9_-]/g, '')}`;
  const layout = buildGlyphLayout(text);

  if (!layout || layout.width <= 0) {
    return <span className={`repair-hero__title-line ${className}`.trim()}>{text}</span>;
  }

  const isCobalt = material === 'cobalt';
  const faceId = `${idPrefix}-face`;
  const rimId = `${idPrefix}-rim`;
  const ratio = layout.width / LINE_HEIGHT;
  const widthInEm = layout.width / UNITS_PER_EM;

  return (
    <span
      className={`repair-hero__title-line repair-hero__title-line--vector ${className}`.trim()}
      style={{
        '--repair-hero-vector-width': `${widthInEm}em`,
        '--repair-hero-vector-ratio': ratio,
      }}
    >
      <span className="repair-hero__title-accessible-text">{text}</span>
      <svg
        className="repair-hero__title-vector"
        viewBox={`${layout.viewBoxX} 0 ${layout.width} ${LINE_HEIGHT}`}
        preserveAspectRatio="none"
        aria-hidden="true"
        focusable="false"
      >
        <defs>
          <linearGradient id={faceId} x1="0" y1="0" x2="0" y2="1">
            {isCobalt ? (
              <>
                <stop stopColor="#2457ed" />
                <stop offset=".5" stopColor="#2255ee" />
                <stop offset="1" stopColor="#2050df" />
              </>
            ) : (
              <>
                <stop stopColor="#e4e6e9" />
                <stop offset=".55" stopColor="#bfc2c8" />
                <stop offset="1" stopColor="#e9eaed" />
              </>
            )}
          </linearGradient>
          <linearGradient id={rimId} x1="0" y1="0" x2="0" y2="1">
            {isCobalt ? (
              <>
                <stop stopColor="#6689f3" />
                <stop offset="1" stopColor="#102b79" />
              </>
            ) : (
              <>
                <stop stopColor="#f7f8fa" />
                <stop offset="1" stopColor="#656a72" />
              </>
            )}
          </linearGradient>
        </defs>
        <GlyphUses items={layout.items} fill="#02040a" stroke="#02040a" strokeWidth={25} shadowOffset={6} />
        <GlyphUses items={layout.items} fill={`url(#${faceId})`} stroke="#05070b" strokeWidth={22} />
        <GlyphUses items={layout.items} fill="none" stroke={`url(#${rimId})`} strokeWidth={5} />
      </svg>
    </span>
  );
}

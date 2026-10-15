/**
 * frontend/src/components/schematics-v2/HotspotLayer.jsx
 *
 * Renders EVERY hotspot occurrence for the active page (never collapsed to
 * one marker per part). Coordinates are normalized percentage-of-page values
 * (0-100, center-anchored x_pct/y_pct/width_pct/height_pct — see
 * Domain/SchematicHotspotDataset.php's dtb_schematic_hotspot_normalize_coordinates())
 * of the diagram's intrinsic width/height. An occurrence with invalid/missing
 * coordinates is hidden — it is never defaulted to the image center.
 *
 * Markup/positioning ported verbatim from the legacy `Schematics.jsx`
 * (docs/_working/frontend-legacy/frontend/src/pages/Schematics.jsx,
 * ~L3944-4032): a plain `<div role="button" tabIndex={0}>` — not a
 * `<button>` — with class `hotspot hotspot-{shape}`, positioned via
 * `top`/`left` percentages plus `transform: translate(-50%, -50%)` so the
 * coordinate is the marker's CENTER (matching x_pct/y_pct's center-anchored
 * semantics), `aria-label`/`title` of `"Hotspot: {name} ({sku})"`, and
 * Enter/Space keyboard activation via onKeyDown (the div-as-button pattern).
 *
 * Resolved occurrences whose authoritative schematic part also has a real
 * storefront product projection receive only the presentation class
 * `hotspot--linked-product`. The class is deliberately derived from existing
 * API state; the frontend does not infer or create schematic/product links.
 */
import { useMemo } from 'react';
import { normalizePartRef } from '../../utils/string.js';
import { isSchematicHotspotGlowEnabled } from '../../utils/featureFlags.js';

// Keep the callout-glow implementation dormant by default. The hotspot's
// interactive geometry and product relationship state remain unchanged.
const showLinkedProductGlow = isSchematicHotspotGlowEnabled();

// The REST API serializes normalized coordinates from post meta, which can
// come back as numeric strings (e.g. "0.42") rather than JS numbers — coerce
// before validating so those occurrences aren't silently dropped.
function toPercent(value) {
  const num = typeof value === 'string' ? Number(value) : value;
  return typeof num === 'number' && Number.isFinite(num) && num >= 0 && num <= 100 ? num : null;
}

// Center-anchored: top/left ARE the marker's center point (x_pct/y_pct's
// documented semantics — see SchematicHotspotDatasetReader.php's
// "canonical center-anchored geometry"). `transform: translate(-50%, -50%)`
// in the element itself re-centers the box on that point, matching legacy.
function toStyle(coordinates) {
  const x = toPercent(coordinates?.x_pct);
  const y = toPercent(coordinates?.y_pct);
  if (x === null || y === null) return null;

  const width = toPercent(coordinates?.width_pct);
  const height = toPercent(coordinates?.height_pct);

  if (width !== null && height !== null && width > 0 && height > 0) {
    return {
      left: `${x}%`,
      top: `${y}%`,
      width: `${width}%`,
      height: `${height}%`,
    };
  }

  // Point hotspot — size/shape is owned by the `.hotspot` CSS rule (fixed
  // circle diameter per breakpoint, matching legacy). Inline styles always
  // win over stylesheet rules regardless of specificity, so width/height
  // must NOT be set here — doing so previously collapsed every point
  // hotspot to a 0x0 hit area (invisible + unclickable).
  return {
    left: `${x}%`,
    top: `${y}%`,
  };
}

export default function HotspotLayer({ hotspotDataset, parts, onSelectPart, activePartRef }) {
  const occurrences = useMemo(() => {
    if (!hotspotDataset?.available || !Array.isArray(hotspotDataset.occurrences)) return [];
    return hotspotDataset.occurrences
      .map((occurrence) => ({ occurrence, style: toStyle(occurrence.coordinates) }))
      .filter((entry) => entry.style !== null);
  }, [hotspotDataset]);

  if (occurrences.length === 0) return null;

  return (
    <div className="dtb-schematic-hotspot-layer" aria-label="Part hotspots">
      {occurrences.map(({ occurrence, style }) => {
        const part = parts?.find(
          (p) => normalizePartRef(p.part_ref) === normalizePartRef(occurrence.part_ref),
        ) || null;
        const label = occurrence.label || part?.title || 'Part';
        const sku = part?.sku || part?.mpn || '';
        const shape = occurrence.shape_type || 'circle';
        const isLinkedProduct = part?.resolution_state === 'resolved' && part?.product != null;

        const activate = (event) => {
          onSelectPart(occurrence.part_ref, event.currentTarget.getBoundingClientRect());
        };

        return (
          <div
            key={occurrence.hotspot_id}
            className={`hotspot hotspot-${shape}${showLinkedProductGlow && isLinkedProduct ? ' hotspot--linked-product' : ''}${activePartRef && normalizePartRef(activePartRef) === normalizePartRef(occurrence.part_ref) ? ' hotspot--selected' : ''}`}
            role="button"
            tabIndex={0}
            style={{
              position: 'absolute',
              top: style.top,
              left: style.left,
              transform: 'translate(-50%, -50%)',
              zIndex: 100,
              pointerEvents: 'auto',
              ...(style.width !== undefined ? { width: style.width, height: style.height } : {}),
            }}
            onClick={activate}
            onKeyDown={(event) => {
              if (event.key !== 'Enter' && event.key !== ' ') return;
              event.preventDefault();
              activate(event);
            }}
            aria-label={`Hotspot: ${label}${sku ? ` (${sku})` : ''}`}
            title={sku ? `${label} (${sku})` : label}
          />
        );
      })}
    </div>
  );
}

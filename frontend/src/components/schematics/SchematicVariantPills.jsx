/**
 * frontend/src/components/schematics/SchematicVariantPills.jsx
 *
 * Size/variant switcher for both supported domain projections: distinct
 * schematic records in one family, and WooCommerce variations that share a
 * single diagram. Both arrive from the public schematics API.
 */
import { useRef } from 'react';

/** Numeric-first sort so "10 in." sorts after "8 in." rather than before it lexically. */
function variantSortValue(label) {
  const match = /-?\d+(\.\d+)?/.exec(label || '');
  return match ? parseFloat(match[0]) : Number.POSITIVE_INFINITY;
}

export function sortVariants(items) {
  return [...items].sort((a, b) => {
    const diff = variantSortValue(a.variant_label) - variantSortValue(b.variant_label);
    if (diff !== 0) return diff;
    return (a.variant_label || '').localeCompare(b.variant_label || '');
  });
}

export default function SchematicVariantPills({ variants, activeVariantId, onSelectVariant }) {
  const pillRefs = useRef(new Map());

  if (!variants || variants.length <= 1) return null;

  function focusPillAt(index) {
    const variant = variants[index];
    if (!variant) return;
    const node = pillRefs.current.get(variant.id);
    node?.focus();
    onSelectVariant(variant.id);
  }

  function handleKeyDown(event, index) {
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        event.preventDefault();
        focusPillAt((index + 1) % variants.length);
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault();
        focusPillAt((index - 1 + variants.length) % variants.length);
        break;
      case 'Home':
        event.preventDefault();
        focusPillAt(0);
        break;
      case 'End':
        event.preventDefault();
        focusPillAt(variants.length - 1);
        break;
      default:
        break;
    }
  }

  return (
    <div className="dtb-schematic-variant-pills" role="tablist" aria-label="Size">
      <span className="dtb-schematic-variant-pills__label" aria-hidden="true">Size</span>
      {variants.map((variant, index) => {
        const selected = String(variant.id) === String(activeVariantId);
        return (
          <button
            key={variant.id}
            ref={(node) => {
              if (node) pillRefs.current.set(variant.id, node);
              else pillRefs.current.delete(variant.id);
            }}
            type="button"
            role="tab"
            aria-selected={selected}
            tabIndex={selected ? 0 : -1}
            className={`dtb-schematic-variant-pill${selected ? ' dtb-schematic-variant-pill--active' : ''}`}
            onClick={() => onSelectVariant(variant.id)}
            onKeyDown={(event) => handleKeyDown(event, index)}
          >
            <span className="dtb-schematic-variant-pill__label">{variant.variant_label}</span>
            {variant.sku && (
              <span className="dtb-schematic-variant-pill__sku">{variant.sku}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/**
 * TechnicalSpecifications.jsx
 *
 * Unified, table-first technical specification renderer.
 *
 * Props:
 *   specs        Array<{ label: string, value?: string, items?: { name, sku? }[] }>
 *   title        string
 *   onItemClick  () => void
 */

import DOMPurify from 'dompurify';
import { Link } from 'react-router-dom';
import { isCustomerFacingSpecificationLabel } from '../../utils/productSpecificationPolicy.js';

const MONO_LABELS = new Set([
  'asin',
  'gtin',
  'sku',
  'upc',
]);

const LEGACY_IDENTIFIER_LABELS = new Set([
  'item number',
  'item no',
  'item #',
  'model',
  'model number',
  'model no',
  'mpn',
  'part number',
  'part no',
  'part #',
]);

const HIDDEN_SPEC_LABELS = new Set([
  'brand',
]);

function normalizeLabel(label = '') {
  return label.toString().trim().toLowerCase();
}

function canonicalDisplayLabel(label = '') {
  const normalized = normalizeLabel(label);
  if (normalized === 'sku') return 'SKU';
  if (normalized === 'schematic diagram') return 'Schematic Diagram';
  return label;
}

function hasRenderableValue(spec) {
  if (!spec) return false;
  if (Array.isArray(spec.items) && spec.items.length > 0) return true;
  if (Array.isArray(spec.value)) return spec.value.some((item) => String(item ?? '').trim() !== '');
  return spec.value !== null && spec.value !== undefined && String(spec.value).trim() !== '';
}

function buildSkuSpecFromLegacyIdentifier(spec) {
  if (!hasRenderableValue(spec)) return null;
  return {
    ...spec,
    label: 'SKU',
  };
}

function normalizeVisibleSpecs(specs = []) {
  const sourceSpecs = Array.isArray(specs)
    ? specs.filter((spec) => isCustomerFacingSpecificationLabel(spec?.label) && hasRenderableValue(spec))
    : [];
  const explicitSku = sourceSpecs.find((spec) => normalizeLabel(spec.label) === 'sku');
  const legacyIdentifier = sourceSpecs.find((spec) => LEGACY_IDENTIFIER_LABELS.has(normalizeLabel(spec.label)));
  const skuSpec = explicitSku
    ? { ...explicitSku, label: 'SKU' }
    : buildSkuSpecFromLegacyIdentifier(legacyIdentifier);

  const visible = [];
  const seenLabels = new Set();

  const pushSpec = (spec) => {
    if (!spec?.label || !hasRenderableValue(spec)) return;
    const normalized = normalizeLabel(spec.label);
    if (!normalized || seenLabels.has(normalized)) return;
    seenLabels.add(normalized);
    visible.push({
      ...spec,
      label: canonicalDisplayLabel(spec.label),
    });
  };

  if (skuSpec) pushSpec(skuSpec);

  sourceSpecs.forEach((spec) => {
    const normalized = normalizeLabel(spec.label);
    if (normalized === 'sku') return;
    if (LEGACY_IDENTIFIER_LABELS.has(normalized)) return;
    if (HIDDEN_SPEC_LABELS.has(normalized)) return;
    pushSpec(spec);
  });

  return visible;
}

function isIncludesLabel(label) {
  return /^(set\s+)?includes?$/i.test((label || '').trim());
}

function isHtmlValue(value) {
  return typeof value === 'string' && /<\/?[a-z][\s\S]*>/i.test(value);
}

function getRowType(label = '') {
  const normalized = normalizeLabel(label);
  if (MONO_LABELS.has(normalized)) return 'code';
  if (normalized.includes('size') || normalized.includes('width') || normalized.includes('length')) return 'dimension';
  if (normalized.includes('type')) return 'type';
  return 'standard';
}

function renderSpecValue(spec, isMono, onItemClick) {
  const { value, href } = spec;

  if (value === null || value === undefined || value === '') {
    return <span className="ts-value__empty">Not listed</span>;
  }

  if (href) {
    return (
      <Link
        to={href}
        onClick={onItemClick}
        className="ts-value__link"
        title={`View ${value}`}
      >
        {value}
      </Link>
    );
  }

  if (Array.isArray(value)) {
    return (
      <span className="ts-value__list">
        {value.map((item, index) => (
          <span key={`${item}-${index}`} className="ts-value__token">
            {item}
          </span>
        ))}
      </span>
    );
  }

  if (isHtmlValue(value)) {
    return (
      <span
        className="ts-value__html"
        dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(value) }}
      />
    );
  }

  return isMono ? <code className="ts-value__code">{value}</code> : <span>{value}</span>;
}

function renderIncludesValue(spec, onItemClick) {
  const items = Array.isArray(spec.items) && spec.items.length > 0
    ? spec.items
    : [{ name: spec.value }].filter((item) => item.name);

  if (items.length === 0) return <span className="ts-value__empty">Not listed</span>;

  return (
    <ul className="ts-includes-list" role="list">
      {items.map((item, index) => (
        <li key={`${item.name}-${item.sku || index}`} className="ts-includes-list__item">
          {item.sku ? (
            <Link
              to={`/products?search=${encodeURIComponent(item.sku)}`}
              onClick={onItemClick}
              className="ts-includes-list__link"
              title={`View ${item.name}`}
            >
              <span className="ts-includes-list__name">{item.name}</span>
              <span className="ts-includes-list__sku">SKU: {item.sku}</span>
            </Link>
          ) : (
            <span className="ts-includes-list__plain">{item.name}</span>
          )}
        </li>
      ))}
    </ul>
  );
}

function SkeletonBlock({ className = '' }) {
  return <span className={`ts-skeleton ${className}`.trim()} aria-hidden="true" />;
}

export default function TechnicalSpecifications({
  specs = [],
  title = 'Technical Specifications',
  onItemClick,
}) {
  const visibleSpecs = normalizeVisibleSpecs(specs);

  if (visibleSpecs.length === 0) {
    return (
      <section className="ts-root" aria-label={title}>
        <div className="ts-table-shell" role="table" aria-label="Product specifications placeholder">
          <div className="ts-table-head" role="row">
            <span role="columnheader">Specification</span>
            <span role="columnheader">Value</span>
          </div>
          {['SKU', 'Size', 'Product Type'].map((label) => (
            <div key={label} className="ts-row" role="row">
              <span className="ts-row__label" role="rowheader">{label}</span>
              <span className="ts-row__value" role="cell"><SkeletonBlock className="ts-skeleton--row" /></span>
            </div>
          ))}
        </div>
      </section>
    );
  }

  return (
    <section className="ts-root" aria-label={title}>
      <div className="ts-table-shell" role="table" aria-label="Product specifications">
        <div className="ts-table-head" role="row">
          <span role="columnheader">Specification</span>
          <span role="columnheader">Value</span>
        </div>
        {visibleSpecs.map((spec, index) => {
          const normalized = normalizeLabel(spec.label);
          const isMono = MONO_LABELS.has(normalized);
          const rowType = isIncludesLabel(spec.label) ? 'includes' : getRowType(spec.label);

          return (
            <div key={`${spec.label}-${index}`} className={`ts-row ts-row--${rowType}`} role="row">
              <span className="ts-row__label" role="rowheader">
                {spec.label}
              </span>
              <span className="ts-row__value" role="cell">
                {isIncludesLabel(spec.label)
                  ? renderIncludesValue(spec, onItemClick)
                  : renderSpecValue(spec, isMono, onItemClick)}
              </span>
            </div>
          );
        })}
      </div>
    </section>
  );
}

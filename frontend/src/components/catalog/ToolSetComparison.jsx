import { useState } from 'react';
import { Link } from 'react-router-dom';
import { getStructuredIncludedItems, isToolSetProduct } from '../../utils/productMerchandising.js';
import warrantyEvidence from '../../../../products/launch/official/other/toolset_warranty_evidence.json';
import '../../styles/tool-set-comparison.css';

const GROUPS = [
  { label: 'Taping tools', pattern: /\btaper\b|\bbanjo\b|\btaping\b/i },
  { label: 'Loading equipment', pattern: /\bpump\b|\bgooseneck\b|\bfiller\b/i },
  { label: 'Flat boxes and sizes', pattern: /\bflat (?:finishing )?box\b|\bfinishing box\b/i },
  { label: 'Handles', pattern: /\bhandle\b|\bextension\b/i },
  { label: 'Corner tools', pattern: /\bcorner\b|\bangle (?:head|box)\b|\bflusher\b/i },
];

function productUrl(product) {
  return product?.slug ? `/products/${product.slug}` : `/product/${product.id}`;
}

function priceLabel(product) {
  const value = Number(product?.price);
  return Number.isFinite(value) && value > 0
    ? new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value)
    : 'See product details';
}

function IncludedItems({ items }) {
  if (!items.length) return <span className="dtb-set-comparison__not-listed">Not listed in set contents</span>;
  return <ul className="dtb-set-comparison__items">{items.map((item, index) => <li key={`${item.sku || item.name}-${index}`}>{item.name}{item.sku && <small>SKU {item.sku}</small>}</li>)}</ul>;
}

function helpUrl(product, inquiry) {
  const params = new URLSearchParams({ inquiry, sku: String(product?.sku || '') });
  return `/contact?${params.toString()}`;
}

function availabilityLabel(product) {
  const status = product?.stock_status || product?.inventory?.stockStatus;
  if (status === 'instock') return 'In stock';
  if (status === 'outofstock') return 'Out of stock';
  if (status === 'onbackorder') return 'On backorder';
  return 'Check product details';
}

function warrantyFor(product) {
  const entry = warrantyEvidence.sets[String(product?.sku || '').trim()];
  return entry?.status === 'source_conflict' ? warrantyEvidence.conflicts.source_conflict : entry;
}

export default function ToolSetComparison({ products = [] }) {
  const sets = products.filter((product) => isToolSetProduct(product) && getStructuredIncludedItems(product).length > 0);
  const [selection, setSelection] = useState([0, 1, 2]);
  if (sets.length < 2) return null;

  const selected = selection.slice(0, Math.min(3, sets.length)).map((index) => sets[index]).filter(Boolean);
  const setSelectionAt = (slot, nextIndex) => setSelection((current) => current.map((value, index) => index === slot ? nextIndex : value));

  return (
    <section className="dtb-set-comparison" aria-labelledby="dtb-set-comparison-title">
      <div className="dtb-set-comparison__intro">
        <p className="dtb-set-comparison__eyebrow">Compare actual sets</p>
        <h2 id="dtb-set-comparison-title">See what each set includes.</h2>
        <p>Choose up to three sets from the current catalog results. Contents come from each product’s listed items. Warranty information comes from linked manufacturer sources reviewed October 20, 2026; confirm coverage for each included tool and accessory fit before ordering.</p>
      </div>
      <div className="dtb-set-comparison__scroll" role="region" aria-label="Tool set comparison" tabIndex={0}>
        <table>
          <thead><tr><th scope="col">Compare</th>{selected.map((product, slot) => (
            <th scope="col" key={slot}>
              <label htmlFor={`dtb-compare-set-${slot}`}>Set {slot + 1}</label>
              <select id={`dtb-compare-set-${slot}`} value={selection[slot]} onChange={(event) => setSelectionAt(slot, Number(event.target.value))}>
                {sets.map((option, index) => <option key={option.sku || option.id} value={index} disabled={selection.some((chosen, chosenSlot) => chosenSlot !== slot && chosen === index)}>{option.name}</option>)}
              </select>
              <Link to={productUrl(product)}>{product.name}</Link>
              {product.sku && <small className="dtb-set-comparison__set-sku">Set SKU {product.sku}</small>}
            </th>
          ))}</tr></thead>
          <tbody>
            <tr><th scope="row">Brand</th>{selected.map((product, index) => <td key={index}>{product.brand || 'See product details'}</td>)}</tr>
            <tr><th scope="row">Current price</th>{selected.map((product, index) => <td key={index}>{priceLabel(product)}</td>)}</tr>
            <tr><th scope="row">Stock status</th>{selected.map((product, index) => <td key={index}>{availabilityLabel(product)}</td>)}</tr>
            <tr><th scope="row">All listed items</th>{selected.map((product, index) => {
              const items = getStructuredIncludedItems(product);
              return <td key={index}><details><summary>{items.length} listed {items.length === 1 ? 'item' : 'items'}</summary><IncludedItems items={items} /></details></td>;
            })}</tr>
            {GROUPS.map(({ label, pattern }) => <tr key={label}><th scope="row">{label}</th>{selected.map((product, index) => <td key={index}><IncludedItems items={getStructuredIncludedItems(product).filter((item) => pattern.test(item.name))} /></td>)}</tr>)}
            <tr><th scope="row">Manufacturer warranty</th>{selected.map((product, index) => {
              const evidence = warrantyFor(product);
              return <td key={index}>
                {evidence ? <>
                  {evidence.summary}
                  {' · '}{evidence.sources.map((url, sourceIndex) => <span key={url}>{sourceIndex > 0 && ' / '}<a href={url} target="_blank" rel="noopener noreferrer">Manufacturer source {sourceIndex + 1}</a></span>)}
                </> : 'No verified manufacturer term available.'}
                {' · '}<Link to={helpUrl(product, 'returns_warranty')}>Ask about this set</Link>
              </td>;
            })}</tr>
            <tr><th scope="row">Parts access</th>{selected.map((product, index) => <td key={index}><Link to="/parts">Browse replacement parts</Link> · <Link to={helpUrl(product, 'parts_compatibility')}>Ask us to identify the exact part</Link><small className="dtb-set-comparison__note">Confirm model and component fit before ordering.</small></td>)}</tr>
            <tr><th scope="row">Support and repair</th>{selected.map((product, index) => <td key={index}><Link to={helpUrl(product, 'product_guidance')}>Get set-specific help</Link> · <Link to="/repairs">Request repair service</Link></td>)}</tr>
          </tbody>
        </table>
      </div>
    </section>
  );
}

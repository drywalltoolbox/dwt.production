import { useState } from 'react';
import { Link } from 'react-router-dom';
import { getStructuredIncludedItems, isToolSetProduct } from '../../utils/productMerchandising.js';
import '../../styles/tool-set-comparison.css';

const GROUPS = [
  { label: 'Taping tools', pattern: /\btaper\b|\bbanjo\b/i },
  { label: 'Loading equipment', pattern: /\bpump\b|\bgooseneck\b|\bfiller\b/i },
  { label: 'Flat boxes and sizes', pattern: /\bflat box\b|\bfinishing box\b/i },
  { label: 'Handles', pattern: /\bhandle\b|\bextension\b/i },
  { label: 'Corner tools', pattern: /\bcorner\b|\bangle head\b|\bflusher\b/i },
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

function includedNames(product, pattern) {
  const matches = getStructuredIncludedItems(product).filter((item) => pattern.test(item.name));
  return matches.length ? matches.map((item) => item.name).join('; ') : 'Not listed in set contents';
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
        <p>Choose up to three sets from the current catalog results. Contents come from each product’s listed items. Ask us about exact warranty terms and accessory fit before ordering.</p>
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
            </th>
          ))}</tr></thead>
          <tbody>
            <tr><th scope="row">Brand</th>{selected.map((product, index) => <td key={index}>{product.brand || 'See product details'}</td>)}</tr>
            <tr><th scope="row">Current price</th>{selected.map((product, index) => <td key={index}>{priceLabel(product)}</td>)}</tr>
            <tr><th scope="row">Stock status</th>{selected.map((product, index) => <td key={index}>{availabilityLabel(product)}</td>)}</tr>
            <tr><th scope="row">Listed items</th>{selected.map((product, index) => <td key={index}>{getStructuredIncludedItems(product).map((item) => item.name).join('; ')}</td>)}</tr>
            {GROUPS.map(({ label, pattern }) => <tr key={label}><th scope="row">{label}</th>{selected.map((product, index) => <td key={index}>{includedNames(product, pattern)}</td>)}</tr>)}
            <tr><th scope="row">Warranty</th>{selected.map((product, index) => <td key={index}>Term not in catalog · <Link to={helpUrl(product, 'Returns & Warranty')}>Ask about this set</Link></td>)}</tr>
            <tr><th scope="row">Parts access</th>{selected.map((product, index) => <td key={index}><Link to="/parts">Browse parts</Link> · <Link to={helpUrl(product, 'Parts Availability')}>Ask about this set</Link></td>)}</tr>
            <tr><th scope="row">Support and repair</th>{selected.map((product, index) => <td key={index}><Link to={helpUrl(product, 'Technical Support')}>Ask for help</Link> · <Link to="/repairs">Repair service</Link></td>)}</tr>
          </tbody>
        </table>
      </div>
    </section>
  );
}

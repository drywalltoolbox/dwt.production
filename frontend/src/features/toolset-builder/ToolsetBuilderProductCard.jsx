import { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, m as Motion, useReducedMotion } from 'framer-motion';
import { dtbTransition, reducedTransition } from '../../motion/dtbMotion.js';
import { Check, ChevronDown, Layers3, PackageCheck, RefreshCw, Trash2 } from 'lucide-react';
import { fetchToolsetVariations, normalizeToolsetSelection } from '../../api/toolsetBuilderApi.js';
import { resolveBrandLogo } from '../../utils/brandLogoAssets.js';
import ProductCardImage from '../../components/product/ProductCardImage.jsx';

function formatCurrency(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return 'Price unavailable';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(number);
}

function productPrice(product) {
  const value = product?.cardProduct?.price ?? product?.price?.value ?? product?.price ?? null;
  return Number.isFinite(Number(value)) ? Number(value) : null;
}

function productImage(product) {
  return product?.media?.image || product?.cardProduct?.image || product?.image || '';
}

function productBrand(product) {
  return product?.brand?.label || product?.brandLabel || '';
}

function productStockStatus(product) {
  return product?.inventory?.stockStatus || product?.cardProduct?.stockStatus || 'instock';
}

function variationLabel(variation) {
  return variation?.variation?.label
    || variation?.variation?.value
    || variation?.variationLabel
    || variation?.sku
    || 'Option';
}

function variationImage(variation, fallback = '') {
  const candidate = variation?.media?.variationImages?.[0]
    || variation?.media?.images?.[0]
    || variation?.images?.[0]
    || variation?.media?.image
    || variation?.image
    || fallback;

  if (typeof candidate === 'string') return candidate;
  return candidate?.src || candidate?.url || fallback;
}

export default function ToolsetBuilderProductCard({
  product,
  selectedItems = [],
  maximum = 1,
  onSelect,
  onRemove,
}) {
  const [variationOpen, setVariationOpen] = useState(false);
  const [variations, setVariations] = useState([]);
  const [variationLoading, setVariationLoading] = useState(false);
  const [variationError, setVariationError] = useState('');
  const [selectedVariationId, setSelectedVariationId] = useState('');
  const variationMenuRef = useRef(null);
  const reduceMotion = useReducedMotion();

  const isVariable = product?.type === 'variable';
  const selectedProductItems = useMemo(
    () => selectedItems.filter((item) => Number(item.productId) === Number(product?.id)),
    [product?.id, selectedItems],
  );
  const isSelected = selectedProductItems.length > 0;
  const atMaximum = selectedItems.length >= Number(maximum || 1);
  const stockStatus = productStockStatus(product);
  const isOutOfStock = stockStatus === 'outofstock';
  const image = productImage(product);
  const price = productPrice(product);
  const brand = productBrand(product);
  const brandLogo = resolveBrandLogo(product?.brand || brand);
  const selectedVariationSku = isVariable
    ? selectedProductItems.find((item) => item?.variationId)?.sku || ''
    : '';
  const activeVariation = variations.find(
    (item) => String(item?.id || item?.variationId) === String(selectedVariationId),
  );
  const displayedSku = isVariable
    ? activeVariation?.sku || selectedVariationSku
    : product?.sku || '';

  useEffect(() => {
    if (!variationOpen) return undefined;

    const closeOnPointerOutside = (event) => {
      if (!variationMenuRef.current?.contains(event.target)) setVariationOpen(false);
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') setVariationOpen(false);
    };

    document.addEventListener('pointerdown', closeOnPointerOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnPointerOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [variationOpen]);

  const requestVariations = async () => {
    if (variationLoading) return;

    setVariationLoading(true);
    setVariationError('');

    try {
      const payload = await fetchToolsetVariations(product?.id);
      setVariations(Array.isArray(payload?.variations) ? payload.variations : []);
    } catch (error) {
      setVariations([]);
      setVariationError(error?.message || 'Could not load options for this product.');
    } finally {
      setVariationLoading(false);
    }
  };

  const toggleVariations = () => {
    const nextOpen = !variationOpen;
    setVariationOpen(nextOpen);
    if (nextOpen && variations.length === 0 && !variationLoading) {
      void requestVariations();
    }
  };

  const chooseVariation = (variationId) => {
    setSelectedVariationId(String(variationId));
    setVariationOpen(false);
  };

  const handleSimpleSelect = () => {
    if (isSelected) {
      selectedProductItems.forEach((item) => onRemove?.(item.key));
      return;
    }
    if (atMaximum || isOutOfStock) return;
    onSelect?.(normalizeToolsetSelection(product));
  };

  const handleVariationSelect = () => {
    const variation = variations.find((item) => String(item?.id || item?.variationId) === String(selectedVariationId));
    if (!variation) return;

    const normalized = normalizeToolsetSelection(product, variation);
    const alreadySelected = selectedItems.some((item) => item.key === normalized.key);
    if (alreadySelected) {
      onRemove?.(normalized.key);
      return;
    }
    if (atMaximum || normalized.stockStatus === 'outofstock') return;
    onSelect?.(normalized);
  };

  const activeVariationSelection = activeVariation
    ? normalizeToolsetSelection(product, activeVariation)
    : null;
  const activeVariationAlreadySelected = activeVariationSelection
    ? selectedItems.some((item) => item.key === activeVariationSelection.key)
    : false;

  return (
    <article className={'dtb-toolset-product-card' + (isSelected ? ' is-selected' : '') + (variationOpen ? ' is-config-open' : '')}>
      <div className="dtb-toolset-product-card__media">
        {image ? (
          <ProductCardImage
            product={product}
            src={image}
            alt={product?.name || 'Tool'}
            className="dtb-toolset-product-card__image"
            padding="0"
            fit="contain"
            preferThumbnail
            eager={false}
          />
        ) : (
          <span className="dtb-toolset-product-card__media-placeholder" aria-hidden="true">
            <PackageCheck size={34} />
          </span>
        )}
        <AnimatePresence initial={false}>
          {isSelected ? (
            <Motion.span
              key="selected"
              className="dtb-toolset-product-card__selected-badge"
              initial={{ opacity: 0, scale: reduceMotion ? 1 : 0.94 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: reduceMotion ? 1 : 0.97 }}
              transition={reduceMotion ? reducedTransition : dtbTransition.fast}
            >
              <Check size={14} aria-hidden="true" />
              Selected
            </Motion.span>
          ) : null}
        </AnimatePresence>
      </div>

      <div className="dtb-toolset-product-card__body">
        <div className="dtb-toolset-product-card__identity">
          {brandLogo ? (
            <span className="dtb-toolset-product-card__brand-logo-wrap">
              <img
                src={brandLogo}
                alt={`${brand || 'Product brand'} logo`}
                className="dtb-toolset-product-card__brand-logo"
                loading="lazy"
                decoding="async"
              />
            </span>
          ) : brand ? (
            <span className="dtb-toolset-product-card__brand">{brand}</span>
          ) : null}
          <h3>{product?.name || 'Unnamed product'}</h3>
          {displayedSku ? <span className="dtb-toolset-product-card__sku">SKU {displayedSku}</span> : null}
        </div>

        <div className="dtb-toolset-product-card__commerce">
          <strong>{formatCurrency(price)}</strong>
        </div>

        {isVariable ? (
          <div ref={variationMenuRef} className="dtb-toolset-product-card__variation">
            <button
              type="button"
              className="dtb-toolset-product-card__options-toggle"
              onClick={toggleVariations}
              aria-expanded={variationOpen}
              aria-controls={'toolset-variation-menu-' + product.id}
            >
              <Layers3 className="dtb-toolset-product-card__options-icon" size={17} aria-hidden="true" />
              <span>
                {activeVariation
                  ? variationLabel(activeVariation) + ' · ' + formatCurrency(activeVariation?.price?.value ?? activeVariation?.price ?? null)
                  : 'Choose configuration'}
              </span>
              <ChevronDown size={17} aria-hidden="true" />
            </button>

            {variationOpen ? (
              <div
                id={'toolset-variation-menu-' + product.id}
                className="dtb-toolset-product-card__variation-menu"
                role="menu"
                aria-label={'Choose configuration for ' + (product?.name || 'product')}
              >
                {variationLoading ? (
                  <div className="dtb-toolset-product-card__variation-status" role="status">
                    <RefreshCw size={16} aria-hidden="true" />
                    Loading options…
                  </div>
                ) : variationError ? (
                  <div className="dtb-toolset-product-card__variation-error" role="alert">
                    <span>{variationError}</span>
                    <button type="button" onClick={() => void requestVariations()}>
                      Retry
                    </button>
                  </div>
                ) : variations.length === 0 ? (
                  <p className="dtb-toolset-product-card__variation-status">No purchasable configurations are available.</p>
                ) : variations.map((variation) => {
                  const id = variation?.id || variation?.variationId;
                  const stock = variation?.inventory?.stockStatus || variation?.stockStatus || 'instock';
                  const priceValue = variation?.price?.value ?? variation?.price ?? null;
                  const selected = String(id) === String(selectedVariationId);
                  const optionImage = variationImage(variation, image);
                  return (
                    <button
                      type="button"
                      key={id}
                      role="menuitemradio"
                      aria-checked={selected}
                      className={selected ? 'is-selected' : ''}
                      disabled={stock === 'outofstock'}
                      onClick={() => chooseVariation(id)}
                    >
                      <span className="dtb-toolset-product-card__variation-option-media" aria-hidden="true">
                        {optionImage ? <img src={optionImage} alt="" loading="lazy" decoding="async" /> : <PackageCheck size={18} />}
                      </span>
                      <span className="dtb-toolset-product-card__variation-option-copy">
                        <strong>{variationLabel(variation)}</strong>
                        {variation?.sku ? <small>SKU {variation.sku}</small> : null}
                      </span>
                      <span className="dtb-toolset-product-card__variation-option-price">
                        {formatCurrency(priceValue)}
                      </span>
                      <span className={'dtb-toolset-product-card__variation-radio' + (selected ? ' is-selected' : '')} aria-hidden="true">
                        <span />
                      </span>
                    </button>
                  );
                })}
              </div>
            ) : null}

            {selectedVariationId ? (
              <button
                type="button"
                className="dtb-toolset-product-card__select"
                disabled={atMaximum && !activeVariationAlreadySelected}
                onClick={handleVariationSelect}
                aria-pressed={activeVariationAlreadySelected}
              >
                {activeVariationAlreadySelected ? 'Remove selection' : 'Select configuration'}
              </button>
            ) : null}
          </div>
        ) : (
          <button
            type="button"
            className="dtb-toolset-product-card__select"
            disabled={(atMaximum && !isSelected) || isOutOfStock}
            onClick={handleSimpleSelect}
            aria-pressed={isSelected}
          >
            {isSelected ? <Trash2 size={16} aria-hidden="true" /> : null}
            <span>{isSelected ? 'Remove selection' : atMaximum ? 'Selection limit reached' : 'Select tool'}</span>
          </button>
        )}
      </div>
    </article>
  );
}

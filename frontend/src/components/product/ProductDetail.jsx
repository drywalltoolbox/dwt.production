import { useMemo, useState, useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { Link } from 'react-router-dom';
import Reviews from './Reviews';
import ProductRating from './ProductRating.jsx';
import { useCart } from '../../context/CartContext';
import ProductImageGallery from './ProductImageGallery';
import ProductVariationRail from './ProductVariationRail';
import ProductDetailHeader from './ProductDetailHeader';
import ProductPurchasePanel from './ProductPurchasePanel';
import ProductInformationAccordion from './ProductInformationAccordion.jsx';
import ProductSpecTable from './ProductSpecTable';
import { getProductSpecifications } from '../../utils/productSpecifications';
import { getProductVariations } from '../../services/api';
import {
  canonicalizeAttributeValue,
  findMatchingVariation,
  getVariationSelectionMap,
  normalizeAttributeKey,
} from '../../utils/variationSelection';
import { setCachedVariations } from '../../utils/variationCache';
import { apiClient } from '../../api/client.js';
import { getBrandLogo } from '../../utils/brandAssets.js';
import { toProductDetailDTO } from '../../utils/catalogDtoAdapters.js';
import { BRAND_TO_SLUG, BRAND_ALIASES } from '../../utils/catalogUrlState.js';
import { getSchematicLinkForProduct } from '../../data/schematicMappings';
import { getWooCheckoutUrl } from '../../utils/checkoutUrl.js';
import { navigateDocument } from '../../utils/documentNavigation.js';
import DOMPurify from 'dompurify';
import { useEditableComponent } from '../../designer/useEditableComponent.js';
// The PDP is loaded only for a product route or an explicit Quick View. Keep
// its substantial visual system out of the storefront entry stylesheet.
import '../../styles/technical-specifications.css';
import '../../styles/product-detail-modern.css';
import '../../styles/product-variation-selector-overlay.css';
import '../../styles/reviews.css';
import '../../styles/product-detail-typography.css';
import '../../styles/product-detail-production.css';
import '../../styles/product-detail-desktop-polish.css';
import '../../styles/product-detail-approved-mockup.css';
import '../../styles/product-detail-description.css';

function buildSeedVariations(initialVariations = [], initialResolvedVariation = null) {
  const seeded = [];
  const seen = new Set();

  const pushVariation = (variation) => {
    if (!variation?.id || seen.has(variation.id)) return;
    seen.add(variation.id);
    seeded.push(variation);
  };

  pushVariation(initialResolvedVariation);
  (Array.isArray(initialVariations) ? initialVariations : []).forEach(pushVariation);

  return seeded;
}

function money(value) {
  const parsed = typeof value === 'number' ? value : parseFloat(value || 0);
  return Number.isFinite(parsed) ? parsed.toFixed(2) : '0.00';
}

function decodeEscapedHtml(value) {
  if (typeof value !== 'string' || !value.includes('&lt;')) return value;
  if (typeof document === 'undefined') return value;

  const textarea = document.createElement('textarea');
  textarea.innerHTML = value;
  return textarea.value;
}

function hasHtmlMarkup(value) {
  return typeof value === 'string' && /<\s*\/?\s*[a-z][^>]*>/i.test(value);
}

function htmlToPlainText(html) {
  if (typeof html !== 'string') return '';
  if (typeof document === 'undefined') {
    return html.replace(/<[^>]*>/g, ' ');
  }
  const container = document.createElement('div');
  container.innerHTML = DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
  return container.textContent || container.innerText || '';
}

function normalizeDescriptionText(value = '') {
  return String(value)
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t\r\n]+/g, ' ')
    .trim();
}

const DESCRIPTION_SECTION_HEADINGS = [
  'Specifications',
  'Increased Reach',
  'Easy Connection',
  'Independent Controls',
  'Improve Your Work Quality',
  'Explore More',
  'Conclusion',
  'Note',
];

function splitSentences(text) {
  return normalizeDescriptionText(text)
    .match(/[^.!?]+[.!?]+(?:\s|$)|[^.!?]+$/g)
    ?.map((sentence) => sentence.trim())
    .filter(Boolean) || [];
}

function splitDescriptionSections(text) {
  let normalized = normalizeDescriptionText(text);
  DESCRIPTION_SECTION_HEADINGS.forEach((heading) => {
    const pattern = new RegExp(`\\s+(${heading})\\s*[-:–]\\s*`, 'gi');
    normalized = normalized.replace(pattern, `\n\n$1\n`);
  });

  const chunks = normalized
    .split(/\n{2,}/)
    .map((chunk) => chunk.trim())
    .filter(Boolean);

  if (chunks.length > 1) return chunks;

  const sentences = splitSentences(normalized);
  if (sentences.length <= 3) return [normalized].filter(Boolean);

  const groups = [sentences.slice(0, 2).join(' ')];
  for (let i = 2; i < sentences.length; i += 2) {
    groups.push(sentences.slice(i, i + 2).join(' '));
  }
  return groups.filter(Boolean);
}

function isIncludesLabel(label) {
  return /^(?:set\s+|kit\s+)?includes?$/i.test(String(label || '').trim());
}

function looksLikeSetContentsParagraph(value) {
  const plain = String(value || '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/[ \t\r\n]+/g, ' ')
    .trim();

  if (!plain || plain.length < 40) return false;

  const commaCount = (plain.match(/,/g) || []).length;
  const slashSkuCount = (plain.match(/\b[A-Z0-9]{2,}(?:\/[A-Z0-9]{2,})+\b/g) || []).length;
  const skuCount = (plain.match(/\b(?:[A-Z]{1,5}\d{1,5}[A-Z0-9-]*|\d+[A-Z]{2,}[A-Z0-9-]*)\b/g) || []).length;
  const quantityMarkers = (plain.match(/\b(?:x\d+|\d+\s*x)\b/gi) || []).length;
  const hasToolTerms = /(taper|box|roller|finisher|pump|spotter|handle|adapter|gooseneck|filler|applicator)/i.test(plain);

  return (
    hasToolTerms &&
    (
      (commaCount >= 4 && (skuCount + slashSkuCount) >= 3) ||
      (commaCount >= 5 && quantityMarkers >= 2)
    )
  );
}

function stripSetIncludesFromDescription(content) {
  if (!content || typeof content !== 'string') return content;

  const cleanedHtml = content
    .replace(
      /<h[1-6][^>]*>\s*(?:set|kit)\s+includes?\s*:?\s*<\/h[1-6]>\s*<(?:ul|ol)[^>]*>\s*(?:<li[^>]*>[\s\S]*?<\/li>\s*)+<\/(?:ul|ol)>\s*/gi,
      ''
    )
    .replace(
      /<(?:p|div|li|strong|b|h[1-6])[^>]*>\s*(?:<[^>]+>\s*)*(?:&bull;|&#8226;|•)?\s*(?:set|kit)\s+includes?\s*:?\s*(?:<\/[^>]+>\s*)*<\/(?:p|div|li|strong|b|h[1-6])>\s*/gi,
      ''
    )
    .replace(/<(?:ul|ol)[^>]*>\s*(?:<li[^>]*>[\s\S]*?<\/li>\s*)+<\/(?:ul|ol)>\s*/gi, (listBlock) => {
      return /(?:set|kit)\s+includes?/i.test(listBlock) ? '' : listBlock;
    })
    .replace(/<p[^>]*>[\s\S]*?<\/p>/gi, (paragraphBlock) => {
      return looksLikeSetContentsParagraph(paragraphBlock) ? '' : paragraphBlock;
    });

  const lines = cleanedHtml
    .replace(/<br\s*\/?>/gi, '\n')
    .split('\n');

  const out = [];
  let skippingIncludes = false;

  const isIncludesHeader = (line) => (
    /^(?:\s|&nbsp;|<[^>]+>|&bull;|&#8226;|•|-|\*)*(?:set|kit)\s+includes?\s*:?\s*$/i
      .test(String(line || '').trim())
  );

  const isIncludesItem = (line) => {
    const plain = String(line || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/&nbsp;/gi, ' ')
      .replace(/[ \t]+/g, ' ')
      .trim();
    if (!plain) return true;
    return (
      /^(?:[-*•]\s*)?(?:\d+\s*x\s+)?[A-Z0-9][A-Z0-9.\- ]{1,20}(?:\s+SKU\b|\s+[A-Za-z].*)?$/i.test(plain)
      || /^sku\s*:/i.test(plain)
    );
  };

  const isSectionHeader = (line) => (
    /^(?:features?|benefits?|overview|description|specifications?|notes?)\s*:?\s*$/i
      .test(String(line || '').replace(/<[^>]*>/g, ' ').trim())
  );

  for (const line of lines) {
    if (!skippingIncludes && isIncludesHeader(line)) {
      skippingIncludes = true;
      continue;
    }

    if (skippingIncludes) {
      if (isSectionHeader(line)) {
        skippingIncludes = false;
        out.push(line);
        continue;
      }

      if (isIncludesItem(line)) {
        continue;
      }

      skippingIncludes = false;
    }

    if (looksLikeSetContentsParagraph(line)) {
      continue;
    }

    out.push(line);
  }

  return out.join('\n').trim();
}


/**
 * Extract only explicitly identified Features sections. Never infer features
 * from general marketing copy or specifications.
 */
function splitProductFeatureSection(source = '') {
  const value = String(source || '');
  if (!value.trim()) return { description: value, features: '' };

  // Structured HTML: take a Features / Key Features heading and its following
  // siblings until the next heading; retain all other content as Description.
  if (hasHtmlMarkup(value) && typeof document !== 'undefined') {
    const root = document.createElement('div');
    root.innerHTML = DOMPurify.sanitize(value, { USE_PROFILES: { html: true } });
    const heading = [...root.querySelectorAll('h2, h3, h4, h5, h6')].find((node) =>
      /^(key\s+|product\s+)?features(?:\s*(?:&|and)\s*benefits)?\s*:?$/i.test(node.textContent.trim())
    );
    if (heading) {
      const extracted = [];
      let sibling = heading.nextSibling;
      while (sibling && !(sibling.nodeType === 1 && /^H[2-6]$/.test(sibling.nodeName))) {
        const next = sibling.nextSibling;
        extracted.push(sibling.cloneNode(true));
        sibling.remove();
        sibling = next;
      }
      if (extracted.some((node) => node.textContent?.trim() || node.nodeType === 1)) {
        heading.remove();
        const featuresRoot = document.createElement('div');
        extracted.forEach((node) => featuresRoot.appendChild(node));
        return { description: root.innerHTML, features: featuresRoot.innerHTML };
      }
    }
  }

  // Plain text catalogs often include an explicit Features: subheading.
  const match = /(?:^|\n)\s*(?:key |product )?features(?: and benefits)?\s*:\s*/i.exec(value);
  if (match) {
    const remaining = value.slice(match.index + match[0].length);
    const nextHeading = /\n\s*(?:specifications?|description|overview|shipping|warranty|compatibility)\s*:/i.exec(remaining);
    const features = (nextHeading ? remaining.slice(0, nextHeading.index) : remaining).trim();
    if (features) {
      return {
        description: (value.slice(0, match.index) + (nextHeading ? remaining.slice(nextHeading.index) : '')).trim(),
        features,
      };
    }
  }

  return { description: value, features: '' };
}

function ProductDescriptionContent({ html, text }) {
  const sourceText = normalizeDescriptionText(text || '');
  const plainHtmlText = hasHtmlMarkup(html) ? normalizeDescriptionText(htmlToPlainText(html)) : '';
  const shouldFormatPlainText = !hasHtmlMarkup(html) || (
    plainHtmlText
    && plainHtmlText.length > 260
    && !/<\s*(ul|ol|li|table|h[1-6]|blockquote)\b/i.test(html)
  );
  const content = shouldFormatPlainText ? (plainHtmlText || sourceText) : '';
  const sections = shouldFormatPlainText ? splitDescriptionSections(content) : [];

  if (!shouldFormatPlainText && hasHtmlMarkup(html)) {
    return (
      <div className="dtb-pdp-description-content dtb-pdp-description-content--rich">
        <div
          className="dtb-pdp-description-card"
          dangerouslySetInnerHTML={{
            __html: DOMPurify.sanitize(html, { USE_PROFILES: { html: true } }),
          }}
        />
      </div>
    );
  }

  if (sections.length === 0) {
    return (
      <div className="dtb-pdp-description-content dtb-pdp-description-content--empty">
        <p>No description available.</p>
      </div>
    );
  }

  return (
    <div className="dtb-pdp-description-content dtb-pdp-description-content--formatted">
      <p className="dtb-pdp-description-kicker">Product Overview</p>
      {sections.map((section, index) => {
        const heading = DESCRIPTION_SECTION_HEADINGS.find((candidate) => (
          new RegExp(`^${candidate}\\b`, 'i').test(section)
        ));
        const body = heading
          ? section.replace(new RegExp(`^${heading}\\b\\s*`, 'i'), '').trim()
          : section;

        return (
          <section key={`${heading || 'section'}-${index}`} className="dtb-pdp-description-card">
            {heading ? <h3>{heading}</h3> : null}
            <p className={index === 0 && !heading ? 'dtb-pdp-description-lead' : ''}>
              {body}
            </p>
          </section>
        );
      })}
    </div>
  );
}

function toFinitePrice(value) {
  if (typeof value === 'number') return Number.isFinite(value) ? value : null;
  if (typeof value === 'string') {
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
  }
  return null;
}

function pickFirstFinitePrice(...candidates) {
  for (const candidate of candidates) {
    const parsed = toFinitePrice(candidate);
    if (parsed != null) return parsed;
  }
  return null;
}

function getCurrentDisplayPrice(source, { preferMin = false } = {}) {
  if (!source) return 0;

  const priceObject = source?.price && typeof source.price === 'object' ? source.price : null;
  const priceValue = pickFirstFinitePrice(
    source?.price,
    source?.price_value,
    priceObject?.value,
  );
  const saleValue = pickFirstFinitePrice(
    source?.sale_price,
    source?.salePrice,
    priceObject?.sale,
  );
  const regularValue = pickFirstFinitePrice(
    source?.regular_price,
    source?.regularPrice,
    priceObject?.regular,
  );
  const minValue = pickFirstFinitePrice(
    source?.min_price,
    source?.price_min,
    source?.minPrice,
    priceObject?.min,
  );

  if (preferMin) {
    return pickFirstFinitePrice(minValue, priceValue, saleValue, regularValue, 0) ?? 0;
  }

  return pickFirstFinitePrice(priceValue, saleValue, regularValue, minValue, 0) ?? 0;
}

function getCompareAtPrice(source) {
  if (!source) return null;
  const priceObject = source?.price && typeof source.price === 'object' ? source.price : null;
  return pickFirstFinitePrice(
    source?.regular_price,
    source?.regularPrice,
    priceObject?.regular,
  );
}

function getVariantStatus(variation) {
  if (!variation) return 'unavailable';
  return variation.stock_status === 'outofstock' ? 'sold-out' : 'available';
}

function buildInitialVariationSelection({ autoSelectDefaultVariation, initialSelectedAttrs, seededVariations }) {
  if (Object.keys(initialSelectedAttrs || {}).length > 0) return initialSelectedAttrs;
  if (!autoSelectDefaultVariation) return {};
  return getVariationSelectionMap(seededVariations.find((v) => v.stock_status !== 'outofstock') || seededVariations[0] || {});
}

function getSelectedVariationLabel(selectedVariation, selectedAttrs, variationAttributes) {
  const selectedValues = variationAttributes
    .map((attr) => selectedAttrs?.[attr.name])
    .filter((value) => value != null && `${value}`.trim() !== '')
    .map((value) => `${value}`.trim());

  if (selectedValues.length > 0) return selectedValues.join(' / ');

  const attrValues = getVariationSelectionMap(selectedVariation);
  const variationValues = Object.values(attrValues)
    .filter((value) => value != null && `${value}`.trim() !== '')
    .map((value) => `${value}`.trim());

  if (variationValues.length > 0) return variationValues.join(' / ');

  return '';
}

function normalizeNameToken(value) {
  return `${value || ''}`
    .trim()
    .toLowerCase()
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/\b(inches|inch|in)\b/g, '')
    .replace(/["']/g, '')
    .replace(/-/g, '.')
    .replace(/[^a-z0-9.]+/g, '')
    .replace(/\.0+$/g, '')
    .trim();
}

function shouldComposeVariationName(parentProduct, selectedVariation, selectedLabel) {
  if (!selectedVariation || !parentProduct?.name || !selectedLabel) return false;

  const rawName = `${selectedVariation.name || ''}`.trim();
  if (!rawName) return true;

  const normalizedRaw = normalizeNameToken(rawName);
  const normalizedLabel = normalizeNameToken(selectedLabel);
  const normalizedParent = normalizeNameToken(parentProduct.name);

  if (normalizedRaw === normalizedLabel) return true;
  if (/^\d+(?:\.\d+)?$/.test(normalizedRaw)) return true;
  if (normalizedParent && !normalizedRaw.includes(normalizedParent)) return true;

  return false;
}

function productImageKey(image) {
  if (!image) return '';
  if (typeof image === 'string') return image.trim().split('?')[0].replace(/\/+$/, '').toLowerCase();
  return String(image.src || image.url || image.full || image.large || '')
    .trim()
    .split('?')[0]
    .replace(/\/+$/, '')
    .toLowerCase();
}

function mergeProductImages(...sets) {
  const out = [];
  const seen = new Set();

  sets.flat().forEach((image) => {
    const key = productImageKey(image);
    if (!key || seen.has(key)) return;
    seen.add(key);
    out.push(image);
  });

  return out;
}

function isVariationImageInherited(selectedVariation) {
  const value = selectedVariation?.variation?.inheritParentImage
    ?? selectedVariation?.inheritParentImage
    ?? selectedVariation?.inherit_parent_image;

  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase());
  }

  return value === true || value === 1;
}

function getParentProductImages(parentProduct) {
  return mergeProductImages(
    Array.isArray(parentProduct?.media?.images) ? parentProduct.media.images : [],
    Array.isArray(parentProduct?.images) ? parentProduct.images : [],
    parentProduct?.media?.image ? [parentProduct.media.image] : [],
    parentProduct?.image ? [parentProduct.image] : []
  );
}

function getEffectiveVariationImages(parentProduct, selectedVariation) {
	if (isVariationImageInherited(selectedVariation)) {
	  return getParentProductImages(parentProduct);
	}

  const explicitGallery = Array.isArray(selectedVariation?.variationGalleryImages)
    ? selectedVariation.variationGalleryImages
    : Array.isArray(selectedVariation?.media?.variationImages)
      ? selectedVariation.media.variationImages
      : null;

  if (explicitGallery && explicitGallery.length > 0) {
    return mergeProductImages(explicitGallery);
  }

  const selectedImages = mergeProductImages(
    Array.isArray(selectedVariation?.images) ? selectedVariation.images : [],
    selectedVariation?.image ? [selectedVariation.image] : []
  );

  if (selectedImages.length > 0) return selectedImages;

  return getParentProductImages(parentProduct);
}

function composeVariableParentProduct(parentProduct, variations = []) {
  if (!parentProduct?.is_variable) return parentProduct;

  const variationImages = (Array.isArray(variations) ? variations : []).flatMap((variation) => [
    ...(Array.isArray(variation?.variationGalleryImages) ? variation.variationGalleryImages : []),
    ...(Array.isArray(variation?.media?.variationImages) ? variation.media.variationImages : []),
    ...(Array.isArray(variation?.media?.images) ? variation.media.images : []),
    ...(Array.isArray(variation?.images) ? variation.images : []),
    variation?.media?.image,
    variation?.image,
  ]);
  const images = mergeProductImages(
    Array.isArray(parentProduct?.media?.images) ? parentProduct.media.images : [],
    Array.isArray(parentProduct?.images) ? parentProduct.images : [],
    parentProduct?.media?.image ? [parentProduct.media.image] : [],
    parentProduct?.image ? [parentProduct.image] : [],
    variationImages,
  );

  if (images.length === 0) return parentProduct;

  return {
    ...parentProduct,
    image: images[0],
    images,
    media: {
      ...(parentProduct.media || {}),
      image: images[0],
      images,
    },
  };
}

function composeEffectiveVariationProduct(parentProduct, selectedVariation, selectedLabel) {
  if (!selectedVariation) return parentProduct;

  const name = shouldComposeVariationName(parentProduct, selectedVariation, selectedLabel)
    ? `${parentProduct.name} - ${selectedLabel}`
    : (selectedVariation.name || parentProduct.name);
  const images = getEffectiveVariationImages(parentProduct, selectedVariation);

  return {
    ...selectedVariation,
    brand: selectedVariation.brand || parentProduct.brand,
    description: selectedVariation.description || parentProduct.description,
    description_full: selectedVariation.description_full || parentProduct.description_full,
    short_description: selectedVariation.short_description || parentProduct.short_description,
    images: images.length > 0 ? images : (parentProduct.images || parentProduct?.media?.images),
    image: images[0] || selectedVariation.image || parentProduct?.media?.image || parentProduct.image,
    variationGalleryImages: selectedVariation.variationGalleryImages || selectedVariation?.media?.variationImages || [],
    name,
  };
}

function getBrandLabel(product, effectiveProduct = null) {
  return (
    product?.brand?.label ||
    effectiveProduct?.brand?.label ||
    product?.brandLabel ||
    effectiveProduct?.brandLabel ||
    product?.brand ||
    effectiveProduct?.brand ||
    ''
  );
}

function ProductReviewsButton({ onClick, className = '', rating = 0, ratingCount = 0 }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`dtb-pdp-header__reviews ${className}`.trim()}
      aria-label="View product reviews"
    >
      <ProductRating rating={rating} ratingCount={ratingCount} />
    </button>
  );
}

export default function ProductDetail({
  product,
  onAddToCart,
  onClose,
  onVariationChange,
  renderExpressCheckout = null,
  initialSelectedAttrs = {},
  initialVariations = [],
  initialResolvedVariation = null,
  disableLegacyDetailFetch = false,
  initialComputedData = null,
  autoSelectDefaultVariation = false,
  variationsHydrating = false,
}) {
  const { addToCart } = useCart();
  const gallery = useEditableComponent('product-detail', 'image-gallery');
  const purchasePanel = useEditableComponent('product-detail', 'purchase-panel');
  const detailTabs = useEditableComponent('product-detail', 'detail-tabs');
  const specTable = useEditableComponent('product-detail', 'spec-table');
  const [quantity, setQuantity] = useState(1);
  const [isWishlisted, setIsWishlisted] = useState(false);
  const [activeTab, setActiveTab] = useState('description');
  const [specificationsRequest, setSpecificationsRequest] = useState(0);
  const [addToCartError, setAddToCartError] = useState('');
  const [addToCartState, setAddToCartState] = useState('idle');
  const [buyNowState, setBuyNowState] = useState('idle');
  const addToCartFeedbackTimerRef = useRef(null);
  const buyNowRedirectTimerRef = useRef(null);
  const seededVariations = buildSeedVariations(initialVariations, initialResolvedVariation);
  const initialVariationSelection = buildInitialVariationSelection({
    autoSelectDefaultVariation,
    initialSelectedAttrs,
    seededVariations,
  });

  const [variations, setVariations] = useState(seededVariations);
  const [variationsLoading, setVariationsLoading] = useState(false);
  const [selectedAttrs, setSelectedAttrs] = useState(initialVariationSelection);
  const [computedData, setComputedData] = useState(initialComputedData);

  useEffect(() => () => {
    if (addToCartFeedbackTimerRef.current) {
      window.clearTimeout(addToCartFeedbackTimerRef.current);
    }
    if (buyNowRedirectTimerRef.current) {
      window.clearTimeout(buyNowRedirectTimerRef.current);
    }
  }, []);

  const hasInitialVariations = Array.isArray(initialVariations) && initialVariations.length > 0;
  const hasSeedVariations = seededVariations.length > 0;

  useEffect(() => {
    if (!product?.is_variable || !product.id) return;

    let mounted = true;
    const currentSeeded = seededVariations;
    const currentInitialAttrs = initialSelectedAttrs;

    Promise.resolve().then(() => {
      if (!mounted) return;
      setComputedData(initialComputedData);
      setVariations(currentSeeded);
      setSelectedAttrs(buildInitialVariationSelection({
        autoSelectDefaultVariation,
        initialSelectedAttrs: currentInitialAttrs,
        seededVariations: currentSeeded,
      }));
      setVariationsLoading(Boolean(product.is_variable && (variationsHydrating || !hasInitialVariations)));
    });

    if (disableLegacyDetailFetch) {
      return () => { mounted = false; };
    }

    const applyVariations = (vars) => {
      if (!mounted || !Array.isArray(vars) || vars.length === 0) return false;
      setVariations(vars);

      if (Object.keys(currentInitialAttrs || {}).length > 0) {
        const matchedVariation = findMatchingVariation(vars, currentInitialAttrs);
        if (matchedVariation) {
          setSelectedAttrs(currentInitialAttrs);
        } else if (autoSelectDefaultVariation) {
          const firstInStock = vars.find((v) => v.stock_status !== 'outofstock') || vars[0];
          if (firstInStock) {
            setSelectedAttrs(getVariationSelectionMap(firstInStock));
          } else {
            setSelectedAttrs({});
          }
        } else {
          setSelectedAttrs(currentInitialAttrs);
        }
      } else if (autoSelectDefaultVariation) {
        const firstInStock = vars.find((v) => v.stock_status !== 'outofstock') || vars[0];
        if (firstInStock) {
          setSelectedAttrs(getVariationSelectionMap(firstInStock));
        } else {
          setSelectedAttrs({});
        }
      } else {
        setSelectedAttrs({});
      }
      return true;
    };

    Promise.resolve()
      .then(async () => {
        if (!mounted) return;

        if (product.slug) {
          try {
            const data = await apiClient(`/wp-json/dtb/v1/catalog/products/${encodeURIComponent(product.slug)}/detail`);
            if (!mounted) return;
            const detail = toProductDetailDTO(data);
            if (detail?.computed) setComputedData(detail.computed);
            const detailVars = Array.isArray(detail?.variations) && detail.variations.length > 0 ? detail.variations : null;
            if (detailVars) {
              setCachedVariations(product.id, detailVars);
              applyVariations(detailVars);
              return;
            }
          } catch {
            if (!mounted) return;
          }
        }

        if (product.slug && !disableLegacyDetailFetch) {
          try {
            const data = await apiClient(`/wp-json/drywall/v1/products/slug/${encodeURIComponent(product.slug)}/detail`);
            if (!mounted) return;
            if (data?.computed) setComputedData(data.computed);
            const detailVars = Array.isArray(data?.variations) && data.variations.length > 0 ? data.variations : null;
            if (detailVars) {
              setCachedVariations(product.id, detailVars);
              applyVariations(detailVars);
              return;
            }
          } catch {
            if (!mounted) return;
          }
        }

        try {
          const vars = await getProductVariations(product.id);
          if (!mounted || !Array.isArray(vars) || vars.length === 0) return;
          setCachedVariations(product.id, vars);
          applyVariations(vars);
        } catch {
          // variations not critical
        }
      })
      .finally(() => {
        if (mounted) setVariationsLoading(false);
      });

    return () => { mounted = false; };
  }, [product?.id, product?.slug, product?.is_variable, hasInitialVariations, hasSeedVariations, variationsHydrating]); // eslint-disable-line react-hooks/exhaustive-deps

  const selectedVariation = useMemo(
    () => (product?.is_variable ? findMatchingVariation(variations, selectedAttrs) : null),
    [product?.is_variable, variations, selectedAttrs],
  );

  useEffect(() => {
    if (typeof onVariationChange !== 'function') return;
    onVariationChange(selectedVariation || null);
  }, [onVariationChange, selectedVariation]);

  const variationAttributes = useMemo(
    () => (Array.isArray(product?.variation_attributes)
      ? product.variation_attributes.filter((attr) =>
        attr?.name
        && attr.name.toLowerCase() !== 'brand'
        && Array.isArray(attr.options)
        && attr.options.length > 0,
      )
      : []),
    [product],
  );

  const variantOptionMeta = useMemo(() => {
    const meta = {};
    const matrix = computedData?.available_option_matrix ?? {};

    variationAttributes.forEach((attr) => {
      const name = attr.name;
      const options = Array.isArray(attr.options) ? attr.options : [];
      const attrMatrix = matrix[name] ?? matrix[normalizeAttributeKey(name)] ?? {};
      const lowerMatrix = Object.entries(attrMatrix).reduce((acc, [key, value]) => {
        acc[String(key).toLowerCase()] = value;
        acc[canonicalizeAttributeValue(key)] = value;
        return acc;
      }, {});

      meta[name] = options.map((option) => {
        const matrixEntry = attrMatrix[option]
          ?? lowerMatrix[String(option).toLowerCase()]
          ?? lowerMatrix[canonicalizeAttributeValue(option)];

        if (matrixEntry) {
          const matchedVariation = variations.find((v) => v.id === matrixEntry.variation_id) || null;
          const stockStatus = matrixEntry.stock_status || matchedVariation?.stock_status || '';
          const status = stockStatus === 'outofstock'
            ? 'sold-out'
            : (!matrixEntry.purchasable ? 'unavailable' : 'available');
          return {
            value: option,
            variation: matchedVariation,
            status,
            price: matchedVariation?.price ?? null,
          };
        }

        const candidateSelection = { ...selectedAttrs, [name]: option };
        const exact = findMatchingVariation(variations, candidateSelection);
        const fallback = exact || variations.find((variation) => {
          const map = getVariationSelectionMap(variation);
          return Object.entries(map).some(([attrName, attrValue]) => (
            normalizeAttributeKey(attrName) === normalizeAttributeKey(name)
              && canonicalizeAttributeValue(attrValue) === canonicalizeAttributeValue(option)
          ));
        });

        return {
          value: option,
          variation: fallback || null,
          status: getVariantStatus(fallback),
          price: fallback?.price ?? null,
        };
      });
    });

    return meta;
  }, [variationAttributes, variations, selectedAttrs, computedData]);

  if (!product) return null;

  const stripSpecsFromHtml = (html) => {
    if (!html || typeof html !== 'string') return html;
    return html
      .replace(/<p[^>]*>\s*<(?:strong|b)[^>]*>Specifications?:?<\/\s*(?:strong|b)>\s*<\/p>\s*/gi, '')
      .replace(/<p[^>]*>(?:\s*\|[^<]*)+<\/p>/gi, '')
      .replace(/<table[^>]*>([\s\S]*?)(?:Specification|Detail|DETAIL|SPECIFICATION)([\s\S]*?)<\/table>/gi, '');
  };

  const selectedVariationLabel = getSelectedVariationLabel(selectedVariation, selectedAttrs, variationAttributes);

  const parentContextProduct = composeVariableParentProduct(product, variations);
  const effectiveProduct = selectedVariation
    ? composeEffectiveVariationProduct(product, selectedVariation, selectedVariationLabel)
    : parentContextProduct;
  const schematicLink = getSchematicLinkForProduct(effectiveProduct, { allowLegacyFallback: false });
  const partsUrl = schematicLink?.url || null;
  const brandLabel = getBrandLabel(product, effectiveProduct);

  const canonicalBrandForParts = BRAND_ALIASES[brandLabel] || brandLabel;
  const brandSlugForParts = BRAND_TO_SLUG[canonicalBrandForParts] || '';
  const browsePartsUrl = partsUrl ||
    (brandSlugForParts ? `/parts?brand=${encodeURIComponent(brandSlugForParts)}` : '/parts');
  const effectiveSku = effectiveProduct.sku || product.sku || '';
  const effectiveStock = effectiveProduct.stock_status || product.stock_status || 'instock';
  const isOutOfStock = effectiveStock === 'outofstock';
  const detailProductUrl = product?.slug
    ? `/products/${product.slug}${selectedVariation?.id ? `/variations/${encodeURIComponent(selectedVariation.id)}` : ''}`
    : '';
  const needsVariation = product.is_variable && variationAttributes.length > 0;

  const hasCompleteSelection = !needsVariation || variationAttributes.every((attr) => {
    const value = selectedAttrs?.[attr.name]
      ?? Object.entries(selectedAttrs || {}).find(
        ([name]) => normalizeAttributeKey(name) === normalizeAttributeKey(attr.name)
      )?.[1];
    return value != null && (typeof value !== 'string' || value.trim() !== '');
  });

  const canAddToCart = !isOutOfStock && (!needsVariation || Boolean(selectedVariation && hasCompleteSelection));

  const handleAddToCart = async () => {
    if (!canAddToCart || addToCartState !== 'idle') return;
    const productToAdd = selectedVariation ? effectiveProduct : product;
    try {
      setAddToCartError('');
      setAddToCartState('adding');
      if (onAddToCart) await onAddToCart(productToAdd, quantity);
      else await addToCart(productToAdd, quantity);
      setAddToCartState('added');

      if (typeof onClose === 'function') {
        addToCartFeedbackTimerRef.current = window.setTimeout(() => {
          onClose();
          addToCartFeedbackTimerRef.current = null;
        }, 940);
      } else {
        addToCartFeedbackTimerRef.current = window.setTimeout(() => {
          setAddToCartState('idle');
          addToCartFeedbackTimerRef.current = null;
        }, 1200);
      }
    } catch (err) {
      setAddToCartState('idle');
      setAddToCartError(
        err?.message ||
        'Unable to add this item to cart. Please check your selection and try again. If this continues, contact support.'
      );
    }
  };

  const handleBuyNow = async () => {
    if (!canAddToCart || buyNowState !== 'idle') return;
    const productToAdd = selectedVariation ? effectiveProduct : product;

    try {
      setAddToCartError('');
      setBuyNowState('pending');
      await addToCart(productToAdd, quantity);
      setBuyNowState('confirmed');
      buyNowRedirectTimerRef.current = window.setTimeout(() => {
        navigateDocument(getWooCheckoutUrl(), { transition: 'checkout' });
      }, 720);
    } catch (err) {
      setBuyNowState('idle');
      setAddToCartError(
        err?.message ||
        'Unable to prepare checkout. Please check your selection and try again.'
      );
    }
  };

  const clearAddToCartError = () => {
    if (addToCartError) setAddToCartError('');
  };

  const rawPrice = selectedVariation
    ? getCurrentDisplayPrice(selectedVariation)
    : getCurrentDisplayPrice(product, { preferMin: Boolean(product.is_variable) });
  const displayPrice = money(rawPrice);
  const pricePrefix = product.is_variable && !selectedVariation ? 'From $' : '$';
  const compareAt = getCompareAtPrice(selectedVariation) ?? getCompareAtPrice(product);
  const baseProductSpecifications = getProductSpecifications(effectiveProduct);
  const productSpecifications = schematicLink
    ? [
        ...baseProductSpecifications.filter((spec) => spec?.label !== 'Schematic Diagram'),
        {
          label: 'Schematic Diagram',
          value: schematicLink.title,
          href: schematicLink.url,
        },
      ]
    : baseProductSpecifications;
  const includesSpec = productSpecifications.find((spec) => isIncludesLabel(spec?.label)) || null;
  const hasIncludesSpec = Boolean(includesSpec);
  const includesItems = Array.isArray(includesSpec?.items) ? includesSpec.items : [];
  const setSummary = hasIncludesSpec
    ? {
        itemCount: includesItems.length,
        items: includesItems,
      }
    : null;
  const rawDescriptionBase = stripSpecsFromHtml(
    effectiveProduct.description_full || effectiveProduct.description || effectiveProduct.short_description || ''
  );
  const rawDescription = hasIncludesSpec
    ? stripSetIncludesFromDescription(rawDescriptionBase)
    : rawDescriptionBase;
  const decodedDescription = decodeEscapedHtml(rawDescription);
  const separatedContent = splitProductFeatureSection(decodedDescription);
  const descriptionNode = hasHtmlMarkup(separatedContent.description)
    ? <ProductDescriptionContent html={separatedContent.description} />
    : <ProductDescriptionContent text={separatedContent.description || 'No description available.'} />;
  // Server-validated structured content takes precedence over legacy HTML
  // feature extraction; both full PDP and Quick View reuse this component.
  const structuredSource = effectiveProduct.structured_content?.structured === true
    ? effectiveProduct.structured_content
    : product.structured_content?.structured === true
      ? product.structured_content
      : null;
  const structuredFeatures = structuredSource ? structuredSource.features : null;
  const featuresNode = separatedContent.features
    ? (hasHtmlMarkup(separatedContent.features)
      ? <ProductDescriptionContent html={separatedContent.features} />
      : <ProductDescriptionContent text={separatedContent.features} />)
    : null;

  const handleViewIncludes = () => {
    if (!hasIncludesSpec || detailTabs.hidden) return;
    setSpecificationsRequest((current) => current + 1);

    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    window.requestAnimationFrame(() => {
      const specsButton = document.querySelector('.dtb-pdp-info-accordion [data-pdp-section="specs"]');
      specsButton?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      specsButton?.focus({ preventScroll: true });
    });
  };

  return (
    <div className={`dtb-pdp${onClose ? ' dtb-pdp--modal' : ''} w-full max-w-6xl mx-auto flex flex-col relative`}>
      {onClose ? (
        <button
          onClick={onClose}
          className="dtb-pdp__close-btn"
          aria-label="Close product detail"
          title="Close"
        >
          <X size={18} strokeWidth={2.5} />
        </button>
      ) : null}

      <div className="overflow-x-hidden">
        <div className="dtb-pdp__inner max-w-full">
          <div className="dtb-pdp__hero grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 md:gap-8 mb-6 sm:mb-8">
            <div {...gallery.rootProps} className="dtb-pdp-gallery">
              <ProductImageGallery product={effectiveProduct} />
            </div>

            <div className="dtb-pdp__info-column flex flex-col">
              <div className="dtb-pdp__summary-region">
                <ProductDetailHeader
                  product={product}
                  productUrl={detailProductUrl}
                  effectiveName={(effectiveProduct.name || product.name)}
                  effectiveSku={effectiveSku}
                  isOutOfStock={isOutOfStock}
                  brandLabel={brandLabel}
                  brandLogoSrc={brandLabel ? getBrandLogo(brandLabel) : null}
                  brandLogoClassName="product-detail-brand-logo"
                  displayPrice={displayPrice}
                  pricePrefix={pricePrefix}
                  compareAt={compareAt}
                  rawPrice={rawPrice}
                  onReviewsClick={() => setActiveTab('reviews')}
                  money={money}
                  reviewsClassName="dtb-pdp-mobile-relocate"
                />

                {needsVariation ? (
                  <ProductVariationRail
                    variationAttributes={variationAttributes}
                    variantOptionMeta={variantOptionMeta}
                    selectedAttrs={selectedAttrs}
                    setSelectedAttrs={(next) => {
                      clearAddToCartError();
                      setSelectedAttrs(next);
                    }}
                    variationsLoading={variationsLoading}
                    selectedVariation={selectedVariation}
                    hasCompleteSelection={hasCompleteSelection}
                  />
                ) : null}
              </div>

              <div
                {...purchasePanel.rootProps}
                className="dtb-pdp__purchase-region"
                style={purchasePanel.getValue('sticky', true) ? { position: 'sticky', top: 'var(--dtb-header-height, 72px)' } : undefined}
              >
                <ProductPurchasePanel
                  expressCheckout={typeof renderExpressCheckout === 'function' ? renderExpressCheckout({
                    productId: product?.id,
                    variationId: selectedVariation?.id,
                    quantity,
                    selectedAttributes: selectedAttrs,
                    canAddToCart,
                  }) : null}
                  quantity={quantity}
                  onDecrease={() => {
                    clearAddToCartError();
                    setQuantity((prev) => Math.max(1, prev - 1));
                  }}
                  onIncrease={() => {
                    clearAddToCartError();
                    setQuantity((prev) => prev + 1);
                  }}
                  onQuantityChange={(val) => {
                    clearAddToCartError();
                    setQuantity(val);
                  }}
                  onAddToCart={handleAddToCart}
                  addToCartState={addToCartState}
                  onBuyNow={handleBuyNow}
                  buyNowState={buyNowState}
                  canBuyNow={canAddToCart}
                  canAddToCart={canAddToCart}
                  isOutOfStock={isOutOfStock}
                  needsVariation={needsVariation}
                  hasCompleteSelection={hasCompleteSelection}
                  isWishlisted={isWishlisted}
                  onToggleWishlist={() => setIsWishlisted((prev) => !prev)}
                  partsUrl={partsUrl}
                  setSummary={!detailTabs.hidden ? setSummary : null}
                  onViewIncludes={handleViewIncludes}
                  reviewNode={<ProductReviewsButton onClick={() => setActiveTab('reviews')} className="dtb-pdp-header__reviews--mobile-inline" rating={effectiveProduct.average_rating ?? effectiveProduct.rating ?? product.average_rating ?? product.rating} ratingCount={effectiveProduct.rating_count ?? effectiveProduct.reviews ?? product.rating_count ?? product.reviews} />}
                />

                {addToCartError ? (
                  <p className="text-sm text-red-600 mt-2" role="alert" aria-live="assertive">{addToCartError}</p>
                ) : null}
              </div>

              {!detailTabs.hidden && (
                <ProductInformationAccordion
                  description={descriptionNode}
                  features={structuredFeatures !== null
                    ? (structuredFeatures.length ? (
                      <ul className="dtb-pdp-structured-features">
                        {structuredFeatures.map((feature, index) => (
                          <li key={`${index}-${feature.label}`}>
                            <strong>{feature.label}:</strong> {feature.description}
                          </li>
                        ))}
                      </ul>
                    ) : null)
                    : featuresNode}
                  specs={!specTable.hidden ? <ProductSpecTable specs={productSpecifications} /> : null}
                  partsUrl={browsePartsUrl}
                  specificationsRequest={specificationsRequest}
                />
              )}

              <div className="dtb-pdp-mobile-post-purchase">
                {partsUrl ? (
                  <Link to={partsUrl} className="dtb-pdp-parts-link dtb-pdp-parts-link--mobile">
                    View associated schematic and part numbers
                  </Link>
                ) : null}
              </div>
            </div>
          </div>

          {getBrandLogo(brandLabel) ? (
            <div className="dtb-pdp-brand-banner">
              <img
                src={getBrandLogo(brandLabel)}
                alt={brandLabel}
                className="dtb-pdp-brand-banner__logo"
              />
            </div>
          ) : null}


          <div className="dtb-pdp-browse-parts-row">
            <Link to={browsePartsUrl} className="dtb-pdp-browse-parts-row__link">
              {partsUrl ? 'View schematic and part numbers' : 'Browse replacement parts'}
            </Link>
          </div>

        </div>
      </div>
    </div>
  );
}

/**
 * frontend/src/pages/ProductDetailPage.jsx
 *
 * Route-level product detail page — /products/:slug
 *
 * Architecture:
 *   - useProductDetail(slug)       → fetches parent + variations + computed
 *   - URL-derived explicit variant → seeds the PDP only when requested
 *   - ProductMediaGallery          → variation-aware gallery
 *   - ProductPrice                 → price with From/sale
 *   - ProductSkuBlock              → SKU / MPN
 *   - ProductAvailabilityNotice    → stock status badge
 *   - ProductPurchasePanel         → variant selector + qty + add-to-cart
 *   - ProductDescriptionAccordion  → description/specs/shipping
 *
 * URL contract:
 *   /products/:slug                — parent context; no variation selected
 *   /products/:slug?variant=12345  — pre-select variation 12345
 *
 * Full-page variation changes are intentionally handled in local component state
 * and mirrored into the address bar with history.replaceState. This preserves a
 * copyable variant URL without notifying React Router, which prevents the route
 * transition shell from remounting the full PDP on each size click.
 */

import { useParams, Link, useLocation, useNavigate } from 'react-router-dom';
import { useCart } from '../context/CartContext';
import useCatalogProductDetail from '../hooks/useCatalogProductDetail.js';
import { getVariationSelectionMap } from '../utils/variationSelection';
import { buildBreadcrumbSchema, buildProductSchema, stripHtml } from '../utils/schema';
import ProductDetail from '../components/product/ProductDetail';
import SEOHead from '../components/shared/SEOHead';
import Breadcrumb from '../components/shared/Breadcrumb.jsx';
import LoadingSpinner from '../components/shared/LoadingSpinner';
import Toast from '../components/ui/Toast';
import StorefrontRail from '../components/storefront/StorefrontRail';
import StorefrontProductTile from '../components/storefront/StorefrontProductTile';
import { useState, useEffect, useCallback, useMemo } from 'react';
import { addRecentlyViewed } from '../utils/recentlyViewed.js';
import { buildVariantSearch, getVariantParam, resolveInitialVariation } from '../utils/variationUrl.js';
// Full-page-only calibration intentionally stays out of the Quick View chunk.
import '../styles/product-detail-full-page-target.css';

function getVariationDisplayName(product, selectedVariation, effectiveVariationName) {
  const variationName = `${selectedVariation?.name || ''}`.trim();
  const parentName = `${product?.name || ''}`.trim();
  if (variationName && variationName.toLowerCase() !== parentName.toLowerCase()) {
    return variationName;
  }
  return [parentName, effectiveVariationName].filter(Boolean).join(' — ');
}

function replaceVariantInAddressBar(location, variationId) {
  if (typeof window === 'undefined' || !window.history?.replaceState) return;

  const currentPath = window.location.pathname || location.pathname;
  const currentSearch = window.location.search || location.search || '';
  const currentHash = window.location.hash || location.hash || '';
  const productPath = currentPath.replace(/\/variations\/[^/]+\/?$/, '');
  const nextSearch = buildVariantSearch(currentSearch, variationId ?? null);
  const nextUrl = `${productPath}${nextSearch}${currentHash}`;
  const currentUrl = `${currentPath}${currentSearch}${currentHash}`;

  if (nextUrl !== currentUrl) {
    window.history.replaceState(window.history.state, '', nextUrl);
  }
}

function getRelatedProductsCopy(context = 'related') {
  if (context === 'compatible_parts') {
    return {
      eyebrow: 'Works with this tool',
      title: 'Compatible parts',
      railLabel: 'Compatible parts',
    };
  }
  if (context === 'compatible_tools') {
    return {
      eyebrow: 'Compatible equipment',
      title: 'Compatible tools',
      railLabel: 'Compatible tools',
    };
  }
  return {
    eyebrow: 'Recommended for this product',
    title: 'Related products',
    railLabel: 'Related products',
  };
}

export default function ProductDetailPage() {
  const { slug, variationId } = useParams();
  const location = useLocation();
  const navigate  = useNavigate();
  const { addToCart } = useCart();
  const legacyPathVariantId = Number.isFinite(parseInt(variationId || '', 10)) ? parseInt(variationId, 10) : null;

  const [toast, setToast] = useState(null);
  const [locallySelectedVariation, setLocallySelectedVariation] = useState(null);

  const { product, variations, relatedProducts, computed, status, error } = useCatalogProductDetail(slug);
  const relatedProductsCopy = getRelatedProductsCopy(computed?.relatedProductsContext);

  const urlVariantId = useMemo(
    () => legacyPathVariantId ?? getVariantParam(location.search),
    [legacyPathVariantId, location.search]
  );

  const resolvedInitialVariation = useMemo(
    () => resolveInitialVariation(urlVariantId, variations),
    [urlVariantId, variations]
  );

  useEffect(() => {
    let cancelled = false;
    window.queueMicrotask(() => {
      if (cancelled) return;
      if (!Array.isArray(variations) || variations.length === 0) {
        setLocallySelectedVariation(null);
        return;
      }

      setLocallySelectedVariation((previous) => {
        if (urlVariantId != null) return resolvedInitialVariation;
        if (previous?.id) {
          const stillValid = variations.find((variation) => variation.id === previous.id);
          if (stillValid) return stillValid;
        }
        return resolvedInitialVariation;
      });
    });
    return () => { cancelled = true; };
  }, [product?.id, resolvedInitialVariation, urlVariantId, variations]);

  const selectedVariation = locallySelectedVariation || resolvedInitialVariation;

  useEffect(() => {
    if (!slug || !variationId) return;
    const targetSlug = product?.slug || slug;
    const params = new URLSearchParams(location.search);
    if (legacyPathVariantId) {
      params.set('variant', String(legacyPathVariantId));
    }
    const qs = params.toString();
    const target = `/products/${encodeURIComponent(targetSlug)}${qs ? `?${qs}` : ''}`;
    const current = `${location.pathname}${location.search}`;
    if (current !== target) {
      navigate(target, { replace: true });
    }
  }, [legacyPathVariantId, location.pathname, location.search, navigate, product?.slug, slug, variationId]);

  useEffect( () => {
    if ( ! product ) return;
    addRecentlyViewed( {
      id:    product.id,
      slug:  product.slug || slug,
      name:  product.name,
      image: product.media?.images?.[0]?.src || product.media?.image?.src || product.images?.[0]?.src || product.image || '',
      price: product.price ? `$${ parseFloat( product.price ).toFixed( 2 ) }` : '',
    } );
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ product?.id ] );

  const handleAddToCart = async (prod, qty = 1) => {
    try {
      await addToCart(prod, qty);
    } catch (err) {
      setToast({ message: err.message || 'Failed to add to cart.', type: 'error' });
      throw err;
    }
  };

  const handleVariationChange = useCallback((variation) => {
    setLocallySelectedVariation(variation || null);
    replaceVariantInAddressBar(location, variation?.id ? Number(variation.id) : null);
  }, [location]);

  if (status === 'loading' || status === 'idle') {
    return (
      <>
        <SEOHead noindex title="Loading product…" />
        <LoadingSpinner fullPage size="lg" label="Loading product" />
      </>
    );
  }

  if (status === 'not_found' || !product) {
    return (
      <div className="min-h-screen dtb-container dtb-container--wide py-16">
        <SEOHead noindex title="Product not found" />
        <div className="text-center">
          <h2 className="text-2xl font-bold mb-4">Product not found</h2>
          <p className="text-gray-600 mb-6">We couldn't find the product you're looking for.</p>
          <div className="flex items-center justify-center gap-3">
            <button onClick={() => navigate(-1)} className="px-4 py-2 bg-gray-200 rounded">Go Back</button>
            <Link to="/products" className="px-4 py-2 bg-primary-600 text-white rounded">Browse Products</Link>
          </div>
        </div>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="min-h-screen dtb-container dtb-container--wide py-16">
        <SEOHead noindex title="Error loading product" />
        <div className="text-center">
          <h2 className="text-2xl font-bold mb-4">Unable to load product</h2>
          <p className="text-gray-600 mb-6">{error || 'Something went wrong.'}</p>
          <button onClick={() => navigate(-1)} className="px-4 py-2 bg-gray-200 rounded">Go Back</button>
        </div>
      </div>
    );
  }

  const effectiveVariationName = selectedVariation
    ? (selectedVariation.variation_attribute_values || [])
        .filter((a) => a.option)
        .map((a) => a.option)
        .join(' / ')
    : '';
  const effectiveProduct = selectedVariation || product;
  const effectiveProductName = selectedVariation
    ? getVariationDisplayName(product, selectedVariation, effectiveVariationName)
    : product.name;
  const productDetailPath = `/products/${product.slug || slug}${selectedVariation?.id ? `?variant=${encodeURIComponent(selectedVariation.id)}` : ''}`;

  const metaMap = {};
  if (Array.isArray(product.meta_data)) {
    product.meta_data.forEach(({ key: k, value: v }) => { metaMap[k] = v; });
  }
  const seoTitle   = selectedVariation
    ? effectiveProductName
    : (metaMap['_dtb_seo_title'] || product.name || '');
  const seoDesc    = metaMap['_dtb_seo_description'] || stripHtml(
    selectedVariation?.short_description || selectedVariation?.description_full || product.short_description || product.description || ''
  );
  const seoCanon   = metaMap['_dtb_seo_canonical'] || '';
  const seoNoindex = !!metaMap['_dtb_seo_noindex'];

  const heroImage = selectedVariation?.images?.[0] || selectedVariation?.image || selectedVariation?.media?.image
    || product.media?.images?.[0] || product.media?.image
    || product.images?.[0]?.src || product.image || '';

  const productSchema    = buildProductSchema({ ...product, ...effectiveProduct, name: effectiveProductName });
  const breadcrumbItems  = [
    { label: 'Home',       path: '/' },
    { label: 'Products',   path: '/products' },
    { label: effectiveProductName, path: productDetailPath },
  ];
  const breadcrumbSchema = buildBreadcrumbSchema(breadcrumbItems);

  return (
    <div className="min-h-screen page-wrapper">
      <SEOHead
        title={seoTitle}
        description={seoDesc}
        canonical={seoCanon}
        noSuffix={!!metaMap['_dtb_seo_title'] && !selectedVariation}
        noindex={seoNoindex}
        og={{ type: 'product', image: heroImage, imageAlt: effectiveProductName }}
        schema={[productSchema, breadcrumbSchema].filter(Boolean)}
        links={heroImage ? [{ rel: 'preload', href: heroImage, as: 'image' }] : []}
      />

      <div className="dtb-product-page-shell dtb-container dtb-container--wide py-6 sm:py-8">
        <Breadcrumb items={breadcrumbItems} />
        <ProductDetail
          product={product}
          onAddToCart={handleAddToCart}
          onVariationChange={handleVariationChange}
          initialVariations={variations}
          initialResolvedVariation={selectedVariation}
          initialSelectedAttrs={selectedVariation
            ? getVariationSelectionMap(
                variations.find((variation) => Number(variation?.id) === Number(selectedVariation?.id))
                  || selectedVariation
              )
            : {}}
          initialComputedData={computed}
          disableLegacyDetailFetch
          autoSelectDefaultVariation={false}
        />

        {relatedProducts.length > 0 && (
          <section className="product-related" aria-labelledby="product-related-title">
            <div className="product-related__heading">
              <div>
                <span className="product-related__eyebrow">{relatedProductsCopy.eyebrow}</span>
                <h2 id="product-related-title">{relatedProductsCopy.title}</h2>
              </div>
              <Link to="/products" className="product-related__browse">Browse all products</Link>
            </div>
            <StorefrontRail label={relatedProductsCopy.railLabel} className="storefront-rail--fixed-tiles">
              {relatedProducts.map((relatedProduct, index) => (
                <StorefrontProductTile
                  key={relatedProduct.id}
                  product={relatedProduct}
                  cardProduct={relatedProduct.cardProduct || relatedProduct}
                  variant="grid"
                  index={index}
                  onOpenModal={() => navigate(`/products/${relatedProduct.slug}`)}
                  onAddToCart={() => handleAddToCart(relatedProduct.cardProduct || relatedProduct, 1)}
                />
              ))}
            </StorefrontRail>
          </section>
        )}
      </div>

      {toast && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}
    </div>
  );
}

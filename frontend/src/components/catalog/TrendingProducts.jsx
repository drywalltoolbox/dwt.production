import { lazy, Suspense, useState, useEffect, useCallback, useRef } from 'react';
import { fetchCatalogProducts } from '../../services/catalogPlatformCache.js';
import { toLegacyProductCardDTO } from '../../utils/catalogDtoAdapters.js';
import { useCart } from '../../context/CartContext';
import LoadingCardTransition from '../shared/LoadingCardTransition.jsx';
import StorefrontProductTile from '../storefront/StorefrontProductTile';
import StorefrontSection from '../storefront/StorefrontSection';
import StorefrontRail from '../storefront/StorefrontRail';
import StorefrontSkeletons from '../storefront/StorefrontSkeletons.jsx';
import ProductRelatedProducts from '../product/ProductRelatedProducts.jsx';
import RecentlyViewedProducts from '../product/RecentlyViewedProducts.jsx';
import { useCatalogProductDetail } from '../../hooks/useCatalogProductDetail.js';
import Toast from '../ui/Toast';

const FEATURED_BRANDS = [
  { label: 'Columbia Tools', key: 'columbia' },
  { label: 'Platinum Drywall Tools', key: 'platinum' },
  { label: 'TapeTech', key: 'tapetech' },
  { label: 'LEVEL5', key: 'level5' },
];
const FEATURED_CATEGORIES = ['automatic-tapers', 'toolsets'];

function featuredCategory(product) {
  const category = String(product?.display_category || '').replaceAll('_', '-').toLowerCase();
  if (category.includes('taper')) return 'automatic-tapers';
  if (category.includes('toolset') || category.includes('tool-set')) return 'toolsets';
  return '';
}

function selectFeaturedProducts(productsByBrand, limit = 10) {
  const selected = [];
  const selectedKeys = new Set();
  const categoryCounts = Object.fromEntries(FEATURED_CATEGORIES.map((category) => [category, 0]));

  const add = (product, category) => {
    const key = String(product?.sku || product?.id || product?.slug || '');
    if (!key || selectedKeys.has(key) || selected.length >= limit) return false;
    selected.push(product);
    selectedKeys.add(key);
    categoryCounts[category] += 1;
    return true;
  };

  // First guarantee one product from every requested brand. When a brand has
  // both categories, prefer the category currently underrepresented in the rail.
  FEATURED_BRANDS.forEach(({ key }) => {
    const candidates = productsByBrand[key] || [];
    const category = FEATURED_CATEGORIES
      .filter((value) => candidates.some((product) => featuredCategory(product) === value))
      .sort((a, b) => categoryCounts[a] - categoryCounts[b])[0];
    if (category) {
      add(candidates.find((product) => featuredCategory(product) === category), category);
    }
  });

  // Fill remaining positions from each brand's sales-ranked category results,
  // keeping automatic tapers and tool sets balanced where live data permits.
  while (selected.length < limit) {
    let added = false;
    const categories = [...FEATURED_CATEGORIES].sort((a, b) => categoryCounts[a] - categoryCounts[b]);
    for (const category of categories) {
      for (const { key } of FEATURED_BRANDS) {
        const next = (productsByBrand[key] || []).find(
          (product) => featuredCategory(product) === category && !selectedKeys.has(String(product?.sku || product?.id || product?.slug || ''))
        );
        if (next && add(next, category)) {
          added = true;
          break;
        }
      }
      if (added) break;
    }
    if (!added) break;
  }

  return selected;
}

// Product detail and its gallery/variation dependencies are only needed after
// a shopper explicitly opens Quick View. Keeping them out of the home-route
// chunk prevents a modal-only feature from delaying first render and LCP.
const ProductDetail = lazy(() => import('../product/ProductDetail'));
const ProductModal = lazy(() => import('../product/ProductModal'));

function ProductQuickViewRecommendations({ product, onAddToCart, onOpenProduct }) {
  const slug = product?.slug || product?.post_name || '';
  const { relatedProducts, computed, status } = useCatalogProductDetail(slug);

  return (
    <ProductRelatedProducts
      products={relatedProducts}
      context={computed?.relatedProductsContext}
      className="product-related--pdp"
      onAddToCart={onAddToCart}
      onOpenProduct={onOpenProduct}
      id="featured-quick-view-related"
      status={status}
      showStatus
    />
  );
}

export default function TrendingProducts() {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [shouldLoad, setShouldLoad] = useState(false);
  const [toast, setToast] = useState(null);
  const [modalProduct, setModalProduct] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const { addToCart } = useCart();
  const railRef = useRef(null);
  const modalClearTimerRef = useRef(null);

  // The rail begins below the mobile fold. Fetching the entire catalog before
  // the shopper reaches it creates parse/evaluation work and prompts the
  // browser to start decoding card media during LCP. Reserve the skeleton
  // geometry, then begin the real request when the section enters view.
  useEffect(() => {
    const target = railRef.current;
    if (!target || typeof IntersectionObserver === 'undefined') {
      setShouldLoad(true);
      return undefined;
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry?.isIntersecting) return;
      setShouldLoad(true);
      observer.disconnect();
    });
    observer.observe(target);
    return () => observer.disconnect();
  }, []);

  const showToast = (message, type = 'cart') => {
    setToast({ message, type });
  };

  const openModal = useCallback((product) => {
    if (modalClearTimerRef.current) {
      window.clearTimeout(modalClearTimerRef.current);
      modalClearTimerRef.current = null;
    }
    setModalProduct({ product });
    setIsModalOpen(true);
  }, []);

  const closeModal = useCallback(() => {
    setIsModalOpen(false);
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
    modalClearTimerRef.current = window.setTimeout(() => {
      setModalProduct(null);
      modalClearTimerRef.current = null;
    }, 220);
  }, []);

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') closeModal(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [closeModal]);

  const handleAddToCart = useCallback(async (product, quantity = 1) => {
    try {
      await addToCart(product, quantity);
    } catch (error) {
      showToast(error?.message || 'Could not add item to cart. Please try again.', 'error');
      throw error;
    }
  }, [addToCart]);

  useEffect(() => () => {
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
  }, []);

  useEffect(() => {
    if (!shouldLoad) return undefined;
    let mounted = true;

    Promise.allSettled(FEATURED_BRANDS.map(({ label }) => fetchCatalogProducts({
      brands: [label],
      displayCategory: FEATURED_CATEGORIES,
      perPage: 24,
      sort: 'popular',
      isParts: false,
    }))).then((results) => {
      if (!mounted) return;
      const productsByBrand = Object.fromEntries(results.map((result, index) => {
        const brandKey = FEATURED_BRANDS[index].key;
        const items = result.status === 'fulfilled' && Array.isArray(result.value?.items)
          ? result.value.items
          : [];
        return [brandKey, items
          .map(toLegacyProductCardDTO)
          .filter((product) => {
            if (!product || product.is_parts || product.category === 'parts' || !featuredCategory(product)) return false;
            return (Number(product.price) || Number(product.min_price) || Number(product.regular_price) || 0) > 0;
          })];
      }));
      setProducts(selectFeaturedProducts(productsByBrand));
      setLoading(false);
    }).catch((err) => {
      console.error('Error fetching trending products:', err);
      if (mounted) setLoading(false);
    });

    return () => { mounted = false; };
  }, [shouldLoad]);

  if (!loading && products.length === 0) return null;

  return (
    <StorefrontSection
      className="dtb-home-product-section"
      revealHeading
      title="Featured Products"
      subtitle="Featured automatic tapers and tool sets from trusted brands."
      viewAllHref="/products?sort=popular"
    >
      <div ref={railRef}>
        <LoadingCardTransition
          loading={loading}
          skeleton={<StorefrontSkeletons count={4} variant="rail" />}
          label="Loading trending products"
        >
          <StorefrontRail label="Trending products" className="storefront-rail--fixed-tiles storefront-rail--equal-height">
            {products.map((product, index) => {
              const cardProduct = product.cardProduct || product;

              return (
                <StorefrontProductTile
                  key={product.sku || product.id}
                  product={product}
                  cardProduct={cardProduct}
                  variant="rail"
                  onOpenModal={() => openModal(product)}
                  onAddToCart={(quantity) => handleAddToCart(cardProduct, quantity)}
                  index={index}
                />
              );
            })}
          </StorefrontRail>
        </LoadingCardTransition>
      </div>

      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}

      {modalProduct ? (
        <Suspense fallback={null}>
          <ProductModal isOpen={isModalOpen} product={modalProduct.product || modalProduct} onClose={closeModal}>
            <ProductDetail
              key={`${modalProduct.product?.id || modalProduct.id}:${modalProduct.initialResolvedVariation?.id || 'parent'}`}
              product={modalProduct.product || modalProduct}
              onAddToCart={handleAddToCart}
              onClose={closeModal}
              initialVariations={[]}
              initialResolvedVariation={modalProduct.initialResolvedVariation}
              initialSelectedAttrs={modalProduct.initialSelectedAttrs}
            />
            <ProductQuickViewRecommendations
              product={modalProduct.product || modalProduct}
              onAddToCart={handleAddToCart}
              onOpenProduct={(relatedProduct) => openModal(relatedProduct)}
            />
            <RecentlyViewedProducts
              product={modalProduct.product || modalProduct}
              onOpenProduct={(recentProduct) => openModal(recentProduct)}
            />
          </ProductModal>
        </Suspense>
      ) : null}
    </StorefrontSection>
  );
}

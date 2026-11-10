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
import { useCatalogProductDetail } from '../../hooks/useCatalogProductDetail.js';
import Toast from '../ui/Toast';

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

    fetchCatalogProducts({ perPage: 6, sort: 'popular', isParts: false }).then((payload) => {
      if (!mounted) return;
      const items = Array.isArray(payload?.items) ? payload.items : [];
      setProducts(items
        .map(toLegacyProductCardDTO)
        .filter((product) => {
          if (!product || product.is_parts || product.category === 'parts') return false;
          return (Number(product.price) || Number(product.min_price) || Number(product.regular_price) || 0) > 0;
        })
        .slice(0, 6));
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
      subtitle="Popular tools trusted by drywall professionals."
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
          </ProductModal>
        </Suspense>
      ) : null}
    </StorefrontSection>
  );
}

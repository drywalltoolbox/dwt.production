import { lazy, Suspense, useState, useEffect, useCallback, useRef } from 'react';
import { fetchCatalogProducts } from '../../services/catalogPlatformCache.js';
import { toLegacyProductCardDTO } from '../../utils/catalogDtoAdapters.js';
import { useCart } from '../../context/CartContext';
import Toast from '../ui/Toast';
import LoadingCardTransition from '../shared/LoadingCardTransition.jsx';
import StorefrontRail from './StorefrontRail';
import StorefrontProductTile from './StorefrontProductTile';
import StorefrontSkeletons from './StorefrontSkeletons';
import { useCatalogProductDetail } from '../../hooks/useCatalogProductDetail.js';
import ProductRelatedProducts from '../product/ProductRelatedProducts.jsx';

// Do not ship quick-view's PDP implementation until the user opens it.
const ProductDetail = lazy(() => import('../product/ProductDetail'));
const ProductModal = lazy(() => import('../product/ProductModal'));

function ProductQuickViewRecommendations({ product, onAddToCart, onOpenProduct }) {
  const slug = product?.slug || product?.post_name || '';
  const { relatedProducts, computed } = useCatalogProductDetail(slug);

  return (
    <ProductRelatedProducts
      products={relatedProducts}
      context={computed?.relatedProductsContext}
      onAddToCart={onAddToCart}
      onOpenProduct={onOpenProduct}
      id="quick-view-related"
    />
  );
}

/**
 * A horizontal product rail that fetches products from the catalog API.
 *
 * @param {{ category?: string, brand?: string, sort?: string, maxItems?: number, label?: string }} props
 */
export default function StorefrontProductRail({
  category,
  brand,
  sort,
  maxItems = 12,
  label = 'Products',
}) {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [shouldLoad, setShouldLoad] = useState(false);
  const [toast, setToast] = useState(null);
  const [modalProduct, setModalProduct] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const { addToCart } = useCart();
  const railRef = useRef(null);
  const modalClearTimerRef = useRef(null);

  useEffect(() => {
    const target = railRef.current;
    if (!target || typeof IntersectionObserver === 'undefined') {
      setShouldLoad(true);
      return undefined;
    }
    // Begin fetching before the rail reaches the viewport so mobile swipes
    // do not wait for network work that could have started during approach.
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry?.isIntersecting) return;
      setShouldLoad(true);
      observer.disconnect();
    }, { rootMargin: '280px 0px' });
    observer.observe(target);
    return () => observer.disconnect();
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

  useEffect(() => () => {
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
  }, []);

  useEffect(() => {
    if (!shouldLoad) return undefined;
    let mounted = true;

    const query = {
      perPage: maxItems,
      sort: sort || 'popular',
      ...(brand ? { brands: [brand] } : {}),
      ...(category ? { displayCategory: [category] } : {}),
    };

    fetchCatalogProducts(query).then((payload) => {
      if (!mounted) return;
      const items = Array.isArray(payload?.items) ? payload.items : [];
      setProducts(items.map(toLegacyProductCardDTO).filter(Boolean).slice(0, maxItems));
      setLoading(false);
    }).catch((err) => {
      console.error('StorefrontProductRail fetch error:', err);
      if (mounted) setLoading(false);
    });

    return () => { mounted = false; };
  }, [category, brand, sort, maxItems, shouldLoad]);

  const handleAddToCart = async (product) => {
    try {
      await addToCart(product, 1);
    } catch (err) {
      setToast({ message: err?.message || 'Could not add item to cart. Please try again.', type: 'error' });
      throw err;
    }
  };

  const openModal = (product) => {
    if (modalClearTimerRef.current) {
      window.clearTimeout(modalClearTimerRef.current);
      modalClearTimerRef.current = null;
    }
    setModalProduct({ product });
    setIsModalOpen(true);
  };

  if (!loading && products.length === 0) return null;

  return (
    <>
      <div ref={railRef}>
        <LoadingCardTransition
          loading={loading}
          skeleton={<StorefrontSkeletons count={4} variant="rail" />}
          label={`Loading ${label.toLowerCase()}`}
        >
          <StorefrontRail label={label} className="storefront-rail--fixed-tiles storefront-rail--equal-height">
            {products.map((product, index) => {
              const cardProduct = product.cardProduct || product;

              return (
                <StorefrontProductTile
                  key={product.sku || product.id}
                  product={product}
                  cardProduct={cardProduct}
                  variant="rail"
                  onOpenModal={() => openModal(product)}
                  onAddToCart={() => handleAddToCart(cardProduct)}
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
    </>
  );
}

import { lazy, Suspense, useState, useEffect, useCallback, useRef } from 'react';
import { getProducts } from '../../services/catalog';
import { useCart } from '../../context/CartContext';
import LoadingCardTransition from '../shared/LoadingCardTransition.jsx';
import StorefrontProductTile from '../storefront/StorefrontProductTile';
import StorefrontSection from '../storefront/StorefrontSection';
import StorefrontRail from '../storefront/StorefrontRail';
import StorefrontSkeletons from '../storefront/StorefrontSkeletons.jsx';
import Toast from '../ui/Toast';

// Product detail and its gallery/variation dependencies are only needed after
// a shopper explicitly opens Quick View. Keeping them out of the home-route
// chunk prevents a modal-only feature from delaying first render and LCP.
const ProductDetail = lazy(() => import('../product/ProductDetail'));
const ProductModal = lazy(() => import('../product/ProductModal'));

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

    getProducts().then((allProducts) => {
      if (!mounted) return;

      const toolsOnly = allProducts.filter((p) => !p.is_parts && p.category !== 'parts');
      const withPrice = toolsOnly.filter((p) => {
        const price = Number(p.price) || Number(p.min_price) || Number(p.regular_price) || 0;
        return price > 0;
      });

      const groupedByBrand = {};
      withPrice.forEach((p) => {
        const brandName = p.brand || 'Other';
        if (!groupedByBrand[brandName]) groupedByBrand[brandName] = [];
        groupedByBrand[brandName].push(p);
      });

      let balancedSelection = [];
      Object.keys(groupedByBrand).forEach((brand) => {
        const brandTools = groupedByBrand[brand];
        brandTools.sort((a, b) => {
          const aPrice = Number(a.price) || Number(a.min_price) || Number(a.regular_price) || 0;
          const bPrice = Number(b.price) || Number(b.min_price) || Number(b.regular_price) || 0;
          return bPrice - aPrice;
        });
        balancedSelection.push(...brandTools.slice(0, 4));
      });

      balancedSelection.sort((a, b) => {
        const aPrice = Number(a.price) || Number(a.min_price) || Number(a.regular_price) || 0;
        const bPrice = Number(b.price) || Number(b.min_price) || Number(b.regular_price) || 0;
        if (Math.abs(bPrice - aPrice) > 1) return bPrice - aPrice;
        return (a.brand || '').localeCompare(b.brand || '');
      });

      setProducts(balancedSelection.slice(0, 16));
      setLoading(false);
    }).catch((err) => {
      console.error('Error fetching trending products:', err);
      if (mounted) setLoading(false);
    });

    return () => { mounted = false; };
  }, [shouldLoad]);

  if (!loading && products.length === 0) return null;

  return (
    <StorefrontSection revealHeading title="Featured Products" viewAllHref="/products?sort=popular">
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
          </ProductModal>
        </Suspense>
      ) : null}
    </StorefrontSection>
  );
}

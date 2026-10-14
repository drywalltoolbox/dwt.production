import { Link } from 'react-router-dom';
import { useCallback, useEffect, useMemo, useRef, useState, lazy, Suspense } from 'react';
import { brandToSlug } from '../../utils/catalogUrlState.js';
import { buildDisplayCategoryUrl, normalizeCatalogCategoryEntry } from '../../utils/catalogFacets.js';
import ProductModal from '../product/ProductModal.jsx';

// Lazy-loaded: only needed when a search-result quick-view is actually opened.
// See StorefrontCartSheet.jsx for the same pattern/rationale.
const ProductDetail = lazy(() => import('../product/ProductDetail.jsx'));
import LoadingCardTransition from '../shared/LoadingCardTransition.jsx';
import StorefrontProductTile from './StorefrontProductTile.jsx';
import StorefrontSearchLoading from './StorefrontSearchLoading.jsx';
import { useCart } from '../../context/CartContext.jsx';

export default function StorefrontSearchOverlay({
  isOpen,
  query,
  setQuery,
  onClose,
  onViewAll,
  loading,
  recent = [],
  brands = [],
  categories = [],
  results = [],
}) {
  const { addToCart } = useCart();
  const [modalProduct, setModalProduct] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const modalClearTimerRef = useRef(null);
  const hasQuery = useMemo(() => query.trim().length > 0, [query]);
  const productResults = useMemo(() => (Array.isArray(results) ? results : []), [results]);
  const hasLoadedResults = !loading && productResults.length > 0;
  const normalizedCategories = useMemo(
    () => categories
      .map((category) => normalizeCatalogCategoryEntry(category))
      .filter((category) => category.label && category.slug),
    [categories]
  );

  const closeQuickView = useCallback(() => {
    setIsModalOpen(false);
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
    modalClearTimerRef.current = window.setTimeout(() => {
      setModalProduct(null);
      modalClearTimerRef.current = null;
    }, 220);
  }, []);

  const closeSearch = useCallback(() => {
    closeQuickView();
    onClose?.();
  }, [closeQuickView, onClose]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const previousDocumentOverflow = document.documentElement.style.overflow;
    const previousOverflow = document.body.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        // Quick View and its image lightbox render through portals. Their own
        // active dialog handles Escape before search suggestions may close.
        const owningDialog = event.target instanceof Element
          ? event.target.closest('[role="dialog"][aria-modal="true"]')
          : null;
        if (owningDialog && !document.getElementById('storefront-search-results')?.contains(owningDialog)) return;
        if (isModalOpen) closeQuickView();
        else onClose?.();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => {
      document.documentElement.style.overflow = previousDocumentOverflow;
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [isOpen, onClose, isModalOpen, closeQuickView]);

  const openQuickView = useCallback((product) => {
    if (!product?.id) return;
    if (modalClearTimerRef.current) {
      window.clearTimeout(modalClearTimerRef.current);
      modalClearTimerRef.current = null;
    }
    setModalProduct(product);
    setIsModalOpen(true);
  }, []);

  useEffect(() => () => {
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
  }, []);

  const handleAddToCart = useCallback(async (product, quantity = 1) => {
    await addToCart(product, quantity);
  }, [addToCart]);

  const searchSkeleton = (
    <section className="storefront-search-overlay__results" aria-hidden="true">
      <StorefrontSearchLoading />
    </section>
  );

  const searchResults = productResults.length > 0 ? (
    <section className="storefront-search-overlay__results storefront-search-overlay__results--product-cards">
      {productResults.map((product, index) => (
        <div className="storefront-search-overlay__result-entry" style={{ '--search-result-index': index }} key={product.id || product.slug || product.sku || index}>
          <StorefrontProductTile
            product={product}
            cardProduct={product?.cardProduct || null}
            variant="search"
            index={index}
            onOpenModal={() => openQuickView(product)}
            onAddToCart={() => handleAddToCart(product, 1)}
          />
        </div>
      ))}
    </section>
  ) : (
    <section className="storefront-search-overlay__results">
      <div className="storefront-search-overlay__empty-message">
        No products matched &quot;{query.trim()}&quot;. Try a suggestion, brand, SKU, or broader term.
      </div>
    </section>
  );

  return (
    <>
      <div id="storefront-search-results" className="storefront-search-overlay" data-open={isOpen ? 'true' : 'false'} data-has-query={hasQuery ? 'true' : 'false'} role="region" aria-hidden={!isOpen} inert={!isOpen ? true : undefined} aria-label="Product search suggestions">
        <button
          type="button"
          className="storefront-mobile-drawer__backdrop"
          onClick={(event) => {
            event.currentTarget.blur();
            closeSearch();
          }}
          aria-label="Close search"
        />
        <div className="storefront-search-overlay__sheet">
          <div className="storefront-search-overlay__panel" onClick={(event) => event.stopPropagation()}>
            {hasQuery ? (
              <div className="storefront-search-overlay__topline">
                <p className="storefront-search-overlay__panel-label">Search results</p>
              </div>
            ) : null}

            <div className={`storefront-search-overlay__body${hasQuery ? '' : ' is-browsing'}`}>
              {!hasQuery ? (
                <section className="storefront-search-overlay__empty-state">
                  {normalizedCategories.length > 0 ? (
                    <div className="storefront-search-overlay__section">
                      <h3 className="storefront-search-overlay__section-title">Popular categories</h3>
                      <div className="storefront-search-overlay__chip-list">
                        {normalizedCategories.map((category) => (
                          <Link key={category.slug} to={buildDisplayCategoryUrl(category.slug)} onClick={closeSearch} className="storefront-search-overlay__chip">
                            {category.label}
                          </Link>
                        ))}
                      </div>
                    </div>
                  ) : null}

                  {brands.length > 0 ? (
                    <div className="storefront-search-overlay__section">
                      <h3 className="storefront-search-overlay__section-title">Popular brands</h3>
                      <div className="storefront-search-overlay__chip-list">
                        {brands.map((brand) => (
                          <Link key={brand} to={`/products/brands/${brandToSlug(brand)}`} onClick={closeSearch} className="storefront-search-overlay__chip">
                            {brand}
                          </Link>
                        ))}
                      </div>
                    </div>
                  ) : null}

                  {recent.length > 0 ? (
                    <div className="storefront-search-overlay__section">
                      <h3 className="storefront-search-overlay__section-title">Recent searches</h3>
                      <div className="storefront-search-overlay__chip-list">
                        {recent.map((item) => (
                          <button key={item} type="button" onClick={() => setQuery?.(item)} className="storefront-search-overlay__chip" aria-label={`Search for ${item}`}>
                            {item}
                          </button>
                        ))}
                      </div>
                    </div>
                  ) : null}
                </section>
              ) : null}

              {hasQuery ? (
                <LoadingCardTransition
                  loading={loading}
                  skeleton={searchSkeleton}
                  label="Loading search results"
                  className="storefront-search-overlay__transition"
                >
                  {searchResults}
                </LoadingCardTransition>
              ) : null}
            </div>

            {hasQuery && hasLoadedResults ? (
              <div className="storefront-search-overlay__footer">
                <button type="button" onClick={onViewAll} className="storefront-search-overlay__view-all">
                  View all results for &quot;{query.trim()}&quot;
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </div>

      <ProductModal isOpen={isModalOpen && !!modalProduct} product={modalProduct} onClose={closeQuickView}>
        {modalProduct ? (
          <Suspense fallback={<div className="storefront-search-overlay__product-detail-loading" aria-busy="true" />}>
            <ProductDetail product={modalProduct} onAddToCart={handleAddToCart} onClose={closeQuickView} initialVariations={[]} />
          </Suspense>
        ) : null}
      </ProductModal>
    </>
  );
}

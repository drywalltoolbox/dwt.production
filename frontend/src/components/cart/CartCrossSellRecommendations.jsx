import { useEffect, useRef, useState, useId } from 'react';
import { Link } from 'react-router-dom';
import { useCart } from '../../context/CartContext.jsx';
import { getVisibleCartRecommendations, getVisibleCrossSells, storePrice } from '../../utils/cartCrossSells.js';
import useCartCompatibleParts from '../../hooks/useCartCompatibleParts.js';
import AddToCartButton from '../ui/AddToCartButton.jsx';
import '../../styles/cart-cross-sells.css';

function getProductPath(product, variation = null) {
  const slug = String(product?.slug || '').trim();
  let variationQuery = '';
  if (variation?.permalink) {
    try {
      variationQuery = new URL(variation.permalink, window.location.origin).search;
    } catch {
      variationQuery = '';
    }
  }
  if (slug) return `/products/${encodeURIComponent(slug)}${variationQuery}`;

  const permalink = String(product?.permalink || '').trim();
  if (!permalink) return '/products';
  try {
    const segments = new URL(permalink, window.location.origin).pathname.split('/').filter(Boolean);
    const last = segments.at(-1);
    return last ? `/products/${encodeURIComponent(last)}` : '/products';
  } catch {
    return '/products';
  }
}

function formatPrice(value, currency) {
  if (value === null) return '';
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: /^[A-Z]{3}$/.test(currency) ? currency : 'USD',
    }).format(value);
  } catch {
    return `$${value.toFixed(2)}`;
  }
}

export default function CartCrossSellRecommendations({ cart, cartItems = [], compact = false, isOpen = false }) {
  const { addToCart, isMutating } = useCart();
  const titleId = useId();
  const [feedback, setFeedback] = useState(null);
  const nativeCrossSells = getVisibleCrossSells(cart, cartItems);
  const { products: compatibleParts, isLoading, isError, retry } = useCartCompatibleParts(cartItems, nativeCrossSells.length < 4);
  const wasOpen = useRef(isOpen);
  const recommendations = getVisibleCartRecommendations(cart, cartItems, compatibleParts);

  useEffect(() => {
    if (compact && isOpen && !wasOpen.current && isError) retry();
    wasOpen.current = isOpen;
  }, [compact, isError, isOpen, retry]);

  if (!recommendations.length && !isLoading && !isError) return null;

  const currency = cart?.totals?.currency_code || 'USD';
  const minorUnit = cart?.totals?.currency_minor_unit;

  return (
    <section
      className={`dtb-cart-cross-sells${compact ? ' dtb-cart-cross-sells--compact' : ''}`}
      aria-labelledby={titleId}
      aria-busy={isLoading}
    >
      <div className="dtb-cart-cross-sells__heading">
        <h2 id={titleId}>Recommended for your cart</h2>
      </div>
      {feedback ? (
        <p className={`dtb-cart-cross-sells__feedback is-${feedback.state}`} role={feedback.state === 'error' ? 'alert' : 'status'} aria-live={feedback.state === 'error' ? 'assertive' : 'polite'}>
          {feedback.message}
        </p>
      ) : null}
      {isError && !recommendations.length ? (
        <div className="dtb-cart-cross-sells__load-error" role="status">
          <span>Recommendations couldn’t load.</span>
          <button className="dtb-cart-cross-sells__retry" type="button" onClick={retry}>Try again</button>
        </div>
      ) : null}
      {recommendations.length || isLoading ? <ul className="dtb-cart-cross-sells__list">
        {recommendations.map(({ type: recommendationType, product, compatibleWithSku, matchedVariation }, index) => {
          const id = Number(product.id);
          const isCompatiblePart = recommendationType === 'compatible_part';
          const image = isCompatiblePart
            ? { src: product.image || '', thumbnail: product.image || '' }
            : (Array.isArray(product.images) ? product.images[0] : null);
          const priceSource = matchedVariation || product;
          const price = isCompatiblePart
            ? (priceSource.price_available !== false && Number.isFinite(Number(priceSource.price)) && String(priceSource.price ?? '').trim() !== '' ? Number(priceSource.price) : null)
            : storePrice(product?.prices?.price, product?.prices?.currency_minor_unit ?? minorUnit);
          const path = getProductPath(product, matchedVariation);
          const canAdd = product.type === 'simple'
            && product.is_purchasable !== false
            && product.purchasable !== false
            && product.is_in_stock !== false
            && product.stock_status !== 'outofstock';
          const itemFeedback = feedback?.id === id ? feedback : null;
          const addableProduct = {
            id,
            name: product.name || 'Product',
            sku: product.sku || '',
            slug: product.slug || '',
            type: product.type || 'simple',
            price,
            regular_price: isCompatiblePart ? Number(priceSource.regular_price) || price : storePrice(product?.prices?.regular_price, minorUnit),
            sale_price: isCompatiblePart ? Number(priceSource.sale_price) || price : storePrice(product?.prices?.sale_price, minorUnit),
            stock_status: product.is_in_stock === false || product.stock_status === 'outofstock' ? 'outofstock' : 'instock',
            image: image?.thumbnail || image?.src || '',
          };
          const matchedSku = isCompatiblePart ? compatibleWithSku : '';
          const compatibleCartItem = matchedSku
            ? cartItems.find((item) => item?.sku === matchedSku)
            : null;

          return (
            <li className="dtb-cart-cross-sells__item" key={`${recommendationType}-${id}-${index}`}>
              <Link className="dtb-cart-cross-sells__product" to={path}>
                {image?.src ? (
                  <img
                    src={image.thumbnail || image.src}
                    alt=""
                    loading={compact ? 'eager' : 'lazy'}
                    fetchPriority={compact && index === 0 ? 'high' : 'auto'}
                    decoding="async"
                  />
                ) : null}
                <span className="dtb-cart-cross-sells__details">
                  <span className="dtb-cart-cross-sells__name">{product.name || 'View product'}</span>
                  {compatibleCartItem?.name ? <span className="dtb-cart-cross-sells__fit">Fits {compatibleCartItem.name} in your cart</span> : null}
                  {matchedVariation?.attributes ? <span className="dtb-cart-cross-sells__fit">{Object.values(matchedVariation.attributes).filter(Boolean).join(' · ')}</span> : null}
                  {price !== null ? <span className="dtb-cart-cross-sells__price">{formatPrice(price, currency)}</span> : null}
                </span>
              </Link>
              {canAdd ? (
                <AddToCartButton
                  className="dtb-cart-cross-sells__add"
                  size="compact"
                  label="Add"
                  pendingLabel="Adding…"
                  state={itemFeedback?.state === 'adding' ? 'adding' : itemFeedback?.state === 'added' ? 'added' : 'idle'}
                  productId={id}
                  aria-label={`Add ${product.name || 'product'} to cart`}
                  disabled={isMutating}
                  onClick={async () => {
                    setFeedback({ id, state: 'adding', message: `Adding ${product.name || 'product'} to your cart…` });
                    try {
                      await addToCart(addableProduct);
                      setFeedback({ id, state: 'added', message: `${product.name || 'Product'} was added to your cart.` });
                    } catch (error) {
                      setFeedback({
                        id,
                        state: 'error',
                        message: error?.message || `Could not add ${product.name || 'this product'} to your cart. Please try again.`,
                      });
                    }
                  }}
                />
              ) : (
                <Link className="dtb-cart-cross-sells__view" to={path}>
                  {product.has_options ? 'View options' : 'View'}
                </Link>
              )}
            </li>
          );
        })}
        {isLoading && recommendations.length < 4 ? (
          <li className="dtb-cart-cross-sells__loading-item" aria-hidden="true">
            <span className="dtb-cart-cross-sells__loading-image" />
            <span className="dtb-cart-cross-sells__loading-copy"><i /><i /><i /></span>
            <span className="dtb-cart-cross-sells__loading-action" />
          </li>
        ) : null}
      </ul> : null}
      {isLoading ? <span className="dtb-cart-cross-sells__sr-status" role="status">Loading compatible product recommendations</span> : null}
    </section>
  );
}

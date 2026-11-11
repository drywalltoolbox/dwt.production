import { useEffect, useState } from 'react';
import { getRecentlyViewed, recordRecentlyViewedProduct } from '../../utils/recentlyViewed.js';
import StorefrontRail from '../storefront/StorefrontRail.jsx';
import ProductShoppingCard from '../ui/ProductShoppingCard.jsx';

function toCardProduct(entry) {
  const price = Number(String(entry?.price || '').replace(/[^\d.]/g, ''));
  return {
    id: entry.id,
    slug: entry.slug,
    name: entry.name || 'Product',
    image: entry.image || '',
    ...(Number.isFinite(price) && price > 0 ? { price } : {}),
  };
}

export default function RecentlyViewedProducts({ product, onOpenProduct, id = 'product-recently-viewed' }) {
  const [products, setProducts] = useState(() => getRecentlyViewed().filter((entry) => entry?.slug && entry?.id));
  const productId = product?.id;
  const productSlug = product?.slug;
  const productName = product?.name;
  const productImage = typeof product?.image === 'string'
    ? product.image
    : product?.image?.src || product?.image?.url || product?.media?.images?.[0]?.src || product?.media?.images?.[0]?.url || '';
  const productPrice = typeof product?.price === 'object'
    ? product.price?.value ?? product.price?.min
    : product?.price;

  useEffect(() => {
    const refresh = () => setProducts(getRecentlyViewed().filter((entry) => entry?.slug && entry?.id));
    window.addEventListener('dtb:recently-viewed-change', refresh);
    window.addEventListener('storage', refresh);
    recordRecentlyViewedProduct({ id: productId, slug: productSlug, name: productName, image: productImage, price: productPrice });
    return () => {
      window.removeEventListener('dtb:recently-viewed-change', refresh);
      window.removeEventListener('storage', refresh);
    };
  }, [productId, productImage, productName, productPrice, productSlug]);

  const titleId = `${id}-title`;
  return (
    <section className="product-related product-related--pdp product-recently-viewed" aria-labelledby={titleId}>
      <div className="product-related__heading">
        <div><h2 id={titleId}>Recently viewed</h2></div>
      </div>
      {products.length > 0 ? (
        <StorefrontRail label="Recently viewed products" className="storefront-rail--fixed-tiles storefront-rail--equal-height storefront-rail--two-up-mobile product-recently-viewed__rail">
          {products.slice(0, 10).map((entry, index) => {
            const cardProduct = toCardProduct(entry);
            return (
              <ProductShoppingCard
                key={entry.id}
                product={cardProduct}
                cardProduct={cardProduct}
                variant="rail"
                index={index}
                hideCommerceControls
                onOpenModal={() => onOpenProduct?.(cardProduct)}
              />
            );
          })}
        </StorefrontRail>
      ) : (
        <p className="product-related__status" role="status">Products you view will appear here.</p>
      )}
    </section>
  );
}

import { Link, useNavigate } from 'react-router-dom';
import StorefrontRail from '../storefront/StorefrontRail';
import ProductShoppingCard from '../ui/ProductShoppingCard.jsx';

const MAX_RELATED_PRODUCTS = 10;

export function getRelatedProductsCopy(context = 'related') {
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
  if (context === 'included_components') {
    return {
      eyebrow: 'Included with this configuration',
      title: 'Included components',
      railLabel: 'Included components',
    };
  }
  if (context === 'family_related') {
    return {
      eyebrow: 'Same brand and product family',
      title: 'More from this product family',
      railLabel: 'Related products from this family',
    };
  }
  if (context === 'category_related') {
    return {
      eyebrow: 'Same brand and product category',
      title: 'More in this category',
      railLabel: 'Products in this category',
    };
  }
  if (context === 'brand_related') {
    return {
      eyebrow: 'More from this brand',
      title: 'More from this brand',
      railLabel: 'More products from this brand',
    };
  }
  return {
    title: 'Related products',
    railLabel: 'Related products',
  };
}

export default function ProductRelatedProducts({
  products = [],
  context = 'related',
  onAddToCart,
  onOpenProduct,
  id = 'product-related',
  status = 'ready',
  showStatus = false,
}) {
  const navigate = useNavigate();
  const hasProducts = Array.isArray(products) && products.length > 0;
  if (!hasProducts && !showStatus) return null;

  const visibleProducts = hasProducts ? products.slice(0, MAX_RELATED_PRODUCTS) : [];
  const copy = getRelatedProductsCopy(context);
  const titleId = `${id}-title`;

  return (
    <section className="product-related" aria-labelledby={titleId}>
      <div className="product-related__heading">
        <div>
          {copy.eyebrow ? <span className="product-related__eyebrow">{copy.eyebrow}</span> : null}
          <h2 id={titleId}>{copy.title}</h2>
        </div>
        <Link to="/products" className="product-related__browse">Browse all products</Link>
      </div>
      {visibleProducts.length > 0 ? <StorefrontRail label={copy.railLabel} className="storefront-rail--fixed-tiles storefront-rail--equal-height storefront-rail--two-up-mobile">
        {visibleProducts.map((relatedProduct, index) => {
          const cardProduct = relatedProduct.cardProduct || relatedProduct;
          return (
            <ProductShoppingCard
              key={relatedProduct.id || relatedProduct.sku || relatedProduct.slug}
              product={relatedProduct}
              cardProduct={cardProduct}
              variant="rail"
              index={index}
              onOpenModal={() => relatedProduct.includedVariation ? navigate(`/products/${encodeURIComponent(relatedProduct.slug)}?variant=${encodeURIComponent(relatedProduct.id)}`) : onOpenProduct?.(relatedProduct)}
              onAddToCart={(quantity) => onAddToCart?.(cardProduct, quantity)}
            />
          );
        })}
      </StorefrontRail> : (
        <p className="product-related__status" role="status">
          {status === 'loading' || status === 'idle'
            ? 'Loading related products…'
            : status === 'error' || status === 'not_found'
              ? 'Related products are temporarily unavailable.'
              : 'No related products are available for this item.'}
        </p>
      )}
    </section>
  );
}

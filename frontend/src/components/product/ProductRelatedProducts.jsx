import { Link } from 'react-router-dom';
import StorefrontRail from '../storefront/StorefrontRail';
import StorefrontProductTile from '../storefront/StorefrontProductTile';
import { useNavigate } from 'react-router-dom';

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
  return {
    eyebrow: 'Recommended for this product',
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
}) {
  const navigate = useNavigate();
  if (!Array.isArray(products) || products.length === 0) return null;

  const visibleProducts = products.slice(0, MAX_RELATED_PRODUCTS);
  const copy = getRelatedProductsCopy(context);
  const titleId = `${id}-title`;

  return (
    <section className="product-related" aria-labelledby={titleId}>
      <div className="product-related__heading">
        <div>
          <span className="product-related__eyebrow">{copy.eyebrow}</span>
          <h2 id={titleId}>{copy.title}</h2>
        </div>
        <Link to="/products" className="product-related__browse">Browse all products</Link>
      </div>
      <StorefrontRail label={copy.railLabel} className="storefront-rail--fixed-tiles">
        {visibleProducts.map((relatedProduct, index) => {
          const cardProduct = relatedProduct.cardProduct || relatedProduct;
          return (
            <StorefrontProductTile
              key={relatedProduct.id || relatedProduct.sku || relatedProduct.slug}
              product={relatedProduct}
              cardProduct={cardProduct}
              variant="grid"
              index={index}
              onOpenModal={() => relatedProduct.includedVariation ? navigate(`/products/${encodeURIComponent(relatedProduct.slug)}?variant=${encodeURIComponent(relatedProduct.id)}`) : onOpenProduct?.(relatedProduct)}
              onAddToCart={() => onAddToCart?.(cardProduct, 1)}
            />
          );
        })}
      </StorefrontRail>
    </section>
  );
}

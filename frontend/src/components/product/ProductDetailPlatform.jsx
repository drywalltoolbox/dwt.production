import ProductDetail from './ProductDetail.jsx';
import ProductRelatedProducts from './ProductRelatedProducts.jsx';
import { useCatalogProductDetail } from '../../hooks/useCatalogProductDetail.js';
import {
  toLegacyProductCardDTO,
  toLegacyVariationDTO,
} from '../../utils/catalogDtoAdapters.js';

export default function ProductDetailPlatform({
  product,
  onAddToCart,
  onClose,
  onNavigateToProduct,
  onOpenProduct,
  initialSelectedAttrs = {},
  initialVariations = [],
  initialResolvedVariation = null,
}) {
  const slug = product?.slug || '';
  const {
    product: detailProduct,
    variations,
    relatedProducts,
    computed,
    status,
  } = useCatalogProductDetail(slug);

  const fallbackProduct = product ? toLegacyProductCardDTO(product) : null;
  const resolvedProduct = detailProduct || fallbackProduct;

  const resolvedVariations =
    Array.isArray(variations) && variations.length > 0
      ? variations
      : (Array.isArray(initialVariations)
        ? initialVariations.map((variation) => toLegacyVariationDTO(variation, product || null))
        : []);

  const resolvedInitialVariation = initialResolvedVariation
    ? toLegacyVariationDTO(initialResolvedVariation, product || null)
    : null;

  const resolvedSelectedAttrs = Object.keys(initialSelectedAttrs || {}).length > 0
    ? initialSelectedAttrs
    : {};

  return (
    <>
      <ProductDetail
        product={resolvedProduct}
        onAddToCart={onAddToCart}
        onClose={onClose}
        onNavigateToProduct={onNavigateToProduct}
        initialVariations={resolvedVariations}
        initialResolvedVariation={resolvedInitialVariation}
        initialSelectedAttrs={resolvedSelectedAttrs}
        initialComputedData={computed}
        variationsHydrating={status === 'idle' || status === 'loading'}
        disableLegacyDetailFetch
        autoSelectDefaultVariation={false}
      />
      <ProductRelatedProducts
        products={relatedProducts}
        context={computed?.relatedProductsContext}
        onAddToCart={onAddToCart}
        onOpenProduct={onOpenProduct}
        id="catalog-quick-view-related"
      />
    </>
  );
}

import { useCallback, useState } from 'react';
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
    relatedProductsByVariation,
    computed,
    status,
  } = useCatalogProductDetail(slug);
  const [variationSelection, setVariationSelection] = useState(null);
  const hasCurrentVariationSelection = variationSelection?.slug === slug
    && variationSelection?.initialVariationId === (initialResolvedVariation?.id || null);
  const selectedVariationId = hasCurrentVariationSelection
    ? variationSelection.variationId
    : (initialResolvedVariation?.id || null);
  const handleVariationChange = useCallback((variation) => {
    setVariationSelection({
      slug,
      initialVariationId: initialResolvedVariation?.id || null,
      variationId: variation?.id || null,
    });
  }, [initialResolvedVariation?.id, slug]);

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
  const variationRelatedProducts = selectedVariationId
    ? relatedProductsByVariation?.[String(selectedVariationId)]
    : null;
  const displayedRelatedProducts = Array.isArray(variationRelatedProducts) ? variationRelatedProducts : relatedProducts;

  const resolvedSelectedAttrs = Object.keys(initialSelectedAttrs || {}).length > 0
    ? initialSelectedAttrs
    : {};

  return (
    <>
      <ProductDetail
        product={resolvedProduct}
        onAddToCart={onAddToCart}
        onVariationChange={handleVariationChange}
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
        products={displayedRelatedProducts}
        context={computed?.relatedProductsContext}
        onAddToCart={onAddToCart}
        onOpenProduct={onOpenProduct}
        id="catalog-quick-view-related"
        status={status}
        showStatus
      />
    </>
  );
}

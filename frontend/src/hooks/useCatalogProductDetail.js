import { useMemo } from 'react';
import useProductDetail from './useProductDetail.js';
import { toProductDetailDTO } from '../utils/catalogDtoAdapters.js';

export function useCatalogProductDetail(slug) {
  const state = useProductDetail(slug);

  const adapted = useMemo(() => {
    if (!state?.product) {
      return {
        product: null,
        variations: [],
        relatedProducts: [],
        familyRelatedProducts: [],
        categoryRelatedProducts: [],
        brandRelatedProducts: [],
        relatedProductsByVariation: {},
        computed: null,
      };
    }

    return toProductDetailDTO({
      product: state.product,
      variations: state.variations,
      relatedProducts: state.relatedProducts,
      familyRelatedProducts: state.familyRelatedProducts,
      categoryRelatedProducts: state.categoryRelatedProducts,
      brandRelatedProducts: state.brandRelatedProducts,
      relatedProductsByVariation: state.relatedProductsByVariation,
      computed: state.computed,
    });
  }, [state.product, state.variations, state.relatedProducts, state.familyRelatedProducts, state.categoryRelatedProducts, state.brandRelatedProducts, state.relatedProductsByVariation, state.computed]);

  return {
    ...state,
    ...adapted,
  };
}

export default useCatalogProductDetail;

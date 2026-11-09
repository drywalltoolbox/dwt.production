import { useCallback, useEffect, useState } from 'react';
import { getCachedCartCompatibleParts, getCartCompatibleParts } from '../api/cartRecommendations.js';
import { getCartCompatibilitySkus } from '../utils/cartCrossSells.js';

export default function useCartCompatibleParts(cartItems = [], enabled = true) {
  const skus = getCartCompatibilitySkus(cartItems);
  const key = enabled ? skus.slice().sort().join(',') : '';
  const cachedProducts = key ? getCachedCartCompatibleParts(cartItems) : null;
  const [state, setState] = useState({ key: '', products: [], failed: false });
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    if (!key || cachedProducts !== null) return undefined;

    let active = true;
    getCartCompatibleParts(cartItems)
      .then((products) => {
        if (active) setState({ key, products, failed: false });
      })
      .catch(() => {
        if (active) setState({ key, products: [], failed: true });
      });

    return () => { active = false; };
  }, [cachedProducts, cartItems, key, requestVersion]);

  const isCurrentResult = state.key === key;
  const products = key ? (cachedProducts ?? (isCurrentResult ? state.products : [])) : [];
  const isLoading = Boolean(key && cachedProducts === null && !(isCurrentResult && state.failed));
  const isError = Boolean(key && cachedProducts === null && isCurrentResult && state.failed);
  const retry = useCallback(() => {
    if (!key) return;
    setState((current) => ({
      key,
      products: current.key === key ? current.products : [],
      failed: false,
    }));
    setRequestVersion((version) => version + 1);
  }, [key]);

  return { products, isLoading, isError, retry };
}

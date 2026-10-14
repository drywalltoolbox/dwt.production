import { useEffect, useState } from 'react';
import { getProductBuyNowReadiness } from '../api/checkoutCapabilities.js';

export const DEFAULT_CHECKOUT_READINESS = Object.freeze({
  state: 'unknown',
});

export default function useCheckoutReadiness() {
  const [readiness, setReadiness] = useState(DEFAULT_CHECKOUT_READINESS);

  useEffect(() => {
    let active = true;

    getProductBuyNowReadiness().then((result) => {
      if (active) setReadiness(result || DEFAULT_CHECKOUT_READINESS);
    });

    return () => {
      active = false;
    };
  }, []);

  return readiness;
}

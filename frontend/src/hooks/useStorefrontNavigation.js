import { useEffect, useState } from 'react';
import {
  fetchStorefrontNavigation,
  getCachedStorefrontNavigation,
} from '../services/catalogPlatformCache.js';

/** Read the compact public header navigation once, with a brief stale fallback. */
export function useStorefrontNavigation() {
  const [initialCache] = useState(() => getCachedStorefrontNavigation());
  const [navigation, setNavigation] = useState(() => initialCache?.data || null);
  const [loading, setLoading] = useState(() => !initialCache?.data);
  const [error, setError] = useState(null);

  useEffect(() => {
    let mounted = true;

    fetchStorefrontNavigation()
      .then((data) => {
        if (!mounted) return;
        setNavigation(data);
        setLoading(false);
        setError(null);
      })
      .catch((requestError) => {
        if (!mounted) return;
        setError(requestError);
        setLoading(false);
      });

    return () => { mounted = false; };
  }, []);

  return { navigation, loading, error };
}

export default useStorefrontNavigation;

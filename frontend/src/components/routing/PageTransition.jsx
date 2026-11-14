import { useLayoutEffect } from 'react';
import { beginGlobalPreloader } from './globalPreloader.js';

/**
 * Keeps the route content wrapper stable and uses the branded preloader during
 * route changes. The bounded minimum prevents a flash on fast local routes;
 * lazy route boundaries keep it visible for the duration of a chunk load.
 */
export default function PageTransition({ children, animate = true }) {
  useLayoutEffect(() => {
    if (!animate) return undefined;

    const release = beginGlobalPreloader();
    const timeout = window.setTimeout(release, 220);
    return () => {
      window.clearTimeout(timeout);
      release();
    };
  }, [animate]);

  return (
    <div className="dtb-page-transition">
      <div className="dtb-page-transition__content" data-route-enter={animate ? 'true' : undefined}>
        {children}
      </div>
    </div>
  );
}

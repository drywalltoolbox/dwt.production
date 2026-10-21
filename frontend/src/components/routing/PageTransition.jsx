import { useEffect, useState } from 'react';
import RouteLoadingSurface from './RouteLoadingSurface.jsx';

const ROUTE_READY_TIMEOUT_MS = 2400;
const LOADER_EXIT_MS = 260;

function waitForRouteContent(root, isCancelled, deadline) {
  return new Promise((resolve) => {
    const check = () => {
      if (isCancelled()) return resolve();
      const routeChunkPending = root.querySelector('.dtb-route-brand-preloader[aria-busy="true"]');
      const routeDataPending = root.querySelector('[data-route-readiness="pending"], [aria-busy="true"]');
      if ((!routeChunkPending && !routeDataPending) || Date.now() >= deadline) {
        resolve();
        return;
      }
      window.requestAnimationFrame(check);
    };
    check();
  });
}

function waitForInitialImages(root, deadline) {
  const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
  const images = [...root.querySelectorAll('img')].filter((image) => {
    const rect = image.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && rect.top < viewportHeight + 80 && rect.bottom > 0;
  });

  return Promise.all(images.map((image) => {
    if (image.complete) {
      return typeof image.decode === 'function' ? image.decode().catch(() => {}) : Promise.resolve();
    }

    return new Promise((resolve) => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        image.removeEventListener('load', finish);
        image.removeEventListener('error', finish);
        resolve();
      };
      image.addEventListener('load', finish, { once: true });
      image.addEventListener('error', finish, { once: true });
      window.setTimeout(finish, Math.max(0, deadline - Date.now()));
    });
  }));
}

/**
 * Holds the incoming route under the existing brand preloader until its lazy
 * route/data boundary and first-viewport typography/images are ready. Readiness
 * is bounded so a failed or slow asset can never strand the customer here.
 */
export default function PageTransition({ children, animate = true }) {
  const [ready, setReady] = useState(!animate);
  const [showLoader, setShowLoader] = useState(animate);

  useEffect(() => {
    if (!animate) {
      setReady(true);
      setShowLoader(false);
      return undefined;
    }

    let cancelled = false;
    const deadline = Date.now() + ROUTE_READY_TIMEOUT_MS;
    const transition = document.querySelector('.dtb-page-transition[data-route-ready="false"]');
    const root = transition?.querySelector('.dtb-page-transition__content');

    const settleRoute = async () => {
      if (!root) {
        setReady(true);
        setShowLoader(false);
        return;
      }

      await waitForRouteContent(root, () => cancelled, deadline);
      if (cancelled) return;

      if (document.fonts?.ready) {
        await Promise.race([
          document.fonts.ready.catch(() => {}),
          new Promise((resolve) => window.setTimeout(resolve, Math.max(0, deadline - Date.now()))),
        ]);
      }

      await Promise.race([
        waitForInitialImages(root, deadline),
        new Promise((resolve) => window.setTimeout(resolve, Math.max(0, deadline - Date.now()))),
      ]);
      if (cancelled) return;

      setReady(true);
      window.setTimeout(() => {
        if (!cancelled) setShowLoader(false);
      }, LOADER_EXIT_MS);
    };

    settleRoute();
    return () => { cancelled = true; };
  }, [animate]);

  return (
    <div className="dtb-page-transition" data-route-ready={ready ? 'true' : 'false'}>
      <div className="dtb-page-transition__content" data-route-enter={animate && ready ? 'true' : undefined}>
        {children}
      </div>
      {animate && showLoader && (
        <div className="dtb-page-transition__loader" data-exiting={ready ? 'true' : undefined}>
          <RouteLoadingSurface label="Preparing your page" />
        </div>
      )}
    </div>
  );
}

import { useCallback, useEffect, useRef, useState } from 'react';

const SCROLL_RATIO = 0.82;
const MIN_SCROLL_AMOUNT = 240;
const EDGE_TOLERANCE_PX = 2;

export default function StorefrontRail({ label, className = '', children }) {
  const railRef = useRef(null);
  const productRail = className.split(/\s+/).includes('storefront-rail--fixed-tiles');
  const [scrollState, setScrollState] = useState({ canScrollPrev: false, canScrollNext: false });

  const updateScrollState = useCallback(() => {
    const rail = railRef.current;
    if (!rail) return;

    const maxScrollLeft = Math.max(0, rail.scrollWidth - rail.clientWidth);
    const nextState = {
      canScrollPrev: rail.scrollLeft > EDGE_TOLERANCE_PX,
      canScrollNext: rail.scrollLeft < maxScrollLeft - EDGE_TOLERANCE_PX,
    };

    setScrollState((previous) => (
      previous.canScrollPrev === nextState.canScrollPrev && previous.canScrollNext === nextState.canScrollNext
        ? previous
        : nextState
    ));
  }, []);

  const scrollRail = useCallback((direction) => {
    const rail = railRef.current;
    if (!rail) return;

    const firstCard = rail.firstElementChild;
    const columnGap = parseFloat(window.getComputedStyle(rail).columnGap) || 0;
    const pageAmount = productRail && firstCard
      ? (firstCard.getBoundingClientRect().width + columnGap) * 2
      : Math.max(MIN_SCROLL_AMOUNT, rail.clientWidth * SCROLL_RATIO);
    rail.scrollBy({
      left: direction === 'next' ? pageAmount : -pageAmount,
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
    });
  }, [productRail]);

  const onKeyDown = (event) => {
    if (!railRef.current) return;
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    event.preventDefault();
    scrollRail(event.key === 'ArrowRight' ? 'next' : 'prev');
  };

  useEffect(() => {
    const rail = railRef.current;
    if (!rail) return undefined;

    updateScrollState();

    let scheduledFrame = 0;
    const scheduleScrollStateUpdate = () => {
      if (scheduledFrame) return;
      scheduledFrame = window.requestAnimationFrame(() => {
        scheduledFrame = 0;
        updateScrollState();
      });
    };

    const onScroll = scheduleScrollStateUpdate;
    rail.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', scheduleScrollStateUpdate, { passive: true });

    let resizeObserver;
    if ('ResizeObserver' in window) {
      resizeObserver = new ResizeObserver(scheduleScrollStateUpdate);
      resizeObserver.observe(rail);
    }

    const raf = window.requestAnimationFrame(updateScrollState);
    const timeout = window.setTimeout(updateScrollState, 250);

    return () => {
      rail.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', scheduleScrollStateUpdate);
      if (resizeObserver) resizeObserver.disconnect();
      if (scheduledFrame) window.cancelAnimationFrame(scheduledFrame);
      window.cancelAnimationFrame(raf);
      window.clearTimeout(timeout);
    };
  }, [children, updateScrollState]);

  return (
    <div className={`storefront-rail-shell${productRail ? ' storefront-rail-shell--products' : ''}`}>
      <button
        type="button"
        className="storefront-rail-arrow storefront-rail-arrow--prev"
        onClick={() => scrollRail('prev')}
        disabled={!scrollState.canScrollPrev}
        aria-label={`Scroll ${label || 'product rail'} left`}
      >
        <span aria-hidden="true">‹</span>
      </button>

      <div
        ref={railRef}
        className={`storefront-rail ${className}`.trim()}
        role="region"
        aria-label={label || 'Product rail'}
        tabIndex={0}
        onKeyDown={onKeyDown}
      >
        {children}
      </div>

      <button
        type="button"
        className="storefront-rail-arrow storefront-rail-arrow--next"
        onClick={() => scrollRail('next')}
        disabled={!scrollState.canScrollNext}
        aria-label={`Scroll ${label || 'product rail'} right`}
      >
        <span aria-hidden="true">›</span>
      </button>
    </div>
  );
}

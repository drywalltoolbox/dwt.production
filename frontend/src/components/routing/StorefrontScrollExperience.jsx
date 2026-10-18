import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { ArrowUp } from 'lucide-react';
import { isDocumentScrollLocked, isScrollExperienceEnabled, scrollMetrics } from '../../utils/scrollExperiencePolicy.js';
import '../../styles/storefront-scroll-experience.css';

/**
 * Sole storefront document-scroll enhancement owner.
 *
 * Browser-native input remains authoritative on all devices, including iOS:
 * no wheel/touch interception, virtual scroll wrapper, Lenis loop or CSS
 * scroll-behavior on the root. App.jsx retains route/history scroll authority.
 * Only explicit back-to-top clicks request a smooth native scroll.
 */
export default function StorefrontScrollExperience() {
  const { pathname, search } = useLocation();
  const enabled = isScrollExperienceEnabled(pathname, search);
  const progressRef = useRef(null);
  const [showTop, setShowTop] = useState(false);

  useEffect(() => {
    if (!enabled) return undefined;

    let frame = 0;
    let resizeObserver;
    const update = () => {
      frame = 0;
      const root = document.documentElement;
      const metrics = scrollMetrics(
        window.scrollY,
        Math.max(root.scrollHeight, document.body?.scrollHeight || 0),
        window.innerHeight
      );
      if (progressRef.current) {
        progressRef.current.style.transform = `scaleX(${metrics.progress})`;
      }
      const locked = isDocumentScrollLocked(root, document.body);
      const visible = !locked && metrics.showTop;
      setShowTop(current => current === visible ? current : visible);
    };
    const schedule = () => {
      if (!frame && !document.hidden) frame = window.requestAnimationFrame(update);
    };
    const onVisibility = () => { if (!document.hidden) schedule(); };

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    window.addEventListener('pageshow', schedule);
    document.addEventListener('visibilitychange', onVisibility);

    // API-loaded sections and responsive images change document length without
    // necessarily triggering scroll/resize. Observe layout, not every DOM write.
    if (typeof ResizeObserver !== 'undefined' && document.body) {
      resizeObserver = new ResizeObserver(schedule);
      resizeObserver.observe(document.body);
    }

    update();
    return () => {
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
      window.removeEventListener('pageshow', schedule);
      document.removeEventListener('visibilitychange', onVisibility);
      if (resizeObserver) resizeObserver.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, [enabled, pathname, search]);

  useEffect(() => {
    // Editorial-only opt-in. Never animate product tiles, cart actions,
    // search/filter controls, or every element encountered while scrolling.
    if (pathname !== '/' || !enabled) return undefined;

    const media = window.matchMedia('(prefers-reduced-motion: reduce)');
    const watched = new Set();
    const revealAll = () => {
      for (const node of watched) node.dataset.dtbRevealState = 'visible';
    };
    const observer = !media.matches && typeof IntersectionObserver !== 'undefined'
      ? new IntersectionObserver(entries => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          entry.target.dataset.dtbRevealState = 'visible';
          observer.unobserve(entry.target);
        }
      }, { threshold: 0.05, rootMargin: '0px 0px -8% 0px' })
      : null;

    const register = () => {
      document.querySelectorAll('[data-dtb-scroll-reveal]').forEach(node => {
        if (watched.has(node)) return;
        watched.add(node);
        const belowViewport = node.getBoundingClientRect().top > window.innerHeight * 0.9;
        if (observer && belowViewport) {
          node.dataset.dtbRevealState = 'waiting';
          observer.observe(node);
        } else {
          node.dataset.dtbRevealState = 'visible';
        }
      });
    };

    // Home content can mount after Suspense; observe newly added opt-in
    // sections, without running a document-wide scroll animation controller.
    const main = document.querySelector('main');
    const mutations = main && typeof MutationObserver !== 'undefined'
      ? new MutationObserver(register) : null;
    if (mutations) mutations.observe(main, { childList: true, subtree: true });
    const onPreferenceChange = () => revealAll();
    media.addEventListener?.('change', onPreferenceChange);
    register();

    return () => {
      if (mutations) mutations.disconnect();
      if (observer) observer.disconnect();
      media.removeEventListener?.('change', onPreferenceChange);
      revealAll();
    };
  }, [pathname, enabled]);

  if (!enabled) return null;
  return (
    <>
      <div className="dtb-scroll-progress" aria-hidden="true">
        <div ref={progressRef} />
      </div>
      {showTop && (
        <button
          type="button"
          className="dtb-scroll-to-top"
          aria-label="Back to top"
          onClick={() => {
            if (isDocumentScrollLocked(document.documentElement, document.body)) return;
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: 0, left: 0, behavior: reduced ? 'auto' : 'smooth' });
          }}
        >
          <ArrowUp size={18} aria-hidden="true" />
        </button>
      )}
    </>
  );
}

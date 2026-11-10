import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { getBrandLogo } from '../../utils/brandAssets.js';

const MIN_LOOPS = 2;

function normalizeBrands(brands) {
  return brands
    .map((brand) => {
      const name = String(brand?.name || '').trim();
      const src = String(brand?.src || brand?.logo || getBrandLogo(name) || '').trim();
      const to = String(brand?.to || brand?.href || '').trim();
      return { name, src, to };
    })
    .filter(({ name }) => name);
}

function BrandItem({ brand, clone = false }) {
  const content = (
    <>
      {brand.src ? <img src={brand.src} alt={clone ? '' : brand.name} width="160" height="48" loading={brand.imageLoading || (clone ? 'lazy' : 'eager')} decoding="async" /> : <span>{brand.name}</span>}
    </>
  );
  const itemProps = {
    className: 'dtb-trusted-brand-link',
    'data-brand': brand.name,
    'aria-hidden': clone || undefined,
    tabIndex: clone ? -1 : undefined,
  };

  return brand.to ? (
    <Link {...itemProps} to={brand.to} aria-label={clone ? undefined : `Shop ${brand.name}`}>
      {content}
    </Link>
  ) : (
    <span {...itemProps} aria-label={clone ? undefined : brand.name}>
      {content}
    </span>
  );
}

export default function TrustedBrands({
  brands = [],
  title = 'Trusted Brands',
  speed = 32,
  dark = false,
  transparent = false,
  className = '',
  imageLoading = 'eager',
  animated = true,
}) {
  const normalizedBrands = useMemo(
    () => normalizeBrands(Array.isArray(brands) ? brands : []).map((brand) => ({ ...brand, imageLoading })),
    [brands, imageLoading],
  );
  const sectionId = useId();
  const viewportRef = useRef(null);
  const laneRef = useRef(null);
  const [reducedMotion, setReducedMotion] = useState(() =>
    typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  );
  const [compactViewport, setCompactViewport] = useState(() =>
    typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia('(max-width: 767px)').matches
  );
  const shouldAnimate = animated && !reducedMotion;
  const [loopCount, setLoopCount] = useState(() => shouldAnimate && !compactViewport ? MIN_LOOPS : 1);

  useEffect(() => {
    const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    if (!query) return undefined;
    const sync = () => setReducedMotion(query.matches);
    sync();
    query.addEventListener?.('change', sync);
    return () => query.removeEventListener?.('change', sync);
  }, []);

  useEffect(() => {
    const query = window.matchMedia?.('(max-width: 767px)');
    if (!query) return undefined;
    const sync = () => setCompactViewport(query.matches);
    sync();
    query.addEventListener?.('change', sync);
    return () => query.removeEventListener?.('change', sync);
  }, []);

  useEffect(() => {
    const viewport = viewportRef.current;
    const lane = laneRef.current;
    if (!viewport || !lane || normalizedBrands.length < 2 || !shouldAnimate || compactViewport) return undefined;

    const measure = () => {
      const firstItem = lane.firstElementChild;
      if (!firstItem) return;

      const itemWidth = firstItem.offsetWidth;
      const gap = Number.parseFloat(window.getComputedStyle(lane).columnGap) || 0;
      const pitch = itemWidth + gap;
      const viewportWidth = viewport.clientWidth;
      if (!pitch || !viewportWidth) return;

      const loopCountNext = reducedMotion
        ? 1
        : Math.max(1, Math.ceil((viewportWidth + pitch) / (normalizedBrands.length * pitch)));
      setLoopCount((current) => current === loopCountNext ? current : loopCountNext);

    };

    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(viewport);
    if (lane.firstElementChild) observer.observe(lane.firstElementChild);
    return () => observer.disconnect();
  }, [normalizedBrands.length, shouldAnimate, compactViewport]);

  if (!normalizedBrands.length) return null;

  const loops = Array.from({ length: normalizedBrands.length < 2 || !shouldAnimate ? 1 : compactViewport ? 1 : loopCount }, (_, loopIndex) =>
    normalizedBrands.map((brand, brandIndex) => ({
      ...brand,
      key: `${brand.to || brand.name}-${loopIndex}-${brandIndex}`,
    }))
  ).flat();
  const duration = `${Math.max(18, Number(speed) || 32)}s`;
  const classes = [
    'dtb-ui-trusted-brands',
    normalizedBrands.length < 2 || !shouldAnimate ? 'is-static' : '',
    dark ? 'dtb-ui-trusted-brands--dark' : '',
    transparent ? 'dtb-ui-trusted-brands--transparent' : '',
    className,
  ].filter(Boolean).join(' ');

  return (
    <section
      className={classes}
      aria-labelledby={title ? `${sectionId}-title` : undefined}
      aria-label={title ? undefined : 'Trusted brands'}
      style={{ '--dtb-brand-loop-duration': duration }}
    >
      {title && (
        <div className="dtb-ui-trusted-brands__heading">
          {title && <h2 id={`${sectionId}-title`} className="dtb-ui-trusted-brands__title">{title}</h2>}
        </div>
      )}

      <div className="dtb-ui-trusted-brands__viewport" id={`${sectionId}-viewport`} ref={viewportRef}>
        <div className="dtb-ui-trusted-brands__track">
          <div className="dtb-ui-trusted-brands__lane" ref={laneRef}>
            {loops.map((brand) => <BrandItem key={brand.key} brand={brand} />)}
          </div>
          <div className="dtb-ui-trusted-brands__lane dtb-ui-trusted-brands__lane--clone" aria-hidden="true">
            {loops.map((brand) => <BrandItem key={`${brand.key}-clone`} brand={brand} clone />)}
          </div>
        </div>
      </div>
    </section>
  );
}

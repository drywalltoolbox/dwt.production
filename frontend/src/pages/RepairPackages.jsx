import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { dtbTransition, reducedTransition } from '../motion/dtbMotion.js';
import {
  ArrowRight,
  Check,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import PageHeroBanner from '../components/shared/PageHeroBanner.jsx';
import { getRepairPackageGroups, REPAIR_TOOL_FAMILIES } from '../data/repairPackages.js';
import '../styles/repair-packages.css';

function formatPackageCount(count) {
  return `${count} package${count === 1 ? '' : 's'}`;
}

function isFeaturedPackage(pkg, index, packageCount) {
  return pkg.routeType === 'standard_package'
    && packageCount > 1
    && index === Math.min(1, packageCount - 1);
}

function getPriceDisplay(pkg) {
  if (Number.isFinite(pkg.startingPrice)) {
    return {
      label: 'Starts at',
      value: `$${pkg.startingPrice}`,
      note: 'Starting estimate. Final quote confirmed after inspection.',
    };
  }

  return {
    label: 'Pricing',
    value: pkg.priceLabel,
    note: 'Your final quote is confirmed after inspection.',
  };
}

function persistSelectedPackage(pkg) {
  if (typeof window === 'undefined' || !pkg?.id) return;

  try {
    window.sessionStorage.setItem('dtb:repair:selected-package', JSON.stringify({
      id: pkg.id,
      toolFamily: pkg.toolFamily,
      name: pkg.name,
      selectedAt: Date.now(),
    }));
  } catch {
    // Session storage is non-critical; the URL query param remains authoritative.
  }
}

function AnimatedPrice({ price }) {
  const reduceMotion = useReducedMotion();
  return (
    <Motion.span
      key={price}
      className="repair-package-card__price-value"
      initial={{ opacity: 1, y: reduceMotion ? 0 : 4 }}
      animate={{ opacity: 1, y: 0 }}
      transition={reduceMotion ? reducedTransition : dtbTransition.fast}
    >
      {price}
    </Motion.span>
  );
}

function CategoryTabs({ groups, activeGroupId, focusGroupId, onChange }) {
  const tabsRef = useRef(null);
  const tabRefs = useRef(new Map());
  const [activeMetrics, setActiveMetrics] = useState({ left: 0, width: 0 });

  const updateActiveMetrics = useCallback(() => {
    const activeTab = tabRefs.current.get(activeGroupId);
    if (!activeTab) return;

    const nextMetrics = {
      left: activeTab.offsetLeft,
      width: activeTab.offsetWidth,
    };

    setActiveMetrics((currentMetrics) => (
      currentMetrics.left === nextMetrics.left && currentMetrics.width === nextMetrics.width
        ? currentMetrics
        : nextMetrics
    ));
  }, [activeGroupId]);

  useLayoutEffect(() => {
    updateActiveMetrics();

    const resizeObserver = typeof ResizeObserver === 'undefined'
      ? null
      : new ResizeObserver(updateActiveMetrics);

    if (tabsRef.current) resizeObserver?.observe(tabsRef.current);
    tabRefs.current.forEach((tab) => resizeObserver?.observe(tab));
    window.addEventListener('resize', updateActiveMetrics);

    return () => {
      resizeObserver?.disconnect();
      window.removeEventListener('resize', updateActiveMetrics);
    };
  }, [groups, updateActiveMetrics]);

  useEffect(() => {
    const activeTab = tabRefs.current.get(activeGroupId);
    activeTab?.scrollIntoView({
      behavior: 'auto',
      block: 'nearest',
      inline: 'nearest',
    });

    if (focusGroupId !== activeGroupId) return undefined;

    const frameId = window.requestAnimationFrame(() => activeTab?.focus());
    return () => window.cancelAnimationFrame(frameId);
  }, [activeGroupId, focusGroupId]);

  const moveSelection = useCallback((event, currentIndex) => {
    let nextIndex = currentIndex;

    if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % groups.length;
    else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + groups.length) % groups.length;
    else if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = groups.length - 1;
    else return;

    event.preventDefault();
    const nextGroup = groups[nextIndex];
    onChange(nextGroup.id, { preserveKeyboardFocus: true });
  }, [groups, onChange]);

  return (
    <div className="repair-package-tabs-wrap" aria-label="Repair package tool categories">
      <div ref={tabsRef} className="repair-package-tabs" role="tablist" aria-label="Repair package tool categories" aria-orientation="horizontal">
        <div
          className={`repair-package-tabs__active${activeMetrics.width > 0 ? ' is-ready' : ''}`}
          style={{
            width: `${activeMetrics.width}px`,
            transform: `translate3d(${activeMetrics.left}px, 0, 0)`,
          }}
          aria-hidden="true"
        />
        {groups.map((group, index) => {
          const active = group.id === activeGroupId;
          const tabId = `repair-package-tab-${group.id}`;

          return (
            <button
              key={group.id}
              ref={(node) => {
                if (node) tabRefs.current.set(group.id, node);
                else tabRefs.current.delete(group.id);
              }}
              id={tabId}
              type="button"
              role="tab"
              aria-selected={active}
              aria-controls={`repair-packages-panel-${group.id}`}
              tabIndex={active ? 0 : -1}
              className={`repair-package-tab${active ? ' is-active' : ''}`}
              onClick={() => onChange(group.id)}
              onKeyDown={(event) => moveSelection(event, index)}
            >
              <span className="repair-package-tab__label">{group.label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

function PackageCard({ pkg, index, packageCount, resumeState }) {
  const reduceMotion = useReducedMotion();
  const familyLabel = REPAIR_TOOL_FAMILIES[pkg.toolFamily]?.label || 'Repair Service';
  const featured = isFeaturedPackage(pkg, index, packageCount);
  const price = getPriceDisplay(pkg);
  const startUrl = `/repairs/start?package=${encodeURIComponent(pkg.id)}`;

  return (
    <Motion.article
      layout={!reduceMotion}
      className={`repair-package-card${featured ? ' repair-package-card--featured' : ''}`}
      initial={{ y: reduceMotion ? 0 : 6, opacity: 1 }}
      animate={{ y: 0, opacity: 1 }}
      transition={reduceMotion ? reducedTransition : dtbTransition.fast}
      whileHover={reduceMotion ? undefined : { y: -2 }}
    >
      {featured && (
        <div className="repair-package-card__recommended">
          Recommended
        </div>
      )}

      <div className="repair-package-card__header">
        <div>
          <p className="repair-package-card__eyebrow">{familyLabel}</p>
          <h3>{pkg.name}</h3>
        </div>
      </div>

      <div className="repair-package-card__price">
        <span className="repair-package-card__price-label">{price.label}</span>
        <AnimatedPrice price={price.value} />
      </div>
      <p className="repair-package-card__price-note">
        {price.note}
      </p>

      <p className="repair-package-card__summary">
        Best for {pkg.recommendedFor.join(', ')}.
      </p>

      <ul className="repair-package-card__features" aria-label={`${pkg.name} includes`}>
        {pkg.includes.slice(0, 5).map((item) => (
          <li key={item}>
            <Check size={16} aria-hidden="true" />
            <span>{item}</span>
          </li>
        ))}
      </ul>

      {pkg.requiresApproval && (
        <div className="repair-package-card__meta">
          <span>Approval before work</span>
        </div>
      )}

      <Link
        to={startUrl}
        state={{
          selectedRepairPackageId: pkg.id,
          selectedRepairToolFamily: pkg.toolFamily,
          repairFormResume: resumeState,
        }}
        onClick={() => persistSelectedPackage(pkg)}
        className="repair-package-card__button repair-package-card__button--primary"
      >
        Start with this package
        <ArrowRight size={16} aria-hidden="true" />
      </Link>
    </Motion.article>
  );
}

export default function RepairPackages() {
  const reduceMotion = useReducedMotion();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const groups = useMemo(() => getRepairPackageGroups(), []);
  const requestedGroupId = searchParams.get('tool');
  const activeGroupId = groups.some((group) => group.id === requestedGroupId)
    ? requestedGroupId
    : (groups[0]?.id || 'automatic_taper');
  const activeGroup = groups.find((group) => group.id === activeGroupId) || groups[0];
  const resumeState = location.state?.repairFormResume || null;

  const focusGroupId = location.state?.repairPackageTabFocus || null;

  const handleGroupChange = useCallback((groupId, { preserveKeyboardFocus = false } = {}) => {
    const nextParams = new URLSearchParams(searchParams);
    nextParams.set('tool', groupId);
    setSearchParams(nextParams, {
      replace: true,
      state: {
        ...(location.state || {}),
        repairPackageTabFocus: preserveKeyboardFocus ? groupId : null,
      },
    });
  }, [location.state, searchParams, setSearchParams]);

  return (
    <div className="page-wrapper repair-packages-page">
      <SEOHead
        title="Repair Service Packages"
        description="Compare DTB standard repair packages and diagnostic quote-first paths."
        canonical="/repairs/packages"
      />

      <section className="repair-packages-hero">
        <PageHeroBanner
          eyebrow="Repair Services"
          title="Repair Packages"
          highlight="Choose By Tool Category."
          description="Compare common rebuilds, tune-ups, diagnostic quotes, and service paths before starting your repair intake."
          align="left"
        />
      </section>

      <section className="repair-packages-tabs-section">
        <div className="repair-packages-shell">
          <CategoryTabs
            groups={groups}
            activeGroupId={activeGroup.id}
            focusGroupId={focusGroupId}
            onChange={handleGroupChange}
          />
        </div>
      </section>

      <main className="repair-packages-main">
        <div className="repair-packages-shell">
          <div className="repair-packages-panel-heading">
            <div>
              <p>{formatPackageCount(activeGroup.packages.length)}</p>
              <h2>{activeGroup.label}</h2>
            </div>
            <Link
              to="/repairs/start"
              state={{ repairFormResume: resumeState }}
              className="repair-packages-new-repair"
            >
              <span className="repair-packages-new-repair__copy">
                <strong>Start New Repair</strong>
                <small>Not sure which service fits? Start here.</small>
              </span>
              <span className="repair-packages-new-repair__arrow" aria-hidden="true">
                <ArrowRight size={18} />
              </span>
            </Link>
          </div>

          <Motion.section
            key={activeGroup.id}
            id={`repair-packages-panel-${activeGroup.id}`}
            role="tabpanel"
            aria-labelledby={`repair-package-tab-${activeGroup.id}`}
            className="repair-packages-grid"
            initial={{ opacity: 1, y: reduceMotion ? 0 : 4 }}
            animate={{ opacity: 1, y: 0 }}
            transition={reduceMotion ? reducedTransition : dtbTransition.fast}
          >
            {activeGroup.packages.map((pkg, index) => (
              <PackageCard
                key={pkg.id}
                pkg={pkg}
                index={index}
                packageCount={activeGroup.packages.length}
                resumeState={resumeState}
              />
            ))}
          </Motion.section>
        </div>
      </main>
    </div>
  );
}

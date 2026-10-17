import { useCallback, useRef, useState } from 'react';
import { Gem, Truck, Headset, ArrowUpRight, Wrench, Settings2 } from 'lucide-react';
import { AnimatePresence, m as Motion, useReducedMotion } from 'framer-motion';
import HomeHeroBrands from './HomeHeroBrands';
import HomeHeroButton from './HomeHeroButton';
import { HOME_HERO_CAMPAIGNS, HOME_HERO_CAMPAIGN_COUNT } from './homeHeroCampaigns.js';
import {
  homeHeroContentVariants,
  homeHeroItemVariants,
  homeHeroNavigationTransition,
  reducedHomeHeroContentVariants,
  reducedHomeHeroItemVariants,
} from '../../motion/dtbMotion.js';
import homeHeroDesktopUrl from '@assets/media/home/home-hero-desktop-1600.webp';
import homeHeroMobileUrl from '@assets/media/home/home-hero-mobile-640.webp';

const SWIPE_THRESHOLD_PX = 44;

function HeroTitleLines({ campaign }) {
  return (
    <>
      {campaign.titleLines.map((line, index) => (
        <span
          className={`home-hero__title-line${index === campaign.accentLine ? ' home-hero__title-line--accent' : ''}`}
          key={line}
        >
          {line}
        </span>
      ))}
    </>
  );
}

export default function HomeHero({ brands = [] }) {
  const reduceMotion = useReducedMotion();
  const [activeIndex, setActiveIndex] = useState(0);
  const [direction, setDirection] = useState(1);
  const tabRefs = useRef([]);
  const swipeStartRef = useRef(null);

  const activeCampaign = HOME_HERO_CAMPAIGNS[activeIndex];
  const contentVariants = reduceMotion ? reducedHomeHeroContentVariants : homeHeroContentVariants;
  const itemVariants = reduceMotion ? reducedHomeHeroItemVariants : homeHeroItemVariants;

  const selectCampaign = useCallback((nextIndex, requestedDirection) => {
    if (nextIndex === activeIndex) return;

    const normalizedIndex = (nextIndex + HOME_HERO_CAMPAIGN_COUNT) % HOME_HERO_CAMPAIGN_COUNT;
    setDirection(requestedDirection ?? (normalizedIndex > activeIndex ? 1 : -1));
    setActiveIndex(normalizedIndex);
  }, [activeIndex]);

  const selectRelativeCampaign = useCallback((delta) => {
    selectCampaign(activeIndex + delta, delta >= 0 ? 1 : -1);
  }, [activeIndex, selectCampaign]);

  const handleTabKeyDown = useCallback((event) => {
    let nextIndex = null;

    if (event.key === 'ArrowRight') nextIndex = (activeIndex + 1) % HOME_HERO_CAMPAIGN_COUNT;
    if (event.key === 'ArrowLeft') nextIndex = (activeIndex - 1 + HOME_HERO_CAMPAIGN_COUNT) % HOME_HERO_CAMPAIGN_COUNT;
    if (event.key === 'Home') nextIndex = 0;
    if (event.key === 'End') nextIndex = HOME_HERO_CAMPAIGN_COUNT - 1;

    if (nextIndex === null) return;

    event.preventDefault();
    selectCampaign(nextIndex, event.key === 'ArrowLeft' ? -1 : 1);
    tabRefs.current[nextIndex]?.focus();
  }, [activeIndex, selectCampaign]);

  const handlePointerDown = useCallback((event) => {
    if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
    if (event.target.closest('a, button')) return;

    swipeStartRef.current = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    };
  }, []);

  const handlePointerUp = useCallback((event) => {
    const start = swipeStartRef.current;
    swipeStartRef.current = null;

    if (!start || start.pointerId !== event.pointerId) return;

    const deltaX = event.clientX - start.x;
    const deltaY = event.clientY - start.y;

    if (Math.abs(deltaX) < SWIPE_THRESHOLD_PX || Math.abs(deltaX) <= Math.abs(deltaY)) return;
    selectRelativeCampaign(deltaX < 0 ? 1 : -1);
  }, [selectRelativeCampaign]);

  return (
    <section className="home-hero" aria-labelledby="home-hero-title">
      <div
        className="home-hero__stage"
        data-campaign={activeCampaign.id}
        onPointerDown={handlePointerDown}
        onPointerUp={handlePointerUp}
        onPointerCancel={() => { swipeStartRef.current = null; }}
      >
        <picture className="home-hero__media" aria-hidden="true">
          <source
            media="(max-width: 640px)"
            srcSet={homeHeroMobileUrl}
            type="image/webp"
            width="640"
            height="1138"
          />
          <img
            className="home-hero__media-image"
            src={homeHeroDesktopUrl}
            width="1600"
            height="640"
            sizes="100vw"
            alt=""
            decoding="async"
            loading="eager"
            fetchPriority="high"
            draggable="false"
          />
        </picture>

        <div className="home-hero__ambient" aria-hidden="true" />
        <div className="home-hero__scrim" aria-hidden="true" />

        <div
          className="home-hero__content-shell"
          id="home-hero-panel"
          role="tabpanel"
          aria-labelledby={`home-hero-tab-${activeCampaign.id}`}
          aria-live="polite"
        >
          <AnimatePresence initial={false} mode="wait" custom={direction}>
            <Motion.div
              className="home-hero__content"
              custom={direction}
              key={activeCampaign.id}
              variants={contentVariants}
              initial="enter"
              animate="center"
              exit="exit"
            >
              <Motion.p className="home-hero__eyebrow" variants={itemVariants}>
                <span className="home-hero__eyebrow-bar" aria-hidden="true" />
                {activeCampaign.eyebrow}
              </Motion.p>

              <Motion.h1 id="home-hero-title" className="home-hero__title" variants={itemVariants}>
                <HeroTitleLines campaign={activeCampaign} />
              </Motion.h1>

              <Motion.p className="home-hero__description" variants={itemVariants}>
                {activeCampaign.description}
              </Motion.p>

              <Motion.div className="home-hero__actions" variants={itemVariants}>
                <HomeHeroButton to={activeCampaign.primaryAction.to}>
                  {activeCampaign.primaryAction.label}
                </HomeHeroButton>
                <HomeHeroButton to={activeCampaign.secondaryAction.to} variant="secondary">
                  {activeCampaign.secondaryAction.label}
                </HomeHeroButton>
              </Motion.div>
              <Motion.div className="home-hero__promises" variants={itemVariants} aria-label="Why shop with Drywall Toolbox">
                <span><Gem aria-hidden="true" size={23} /><span><strong>Pro-Grade Quality</strong><small>Built for the jobsite</small></span></span>
                <span><Truck aria-hidden="true" size={23} /><span><strong>Fast Shipping</strong><small>Orders $75+</small></span></span>
                <span><Headset aria-hidden="true" size={23} /><span><strong>Expert Support</strong><small>Real people. Real help.</small></span></span>
              </Motion.div>
            </Motion.div>
          </AnimatePresence>
        </div>

        <nav className="home-hero-campaigns" aria-label="Homepage highlights">
          <div
            className="home-hero-campaigns__rail"
            role="tablist"
            aria-label="Choose homepage highlight"
            onKeyDown={handleTabKeyDown}
          >
            {HOME_HERO_CAMPAIGNS.map((campaign, index) => {
              const isActive = index === activeIndex;

              return (
                <button
                  type="button"
                  key={campaign.id}
                  ref={(node) => { tabRefs.current[index] = node; }}
                  id={`home-hero-tab-${campaign.id}`}
                  className={`home-hero-campaigns__tab${isActive ? ' is-active' : ''}`}
                  role="tab"
                  aria-selected={isActive}
                  aria-controls="home-hero-panel"
                  tabIndex={isActive ? 0 : -1}
                  onClick={() => selectCampaign(index)}
                >
                  <span className="home-hero-campaigns__index" aria-hidden="true">
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  <span className="home-hero-campaigns__label">{campaign.label}<small className="home-hero-campaigns__description">{campaign.id === 'tools' ? 'Taping, finishing and more' : campaign.id === 'parts' ? 'Keep your tools running' : 'Expert service & support'}</small></span>
                  <span className="home-hero-campaigns__glyph" aria-hidden="true">{campaign.id === 'tools' ? <Settings2 size={28} /> : campaign.id === 'parts' ? <Wrench size={28} /> : <Headset size={28} />}</span>
                  <ArrowUpRight className="home-hero-campaigns__arrow" size={20} aria-hidden="true" />
                  <span className="home-hero-campaigns__track" aria-hidden="true">
                    {isActive && (
                      <Motion.span
                        className="home-hero-campaigns__indicator"
                        layoutId="home-hero-campaign-indicator"
                        transition={reduceMotion ? { duration: 0 } : homeHeroNavigationTransition}
                      />
                    )}
                  </span>
                </button>
              );
            })}
          </div>
        </nav>
      </div>

      <HomeHeroBrands brands={brands} />
    </section>
  );
}

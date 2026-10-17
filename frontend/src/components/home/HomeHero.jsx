import { useCallback, useEffect, useRef, useState } from 'react';
import { Gem, Truck, Headset, Pause, Play } from 'lucide-react';
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
const SLIDE_INTERVAL_MS = 8500;

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
  const [paused, setPaused] = useState(false);
  const [interactionPaused, setInteractionPaused] = useState(false);
  const [pageVisible, setPageVisible] = useState(true);
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

  useEffect(() => {
    const updateVisibility = () => setPageVisible(!document.hidden);
    updateVisibility();
    document.addEventListener('visibilitychange', updateVisibility);
    return () => document.removeEventListener('visibilitychange', updateVisibility);
  }, []);

  useEffect(() => {
    if (paused || interactionPaused || reduceMotion || !pageVisible) return undefined;
    const interval = window.setInterval(() => {
      setDirection(1);
      setActiveIndex((index) => (index + 1) % HOME_HERO_CAMPAIGN_COUNT);
    }, SLIDE_INTERVAL_MS);
    return () => window.clearInterval(interval);
  }, [paused, interactionPaused, reduceMotion, pageVisible, activeIndex]);

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
        onMouseEnter={() => setInteractionPaused(true)}
        onMouseLeave={() => setInteractionPaused(false)}
        onFocusCapture={() => setInteractionPaused(true)}
        onBlurCapture={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget)) setInteractionPaused(false);
        }}
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
          aria-live="off"
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

        <nav className="home-hero-carousel" aria-label="Homepage slides">
          <div className="home-hero-carousel__controls" role="group" aria-label="Choose homepage slide">
            {HOME_HERO_CAMPAIGNS.map((campaign, index) => (
              <button
                type="button"
                key={campaign.id}
                ref={(node) => { tabRefs.current[index] = node; }}
                className={`home-hero-carousel__dot${index === activeIndex ? ' is-active' : ''}`}
                aria-label={`Show ${campaign.label} slide (${index + 1} of ${HOME_HERO_CAMPAIGN_COUNT})`}
                aria-current={index === activeIndex ? 'true' : undefined}
                onClick={() => selectCampaign(index)}
                onKeyDown={handleTabKeyDown}
              />
            ))}
            <button type="button" className="home-hero-carousel__pause"
              onClick={() => setPaused((current) => !current)}
              aria-label={paused ? 'Play slideshow' : 'Pause slideshow'}
              aria-pressed={paused}>
              {paused ? <Play size={15} aria-hidden="true" /> : <Pause size={15} aria-hidden="true" />}
            </button>
          </div>
        </nav>
      </div>

      <HomeHeroBrands brands={brands} />
    </section>
  );
}

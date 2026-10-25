import { useCallback, useEffect, useRef, useState } from 'react';
import { useReducedMotion } from 'framer-motion';
import HomeHeroButton from './HomeHeroButton';
import { HOME_HERO_CAMPAIGNS, HOME_HERO_CAMPAIGN_COUNT } from './homeHeroCampaigns.js';
import homeHeroDesktopUrl from '@assets/media/home/home-hero-desktop-1600.webp';
import homeHeroMobileUrl from '@assets/media/home/home-hero-mobile-640.webp';
import homeRepairsHeroMobileUrl from '@assets/media/home/home-hero-repairs-mobile.jpg';
import homeRepairsHeroDesktopUrl from '@assets/media/home/home-repairs-hero-desktop.webp';

const SWIPE_THRESHOLD_PX = 44;
const SLIDE_INTERVAL_MS = 8500;
const HERO_CROSSFADE_DURATION_MS = 620;
const POINTER_IDLE_MS = 3000;

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

function HomeHeroSlide({ campaign, reduceMotion, isActive, isEntering = false, isExiting = false }) {
  const isRepairsMedia = campaign.id === 'repairs';
  const desktopHeroUrl = isRepairsMedia ? homeRepairsHeroDesktopUrl : homeHeroDesktopUrl;
  const mobileHeroUrl = isRepairsMedia ? homeRepairsHeroMobileUrl : homeHeroMobileUrl;
  const desktopHeroDimensions = isRepairsMedia
    ? { width: 1672, height: 941 }
    : { width: 1600, height: 640 };
  const slideDuration = reduceMotion ? 100 : HERO_CROSSFADE_DURATION_MS;
  const slideClassName = [
    'home-hero__slide',
    isEntering && 'home-hero__slide--entering',
    isExiting && 'home-hero__slide--exiting',
  ].filter(Boolean).join(' ');

  return (
    <div
      className={slideClassName}
      aria-hidden={!isActive}
      inert={!isActive}
      style={{ '--home-hero-slide-duration': `${slideDuration}ms` }}
    >
      <picture
        className="home-hero__media"
        aria-hidden="true"
        style={{ '--home-hero-media-image': `url("${desktopHeroUrl}")` }}
      >
        <source
          media="(max-width: 640px)"
          srcSet={mobileHeroUrl}
          type={isRepairsMedia ? 'image/jpeg' : 'image/webp'}
          width={isRepairsMedia ? 720 : 640}
          height={isRepairsMedia ? 1280 : 1138}
        />
        <img
          className="home-hero__media-image"
          src={desktopHeroUrl}
          width={desktopHeroDimensions.width}
          height={desktopHeroDimensions.height}
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
        id={isActive ? 'home-hero-panel' : undefined}
        aria-live="off"
      >
        <div className="home-hero__content">
          <p className="home-hero__eyebrow">
            <span className="home-hero__eyebrow-bar" aria-hidden="true" />
            {campaign.eyebrow}
          </p>

          <h1 id={isActive ? 'home-hero-title' : undefined} className="home-hero__title">
            <HeroTitleLines campaign={campaign} />
          </h1>

          <p className="home-hero__description">{campaign.description}</p>

          <div className="home-hero__actions">
            <HomeHeroButton to={campaign.primaryAction.to}>
              {campaign.primaryAction.label}
            </HomeHeroButton>
            <HomeHeroButton to={campaign.secondaryAction.to} variant="secondary">
              {campaign.secondaryAction.label}
            </HomeHeroButton>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function HomeHero() {
  const reduceMotion = useReducedMotion();
  const [activeIndex, setActiveIndex] = useState(0);
  const tabRefs = useRef([]);
  const [interactionPaused, setInteractionPaused] = useState(false);
  const [pageVisible, setPageVisible] = useState(true);
  const swipeStartRef = useRef(null);
  const touchStartRef = useRef(null);
  const pointerIdleTimerRef = useRef(null);
  const [pointerActive, setPointerActive] = useState(false);

  const activeCampaign = HOME_HERO_CAMPAIGNS[activeIndex];
  const [visibleCampaignId, setVisibleCampaignId] = useState(HOME_HERO_CAMPAIGNS[0].id);
  const [outgoingCampaignId, setOutgoingCampaignId] = useState(null);
  const [crossfadeStarted, setCrossfadeStarted] = useState(false);
  const visibleCampaign = HOME_HERO_CAMPAIGNS.find(({ id }) => id === visibleCampaignId) ?? HOME_HERO_CAMPAIGNS[0];
  const outgoingCampaign = HOME_HERO_CAMPAIGNS.find(({ id }) => id === outgoingCampaignId) ?? null;

  useEffect(() => {
    if (visibleCampaignId === activeCampaign.id) return undefined;

    const mobileViewport = window.matchMedia('(max-width: 640px)').matches;
    const incomingUrl = activeCampaign.id === 'repairs'
      ? (mobileViewport ? homeRepairsHeroMobileUrl : homeRepairsHeroDesktopUrl)
      : (mobileViewport ? homeHeroMobileUrl : homeHeroDesktopUrl);
    const incomingImage = new window.Image();
    let cancelled = false;
    let settled = false;

    const revealWhenDecoded = async () => {
      if (settled) return;
      settled = true;

      try {
        if (typeof incomingImage.decode === 'function') {
          await incomingImage.decode();
        }
      } catch {
        // The load event has fired, and some browsers reject decode() for an
        // image that is otherwise ready to render.
      }

      if (!cancelled) {
        setOutgoingCampaignId(visibleCampaignId);
        setCrossfadeStarted(false);
        setVisibleCampaignId(activeCampaign.id);
      }
    };

    incomingImage.decoding = 'async';
    incomingImage.onload = revealWhenDecoded;
    incomingImage.src = incomingUrl;
    if (incomingImage.complete && incomingImage.naturalWidth > 0) {
      revealWhenDecoded();
    }

    return () => {
      cancelled = true;
      incomingImage.onload = null;
    };
  }, [activeCampaign.id, visibleCampaignId]);

  useEffect(() => {
    if (!outgoingCampaignId) return undefined;

    let frame = 0;
    const timeout = window.setTimeout(() => {
      setOutgoingCampaignId(null);
      setCrossfadeStarted(false);
    }, HERO_CROSSFADE_DURATION_MS + 80);

    frame = window.requestAnimationFrame(() => setCrossfadeStarted(true));

    return () => {
      window.cancelAnimationFrame(frame);
      window.clearTimeout(timeout);
    };
  }, [outgoingCampaignId, visibleCampaignId]);

  const selectCampaign = useCallback((nextIndex) => {
    if (nextIndex === activeIndex) return;

    const normalizedIndex = (nextIndex + HOME_HERO_CAMPAIGN_COUNT) % HOME_HERO_CAMPAIGN_COUNT;
    setActiveIndex(normalizedIndex);
  }, [activeIndex]);

  const selectRelativeCampaign = useCallback((delta) => {
    selectCampaign(activeIndex + delta);
  }, [activeIndex, selectCampaign]);

  const clearPointerIdleTimer = useCallback(() => {
    if (pointerIdleTimerRef.current !== null) {
      window.clearTimeout(pointerIdleTimerRef.current);
      pointerIdleTimerRef.current = null;
    }
  }, []);

  const handleHeroPointerMove = useCallback((event) => {
    if (event.pointerType !== 'mouse') return;
    clearPointerIdleTimer();
    setPointerActive(true);
    pointerIdleTimerRef.current = window.setTimeout(() => {
      setPointerActive(false);
      pointerIdleTimerRef.current = null;
    }, POINTER_IDLE_MS);
  }, [clearPointerIdleTimer]);

  const handleHeroPointerLeave = useCallback(() => {
    clearPointerIdleTimer();
    setPointerActive(false);
  }, [clearPointerIdleTimer]);

  useEffect(() => () => clearPointerIdleTimer(), [clearPointerIdleTimer]);

  useEffect(() => {
    const updateVisibility = () => setPageVisible(!document.hidden);
    updateVisibility();
    document.addEventListener('visibilitychange', updateVisibility);
    return () => document.removeEventListener('visibilitychange', updateVisibility);
  }, []);

  useEffect(() => {
    if (interactionPaused || pointerActive || reduceMotion || !pageVisible) return undefined;
    const interval = window.setInterval(() => {
      setActiveIndex((index) => (index + 1) % HOME_HERO_CAMPAIGN_COUNT);
    }, SLIDE_INTERVAL_MS);
    return () => window.clearInterval(interval);
  }, [interactionPaused, pointerActive, reduceMotion, pageVisible, activeIndex]);

  const handleTabKeyDown = useCallback((event) => {
    let nextIndex = null;

    if (event.key === 'ArrowRight') nextIndex = (activeIndex + 1) % HOME_HERO_CAMPAIGN_COUNT;
    if (event.key === 'ArrowLeft') nextIndex = (activeIndex - 1 + HOME_HERO_CAMPAIGN_COUNT) % HOME_HERO_CAMPAIGN_COUNT;
    if (event.key === 'Home') nextIndex = 0;
    if (event.key === 'End') nextIndex = HOME_HERO_CAMPAIGN_COUNT - 1;

    if (nextIndex === null) return;

    event.preventDefault();
    selectCampaign(nextIndex);
    tabRefs.current[nextIndex]?.focus();
  }, [activeIndex, selectCampaign]);

  // One pointer-gesture implementation for touch, pen and primary mouse drag.
  // Keep vertical page gestures native and never intercept links or controls.
  const handlePointerDown = useCallback((event) => {
    if (event.pointerType === 'touch') return; // Native touch gestures use the fallback below.
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    if (event.target.closest('a, button, input, textarea, select, [contenteditable="true"]')) return;
    if (swipeStartRef.current) return;

    swipeStartRef.current = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    };
    // Pointer capture keeps desktop release reliable after dragging outside
    // the stage; it does not cancel native vertical touch scrolling.
    if (event.pointerType === 'mouse') {
      event.currentTarget.setPointerCapture?.(event.pointerId);
    }
  }, []);

  const handlePointerUp = useCallback((event) => {
    const start = swipeStartRef.current;
    if (!start || start.pointerId !== event.pointerId) return;
    swipeStartRef.current = null;

    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    const deltaX = event.clientX - start.x;
    const deltaY = event.clientY - start.y;

    if (Math.abs(deltaX) < SWIPE_THRESHOLD_PX || Math.abs(deltaX) <= Math.abs(deltaY) * 1.2) return;
    selectRelativeCampaign(deltaX < 0 ? 1 : -1);
  }, [selectRelativeCampaign]);

  const handlePointerCancel = useCallback(() => {
    swipeStartRef.current = null;
  }, []);

  // Touch Events avoid pointercancel when mobile browsers arbitrate native
  // vertical scrolling. Never preventDefault: page scroll remains native.
  const handleTouchStart = useCallback((event) => {
    if (event.touches.length !== 1 ||
      event.target.closest('a, button, input, textarea, select, [contenteditable="true"]')) {
      touchStartRef.current = null;
      return;
    }
    const touch = event.touches[0];
    touchStartRef.current = { x: touch.clientX, y: touch.clientY };
  }, []);

  const handleTouchEnd = useCallback((event) => {
    const start = touchStartRef.current;
    touchStartRef.current = null;
    if (!start || !event.changedTouches.length) return;
    const touch = event.changedTouches[0];
    const dx = touch.clientX - start.x;
    const dy = touch.clientY - start.y;
    if (Math.abs(dx) >= SWIPE_THRESHOLD_PX && Math.abs(dx) > Math.abs(dy) * 1.2) {
      selectRelativeCampaign(dx < 0 ? 1 : -1);
    }
  }, [selectRelativeCampaign]);

  return (
    <section className="home-hero" aria-labelledby="home-hero-title">
      <div
        className="home-hero__stage"
        data-campaign={activeCampaign.id}
        onPointerMove={handleHeroPointerMove}
        onPointerLeave={handleHeroPointerLeave}
        onFocusCapture={() => setInteractionPaused(true)}
        onBlurCapture={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget)) setInteractionPaused(false);
        }}
        onPointerDown={handlePointerDown}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerCancel}
        onTouchStart={handleTouchStart}
        onTouchEnd={handleTouchEnd}
        onTouchCancel={() => { touchStartRef.current = null; }}
      >
        {outgoingCampaign && outgoingCampaign.id !== visibleCampaign.id && (
          <HomeHeroSlide
            key={outgoingCampaign.id}
            campaign={outgoingCampaign}
            reduceMotion={reduceMotion}
            isActive={false}
            isExiting={crossfadeStarted}
          />
        )}
        <HomeHeroSlide
          key={visibleCampaign.id}
          campaign={visibleCampaign}
          reduceMotion={reduceMotion}
          isActive
          isEntering={Boolean(outgoingCampaign) && !crossfadeStarted}
        />

        <nav className="home-hero-carousel" aria-label="Homepage slides">
          <div className="home-hero-carousel__controls" role="group" aria-label="Choose homepage slide"
            data-running={!interactionPaused && !pointerActive && !reduceMotion && pageVisible}
            onMouseEnter={() => setInteractionPaused(true)}
            onMouseLeave={() => setInteractionPaused(false)}>
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

          </div>
        </nav>
      </div>

    </section>
  );
}

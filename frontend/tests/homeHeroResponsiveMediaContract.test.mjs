import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import test from 'node:test';

const repoRoot = new URL('../../', import.meta.url);
const frontendRoot = new URL('frontend/', repoRoot);

const HERO_COMPONENT = new URL('src/components/home/HomeHero.jsx', frontendRoot);
const HERO_CAMPAIGNS = new URL('src/components/home/homeHeroCampaigns.js', frontendRoot);
const HERO_STYLES = new URL('src/styles/home-hero.css', frontendRoot);
const DESKTOP_HERO = new URL('src/assets/media/home/home-hero-desktop-1600.webp', frontendRoot);
const MOBILE_HERO = new URL('src/assets/media/home/home-hero-mobile-640.webp', frontendRoot);

async function readHeroSource() {
  return readFile(HERO_COMPONENT, 'utf8');
}

async function readHeroStyles() {
  return readFile(HERO_STYLES, 'utf8');
}

test('HomeHero preserves browser-native responsive art direction and LCP priority', async () => {
  const source = await readHeroSource();

  assert.match(source, /home-hero-desktop-1600\.webp/);
  assert.match(source, /home-hero-mobile-640\.webp/);
  assert.match(source, /<picture className="home-hero__media" aria-hidden="true">/);
  assert.match(source, /media="\(max-width: 640px\)"/);
  assert.match(source, /srcSet=\{homeHeroMobileUrl\}/);
  assert.match(source, /src=\{homeHeroDesktopUrl\}/);
  assert.match(source, /type="image\/webp"/);
  assert.match(source, /alt=""/);
  assert.match(source, /decoding="async"/);
  assert.match(source, /loading="eager"/);
  assert.match(source, /fetchPriority="high"/);
  assert.match(source, /draggable="false"/);

  assert.doesNotMatch(source, /window\.innerWidth|matchMedia\(|useMediaQuery|isMobile/);
});

test('homepage campaigns only promote customer-ready storefront routes', async () => {
  const [source, styles] = await Promise.all([
    readFile(HERO_CAMPAIGNS, 'utf8'),
    readHeroStyles(),
  ]);

  for (const id of ['tools', 'parts', 'repairs']) {
    assert.match(source, new RegExp(`id: '${id}'`));
  }

  assert.doesNotMatch(source, /id: 'builder'|label: 'Builder'|\/toolset-builder/);

  for (const route of ['/all-products', '/parts', '/schematics', '/repairs/start', '/repairs']) {
    assert.match(source, new RegExp(route.replaceAll('/', '\\/')));
  }

  assert.match(source, /primaryAction: \{ label: 'Shop All', to: '\/all-products' \}/);
  assert.match(source, /secondaryAction: \{ label: 'Shop by Brand', to: '\/products\/brands' \}/);

  assert.match(await readFile(new URL('src/styles/home-hero-desktop-target.css', frontendRoot), 'utf8'), /\.home-hero-carousel__controls/);
  assert.doesNotMatch(source, /^\s*(?:price|inventory|order|payment|checkout)\s*:/im);
});

test('campaign navigation is manual, keyboard accessible, touch navigable, and reduced-motion aware', async () => {
  const source = await readHeroSource();

  assert.match(source, /role="group"/);
  assert.match(source, /home-hero-carousel__dot/);
  assert.match(source, /aria-current=\{index === activeIndex/);
  assert.match(source, /ArrowRight/);
  assert.match(source, /ArrowLeft/);
  assert.match(source, /event\.key === 'Home'/);
  assert.match(source, /event\.key === 'End'/);
  assert.match(source, /SWIPE_THRESHOLD_PX/);
  assert.match(source, /useReducedMotion\(\)/);
  assert.match(source, /AnimatePresence/);

  // Deliberate autoplay with explicit pause controls and accessibility guards.
  assert.match(source, /SLIDE_INTERVAL_MS = 8500/);
  assert.match(source, /window\.setInterval/);
  assert.match(source, /window\.clearInterval/);
  assert.match(source, /visibilitychange/);
  assert.match(source, /reduceMotion/);
  assert.match(source, /aria-pressed=\{paused\}/);
});

test('hero presentation keeps stable media, atmosphere, scrim, content, and navigation layers', async () => {
  const css = await readHeroStyles();

  assert.match(css, /\.home-hero__media\s*\{[\s\S]*?z-index:\s*0;/);
  assert.match(css, /\.home-hero__ambient\s*\{[\s\S]*?z-index:\s*1;/);
  assert.match(css, /\.home-hero__scrim\s*\{[\s\S]*?z-index:\s*2;/);
  assert.match(css, /\.home-hero__content-shell\s*\{[\s\S]*?z-index:\s*3;/);
  assert.match(await readFile(new URL('src/styles/home-hero-desktop-target.css', frontendRoot), 'utf8'), /\.home-hero-carousel\s*\{[\s\S]*?z-index:\s*4;/);

  assert.match(css, /\.home-hero__media-image\s*\{[\s\S]*?object-fit:\s*cover;[\s\S]*?object-position:\s*68% 50%;/);
  assert.match(css, /@media \(min-width: 901px\) and \(max-width: 1199px\)[\s\S]*?object-position:\s*72% 50%;/);
  assert.match(css, /@media \(min-width: 641px\) and \(max-width: 900px\)[\s\S]*?object-position:\s*73% 50%;/);
  assert.match(css, /@media \(max-width: 640px\)[\s\S]*?object-position:\s*64% 50%;/);
});

test('hero styling provides fluid desktop/mobile composition and reduced-motion fallback', async () => {
  const css = await readHeroStyles();

  assert.match(css, /height:\s*max\(32rem, calc\(100dvh - var\(--header-height/);
  assert.match(css, /height:\s*max\(32rem, calc\(100svh - var\(--header-height/);
  assert.match(await readFile(new URL('src/styles/home-hero-desktop-target.css', frontendRoot), 'utf8'), /\.home-hero-carousel__controls\s*\{[\s\S]*?backdrop-filter:\s*blur\(14px\)/);
  assert.match(css, /@media \(max-width: 1024px\)\s*\{\s*\.home-hero__stage/);
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});

test('compressed responsive hero assets remain bounded for above-the-fold delivery', async () => {
  const [desktop, mobile] = await Promise.all([
    stat(DESKTOP_HERO),
    stat(MOBILE_HERO),
  ]);

  assert.ok(desktop.size > 0, 'desktop hero must not be empty');
  assert.ok(mobile.size > 0, 'mobile hero must not be empty');
  assert.ok(desktop.size <= 250_000, `desktop hero is unexpectedly large: ${desktop.size} bytes`);
  assert.ok(mobile.size <= 250_000, `mobile hero is unexpectedly large: ${mobile.size} bytes`);
});


test('desktop hero centers outside Framer Motion transform space', async () => {
  const targetCss = await readFile(new URL('src/styles/home-hero-desktop-target.css', frontendRoot), 'utf8');
  assert.match(targetCss, /Motion-safe desktop composition/);
  assert.match(targetCss, /\.home-hero__content-shell\s*\{\s*display:\s*flex;/);
  assert.match(targetCss, /justify-content:\s*center;/);
  assert.match(targetCss, /\.home-hero__content\s*\{\s*position:\s*relative;/);
  assert.match(targetCss, /top:\s*auto;/);
});

test('autoplay is not blocked by hovering the entire hero and transitions overlap smoothly', async () => {
  const source = await readHeroSource();
  const desktopCss = await readFile(new URL('src/styles/home-hero-desktop-target.css', frontendRoot), 'utf8');
  const motion = await readFile(new URL('src/motion/dtbMotion.js', frontendRoot), 'utf8');
  assert.match(source, /mode="popLayout"/);
  assert.doesNotMatch(source, /data-campaign=\{activeCampaign.id\}\s*onMouseEnter=/);
  assert.match(source, /data-running=\{!paused/);
  assert.match(desktopCss, /home-hero-slide-progress/);
  assert.match(motion, /duration: 0\.72/);
});

test('desktop hover activity pauses carousel only while pointer moves, then resumes after idle', async () => {
  const source = await readHeroSource();
  assert.match(source, /POINTER_IDLE_MS = 3000/);
  assert.match(source, /onPointerMove=\{handleHeroPointerMove\}/);
  assert.match(source, /onPointerLeave=\{handleHeroPointerLeave\}/);
  assert.match(source, /event\.pointerType !== 'mouse'/);
  assert.match(source, /window\.clearTimeout\(pointerIdleTimerRef\.current\)/);
  assert.match(source, /setPointerActive\(false\)/);
  assert.match(source, /interactionPaused \|\| pointerActive \|\| reduceMotion/);
  assert.match(source, /data-running=\{!paused && !interactionPaused && !pointerActive/);
});

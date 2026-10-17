// Canonical JavaScript motion authority for the customer storefront.
// Keep these values numerically aligned with the CSS motion tokens in
// frontend/src/styles/storefront-tokens.css. Components should consume the
// semantic transitions/variants below instead of inventing local easings.

export const dtbEase = {
  standard: [0.22, 1, 0.36, 1],
  emphasized: [0.16, 1, 0.3, 1],
  soft: [0.2, 0.8, 0.2, 1],
  exit: [0.4, 0, 0.2, 1],
  fade: [0.4, 0, 0.2, 1],
};

export const dtbDuration = {
  instant: 0.1,
  fast: 0.18,
  async: 0.22,
  normal: 0.32,
  elevated: 0.36,
  overlay: 0.4,
  slow: 0.44,
};

export const dtbDurationMs = {
  instant: 100,
  fast: 180,
  async: 220,
  normal: 320,
  elevated: 360,
  overlay: 400,
  slow: 440,
};

export const dtbDistance = {
  micro: 4,
  small: 8,
  medium: 12,
  large: 16,
};

// Low-bounce physical response for direct manipulation: tab indicators,
// toggles, drawers and other controls whose geometry is visibly changing.
// Timed route/content reveals intentionally remain deterministic tweens.
export const dtbSpring = {
  responsive: {
    type: 'spring',
    stiffness: 360,
    damping: 34,
    mass: 0.82,
  },
  gentle: {
    type: 'spring',
    stiffness: 300,
    damping: 32,
    mass: 0.9,
  },
};

export const dtbTransition = {
  instant: { duration: dtbDuration.instant, ease: dtbEase.standard },
  fast: { duration: dtbDuration.fast, ease: dtbEase.standard },
  async: { duration: dtbDuration.async, ease: dtbEase.standard },
  standard: { duration: dtbDuration.normal, ease: dtbEase.standard },
  emphasized: { duration: dtbDuration.elevated, ease: dtbEase.emphasized },
  overlay: { duration: dtbDuration.overlay, ease: dtbEase.emphasized },
  slow: { duration: dtbDuration.slow, ease: dtbEase.emphasized },
  exit: { duration: dtbDuration.fast, ease: dtbEase.exit },
};

// MotionConfig's application-wide default. Explicit semantic transitions are
// used where deterministic timing matters; everything else inherits this
// restrained physical response on desktop, tablet and mobile alike.
export const motionConfigTransition = dtbSpring.responsive;

export const routeVariants = {
  initial: {
    // Route-level motion may never hide a fully rendered page. Keeping the
    // surface opaque prevents a white/blank compositor frame between routes.
    opacity: 1,
    y: dtbDistance.micro,
  },
  animate: {
    opacity: 1,
    y: 0,
    transition: dtbTransition.fast,
  },
};

export const reducedRouteVariants = {
  initial: { opacity: 1, y: 0 },
  animate: { opacity: 1, y: 0, transition: { duration: 0.01, ease: 'linear' } },
};

// Generic component/content reveal. Use this for tab panels, empty states,
// async content and other in-page renderer changes so they share the same
// visual grammar as route navigation without replaying a full route motion.
export const contentVariants = {
  hidden: {
    // Content can mount during data resolution or a concurrent presence
    // change. It must never uncover the page behind it for a frame.
    opacity: 1,
    y: dtbDistance.micro,
    scale: 1,
  },
  visible: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: dtbTransition.standard,
  },
  exit: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: dtbTransition.exit,
  },
};

export const reducedContentVariants = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { duration: dtbDuration.instant } },
  initial: { opacity: 0 },
  animate: { opacity: 1, transition: { duration: dtbDuration.instant } },
  exit: { opacity: 0, transition: { duration: dtbDuration.instant } },
};

export const staggerContainerVariants = {
  hidden: {},
  visible: {
    transition: {
      staggerChildren: 0.055,
      delayChildren: 0.02,
    },
  },
};

export const staggerItemVariants = {
  hidden: { opacity: 1, y: dtbDistance.micro },
  visible: {
    opacity: 1,
    y: 0,
    transition: dtbTransition.emphasized,
  },
};

export const surfaceVariants = {
  hidden: { opacity: 1, y: dtbDistance.micro, scale: 1 },
  visible: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: dtbTransition.standard,
  },
  exit: {
    opacity: 1,
    y: 0,
    scale: 1,
    transition: dtbTransition.exit,
  },
};

export const reducedSurfaceVariants = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { duration: dtbDuration.instant } },
  exit: { opacity: 0, transition: { duration: dtbDuration.instant } },
};

export const backdropVariants = {
  hidden: { opacity: 0 },
  visible: { opacity: 1 },
  exit: { opacity: 0 },
};

export const reducedBackdropVariants = backdropVariants;

export const backdropTransition = { duration: dtbDuration.fast, ease: dtbEase.exit };
export const panelTransition = dtbTransition.standard;
export const reducedTransition = { duration: dtbDuration.instant, ease: 'linear' };
export const collapseTransition = dtbTransition.standard;
export const indicatorTransition = dtbSpring.responsive;
export const microInteractionTransition = dtbSpring.responsive;

export const mobileSheetTransition = dtbSpring.gentle;

export const mobileSheetVariants = {
  hidden: { opacity: 0, y: '10%', scale: 0.994 },
  visible: { opacity: 1, y: 0, scale: 1 },
  exit: { opacity: 0, y: '7%', scale: 0.996 },
};

export const productModalTransition = {
  type: 'tween',
  ...dtbTransition.overlay,
};

export const productModalBackdropTransition = {
  duration: dtbDuration.fast,
  ease: dtbEase.exit,
};

// Desktop Quick View covers a large portion of the viewport. Keep its outer
// dialog surface geometrically stable and animate opacity only; translating or
// scaling the viewport-sized scroll shell causes large compositor/raster
// changes and can flash on open/close.
export const productModalDesktopVariants = {
  hidden: { opacity: 1 },
  visible: { opacity: 1 },
  exit: { opacity: 1 },
};

// The desktop scroll shell is structural. The bounded card owns the visible
// presentation motion so the storefront is never seen through a translucent
// white dialog during close.
export const productModalDesktopCardVariants = {
  hidden: { opacity: 0, y: dtbDistance.micro },
  visible: {
    opacity: 1,
    y: 0,
    transition: dtbTransition.fast,
  },
  exit: {
    opacity: 1,
    y: dtbDistance.micro,
    transition: dtbTransition.fast,
  },
};

export const reducedProductModalCardVariants = {
  hidden: { opacity: 0, y: 0 },
  visible: { opacity: 1, y: 0, transition: reducedTransition },
  exit: { opacity: 1, y: 0, transition: reducedTransition },
};

export const productModalMobileVariants = {
  hidden: { opacity: 0, y: '12%', scale: 0.994 },
  visible: { opacity: 1, y: 0, scale: 1 },
  exit: {
    opacity: 0,
    y: '8%',
    scale: 0.996,
    transition: dtbTransition.exit,
  },
};


// Homepage campaign hero motion. The stage itself stays geometrically stable;
// only bounded content inside the stage moves. This avoids full-viewport
// compositor shifts while still giving manual campaign changes clear direction.
export const homeHeroContentVariants = {
  enter: (direction = 1) => ({
    opacity: 0,
    x: direction >= 0 ? 20 : -20,
    filter: 'blur(3px)',
  }),
  center: {
    opacity: 1,
    x: 0,
    filter: 'blur(0px)',
    transition: {
      duration: 0.72,
      ease: [0.22, 1, 0.36, 1],
      staggerChildren: 0.035,
    },
  },
  exit: (direction = 1) => ({
    opacity: 0,
    x: direction >= 0 ? -16 : 16,
    filter: 'blur(2px)',
    transition: { duration: 0.5, ease: [0.4, 0, 1, 1] },
  }),
};

export const homeHeroItemVariants = {
  enter: { opacity: 0, y: 8 },
  center: { opacity: 1, y: 0, transition: { duration: 0.55, ease: [0.22, 1, 0.36, 1] } },
  exit: { opacity: 0, y: -4, transition: { duration: 0.35 } },
};

export const reducedHomeHeroContentVariants = {
  enter: { opacity: 0, x: 0 },
  center: { opacity: 1, x: 0, transition: reducedTransition },
  exit: { opacity: 0, x: 0, transition: reducedTransition },
};

export const reducedHomeHeroItemVariants = {
  enter: { opacity: 0, y: 0 },
  center: { opacity: 1, y: 0, transition: reducedTransition },
  exit: { opacity: 0, y: 0, transition: reducedTransition },
};

export const homeHeroNavigationTransition = dtbSpring.gentle;

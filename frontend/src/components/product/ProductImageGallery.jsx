import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AnimatePresence, m as Motion, useReducedMotion } from 'framer-motion';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { PLACEHOLDER_IMAGE } from '../../constants/images.js';
import { apiClient } from '../../api/client.js';
import '../../styles/product-image-gallery-refinements.css';
import { dtbEase, dtbTransition } from '../../motion/dtbMotion.js';

const LIGHTBOX_Z_INDEX = 10010;
const parentGalleryCache = new Map();

const slideVariants = {
  enter: (direction) => ({ x: direction >= 0 ? '3.5%' : '-3.5%', opacity: 0 }),
  center: { x: 0, opacity: 1 },
  exit: (direction) => ({ x: direction >= 0 ? '-2.5%' : '2.5%', opacity: 0 }),
};

const slideTransition = {
  duration: dtbTransition.emphasized.duration,
  ease: dtbEase.standard,
};

// Used when the image set changes because a different variation/product was
// selected (direction resets to 0), not because the shopper paged through the
// gallery. A plain crossfade — no x-transform — avoids both the unwanted
// "slide in from the right" motion and the transform+overflow-hidden
// compositing flash that could otherwise expose an unpainted frame under the
// moving layer.
const fadeVariants = {
  enter: { opacity: 0 },
  center: { opacity: 1 },
  exit: { opacity: 0 },
};

const fadeTransition = dtbTransition.async;

const LB_NAV_BTN_CLASS = 'product-image-gallery__lb-btn product-image-gallery__lb-btn--nav absolute top-1/2 -translate-y-1/2 z-10 flex items-center justify-center w-11 h-11';

function normalizeImageMeta(rawImage, product = {}, index = 0) {
  if (!rawImage) return null;

  if (typeof rawImage === 'string') {
    const src = rawImage.trim();
    if (!src) return null;
    return {
      src,
      srcSet: index === 0 ? (product?.image_srcset || '') : '',
      sizes: index === 0 ? (product?.image_sizes || '') : '',
    };
  }

  const src = String(rawImage.src || rawImage.url || rawImage.full || rawImage.large || '').trim();
  if (!src) return null;

  return {
    src,
    srcSet: rawImage.srcset || rawImage.srcSet || '',
    sizes: rawImage.sizes || '',
  };
}

function imageIdentity(src = '') {
  return String(src || '')
    .split('?')[0]
    .replace(/\/+$/, '')
    .toLowerCase();
}

function pushUniqueImage(out, seen, imageMeta) {
  if (!imageMeta?.src) return;
  const key = imageIdentity(imageMeta.src);
  if (!key || seen.has(key)) return;
  seen.add(key);
  out.push(imageMeta);
}

function collectDefaultImageMeta(product = {}) {
  const out = [];
  const seen = new Set();

  const push = (image, index = out.length) => pushUniqueImage(out, seen, normalizeImageMeta(image, product, index));
  const pushArray = (images = []) => {
    if (!Array.isArray(images)) return;
    images.forEach((image) => push(image));
  };

  // The WooCommerce featured image is the canonical first gallery image.
  // Gallery arrays are appended afterward and deduplicated by normalized URL.
  push(product?.media?.image);
  push(product?.image);
  pushArray(product?.media?.images);
  pushArray(product?.images);

  return out;
}

function collectExplicitVariationImages(product = {}) {
  const explicitSets = [
    product?.variation_images,
    product?.variationImages,
    product?.variation_gallery_images,
    product?.variationGalleryImages,
    product?.media?.variation_images,
    product?.media?.variationImages,
  ];

  const out = [];
  const seen = new Set();
  explicitSets.forEach((images) => {
    if (!Array.isArray(images)) return;
    images.forEach((image) => pushUniqueImage(out, seen, normalizeImageMeta(image, product, out.length)));
  });

  return out;
}

function normalizeToken(value = '') {
  return String(value || '')
    .toLowerCase()
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '')
    .trim();
}

function normalizeWords(value = '') {
  return String(value || '')
    .toLowerCase()
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/&/g, ' and ')
    .replace(/[^a-z0-9]+/g, ' ')
    .split(/\s+/)
    .map((word) => word.trim())
    .filter((word) => word.length >= 4);
}

function addToken(tokens, value) {
  const normalized = normalizeToken(value);
  if (normalized && normalized.length >= 3) tokens.add(normalized);
}

function addWords(tokens, value) {
  normalizeWords(value).forEach((word) => addToken(tokens, word));
}

function variationImageTokens(product = {}) {
  const tokens = new Set();
  const sku = product?.sku || product?.part_number || product?.partNumber || '';

  // SKU-based tokens — most reliable discriminator.
  addToken(tokens, sku);
  addToken(tokens, String(sku).replace(/-/g, '_'));
  addToken(tokens, String(sku).replace(/-/g, ''));

  // Variation label (e.g. "Carbon Fiber", "3.5\"") — but NOT product name words.
  // Adding product name words caused "TapeTech", "EasyClean", "Taper", etc. to
  // match every image in the parent gallery, producing the "1/15" bloat.
  const variationLabel = product?.variation_label || product?.variationLabel
    || product?.variation?.label || product?.variation?.value || '';
  addWords(tokens, variationLabel);

  // Variation axis attribute values only (e.g. "Carbon Fiber", "Mini-Taper").
  // Explicitly skip attribute names (e.g. "Model", "Size") which are too generic.
  if (Array.isArray(product?.variation_attribute_values)) {
    product.variation_attribute_values.forEach((entry) => {
      addWords(tokens, entry?.option || entry?.value || '');
    });
  }
  if (Array.isArray(product?.attributes)) {
    product.attributes.forEach((entry) => {
      // Only use the option/value, never the attribute name key.
      addWords(tokens, entry?.option || entry?.value || '');
    });
  }

  return Array.from(tokens).filter(Boolean);
}

function imageMatchesVariation(imageMeta, tokens = []) {
  if (!imageMeta?.src || tokens.length === 0) return false;
  const srcKey = normalizeToken(decodeURIComponent(imageMeta.src.split('/').pop() || imageMeta.src));
  return tokens.some((token) => token.length >= 4 && srcKey.includes(token));
}

function collectVariationImageMeta(product = {}) {
  const out = [];
  const seen = new Set();
  const push = (image) => pushUniqueImage(out, seen, normalizeImageMeta(image, product, out.length));
  const pushPrimary = () => {
    push(product?.media?.image);
    push(product?.image);
  };

  // Path 1 — explicit variation gallery from the backend (highest priority).
  // The variation primary image still remains image zero even when an explicit
  // gallery is present, matching WooCommerce's featured-image contract.
  const explicitVariationImages = collectExplicitVariationImages(product);
  if (explicitVariationImages.length > 0) {
    pushPrimary();
    explicitVariationImages.forEach((image) => pushUniqueImage(out, seen, image));
    return out;
  }

  // Path 2 — WooCommerce persisted images[] on the variation object itself.
  // Keep the primary image first, then append the persisted gallery.
  if (Array.isArray(product?.images) && product.images.length > 0) {
    pushPrimary();
    product.images.forEach((image) => push(image));
    if (out.length > 0) return out;
  }
  if (Array.isArray(product?.media?.images) && product.media.images.length > 0) {
    pushPrimary();
    product.media.images.forEach((image) => push(image));
    if (out.length > 0) return out;
  }

  // Path 3 — Token-based fallback against the merged candidate pool.
  // Only reached when the variation has no explicit gallery AND no WC images[].
  // Tokens are SKU + variation label/attribute values only (NOT product name words).
  const candidates = [];
  const candidateSeen = new Set();
  const pushCandidate = (image) => pushUniqueImage(candidates, candidateSeen, normalizeImageMeta(image, product, candidates.length));

  // Add the variation's own primary image first, then the parent pool for matching.
  pushPrimary();
  pushCandidate(product?.media?.image);
  pushCandidate(product?.image);

  const tokens = variationImageTokens(product);
  if (tokens.length > 0) {
    candidates.forEach((image) => {
      if (imageMatchesVariation(image, tokens)) {
        pushUniqueImage(out, seen, image);
      }
    });

    return out;
  }

  // Path 4 — absolute fallback: just the primary image.
  return out;
}

function collectRawWooImageMeta(rawProduct = {}) {
  const out = [];
  const seen = new Set();
  const images = Array.isArray(rawProduct?.images) ? rawProduct.images : [];
  images.forEach((image, index) => pushUniqueImage(out, seen, normalizeImageMeta(image, rawProduct, index)));
  return out;
}

async function fetchParentGallery(parentId) {
  const normalizedParentId = Number(parentId || 0);
  if (!normalizedParentId) return [];

  if (parentGalleryCache.has(normalizedParentId)) {
    return parentGalleryCache.get(normalizedParentId);
  }

  const request = apiClient(`/wp-json/drywall/v1/products/${encodeURIComponent(normalizedParentId)}`)
    .then((parentProduct) => collectRawWooImageMeta(parentProduct))
    .catch(() => []);
  parentGalleryCache.set(normalizedParentId, request);
  return request;
}

function mergeImageSets(...sets) {
  const out = [];
  const seen = new Set();
  sets.flat().forEach((imageMeta) => pushUniqueImage(out, seen, imageMeta));
  return out.length > 0 ? out : [{ src: PLACEHOLDER_IMAGE, srcSet: '', sizes: '' }];
}

export default function ProductImageGallery({ product }) {
  const reduceMotion = useReducedMotion();
  const [currentIndex, setCurrentIndex] = useState(0);
  const [direction, setDirection] = useState(0);
  const [imgLoaded, setImgLoaded] = useState({});
  const [lightbox, setLightbox] = useState({ open: false, index: 0, dir: 0 });
  const [parentImageMeta, setParentImageMeta] = useState([]);
  const [thumbRailState, setThumbRailState] = useState({ canScrollBack: false, canScrollForward: false });

  const thumbsRef = useRef(null);
  const galleryRef = useRef(null);
  const currentIndexRef = useRef(currentIndex);
  const lightboxRef = useRef(lightbox);
  const imagesRef = useRef([]);
  const touchStartX = useRef(null);
  const touchStartY = useRef(null);
  const isDragging = useRef(false);
  const lbTouchStartX = useRef(null);
  const lbTouchStartTime = useRef(null);
  const lbCloseBtnRef = useRef(null);
  const lbPrevFocusRef = useRef(null);

  const parentId = product?.parent_id || product?.parentId;
  const isVariationProduct = Boolean(parentId);
  const baseImageMeta = useMemo(
    () => (isVariationProduct ? collectVariationImageMeta(product) : collectDefaultImageMeta(product)),
    [product, isVariationProduct]
  );
  const imageMeta = useMemo(
    () => mergeImageSets(baseImageMeta, isVariationProduct ? [] : parentImageMeta),
    [baseImageMeta, isVariationProduct, parentImageMeta]
  );
  const images = useMemo(() => imageMeta.map((image) => image.src), [imageMeta]);
  const imageSetKey = useMemo(() => images.map((image) => imageIdentity(image)).join('|'), [images]);
  const resetKey = `${product?.id ?? ''}|${product?.sku ?? ''}|${parentId ?? ''}`;
  const stableGalleryRef = useRef({ resetKey, images: imageMeta });
  const stableGalleryHasOnlyPlaceholder = (
    stableGalleryRef.current.images.length === 1
    && imageIdentity(stableGalleryRef.current.images[0]?.src) === imageIdentity(PLACEHOLDER_IMAGE)
  );
  const nextGalleryHasRealImage = (
    imageMeta.length > 0
    && imageIdentity(imageMeta[0]?.src) !== imageIdentity(PLACEHOLDER_IMAGE)
  );

  if (stableGalleryRef.current.resetKey !== resetKey) {
    stableGalleryRef.current = { resetKey, images: imageMeta };
  } else if (stableGalleryHasOnlyPlaceholder && nextGalleryHasRealImage) {
    // A placeholder is not a valid painted media contract. Permit the first
    // real image to replace it, then freeze the gallery for this identity.
    stableGalleryRef.current = { resetKey, images: imageMeta };
  }

  const stableImageMeta = stableGalleryRef.current.images;

  const stableImages = useMemo(
    () => stableImageMeta.map((image) => image.src),
    [stableImageMeta],
  );

  const [lastResetKey, setLastResetKey] = useState(resetKey);

  if (resetKey !== lastResetKey) {
    setLastResetKey(resetKey);
    setCurrentIndex(0);
    setDirection(0);
    setImgLoaded({});
    setLightbox({ open: false, index: 0, dir: 0 });
    setParentImageMeta([]);
  }

  const hasMultiple = stableImages.length > 1;
  const activeIndex = stableImages.length > 0 ? Math.min(currentIndex, stableImages.length - 1) : 0;
  const activeLightboxIndex = stableImages.length > 0 ? Math.min(lightbox.index, stableImages.length - 1) : 0;

  const updateThumbRailState = useCallback(() => {
    const rail = thumbsRef.current;
    if (!rail) return;

    const maxScrollLeft = Math.max(0, rail.scrollWidth - rail.clientWidth);
    const nextState = {
      canScrollBack: rail.scrollLeft > 2,
      canScrollForward: rail.scrollLeft < maxScrollLeft - 2,
    };
    setThumbRailState((state) => (
      state.canScrollBack === nextState.canScrollBack
      && state.canScrollForward === nextState.canScrollForward
        ? state
        : nextState
    ));
  }, []);

  useEffect(() => {
    if (isVariationProduct || !parentId || baseImageMeta.length > 1) return undefined;

    let cancelled = false;
    fetchParentGallery(parentId).then((parentImages) => {
      if (!cancelled) setParentImageMeta(parentImages);
    });

    return () => { cancelled = true; };
  }, [baseImageMeta.length, isVariationProduct, parentId]);

  // Initialize every new gallery at the featured image. Previously the rail
  // only received a scroll operation after goTo(), which made thumbnails appear
  // only after the shopper clicked an arrow on some browsers/layout passes.
  useLayoutEffect(() => {
    const rail = thumbsRef.current;
    if (!rail || stableImages.length <= 1) return;
    rail.scrollTop = 0;
    rail.scrollLeft = 0;
    updateThumbRailState();
  }, [resetKey, stableImages.length, updateThumbRailState]);

  useEffect(() => {
    const rail = thumbsRef.current;
    if (!rail || stableImages.length <= 1) return undefined;

    const handleRailChange = () => updateThumbRailState();
    rail.addEventListener('scroll', handleRailChange, { passive: true });

    const resizeObserver = typeof ResizeObserver === 'undefined'
      ? null
      : new ResizeObserver(handleRailChange);
    resizeObserver?.observe(rail);
    Array.from(rail.children).forEach((child) => resizeObserver?.observe(child));
    handleRailChange();

    return () => {
      rail.removeEventListener('scroll', handleRailChange);
      resizeObserver?.disconnect();
    };
  }, [resetKey, stableImages.length, updateThumbRailState]);

  const scrollThumb = useCallback((index) => {
    thumbsRef.current?.children[index]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }, []);

  const scrollThumbRail = useCallback((railDirection) => {
    const rail = thumbsRef.current;
    if (!rail) return;

    const visibleWidth = rail.clientWidth;
    if (visibleWidth <= 0) return;

    const step = Math.max(160, Math.round(visibleWidth * 0.72));
    rail.scrollBy({
      left: railDirection * step,
      behavior: 'smooth',
    });
  }, []);

  const goTo = useCallback((index, nextDirection) => {
    setDirection(nextDirection);
    setCurrentIndex(index);
    scrollThumb(index);
  }, [scrollThumb]);

  const prev = useCallback(() => {
    const length = imagesRef.current.length;
    if (length <= 1) return;
    goTo((currentIndexRef.current - 1 + length) % length, -1);
  }, [goTo]);

  const next = useCallback(() => {
    const length = imagesRef.current.length;
    if (length <= 1) return;
    goTo((currentIndexRef.current + 1) % length, 1);
  }, [goTo]);

  const openLightbox = useCallback((index) => {
    lbPrevFocusRef.current = document.activeElement;
    setLightbox({ open: true, index, dir: 0 });
  }, []);

  const closeLightbox = useCallback(() => {
    setLightbox((state) => ({ ...state, open: false }));
    setTimeout(() => {
      if (lbPrevFocusRef.current && typeof lbPrevFocusRef.current.focus === 'function') {
        lbPrevFocusRef.current.focus({ preventScroll: true });
      }
      lbPrevFocusRef.current = null;
    }, 280);
  }, []);

  const lightboxPrev = useCallback(() => {
    const length = imagesRef.current.length;
    if (length <= 1) return;
    setLightbox((state) => ({ ...state, dir: -1, index: (state.index - 1 + length) % length }));
  }, []);

  const lightboxNext = useCallback(() => {
    const length = imagesRef.current.length;
    if (length <= 1) return;
    setLightbox((state) => ({ ...state, dir: 1, index: (state.index + 1) % length }));
  }, []);

  useEffect(() => {
    currentIndexRef.current = activeIndex;
    lightboxRef.current = lightbox;
    imagesRef.current = stableImages;
  }, [activeIndex, lightbox, stableImages]);

  useEffect(() => {
    const handler = (event) => {
      const tag = document.activeElement?.tagName?.toLowerCase();
      if (['input', 'textarea', 'select'].includes(tag)) return;

      const activeLightbox = lightboxRef.current;
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        activeLightbox.open ? lightboxPrev() : prev();
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        activeLightbox.open ? lightboxNext() : next();
      } else if (event.key === 'Escape' && activeLightbox.open) {
        event.stopImmediatePropagation();
        closeLightbox();
      }
    };

    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [closeLightbox, lightboxNext, lightboxPrev, next, prev]);

  useEffect(() => {
    if (!lightbox.open) return undefined;
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    const previousOverflow = document.body.style.overflow;
    const previousPaddingRight = document.body.style.paddingRight;
    document.body.style.overflow = 'hidden';
    if (scrollbarWidth > 0) document.body.style.paddingRight = `${scrollbarWidth}px`;

    return () => {
      document.body.style.overflow = previousOverflow;
      document.body.style.paddingRight = previousPaddingRight;
    };
  }, [lightbox.open]);

  useEffect(() => {
    if (lightbox.open) lbCloseBtnRef.current?.focus({ preventScroll: true });
  }, [lightbox.open]);

  useEffect(() => {
    if (stableImages.length <= 1) return;
    const length = stableImages.length;
    [stableImages[(activeIndex + 1) % length], stableImages[(activeIndex - 1 + length) % length]].forEach((src) => {
      if (!src) return;
      const image = new Image();
      image.src = src;
    });
  }, [activeIndex, stableImages]);

  useEffect(() => {
    const element = galleryRef.current;
    if (!element) return undefined;

    const onMove = (event) => {
      if (touchStartX.current === null) return;
      const deltaX = Math.abs(event.touches[0].clientX - touchStartX.current);
      const deltaY = Math.abs(event.touches[0].clientY - (touchStartY.current ?? 0));
      if (deltaX > deltaY && deltaX > 6) {
        event.preventDefault();
        isDragging.current = true;
      }
    };

    element.addEventListener('touchmove', onMove, { passive: false });
    return () => element.removeEventListener('touchmove', onMove);
  }, []);

  const onTouchStart = (event) => {
    touchStartX.current = event.touches[0].clientX;
    touchStartY.current = event.touches[0].clientY;
    isDragging.current = false;
  };

  const onTouchEnd = (event) => {
    if (touchStartX.current === null) return;
    const diff = touchStartX.current - event.changedTouches[0].clientX;
    if (Math.abs(diff) > 40) diff > 0 ? next() : prev();
    touchStartX.current = null;
  };

  const onGalleryClick = () => {
    if (!isDragging.current) openLightbox(currentIndexRef.current);
  };

  const onLightboxTouchStart = (event) => {
    lbTouchStartX.current = event.touches[0].clientX;
    lbTouchStartTime.current = Date.now();
  };

  const onLightboxTouchEnd = (event) => {
    if (lbTouchStartX.current === null || lbTouchStartTime.current === null) return;
    const diff = lbTouchStartX.current - event.changedTouches[0].clientX;
    const elapsed = Date.now() - lbTouchStartTime.current;
    const velocity = Math.abs(diff) / Math.max(elapsed, 1);
    if (Math.abs(diff) > 40 || (velocity > 0.3 && Math.abs(diff) > 10)) {
      diff > 0 ? lightboxNext() : lightboxPrev();
    }
    lbTouchStartX.current = null;
    lbTouchStartTime.current = null;
  };

  const onThumbPrev = (event) => {
    event.preventDefault();
    event.stopPropagation();
    scrollThumbRail(-1);
  };

  const onThumbNext = (event) => {
    event.preventDefault();
    event.stopPropagation();
    scrollThumbRail(1);
  };

  const activeMeta = stableImageMeta[activeIndex] || stableImageMeta[0];
  const activeLightboxMeta = stableImageMeta[activeLightboxIndex] || stableImageMeta[0];
  const activeImageKey = imageIdentity(stableImages[activeIndex]);

  // A variation can retain image index zero while its source changes. Keeping
  // load state by index made the replacement inherit the previous image's
  // loaded state, then briefly expose an unpainted layer during the transition.
  const isActiveImageLoaded = Boolean(imgLoaded[activeImageKey]);
  const galleryVariants = direction === 0 ? fadeVariants : slideVariants;
  const galleryTransition = reduceMotion
    ? { duration: 0.01, ease: 'linear' }
    : (direction === 0 ? fadeTransition : slideTransition);

  return (
    <>
      <div className="flex flex-col gap-3">
        <div
          ref={galleryRef}
          className="product-image-gallery__main relative w-full overflow-hidden group cursor-zoom-in select-none"
          onClick={onGalleryClick}
          onTouchStart={onTouchStart}
          onTouchEnd={onTouchEnd}
          role="button"
          tabIndex={0}
          aria-label="Tap to view fullscreen"
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              openLightbox(activeIndex);
            }
          }}
        >
          <AnimatePresence>
            {!isActiveImageLoaded && (
              <Motion.div
                key={`skeleton-${activeImageKey}`}
                className="product-image-gallery__skeleton absolute inset-0"
                style={{ zIndex: 3, pointerEvents: 'none' }}
                initial={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={reduceMotion ? { duration: 0 } : dtbTransition.async}
              />
            )}
          </AnimatePresence>

          <AnimatePresence initial={false} custom={direction} mode="sync">
            <Motion.img
              key={`${activeIndex}-${stableImages[activeIndex]}`}
              src={stableImages[activeIndex]}
              srcSet={activeMeta?.srcSet || undefined}
              sizes={activeMeta?.sizes || '(max-width: 767px) 92vw, 48vw'}
              alt={`${product?.name || 'Product'} — image ${activeIndex + 1} of ${stableImages.length}`}
              custom={direction}
              variants={galleryVariants}
              // Do not animate an undecoded image from transparent: the media bed
              // remains painted until loading has finished, including cache hits.
              initial={isActiveImageLoaded ? 'enter' : false}
              animate="center"
              exit="exit"
              transition={galleryTransition}
              // The active image is always immediately visible; only offscreen
              // thumbnails/adjacent preloads should use deferred loading.
              loading="eager"
              fetchPriority={activeIndex === 0 ? 'high' : undefined}
              decoding="async"
              draggable={false}
              className="product-image-gallery__image absolute inset-0 w-full h-full object-contain p-3 sm:p-4 bg-white"
              style={{ zIndex: 2 }}
              onLoad={() => setImgLoaded((state) => ({ ...state, [activeImageKey]: true }))}
              onError={(event) => {
                event.currentTarget.onerror = null;
                event.currentTarget.src = PLACEHOLDER_IMAGE;
                setImgLoaded((state) => ({ ...state, [activeImageKey]: true }));
              }}
            />
          </AnimatePresence>

          {hasMultiple && (
            <>
              <button
                type="button"
                onClick={(event) => { event.stopPropagation(); prev(); }}
                className="product-image-gallery__nav-btn absolute left-2.5 top-1/2 -translate-y-1/2 z-10 flex items-center justify-center w-9 h-9"
                aria-label="Previous image"
              >
                <ChevronLeft size={17} />
              </button>
              <button
                type="button"
                onClick={(event) => { event.stopPropagation(); next(); }}
                className="product-image-gallery__nav-btn absolute right-2.5 top-1/2 -translate-y-1/2 z-10 flex items-center justify-center w-9 h-9"
                aria-label="Next image"
              >
                <ChevronRight size={17} />
              </button>

              <div className="product-image-gallery__counter absolute bottom-3 right-3 z-10 flex items-center pointer-events-none">
                {String(activeIndex + 1).padStart(2, '0')} <span aria-hidden="true">/</span> {String(stableImages.length).padStart(2, '0')}
              </div>

              {stableImages.length <= 8 && (
                <div className="absolute bottom-3 left-3 right-16 flex items-center gap-1.5 z-10 pointer-events-none">
                  {stableImages.map((_, index) => (
                    <span
                      key={index}
                      className={`product-image-gallery__dot ${index === activeIndex ? 'product-image-gallery__dot--active' : ''}`}
                    />
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        {hasMultiple && (
          <div className="product-image-gallery__thumb-shell relative py-1">
            <button
              type="button"
              onClick={onThumbPrev}
              className="product-image-gallery__thumb-nav product-image-gallery__thumb-nav--prev hidden md:flex absolute left-1 top-1/2 z-10 h-9 w-9 -translate-y-1/2 items-center justify-center"
              aria-label="Scroll thumbnail list backward"
              disabled={!thumbRailState.canScrollBack}
            >
              <ChevronLeft size={17} strokeWidth={2.5} />
            </button>

            <div ref={thumbsRef} className="product-image-gallery__thumbs flex gap-2 overflow-x-auto md:px-10" style={{ scrollbarWidth: 'none' }} aria-label="Product image thumbnails">
              {stableImages.map((image, index) => (
                <button
                  key={`${image}-${index}`}
                  type="button"
                  onClick={() => goTo(index, index > activeIndex ? 1 : -1)}
                  aria-label={`View image ${index + 1}`}
                  aria-current={index === activeIndex ? 'true' : undefined}
                  className={`product-image-gallery__thumb shrink-0 w-16 h-16 overflow-hidden ${index === activeIndex ? 'product-image-gallery__thumb--active' : ''}`}
                >
                  <img
                    src={image}
                    alt={`Thumbnail ${index + 1}`}
                    width={64}
                    height={64}
                    loading={index < 8 ? 'eager' : 'lazy'}
                    decoding="async"
                    className="w-full h-full object-contain bg-white p-1"
                    onError={(event) => { event.currentTarget.onerror = null; event.currentTarget.src = PLACEHOLDER_IMAGE; }}
                  />
                </button>
              ))}
              {stableImages.length > 4 ? (
                <button
                  type="button"
                  className="product-image-gallery__mobile-more"
                  onClick={() => openLightbox(4)}
                  aria-label={`View ${stableImages.length - 4} more product ${stableImages.length - 4 === 1 ? 'image' : 'images'}`}
                >
                  +{stableImages.length - 4}
                </button>
              ) : null}
            </div>

            <button
              type="button"
              onClick={onThumbNext}
              className="product-image-gallery__thumb-nav product-image-gallery__thumb-nav--next hidden md:flex absolute right-1 top-1/2 z-10 h-9 w-9 -translate-y-1/2 items-center justify-center"
              aria-label="Scroll thumbnail list forward"
              disabled={!thumbRailState.canScrollForward}
            >
              <ChevronRight size={17} strokeWidth={2.5} />
            </button>
          </div>
        )}
      </div>

      {typeof document !== 'undefined' && createPortal(
        <AnimatePresence>
          {lightbox.open && (
            <Motion.div
              className="fixed inset-0 flex items-center justify-center"
              style={{ zIndex: LIGHTBOX_Z_INDEX }}
              role="dialog"
              aria-modal="true"
              aria-label={`Full-screen image — ${product?.name || 'Product'}`}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.22 }}
            >
              <div className="absolute inset-0 bg-black/95 backdrop-blur-sm" onClick={closeLightbox} aria-hidden="true" />
              <Motion.div
                className="relative flex items-center justify-center w-full h-full"
                initial={{ scale: 0.92, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                exit={{ scale: 0.88, opacity: 0 }}
                transition={{ type: 'spring', stiffness: 380, damping: 40, mass: 0.85 }}
                style={{ willChange: 'transform, opacity' }}
                onTouchStart={onLightboxTouchStart}
                onTouchEnd={onLightboxTouchEnd}
              >
                <AnimatePresence initial={false} custom={lightbox.dir}>
                  <Motion.img
                    key={`${activeLightboxIndex}-${stableImages[activeLightboxIndex]}`}
                    src={stableImages[activeLightboxIndex]}
                    srcSet={activeLightboxMeta?.srcSet || undefined}
                    sizes={activeLightboxMeta?.sizes || '100vw'}
                    alt={`${product?.name || 'Product'} — image ${activeLightboxIndex + 1} of ${stableImages.length}`}
                    custom={lightbox.dir}
                    variants={slideVariants}
                    initial="enter"
                    animate="center"
                    exit="exit"
                    transition={reduceMotion ? { duration: 0.01, ease: 'linear' } : slideTransition}
                    className="max-w-[90vw] max-h-[78vh] w-auto h-auto object-contain select-none"
                    draggable={false}
                    style={{ pointerEvents: 'none', backfaceVisibility: 'hidden', WebkitBackfaceVisibility: 'hidden' }}
                  />
                </AnimatePresence>

                <button ref={lbCloseBtnRef} type="button" onClick={closeLightbox} className="product-image-gallery__lb-btn absolute top-4 right-4 z-10 flex items-center justify-center w-11 h-11" aria-label="Close full-screen image">
                  <X size={22} />
                </button>

                {hasMultiple && (
                  <>
                    <button type="button" onClick={lightboxPrev} className={`${LB_NAV_BTN_CLASS} left-4`} aria-label="Previous image">
                      <ChevronLeft size={26} />
                    </button>
                    <button type="button" onClick={lightboxNext} className={`${LB_NAV_BTN_CLASS} right-4`} aria-label="Next image">
                      <ChevronRight size={26} />
                    </button>
                    <div className="product-image-gallery__lb-counter absolute bottom-4 left-1/2 -translate-x-1/2">
                      {activeLightboxIndex + 1} / {stableImages.length}
                    </div>
                  </>
                )}
              </Motion.div>
            </Motion.div>
          )}
        </AnimatePresence>,
        document.body,
      )}
    </>
  );
}

/**
 * ProductCardImage
 *
 * Updated: robust image resolution across inconsistent product shapes.
 * Accepts either `src` OR full `product` object.
 */
import { useState, useMemo, useEffect, useRef } from 'react';
import { PLACEHOLDER_IMAGE } from '../../constants/images.js';

const PLACEHOLDER = PLACEHOLDER_IMAGE;

function chooseSafeMobileCardFit(image, fallback = 'contain') {
  const safeFallback = { fit: fallback, scale: 1 };
  if (fallback !== 'contain' || !image?.naturalWidth || !image?.naturalHeight || typeof window === 'undefined') return safeFallback;

  try {
    const source = new URL(image.currentSrc || image.src, window.location.href);
    if (source.origin !== window.location.origin) return safeFallback;

    const sampleScale = Math.min(1, 64 / Math.max(image.naturalWidth, image.naturalHeight));
    const sampleWidth = Math.max(1, Math.round(image.naturalWidth * sampleScale));
    const sampleHeight = Math.max(1, Math.round(image.naturalHeight * sampleScale));
    const canvas = document.createElement('canvas');
    canvas.width = sampleWidth;
    canvas.height = sampleHeight;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return safeFallback;

    context.fillStyle = '#fff';
    context.fillRect(0, 0, sampleWidth, sampleHeight);
    context.drawImage(image, 0, 0, sampleWidth, sampleHeight);
    const pixels = context.getImageData(0, 0, sampleWidth, sampleHeight).data;
    let left = sampleWidth;
    let top = sampleHeight;
    let right = -1;
    let bottom = -1;

    // Treat non-white product pixels and their soft edges as foreground. A
    // small, bounded sample keeps this check cheap for mobile card grids.
    for (let y = 0; y < sampleHeight; y += 1) {
      for (let x = 0; x < sampleWidth; x += 1) {
        const offset = (y * sampleWidth + x) * 4;
        const brightness = pixels[offset] + pixels[offset + 1] + pixels[offset + 2];
        if (brightness >= 3 * 190) continue;
        left = Math.min(left, x);
        top = Math.min(top, y);
        right = Math.max(right, x + 1);
        bottom = Math.max(bottom, y + 1);
      }
    }

    if (right <= left || bottom <= top) return safeFallback;

    const widthScale = image.naturalWidth / sampleWidth;
    const heightScale = image.naturalHeight / sampleHeight;
    const subject = {
      left: left * widthScale,
      top: top * heightScale,
      right: right * widthScale,
      bottom: bottom * heightScale,
    };
    const subjectWidth = subject.right - subject.left;
    const subjectHeight = subject.bottom - subject.top;
    const frameAspect = 1.16;
    const imageAspect = image.naturalWidth / image.naturalHeight;
    let visible = { left: 0, top: 0, right: image.naturalWidth, bottom: image.naturalHeight };

    if (imageAspect > frameAspect) {
      const visibleWidth = image.naturalHeight * frameAspect;
      visible.left = (image.naturalWidth - visibleWidth) / 2;
      visible.right = visible.left + visibleWidth;
    } else {
      const visibleHeight = image.naturalWidth / frameAspect;
      visible.top = (image.naturalHeight - visibleHeight) / 2;
      visible.bottom = visible.top + visibleHeight;
    }

    const safeMargin = Math.min(visible.right - visible.left, visible.bottom - visible.top) * 0.03;
    const cropRetainsSubject = subject.left >= visible.left + safeMargin
      && subject.right <= visible.right - safeMargin
      && subject.top >= visible.top + safeMargin
      && subject.bottom <= visible.bottom - safeMargin;
    const subjectWidthInFrame = subjectWidth / (visible.right - visible.left);
    const subjectHeightInFrame = subjectHeight / (visible.bottom - visible.top);
    const canUseCover = cropRetainsSubject && subjectWidthInFrame <= 0.86 && subjectHeightInFrame <= 0.86;
    const selectedFit = canUseCover ? 'cover' : fallback;

    // Keep product scale proportional while preventing a near-edge crop from
    // making some foreground-heavy photos dominate the bay. The target mobile
    // frame is 1.16:1; contain occupancy accounts for letterboxing first.
    const containWidthInFrame = imageAspect > frameAspect ? 1 : imageAspect / frameAspect;
    const containHeightInFrame = imageAspect > frameAspect ? frameAspect / imageAspect : 1;
    const fittedSubjectWidth = selectedFit === 'cover'
      ? subjectWidthInFrame
      : (subjectWidth / image.naturalWidth) * containWidthInFrame;
    const fittedSubjectHeight = selectedFit === 'cover'
      ? subjectHeightInFrame
      : (subjectHeight / image.naturalHeight) * containHeightInFrame;
    const maxSubjectWidth = selectedFit === 'cover' ? 0.76 : 1;
    const maxSubjectHeight = selectedFit === 'cover' ? 0.78 : 0.82;
    const scale = Math.min(
      1,
      maxSubjectWidth / fittedSubjectWidth,
      maxSubjectHeight / fittedSubjectHeight,
    );

    return { fit: selectedFit, scale };
  } catch {
    // Tainted canvases, unsupported image formats, or unavailable contexts
    // fall back to the full-product contain behavior.
    return safeFallback;
  }
}

const resolveImage = (product, src) => {
  if (src) return src;
  if (!product) return PLACEHOLDER;

  return (
    product.image ||
    product.featured_image ||
    product.images?.[0]?.src ||
    product.images?.[0]?.url ||
    product.thumbnail ||
    product.src ||
    PLACEHOLDER
  );
};

const resolveCardImage = (product, src) => {
  if (src) return src;
  if (!product) return PLACEHOLDER;

  return (
    product.image_thumbnail ||
    product.thumbnail ||
    product.image ||
    product.featured_image ||
    product.images?.[0]?.thumbnail ||
    product.images?.[0]?.src ||
    product.images?.[0]?.url ||
    product.src ||
    PLACEHOLDER
  );
};

export default function ProductCardImage({
  product,
  src,
  alt = '',
  padding = '8px',
  className = '',
  srcSet = '',
  sizes,
  fit = 'contain',
  mobileFit = false,
  position = 'center',
  preferThumbnail = false,
  width = 400,
  height = 400,
  eager = false,
}) {
  const initialSrc = useMemo(
    () => (preferThumbnail ? resolveCardImage(product, src) : resolveImage(product, src)),
    [preferThumbnail, product, src],
  );

  const [failedState, setFailedState] = useState({ key: '', src: null });
  const [loadedState, setLoadedState] = useState({ key: '', src: null });
  const [mobileFitState, setMobileFitState] = useState({ key: '', fit: null });
  const imgRef = useRef(null);
  const failedSrc = failedState.key === initialSrc ? failedState.src : null;
  const loadedSrc = loadedState.key === initialSrc ? loadedState.src : null;
  const imgSrc = failedSrc === initialSrc ? PLACEHOLDER : initialSrc;
  const loaded = loadedSrc === imgSrc;
  const effectiveSrcSet = imgSrc === PLACEHOLDER ? undefined : (srcSet || product?.image_srcset || undefined);
  const mobileCardFit = mobileFitState.key === initialSrc ? mobileFitState.fit : null;

  // Keep the media bed visible until the active source is decoded, whether
  // delivered from cache or network. Cancel stale decode completions when a
  // reused card receives a different product image.
  useEffect(() => {
    const image = imgRef.current;
    if (!image) return undefined;
    let cancelled = false;
    let settling = false;

    const settle = async () => {
      if (!image.complete || settling) return;
      settling = true;
      if (image.naturalWidth === 0) {
        if (!cancelled) {
          if (imgSrc !== PLACEHOLDER) setFailedState({ key: initialSrc, src: initialSrc });
          else setLoadedState({ key: initialSrc, src: PLACEHOLDER });
        }
        return;
      }

      try {
        if (typeof image.decode === 'function') await image.decode();
      } catch {
        // Decode can reject after a valid resource has loaded. Keep its
        // natural dimensions as the fallback signal rather than hiding it.
      }

      if (!cancelled && image === imgRef.current && image.naturalWidth > 0) {
        if (mobileFit) {
          setMobileFitState({ key: initialSrc, fit: chooseSafeMobileCardFit(image, fit) });
        }
        setLoadedState({ key: initialSrc, src: imgSrc });
      }
    };

    image.addEventListener('load', settle);
    image.addEventListener('error', settle);
    if (image.complete) void settle();

    return () => {
      cancelled = true;
      image.removeEventListener('load', settle);
      image.removeEventListener('error', settle);
    };
  }, [fit, imgSrc, initialSrc, mobileFit]);

  return (
    <div style={{ position: 'absolute', inset: padding }}>

      <div
        aria-hidden="true"
        style={{
          position: 'absolute',
          inset: 0,
          background: '#fff',
          // A static reserved image bed prevents dozens of independently
          // animated gradients from flashing while a catalog grid resolves.
          animation: 'none',
          opacity: loaded ? 0 : 1,
          transition: `opacity var(--dtb-motion-duration-async, 220ms) var(--dtb-motion-ease-standard)`,
          borderRadius: 'inherit',
          zIndex: 0,
        }}
      />

      <img
        ref={imgRef}
        src={imgSrc}
        srcSet={effectiveSrcSet}
        sizes={sizes || product?.image_sizes || undefined}
        alt={alt || product?.name || 'Product image'}
        width={width}
        height={height}
        loading={eager ? 'eager' : 'lazy'}
        fetchPriority={eager ? 'high' : 'auto'}
        decoding="async"
        className={className}
        style={{
          position: 'absolute',
          inset: 0,
          width: '100%',
          height: '100%',
          objectFit: mobileFit ? (mobileCardFit?.fit || fit) : fit,
          transform: mobileFit && mobileCardFit?.scale < 1 ? `scale(${mobileCardFit.scale})` : undefined,
          transformOrigin: 'center',
          objectPosition: position,
          opacity: loaded ? 1 : 0,
          transition: loaded
            ? `opacity var(--dtb-motion-duration-async, 220ms) var(--dtb-motion-ease-standard)`
            : 'none',
          zIndex: 1,
        }}

      />
    </div>
  );
}

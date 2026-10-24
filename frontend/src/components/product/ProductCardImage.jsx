/**
 * ProductCardImage
 *
 * Updated: robust image resolution across inconsistent product shapes.
 * Accepts either `src` OR full `product` object.
 */
import { useState, useMemo, useEffect, useRef } from 'react';
import { PLACEHOLDER_IMAGE } from '../../constants/images.js';

const PLACEHOLDER = PLACEHOLDER_IMAGE;

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
  const imgRef = useRef(null);
  const failedSrc = failedState.key === initialSrc ? failedState.src : null;
  const loadedSrc = loadedState.key === initialSrc ? loadedState.src : null;
  const imgSrc = failedSrc === initialSrc ? PLACEHOLDER : initialSrc;
  const loaded = loadedSrc === imgSrc;
  const effectiveSrcSet = imgSrc === PLACEHOLDER ? undefined : (srcSet || product?.image_srcset || undefined);

  // Keep the media bed visible until the active source is decoded, whether
  // delivered from cache or network. Cancel stale decode completions when a
  // reused card receives a different product image.
  useEffect(() => {
    const image = imgRef.current;
    if (!image) return undefined;
    let cancelled = false;

    const settle = async () => {
      if (!image.complete) return;
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
  }, [imgSrc, initialSrc]);

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
          objectFit: fit,
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

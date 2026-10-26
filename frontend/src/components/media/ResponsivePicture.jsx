import { RESPONSIVE_HERO_MEDIA_QUERY } from '../../assets/media/responsiveHeroMedia.js';

/**
 * Shared responsive WebP delivery for bundled hero and campaign artwork.
 * The browser selects the closest width candidate from each srcSet.
 */
export default function ResponsivePicture({
  media,
  useMobileSource = true,
  className,
  imageClassName,
  alt = '',
  decorative = false,
  sizes = '100vw',
  loading = 'eager',
  fetchPriority = 'auto',
  decoding = 'async',
}) {
  const desktop = media?.desktop;
  if (!desktop) return null;

  const mobile = useMobileSource ? media?.mobile : null;

  return (
    <picture className={className} aria-hidden={decorative ? 'true' : undefined}>
      {mobile && (
        <source
          type="image/webp"
          media={RESPONSIVE_HERO_MEDIA_QUERY}
          srcSet={mobile.srcSet}
          sizes={sizes}
        />
      )}
      <img
        className={imageClassName}
        src={desktop.src}
        srcSet={desktop.srcSet}
        sizes={sizes}
        width={desktop.width}
        height={desktop.height}
        alt={alt}
        aria-hidden={decorative ? 'true' : undefined}
        loading={loading}
        fetchPriority={fetchPriority}
        decoding={decoding}
      />
    </picture>
  );
}

export function getResponsiveImageSource(media, mobile = false) {
  const selected = (mobile && media?.mobile ? media.mobile : media?.desktop) || null;
  return selected ? { src: selected.src, srcSet: selected.srcSet, sizes: '100vw' } : null;
}

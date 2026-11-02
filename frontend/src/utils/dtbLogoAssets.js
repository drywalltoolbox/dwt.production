/**
 * Canonical public Drywall Toolbox logo endpoints.
 *
 * Visual storefront components use same-origin build assets first. Stable live
 * endpoints remain available as a fallback for static/document contexts.
 */
import DTB_LOGO_WHITE_URL from '../assets/brand/dtb-logo-white.webp';
import DTB_LOGO_WHITE_MOBILE_URL from '../assets/brand/dtb-logo-white-mobile.webp';
import DTB_LOGO_BLACK_URL from '../assets/brand/dtb-logo-black.webp';

export { DTB_LOGO_WHITE_URL, DTB_LOGO_WHITE_MOBILE_URL, DTB_LOGO_BLACK_URL };
export const DTB_LOGO_WHITE_FALLBACK_URL = 'https://drywalltoolbox.com/logos/dtb-logo-white.webp';
export const DTB_LOGO_BLACK_FALLBACK_URL = 'https://drywalltoolbox.com/logos/dtb-logo-black.webp';

export function handleDtbLogoFallback(event, variant = 'white') {
  const image = event?.currentTarget;
  if (!image || image.dataset.liveFallback === 'true') return;
  image.dataset.liveFallback = 'true';
  image.src = variant === 'black' ? DTB_LOGO_BLACK_FALLBACK_URL : DTB_LOGO_WHITE_FALLBACK_URL;
}


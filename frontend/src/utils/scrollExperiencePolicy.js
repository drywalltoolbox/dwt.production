/**
 * Pure policy for the single storefront scroll enhancement owner.
 * Never governs browser touch/wheel input, route restoration or nested scrollers.
 */
export function isScrollExperienceEnabled(pathname = '/', search = '') {
  if (/^\/checkout(?:\/|$)/.test(pathname)) return false;
  if (/^\/schematics(?:\/|$)/.test(pathname) && new URLSearchParams(search).has('schematic')) return false;
  return true;
}

export function scrollMetrics(scrollY, documentHeight, viewportHeight) {
  const height = Number.isFinite(documentHeight) ? Math.max(0, documentHeight) : 0;
  const viewport = Number.isFinite(viewportHeight) ? Math.max(0, viewportHeight) : 0;
  const maxScroll = Math.max(0, height - viewport);
  const top = Number.isFinite(scrollY) ? Math.min(maxScroll, Math.max(0, scrollY)) : 0;
  return {
    progress: maxScroll > 0 ? top / maxScroll : 0,
    showTop: maxScroll > viewport * 1.25 && top > Math.max(700, viewport),
  };
}

export function isDocumentScrollLocked(documentRoot, body) {
  return documentRoot?.style?.overflow === 'hidden' || body?.style?.overflow === 'hidden';
}

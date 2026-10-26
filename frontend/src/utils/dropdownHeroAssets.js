import { RESPONSIVE_HERO_MEDIA } from '@assets/media/responsiveHeroMedia.js';

const brandsHero = '/wp/wp-content/uploads/2026/categories/heroes/brands.webp';

/**
 * Canonical desktop mega-menu hero media registry.
 *
 * Bundled hero artwork lives under
 * frontend/src/assets/media/catalog/dropdown-heroes/. WordPress-owned
 * artwork uses a same-origin uploads URL here so every dropdown renderer
 * continues through this single registry.
 *
 * Add new approved hero assets here as they are created rather than importing
 * them ad hoc from header or panel components. Missing heroes intentionally
 * resolve to an empty string so renderers omit the media region cleanly.
 */
export const DROPDOWN_HERO_MEDIA = Object.freeze({
  parts: RESPONSIVE_HERO_MEDIA.dropdownParts,
  repairs: RESPONSIVE_HERO_MEDIA.dropdownRepairs,
  schematics: RESPONSIVE_HERO_MEDIA.dropdownSchematics,
});

export const DROPDOWN_HERO_ASSETS = Object.freeze({
  brands: brandsHero,
  parts: DROPDOWN_HERO_MEDIA.parts.desktop.src,
  repairs: DROPDOWN_HERO_MEDIA.repairs.desktop.src,
  schematics: DROPDOWN_HERO_MEDIA.schematics.desktop.src,
});

export function getDropdownHero(menuId) {
  const key = String(menuId || '').trim().toLowerCase();
  return DROPDOWN_HERO_ASSETS[key] || '';
}

export function getDropdownHeroMedia(menuId) {
  const key = String(menuId || '').trim().toLowerCase();
  return DROPDOWN_HERO_MEDIA[key] || null;
}

export default getDropdownHero;

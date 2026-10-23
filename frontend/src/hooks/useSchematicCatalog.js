/**
 * frontend/src/hooks/useSchematicCatalog.js
 *
 * Fetches the authoritative schematics collection endpoint and derives the
 * brand / category / tool navigation structure the catalog UI needs.
 *
 * Owns: catalog fetch, loading/error/empty state, brand+category derivation.
 * Does NOT own: URL state (see useSchematicRouteState) or per-schematic detail.
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { fetchSchematicsCollection, SchematicApiError } from '../api/schematicsApi.js';
import { humanizeLabel, humanizeSchematicCategoryLabel } from '../utils/string.js';
import { canonicalizeSchematicBrandId } from '../data/schematicBrands.js';

// Temporary storefront suppression. Remove `asgard` to restore its catalog
// entries without changing the authoritative schematic records.
const HIDDEN_SCHEMATIC_BRAND_IDS = new Set(['asgard']);

const CATEGORY_PREVIEW_OVERRIDES = new Map([
  [
    'columbia::compound-tubes',
    {
      url: 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/columbia_tools_cmt24_01.webp',
      source: 'category_override',
    },
  ],
  [
    'columbia::pumps',
    {
      url: 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/columbia_tools_hmp_01.webp',
      source: 'category_override',
    },
  ],
]);

/**
 * @typedef {'loading'|'success'|'empty'|'error'} SchematicCatalogStatus
 */

export function useSchematicCatalog() {
  const [status, setStatus] = useState('loading');
  const [items, setItems] = useState([]);
  const [error, setError] = useState(null);
  const [catalogVersion, setCatalogVersion] = useState(null);
  const abortRef = useRef(null);

  useEffect(() => {
    const controller = new AbortController();
    abortRef.current = controller;

    fetchSchematicsCollection({ signal: controller.signal })
      .then((body) => {
        if (controller.signal.aborted) return;
        const normalizedItems = Array.isArray(body.items)
          ? body.items
            .filter((item) => !HIDDEN_SCHEMATIC_BRAND_IDS.has(
              canonicalizeSchematicBrandId(item?.brand?.id || ''),
            ))
            .map((item) => ({
              ...item,
              title: humanizeLabel(item?.title, item?.id),
              brand: item?.brand
                ? { ...item.brand, name: humanizeLabel(item.brand.name, item.brand.id) }
                : item?.brand,
              category: item?.category
                ? {
                    ...item.category,
                    name: humanizeSchematicCategoryLabel(item.category.name, item.category.id),
                  }
                : item?.category,
            }))
          : [];
        setItems(normalizedItems);
        setCatalogVersion(body.catalog_version ?? null);
        setStatus(normalizedItems.length > 0 ? 'success' : 'empty');
        setError(null);
      })
      .catch((err) => {
        if (err?.name === 'AbortError') return;
        const message =
          err instanceof SchematicApiError
            ? err.message
            : 'The schematics catalog could not be loaded.';
        setError(message);
        setStatus('error');
      });

    return () => controller.abort();
  }, []);

  const brands = useMemo(() => {
    const map = new Map();
    items.forEach((item) => {
      const brand = item.brand;
      if (!brand?.id) return;
      if (!map.has(brand.id)) map.set(brand.id, { id: brand.id, name: humanizeLabel(brand.name, brand.id), count: 0 });
      map.get(brand.id).count += 1;
    });
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [items]);

  const categoriesByBrand = useMemo(() => {
    const map = new Map();
    items.forEach((item) => {
      const brandId = item.brand?.id;
      const category = item.category;
      if (!brandId || !category?.id) return;
      if (!map.has(brandId)) map.set(brandId, new Map());
      const catMap = map.get(brandId);
      if (!catMap.has(category.id)) {
        catMap.set(category.id, {
          id: category.id,
          name: humanizeSchematicCategoryLabel(category.name, category.id),
          count: 0,
          preview: CATEGORY_PREVIEW_OVERRIDES.get(`${brandId}::${category.id}`) || null,
        });
      }
      const entry = catMap.get(category.id);
      entry.count += 1;
      // Representative preview image for the category tile — first item in
      // the group that actually has a usable preview URL. Purely a UI
      // derivation over already-fetched items; no extra fetch.
      if (!entry.preview && item.preview?.url) {
        entry.preview = item.preview;
      }
    });
    const result = new Map();
    map.forEach((catMap, brandId) => {
      result.set(brandId, Array.from(catMap.values()).sort((a, b) => a.name.localeCompare(b.name)));
    });
    return result;
  }, [items]);

  const toolsByBrandCategory = useMemo(() => {
    const map = new Map();
    items.forEach((item) => {
      const brandId = item.brand?.id;
      const categoryId = item.category?.id;
      if (!brandId || !categoryId) return;
      const key = `${brandId}::${categoryId}`;
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(item);
    });
    map.forEach((list) => list.sort((a, b) => (a.title || '').localeCompare(b.title || '')));
    return map;
  }, [items]);

  function getCategoriesForBrand(brandId) {
    return categoriesByBrand.get(brandId) || [];
  }

  function getToolsForBrandCategory(brandId, categoryId) {
    return toolsByBrandCategory.get(`${brandId}::${categoryId}`) || [];
  }

  function findItem(schematicId) {
    return items.find((item) => String(item.id) === String(schematicId)) || null;
  }

  /** Simple client-side search across title/brand/category — collection is small. */
  function search(query) {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return items.filter((item) => {
      const haystack = `${item.title || ''} ${item.brand?.name || ''} ${item.category?.name || ''}`.toLowerCase();
      return haystack.includes(q);
    });
  }

  return {
    status,
    items,
    error,
    catalogVersion,
    brands,
    getCategoriesForBrand,
    getToolsForBrandCategory,
    findItem,
    search,
  };
}

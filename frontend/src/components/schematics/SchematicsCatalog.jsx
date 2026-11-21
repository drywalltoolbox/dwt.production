/**
 * frontend/src/components/schematics/SchematicsCatalog.jsx
 *
 * Brand -> Category -> Tool drill-down for the schematics catalog root.
 * Purely a composition/state-forwarding layer over useSchematicCatalog +
 * useSchematicRouteState; no data fetching of its own.
 */
import { useMemo, useState } from 'react';
import SearchBar from '../catalog/SearchBar';
import BrandSelector, { resolveSchematicBrandLogo } from './BrandSelector';
import CategorySelector from './CategorySelector';
import ToolSelector from './ToolSelector';

export default function SchematicsCatalog({ catalog, routeState }) {
  const { status, error, brands, getCategoriesForBrand, getToolsForBrandCategory, search } = catalog;
  const { brandId, categoryId, goToBrand, goToCategory, goToSchematic, goToCatalogRoot } = routeState;
  const [searchQuery, setSearchQuery] = useState('');

  const searchResults = useMemo(() => search(searchQuery), [search, searchQuery]);
  const hasQuery = searchQuery.trim().length > 0;

  const currentBrand = brandId ? brands.find((b) => b.id === brandId) : null;
  const categories = brandId ? getCategoriesForBrand(brandId) : [];
  const currentCategory = categoryId ? categories.find((c) => c.id === categoryId) : null;
  const tools = brandId && categoryId ? getToolsForBrandCategory(brandId, categoryId) : [];

  if (status === 'loading') {
    return (
      <div className="dtb-schematics-catalog" aria-busy="true">
        <p className="dtb-schematics-status">Loading schematics…</p>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="dtb-schematics-catalog">
        <p className="dtb-schematics-status dtb-schematics-status--error" role="alert">
          {error || 'The schematics catalog could not be loaded. Please try again shortly.'}
        </p>
      </div>
    );
  }

  if (status === 'empty' && !hasQuery) {
    return (
      <div className="dtb-schematics-catalog">
        <p className="dtb-schematics-status">No schematics are published yet. Check back soon.</p>
      </div>
    );
  }

  return (
    <div className="dtb-schematics-catalog">
      {!brandId && (
        <SearchBar
          placeholder="Search schematics by brand, category, or tool name…"
          value={searchQuery}
          onChange={(event) => setSearchQuery(event.target.value)}
        />
      )}

      {hasQuery ? (
        <div>
          <p className="dtb-schematics-search-summary">
            {searchResults.length === 0
              ? `No schematics found for "${searchQuery}"`
              : `${searchResults.length} result${searchResults.length === 1 ? '' : 's'} found`}
          </p>
          {searchResults.length > 0 && (
            <ToolSelector
              brandName="Schematics"
              brandLogo=""
              categoryName="your search"
              tools={searchResults}
              onBack={() => setSearchQuery('')}
              onSelectTool={(id) => goToSchematic(id)}
            />
          )}
        </div>
      ) : !brandId ? (
        <BrandSelector brands={brands} onSelectBrand={goToBrand} />
      ) : !categoryId ? (
        <CategorySelector
          brandId={brandId}
          brandName={currentBrand?.name}
          categories={categories}
          onBack={goToCatalogRoot}
          onSelectCategory={(id) => goToCategory(brandId, id)}
        />
      ) : (
        <>
          <ToolSelector
            brandName={currentBrand?.name || brandId}
            brandLogo={resolveSchematicBrandLogo(currentBrand || { id: brandId, name: brandId })}
            categoryName={currentCategory?.name}
            tools={tools}
            onBack={() => goToBrand(brandId)}
            onSelectTool={(id) => goToSchematic(id)}
          />
        </>
      )}
    </div>
  );
}

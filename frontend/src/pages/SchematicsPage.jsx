/**
 * frontend/src/pages/SchematicsPage.jsx
 *
 * Live implementation of the /schematics route (Phase 7 rebuild).
 *
 * Composition (per schematics_prompt.md "REACT FRONTEND REBUILD"):
 *   SchematicsPage
 *     -> useSchematicRouteState
 *     -> useSchematicCatalog
 *     -> SchematicsCatalog -> BrandSelector / CategorySelector / ToolSelector
 *     -> SchematicViewerPage -> SchematicHeader / SchematicPageTabs / DiagramViewer / SchematicPartDialog
 *
 * Consumes only the authoritative dtb-schematics public API (see
 * frontend/src/api/schematicsApi.js) — it does not decide which schematics,
 * pages, or products exist; that is entirely server-derived.
 *
 * The old frontend/src/pages/Schematics.jsx implementation (hardcoded tool
 * registries, static per-tool JSON imports, per-hotspot product lookups,
 * global DOM/history runtimes) has been disconnected from routing in favor
 * of this module. It is left in the repo pending Phase 9 removal.
 */
import { useCallback, useEffect, useRef } from 'react';
import AppErrorBoundary from '../components/system/AppErrorBoundary';
import SEOHead from '../components/shared/SEOHead';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
import { buildBreadcrumbSchema } from '../utils/schema';
import { useSchematicRouteState } from '../hooks/useSchematicRouteState';
import { useSchematicCatalog } from '../hooks/useSchematicCatalog';
import SchematicsCatalog from '../components/schematics-v2/SchematicsCatalog';
import SchematicViewerPage from '../components/schematics-v2/SchematicViewerPage';
import '../styles/schematics-v2.css';
import '../styles/selector-cards.css';
import '../styles/schematics-brand-header.css';
import '../styles/schematic-hotspot-card-polish.css';
import '../styles/schematic-linked-hotspot-glow.css';
import '../styles/schematics-loading.css';
import '../styles/schematic-diagram-fit.css';
import '../styles/schematic-parts-navigator.css';

function SchematicsPageInner() {
  const viewerRootRef = useRef(null);
  const routeState = useSchematicRouteState();
  const catalog = useSchematicCatalog();

  const handleSelectBrand = useCallback((brandId) => {
    routeState.goToBrand(brandId);
  }, [routeState]);

  const handleSelectCategory = useCallback((brandId, categoryId) => {
    routeState.goToCategory(brandId, categoryId);
  }, [routeState]);

  const handleSelectTool = useCallback((schematicId) => {
    routeState.goToSchematic(schematicId);
  }, [routeState]);

  const handleBack = useCallback((brandId, categoryId) => {
    if (categoryId) {
      routeState.goToCategory(brandId, categoryId);
    } else if (brandId) {
      routeState.goToBrand(brandId);
    } else {
      routeState.goToCatalogRoot();
    }
  }, [routeState]);

  const handlePageChange = useCallback((pageNumber) => {
    routeState.setPage(pageNumber);
  }, [routeState]);

  const handleSelectVariant = useCallback(({ type, id }) => {
    if (type === 'shared') {
      routeState.setVariant(id);
      return;
    }
    routeState.goToSchematic(id);
  }, [routeState]);

  // `useSchematicRouteState` exposes `viewer` whenever a schematic query
  // parameter is present. Keep this value aligned with that URL-state
  // contract so selecting a tool renders its detail view rather than falling
  // back to the catalog selector.
  const isViewer = routeState.view === 'viewer';
  // Measure available visual viewport below the actual storefront chrome.
  // Header height is responsive (announcement + navigation + search), so a
  // hardcoded --header-height offset incorrectly crops the mobile viewer.
  useEffect(() => {
    if (!isViewer || !viewerRootRef.current) return undefined;
    const root = viewerRootRef.current;
    const update = () => {
      const top = Math.max(0, root.getBoundingClientRect().top);
      const height = Math.max(280, (window.visualViewport?.height || window.innerHeight) - top);
      root.style.setProperty('--dtb-schematic-available-height', `${height}px`);
    };
    update();
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(update) : null;
    const header = document.querySelector('header');
    if (header) observer?.observe(header);
    window.addEventListener('resize', update);
    window.addEventListener('scroll', update, { passive: true });
    window.visualViewport?.addEventListener('resize', update);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', update);
      window.removeEventListener('scroll', update);
      window.visualViewport?.removeEventListener('resize', update);
    };
  }, [isViewer]);

  const title = isViewer ? 'Schematics' : 'Parts Schematics';
  const description = isViewer
    ? 'Interactive parts schematic with linked replacement parts.'
    : 'Browse parts schematics by brand, category, and tool.';

  return (
    <>
      <SEOHead
        title={title}
        description={description}
        canonicalPath="/schematics"
        structuredData={buildBreadcrumbSchema([
          { name: 'Home', path: '/' },
          { name: 'Schematics', path: '/schematics' },
        ])}
      />

      {!isViewer && (
        <InteriorPageHero
          id="dtb-schematics-hero-title"
          breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'Schematics' }]}
          title="Parts Schematics"
          description="Find the diagram for your tool, then select a callout to identify and order the correct replacement part."
        />
      )}

      <main ref={viewerRootRef} className={`dtb-schematics-page__content${isViewer ? ' dtb-schematics-page__content--viewer' : ''}`}>
        {isViewer ? (
          <SchematicViewerPage
            key={routeState.schematicId}
            schematicId={routeState.schematicId}
            initialPage={routeState.page}
            initialVariant={routeState.variant}
            onBack={handleBack}
            onPageChange={handlePageChange}
            catalogItems={catalog.items}
            onSelectVariant={handleSelectVariant}
          />
        ) : (
          <SchematicsCatalog
            catalog={catalog}
            routeState={routeState}
            onSelectBrand={handleSelectBrand}
            onSelectCategory={handleSelectCategory}
            onSelectTool={handleSelectTool}
          />
        )}
      </main>
    </>
  );
}

export default function SchematicsPage() {
  return (
    <AppErrorBoundary>
      <SchematicsPageInner />
    </AppErrorBoundary>
  );
}

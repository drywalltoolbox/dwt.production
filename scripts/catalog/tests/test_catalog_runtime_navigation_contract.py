from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SOURCE_TAXONOMY = ROOT / "products/catalog/source/taxonomy.json"
RUNTIME_TAXONOMY = ROOT / "drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Resources/catalog-taxonomy.json"
HEADER = ROOT / "frontend/src/components/storefront/StorefrontHeader.jsx"
BACKEND_NAVIGATION = ROOT / "drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/CatalogNavigationService.php"
FRONTEND_CACHE = ROOT / "frontend/src/services/catalogPlatformCache.js"


def test_runtime_taxonomy_projection_is_exactly_synchronized() -> None:
    assert RUNTIME_TAXONOMY.read_bytes() == SOURCE_TAXONOMY.read_bytes()


def test_frontend_desktop_menu_contains_no_parallel_taxonomy_registry() -> None:
    source = HEADER.read_text(encoding="utf-8")
    assert "CURATED_DESKTOP_PRODUCT_TAXONOMY" not in source
    assert "items: desktopProductNavigation" in source


def test_backend_and_frontend_facets_contract_versions_match() -> None:
    backend = BACKEND_NAVIGATION.read_text(encoding="utf-8")
    frontend = FRONTEND_CACHE.read_text(encoding="utf-8")
    assert "CONTRACT_VERSION = '2.1'" in backend
    assert "CATALOG_FACETS_CONTRACT_VERSION = '2.1'" in frontend
    assert "FACETS_CACHE_VERSION = 'v15-contract-2-1'" in frontend

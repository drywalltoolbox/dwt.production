from __future__ import annotations

import csv
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SOURCE_TAXONOMY = ROOT / "products/catalog/source/taxonomy.json"
RUNTIME_TAXONOMY = ROOT / "drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Resources/catalog-taxonomy.json"
ASSIGNMENTS = ROOT / "products/catalog/source/product_categories.csv"
HEADER = ROOT / "frontend/src/components/storefront/StorefrontHeader.jsx"
BACKEND_NAVIGATION = ROOT / "drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/CatalogNavigationService.php"
FRONTEND_CACHE = ROOT / "frontend/src/services/catalogPlatformCache.js"


def test_runtime_taxonomy_projection_is_exactly_synchronized() -> None:
    assert RUNTIME_TAXONOMY.read_bytes() == SOURCE_TAXONOMY.read_bytes()


def test_generated_owner_assignments_use_only_canonical_taxon_keys() -> None:
    registry = json.loads(SOURCE_TAXONOMY.read_text(encoding="utf-8"))
    canonical_keys = {taxon["key"] for taxon in registry["taxa"]}
    with ASSIGNMENTS.open(encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    with (ROOT / "products/launch/official/dtb_official_catalog.csv").open(encoding="utf-8-sig", newline="") as handle:
        owners = [row for row in csv.DictReader(handle) if row["Type"] != "variation"]
    assert len(rows) == len(owners)
    assert {row["taxon_key"] for row in rows} <= canonical_keys
    assert len({row["sku"] for row in rows}) == len(rows)


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

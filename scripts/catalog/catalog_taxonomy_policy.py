#!/usr/bin/env python3
"""DTB catalog taxonomy policy derived from the canonical taxonomy registry.

`products/catalog/source/taxonomy.json` owns the product-category tree.
This module adds deterministic compatibility metadata and legacy migration
aliases; it does not maintain a second copy of the hierarchy.
"""

from __future__ import annotations

from dataclasses import dataclass
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
TAXONOMY_PATH = ROOT / "products" / "catalog" / "source" / "taxonomy.json"

CATEGORY_FIELD = "Meta: _dtb_category_key"
DISPLAY_FIELD = "Meta: _dtb_display_category_key"
KIND_FIELD = "Meta: _dtb_product_kind"
PARENT_FIELD = "Meta: _dtb_parent_product_sku"

DRYWALL_ROOT = "Taping & Finishing Tools"
STILT_ROOT = "Drywall Stilts"


def normalize_key(value: object) -> str:
    return re.sub(r"_+", "_", re.sub(r"[^a-z0-9]+", "_", str(value or "").strip().lower())).strip("_")


def split_category_paths(raw: object) -> list[list[str]]:
    text = str(raw or "").strip()
    if not text:
        return []
    candidates: list[str] = []
    current: list[str] = []
    escaped = False
    for character in text:
        if escaped:
            current.append(character)
            escaped = False
        elif character == "\\":
            escaped = True
        elif character == ",":
            candidates.append("".join(current))
            current = []
        else:
            current.append(character)
    if escaped:
        current.append("\\")
    candidates.append("".join(current))

    paths: list[list[str]] = []
    for candidate in candidates:
        parts = [part.strip() for part in candidate.split(">") if part.strip()]
        if parts:
            paths.append(parts)
    return paths


def escape_woocommerce_category_term(value: object) -> str:
    return str(value or "").replace("\\", "\\\\").replace(",", "\\,")


def woocommerce_category_path(parts: list[str] | tuple[str, ...]) -> str:
    return " > ".join(escape_woocommerce_category_term(part) for part in parts if part)


@dataclass(frozen=True)
class NavigationTaxon:
    key: str
    root: str
    group: str
    leaf: str
    category_key: str
    display_key: str

    @property
    def path(self) -> str:
        return " > ".join(part for part in (self.root, self.group, self.leaf) if part)

    @property
    def csv_path(self) -> str:
        return woocommerce_category_path(tuple(part for part in (self.root, self.group, self.leaf) if part))


@dataclass(frozen=True)
class TaxonomyExpectation:
    category_key: str
    display_category_key: str
    reason: str


def _load_registry() -> tuple[dict[str, dict[str, object]], list[str]]:
    payload = json.loads(TAXONOMY_PATH.read_text(encoding="utf-8"))
    taxa = {str(item["key"]): item for item in payload["taxa"]}
    if len(taxa) != len(payload["taxa"]):
        raise ValueError("taxonomy registry contains duplicate keys")
    slugs = [str(item["slug"]) for item in payload["taxa"]]
    if len(slugs) != len(set(slugs)):
        raise ValueError("taxonomy registry contains duplicate slugs")
    return taxa, [str(key) for key in payload.get("root_taxa", [])]


REGISTRY, ROOT_TAXA = _load_registry()


def _path_labels(key: str) -> list[str]:
    labels: list[str] = []
    seen: set[str] = set()
    current: str | None = key
    while current is not None:
        if current in seen or current not in REGISTRY:
            raise ValueError(f"invalid taxonomy ancestry at {current}")
        seen.add(current)
        item = REGISTRY[current]
        labels.append(str(item["label"]))
        parent = item.get("parent_key")
        current = str(parent) if parent is not None else None
    return list(reversed(labels))


# Compatibility facets are intentionally separate from product_cat taxonomy.
# Values match the revised official catalog migration.
COMPATIBILITY_BY_TAXON: dict[str, tuple[str, str]] = {
    "automatic_tapers": ("taping", "automatic_tapers"),
    "semi_automatic_tapers_banjos": ("taping", "semi_automatic_tapers_banjos"),
    "flat_boxes": ("finishing", "flat_boxes"),
    "automatic_corner_finishers": ("corner", "corner_finishers"),
    "automatic_angle_boxes_corner_applicators": ("corner", "automatic_angle_boxes_corner_applicators"),
    "automatic_compound_tubes": ("corner", "compound_tubes"),
    "powered_compound_applicators": ("corner", "powered_compound_applicators"),
    "applicator_heads": ("corner", "applicator_heads"),
    "automatic_corner_flushers": ("corner", "automatic_corner_flushers"),
    "automatic_corner_rollers": ("corner", "automatic_corner_rollers"),
    "automatic_nail_spotters": ("taping", "automatic_nail_spotters"),
    "automatic_loading_pumps": ("mudboxes", "automatic_loading_pumps"),
    "automatic_goosenecks_box_fillers": ("mudboxes", "automatic_goosenecks_box_fillers"),
    "automatic_continuous_flow_tools": ("taping", "automatic_continuous_flow_tools"),
    "automatic_handles_extensions": ("handles", "handles"),
    "automatic_tool_sets": ("taping", "toolsets"),
    "tool_storage_cases": ("accessories", "tool_storage_cases"),
    "bead_tools": ("corner", "bead_hoppers"),
    "bead_rollers": ("corner", "bead_rollers"),
    "mud_pans": ("finishing", "mud_pans"),
    "mud_mixing_tools": ("finishing", "mud_mixing_tools"),
    "hanging_tools": ("hanging", "hanging_tools"),
    "drywall_squares": ("hanging", "drywall_squares"),
    "drywall_saws": ("hanging", "drywall_saws"),
    "fastener_setting_tools": ("hanging", "fastener_setting_tools"),
    "mud_pan_accessories": ("accessories", "mud_pan_accessories"),
    "replacement_parts": ("parts", "parts"),
    "stilts": ("stilts", "stilts"),
    "drywall_sealants": ("materials", "sealants"),
}


def _navigation_taxon(key: str) -> NavigationTaxon:
    labels = _path_labels(key)
    category_key, display_key = COMPATIBILITY_BY_TAXON[key]
    if len(labels) == 1:
        root, group, leaf = labels[0], "", ""
    elif len(labels) == 2:
        root, group, leaf = labels[0], labels[1], ""
    else:
        root, group, leaf = labels[0], labels[1], labels[-1]
    return NavigationTaxon(key, root, group, leaf, category_key, display_key)


NAVIGATION_TAXA: tuple[NavigationTaxon, ...] = tuple(
    _navigation_taxon(key) for key in COMPATIBILITY_BY_TAXON
)
BY_PATH = {normalize_key(t.path): t for t in NAVIGATION_TAXA}
BY_LEAF: dict[str, tuple[NavigationTaxon, ...]] = {}
for _taxon in NAVIGATION_TAXA:
    _leaf_key = normalize_key(_taxon.leaf or _taxon.group or _taxon.root)
    BY_LEAF[_leaf_key] = (*BY_LEAF.get(_leaf_key, ()), _taxon)


# Historical paths are migration inputs only. Ambiguous historical Compound
# Applicators are intentionally not mapped here because that former leaf now
# splits between passive Applicator Heads and Powered Compound Applicators.
LEGACY_PATH_TARGETS = {
    "automatic taping tools > automatic tapers": "automatic_tapers",
    "automatic taping tools > flat boxes": "flat_boxes",
    "automatic taping tools > finishing boxes": "flat_boxes",
    "automatic taping tools > angle heads": "automatic_corner_finishers",
    "automatic taping tools > corner finishers": "automatic_corner_finishers",
    "automatic taping tools > angle boxes": "automatic_angle_boxes_corner_applicators",
    "automatic taping tools > angle boxes & corner applicators": "automatic_angle_boxes_corner_applicators",
    "automatic taping tools > corner boxes": "automatic_angle_boxes_corner_applicators",
    "automatic taping tools > compound tubes": "automatic_compound_tubes",
    "automatic taping tools > corner flushers": "automatic_corner_flushers",
    "automatic taping tools > corner rollers": "automatic_corner_rollers",
    "automatic taping tools > nail spotters": "automatic_nail_spotters",
    "automatic taping tools > loading pumps": "automatic_loading_pumps",
    "automatic taping tools > goosenecks": "automatic_goosenecks_box_fillers",
    "automatic taping tools > goosenecks & box fillers": "automatic_goosenecks_box_fillers",
    "automatic taping tools > box fillers": "automatic_goosenecks_box_fillers",
    "automatic taping tools > handles & extensions": "automatic_handles_extensions",
    "automatic taping tools > corner tool handles": "automatic_handles_extensions",
    "automatic taping tools > fixed handles": "automatic_handles_extensions",
    "automatic taping tools > extendable handles": "automatic_handles_extensions",
    "automatic taping tools > flat box handles": "automatic_handles_extensions",
    "automatic taping tools > tool sets": "automatic_tool_sets",
    "automatic taping tools > automatic taping tool sets": "automatic_tool_sets",
    "automatic taping tools > semi-automatic tools": "semi_automatic_tapers_banjos",
    "semi-automatic tools > semi-automatic tapers": "semi_automatic_tapers_banjos",
    "semi-automatic taping tools > semi-automatic tapers": "semi_automatic_tapers_banjos",
    "automatic taping tools > tool cases": "tool_storage_cases",
    "parts": "replacement_parts",
}

PATH_ALIASES: dict[str, str] = {}
for legacy_suffix, target_key in LEGACY_PATH_TARGETS.items():
    target = _navigation_taxon(target_key)
    for legacy_root in ("Drywall Finishing Tools", DRYWALL_ROOT):
        PATH_ALIASES[normalize_key(f"{legacy_root} > {legacy_suffix}")] = normalize_key(target.path)
PATH_ALIASES[normalize_key("Drywall Finishing Tools > Parts")] = normalize_key("Replacement Parts")
PATH_ALIASES[normalize_key("Taping & Finishing Tools > Goosenecks")] = normalize_key(
    "Automatic Taping Tools > Goosenecks, Box Fillers & Adapters"
)
PATH_ALIASES[normalize_key("Box Fillers & Adapters")] = normalize_key(
    "Automatic Taping Tools > Goosenecks, Box Fillers & Adapters"
)
PATH_ALIASES[normalize_key("Taping & Finishing Tools > Bead Tools")] = normalize_key("Corner Bead Tools > Bead Hoppers")
PATH_ALIASES[normalize_key("Drywall Sealants")] = normalize_key("Materials & Consumables > Sealants")
PATH_ALIASES[normalize_key("Taping & Finishing Tools > Handles & Extensions")] = normalize_key("Automatic Taping Tools > Handles")
PATH_ALIASES[normalize_key("Taping & Finishing Tools > Loading & Compound Pumps")] = normalize_key("Automatic Taping Tools > Loading Pumps")
PATH_ALIASES[normalize_key("Automatic Taping Tools > Handles & Extensions")] = normalize_key("Automatic Taping Tools > Handles")
PATH_ALIASES[normalize_key("Automatic Taping Tools > Loading & Compound Pumps")] = normalize_key("Automatic Taping Tools > Loading Pumps")

LEAF_ALIASES = {
    "angle_heads": "corner_finishers",
    "angle_head": "corner_finishers",
    "anglehead": "corner_finishers",
    "finishing_boxes": "flat_boxes",
    "flat_finishing_boxes": "flat_boxes",
    "compound_pumps": "loading_pumps",
    "loading_pumps": "loading_pumps",
    "mud_pumps": "loading_pumps",
    "drywall_stilts": "stilts",
}
FAMILY_ONLY_KEYS = {"predator", "predator_family"}

PRODUCT_KIND_DEFAULTS = {
    "part": _navigation_taxon("replacement_parts"),
    "toolset": _navigation_taxon("automatic_tool_sets"),
    "kit": _navigation_taxon("automatic_tool_sets"),
    "stilt": _navigation_taxon("stilts"),
}

DISPLAY_TO_CATEGORY_KEY = {
    display: category for category, display in COMPATIBILITY_BY_TAXON.values()
}
# Retain recognized historical compatibility slugs for audit-only reads.
DISPLAY_TO_CATEGORY_KEY.update({
    "automatic_angle_heads": "corner",
    "automatic_corner_finishers": "corner",
    "automatic_compound_tubes": "corner",
    "automatic_compound_applicators": "corner",
    "automatic_handles_extensions": "handles",
    "automatic_tool_sets": "taping",
    "semi_automatic_tools": "taping",
    "nail_spotters": "taping",
    "finishing_boxes": "finishing",
    "pumps": "mudboxes",
})
AMBIGUOUS_DISPLAY_KEYS = {"predator_family", "accessories"}


def taxons_for_path(raw_path: object) -> tuple[NavigationTaxon, ...]:
    candidates: list[NavigationTaxon] = []
    for parts in split_category_paths(raw_path):
        normalized_parts = [normalize_key(part) for part in parts]
        if any(part in FAMILY_ONLY_KEYS for part in normalized_parts):
            continue
        path_key = normalize_key(" > ".join(parts))
        direct = BY_PATH.get(PATH_ALIASES.get(path_key, path_key))
        if direct:
            candidates.append(direct)
            continue
        leaf_key = LEAF_ALIASES.get(normalize_key(parts[-1]), normalize_key(parts[-1]))
        leaf_candidates = BY_LEAF.get(leaf_key, ())
        if len(leaf_candidates) == 1:
            candidates.append(leaf_candidates[0])
    unique = {item.path: item for item in candidates}
    return tuple(unique.values())


def taxon_for_path(raw_path: object) -> NavigationTaxon | None:
    taxons = taxons_for_path(raw_path)
    return taxons[0] if len(taxons) == 1 else None


def navigation_for_row(row: dict[str, str], parent: dict[str, str] | None = None) -> NavigationTaxon | None:
    if normalize_key(row.get("Type")) == "variation":
        return navigation_for_row(parent, None) if parent else None

    kind = normalize_key(row.get(KIND_FIELD))
    if kind in PRODUCT_KIND_DEFAULTS:
        default = PRODUCT_KIND_DEFAULTS[kind]
        if kind == "part":
            return default
        explicit = taxon_for_path(row.get("Categories"))
        return explicit or default
    return taxon_for_path(row.get("Categories"))


def canonical_values(row: dict[str, str], parent: dict[str, str] | None = None) -> dict[str, str] | None:
    taxon = navigation_for_row(row, parent)
    if not taxon:
        return None
    return {"Categories": taxon.csv_path, CATEGORY_FIELD: taxon.category_key, DISPLAY_FIELD: taxon.display_key}


def expected_taxonomy(product_kind: str, display_category_key: str) -> TaxonomyExpectation | None:
    kind = normalize_key(product_kind)
    if kind in PRODUCT_KIND_DEFAULTS:
        taxon = PRODUCT_KIND_DEFAULTS[kind]
        return TaxonomyExpectation(taxon.category_key, taxon.display_key, "product_kind")
    display = normalize_key(display_category_key)
    if display in AMBIGUOUS_DISPLAY_KEYS:
        return None
    category = DISPLAY_TO_CATEGORY_KEY.get(display)
    return TaxonomyExpectation(category, display, "display_category") if category else None


def taxonomy_state(*, product_kind: str, category_key: str, display_category_key: str) -> dict[str, object]:
    raw_category = str(category_key or "").strip()
    raw_display = str(display_category_key or "").strip()
    category = normalize_key(raw_category)
    display = normalize_key(raw_display)
    base = {
        "raw_category_key": raw_category,
        "raw_display_category_key": raw_display,
        "category_key": category,
        "display_category_key": display,
    }
    if display in AMBIGUOUS_DISPLAY_KEYS and normalize_key(product_kind) not in PRODUCT_KIND_DEFAULTS:
        return {**base, "disposition": "ambiguous_review", "consistent": True, "expected_category_key": None, "expected_display_category_key": None, "reason": "cross-cutting merchandising/family value cannot determine taxonomy"}
    expected = expected_taxonomy(product_kind, display)
    if not expected:
        return {**base, "disposition": "unknown", "consistent": True, "expected_category_key": None, "expected_display_category_key": None, "reason": "no deterministic metadata-only expectation"}
    if category != expected.category_key:
        disposition = "deterministic_mismatch"
    elif display != expected.display_category_key:
        disposition = "display_mismatch"
    else:
        disposition = "consistent"
    return {
        **base,
        "disposition": disposition,
        "consistent": disposition == "consistent",
        "expected_category_key": expected.category_key,
        "expected_display_category_key": expected.display_category_key,
        "reason": expected.reason,
    }

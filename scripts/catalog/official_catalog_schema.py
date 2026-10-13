#!/usr/bin/env python3
"""Canonical ordered schema, validation, and safe file operations for the official catalog."""

from __future__ import annotations

import csv
import json
import os
import shutil
import tempfile
from collections import Counter
from pathlib import Path

from catalog_taxonomy_policy import CATEGORY_FIELD, DISPLAY_FIELD, canonical_values


EXPECTED_COLUMNS = (
    "Type", "SKU", "GTIN, UPC, EAN, or ISBN", "Name", "Published", "Is featured?",
    "Visibility in catalog", "Short description", "Description", "Date sale price starts",
    "Date sale price ends", "Tax status", "Tax class", "In stock?", "Stock",
    "Low stock amount", "Backorders allowed?", "Sold individually?", "Weight (lbs)",
    "Length (in)", "Width (in)", "Height (in)", "Allow customer reviews?", "Purchase note",
    "Sale price", "Regular price", "Categories", "Tags", "Shipping class", "Images",
    "Download limit", "Download expiry days", "Parent", "Grouped products", "Upsells",
    "Cross-sells", "External URL", "Button text", "Position", "Cost of goods", "Brands", "Attribute 1 name",
    "Attribute 1 value(s)", "Attribute 1 visible", "Attribute 1 global", "Attribute 1 default",
    "Meta: schema_brand", "Meta: schema_mpn", "Meta: _dtb_manufacturer_sku", "Meta: _dtb_mpn",
    "Meta: schema_condition", "Meta: _dtb_brand_key", "Meta: _dtb_brand_label",
    "Meta: _dtb_product_kind", "Meta: _dtb_commerce_mode", "Meta: _dtb_category_key",
    "Meta: _dtb_display_category_key", "Meta: _dtb_is_parts", "Meta: _dtb_parent_product_sku",
    "Meta: _dtb_variation_axis", "Meta: _dtb_variation_value", "Meta: _dtb_variation_label",
    "Meta: _dtb_default_variation_sku", "Meta: _dtb_variation_sort",
    "Meta: _dtb_inherit_parent_image", "Meta: _dtb_schematic_brand",
    "Meta: _dtb_schematic_group", "Meta: _dtb_schematic_position",
    "Meta: _dtb_replacement_part_for", "Meta: _dtb_compatible_tool_skus", "Meta: _dtb_brand",
    "Meta: _dtb_specs_json", "Meta: _dtb_features_json", "Meta: _dtb_content_schema_version",
    *tuple(value for slot in range(20) for value in (f"Meta: _includes_{slot}_name", f"Meta: _includes_{slot}_sku")),
    "Meta: _dtb_schematic_id", "Meta: _dtb_schematic_category", "Meta: _dtb_schematic_page",
    "Meta: _dtb_schematic_variant", "Meta: _dtb_schematic_url", "Meta: _dtb_seo_title",
    "Meta: _dtb_seo_description", "Meta: _dtb_seo_focus_kw", "Meta: _dtb_seo_canonical",
    "Meta: _dtb_seo_noindex", "Meta: product_family", "Meta: series", "Meta: model",
    "Meta: _dtb_map_price", "Meta: _brikpanel_cogs", "Meta: _yoast_wpseo_title",
    "Meta: _yoast_wpseo_metadesc", "Meta: _yoast_wpseo_focuskw",
    "Meta: _yoast_wpseo_canonical", "Meta: _yoast_wpseo_meta-robots-noindex",
    "Meta: rank_math_title", "Meta: rank_math_description", "Meta: rank_math_focus_keyword",
    "Meta: rank_math_canonical_url", "Meta: _seopress_titles_title",
    "Meta: _seopress_titles_desc", "Meta: _seopress_analysis_target_kw",
    "Meta: _seopress_robots_canonical", "Meta: _seopress_robots_index",
    "Meta: surerank_settings_post_no_index", "Meta: _wds_title", "Meta: _wds_metadesc",
    "Meta: _wds_canonical", "Meta: _wds_focus-keywords",
    "Meta: _veeqo_sellable_id", "Meta: _veeqo_mapped_sku", "Meta: _veeqo_product_id",
)

ALLOWED_TYPES = {"simple", "variable", "variation"}
ALLOWED_SCHEMA_CONDITIONS = {"NewCondition"}


class CatalogValidationError(RuntimeError):
    """The canonical catalog contract was violated."""


def validate_taxonomy_rows(rows: list[dict[str, str]]) -> dict[str, int]:
    """Require every row to match one canonical owner/inheritance tuple."""
    errors: list[str] = []
    by_sku = {(row.get("SKU") or "").strip(): row for row in rows}
    for row in rows:
        sku = (row.get("SKU") or "").strip()
        is_variation = (row.get("Type") or "").strip().casefold() == "variation"
        parent_sku = (row.get("Parent") or row.get("Meta: _dtb_parent_product_sku") or "").strip()
        parent = by_sku.get(parent_sku) if is_variation else None
        expected = canonical_values(row, parent)
        if expected is None:
            errors.append(f"{sku}: taxonomy does not resolve to a canonical navigation path")
            continue
        for field in ("Categories", CATEGORY_FIELD, DISPLAY_FIELD):
            actual = (row.get(field) or "").strip()
            if actual != expected[field]:
                errors.append(f"{sku}: {field} expected {expected[field]!r}, found {actual!r}")
    if errors:
        preview = "\n - ".join(errors[:50])
        remainder = f"\n - ... {len(errors) - 50} additional error(s)" if len(errors) > 50 else ""
        raise CatalogValidationError(
            f"catalog taxonomy has {len(errors)} validation error(s):\n - {preview}{remainder}"
        )
    return {
        "rows": len(rows),
        "owners": sum(1 for row in rows if (row.get("Type") or "").strip().casefold() != "variation"),
        "variations": sum(1 for row in rows if (row.get("Type") or "").strip().casefold() == "variation"),
    }


def validate_catalog_taxonomy(catalog_path: Path) -> dict[str, int]:
    with catalog_path.open("r", encoding="utf-8-sig", newline="") as handle:
        return validate_taxonomy_rows(list(csv.DictReader(handle)))


def create_catalog_backup(catalog_path: Path, backup_path: Path | None = None) -> Path:
    """Create an atomic rollback snapshot without partially written backup files.

    The default sibling ``.bak`` keeps standalone maintenance commands backward
    compatible. Orchestrators should pass a run-scoped ``backup_path`` so one
    baseline snapshot is retained for the entire workflow.
    """
    destination = backup_path or catalog_path.with_name(catalog_path.name + ".bak")
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary_backup = destination.with_name(destination.name + ".tmp")
    try:
        shutil.copy2(catalog_path, temporary_backup)
        os.replace(temporary_backup, destination)
    except OSError as exc:
        temporary_backup.unlink(missing_ok=True)
        raise CatalogValidationError(f"Cannot create rollback snapshot {destination}: {exc}") from exc
    return destination


def write_catalog_atomic(
    catalog_path: Path,
    fieldnames: list[str] | tuple[str, ...],
    rows: list[dict[str, str]],
) -> None:
    """Atomically replace a canonical CSV while preserving encoding and CRLF rows."""
    fd, temporary_name = tempfile.mkstemp(
        prefix=f".{catalog_path.name}.", suffix=".tmp", dir=catalog_path.parent
    )
    os.close(fd)
    temporary_path = Path(temporary_name)
    try:
        with temporary_path.open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(
                handle,
                fieldnames=fieldnames,
                extrasaction="raise",
                lineterminator="\r\n",
            )
            writer.writeheader()
            writer.writerows(rows)
        os.replace(temporary_path, catalog_path)
    except (OSError, csv.Error) as exc:
        raise CatalogValidationError(f"Cannot atomically write {catalog_path}: {exc}") from exc
    finally:
        temporary_path.unlink(missing_ok=True)


def _load_gap_audit(path: Path) -> dict[tuple[str, int], str]:
    # The gap file is an optional reviewed exception list. A fresh checkout with
    # no exception file means strict mode: no include-name-without-SKU gaps are
    # approved. This keeps validation clone-friendly without weakening policy.
    if not path.is_file():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise CatalogValidationError(f"Cannot load include-gap audit {path}: {exc}") from exc
    if payload.get("schema_version") != 1 or not isinstance(payload.get("entries"), list):
        raise CatalogValidationError(f"{path}: expected schema_version 1 and an entries array")
    result: dict[tuple[str, int], str] = {}
    for entry in payload["entries"]:
        try:
            key = (str(entry["product_sku"]).strip(), int(entry["slot"]))
            name = str(entry["include_name"]).strip()
            reason = str(entry["reason"]).strip()
        except (KeyError, TypeError, ValueError) as exc:
            raise CatalogValidationError(f"{path}: malformed include-gap entry") from exc
        if not key[0] or key[1] not in range(20) or not name or not reason or key in result:
            raise CatalogValidationError(f"{path}: invalid or duplicate include-gap entry {key}")
        result[key] = name
    return result


def validate_catalog(catalog_path: Path, gap_audit_path: Path) -> dict[str, object]:
    """Validate the complete catalog and return deterministic summary counts."""
    try:
        with catalog_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            fields = tuple(reader.fieldnames or ())
            rows = list(reader)
    except (OSError, UnicodeError, csv.Error) as exc:
        raise CatalogValidationError(f"Cannot parse {catalog_path}: {exc}") from exc

    errors: list[str] = []
    if fields != EXPECTED_COLUMNS:
        errors.append(
            f"header mismatch: expected {len(EXPECTED_COLUMNS)} ordered columns, found {len(fields)}"
        )
        for index, (expected, actual) in enumerate(zip(EXPECTED_COLUMNS, fields), start=1):
            if expected != actual:
                errors.append(f"column {index}: expected {expected!r}, found {actual!r}")
                break
    if len(fields) != len(set(fields)):
        errors.append("header contains duplicate columns")
    if any(None in row for row in rows):
        errors.append("one or more rows contain more values than the header")

    sku_counts = Counter((row.get("SKU") or "").strip() for row in rows)
    if sku_counts.get(""):
        errors.append(f"{sku_counts['']} row(s) have a blank SKU")
    duplicates = sorted(sku for sku, count in sku_counts.items() if sku and count > 1)
    if duplicates:
        errors.append("duplicate SKUs: " + ", ".join(duplicates))
    by_sku = {(row.get("SKU") or "").strip(): row for row in rows}

    approved_gaps = _load_gap_audit(gap_audit_path)
    observed_gaps: dict[tuple[str, int], str] = {}
    for line, row in enumerate(rows, start=2):
        sku = (row.get("SKU") or "").strip()
        kind = (row.get("Type") or "").strip()
        if kind not in ALLOWED_TYPES:
            errors.append(f"{sku or f'line {line}'}: unsupported Type {kind!r}")

        stock_status = (row.get("In stock?") or "").strip()
        stock_quantity = (row.get("Stock") or "").strip()
        backorders = (row.get("Backorders allowed?") or "").strip()
        if stock_status not in {"0", "1"}:
            errors.append(f"{sku}: In stock? must be 0 or 1, found {stock_status!r}")
        if backorders not in {"0", "1", "notify"}:
            errors.append(f"{sku}: invalid Backorders allowed? value {backorders!r}")
        if kind in {"simple", "variation"}:
            if not stock_quantity.isdigit():
                errors.append(f"{sku}: sellable product requires a non-negative integer Stock quantity")
            elif stock_status != ("1" if int(stock_quantity) > 0 else "0"):
                errors.append(f"{sku}: In stock? does not match its Stock quantity")
        elif kind == "variable" and stock_quantity:
            errors.append(f"{sku}: variable parent stock must be managed by its variations")

        brand = (row.get("Brands") or "").strip()
        brand_values = {
            "Brands": brand,
            "schema_brand": (row.get("Meta: schema_brand") or "").strip(),
            "_dtb_brand": (row.get("Meta: _dtb_brand") or "").strip(),
            "_dtb_brand_label": (row.get("Meta: _dtb_brand_label") or "").strip(),
        }
        if not brand or len(set(brand_values.values())) != 1:
            errors.append(f"{sku}: brand fields disagree: {brand_values}")

        condition = (row.get("Meta: schema_condition") or "").strip()
        if condition not in ALLOWED_SCHEMA_CONDITIONS:
            errors.append(f"{sku}: invalid schema_condition {condition!r}")

        identifiers = [
            (row.get(field) or "").strip()
            for field in ("Meta: schema_mpn", "Meta: _dtb_manufacturer_sku", "Meta: _dtb_mpn")
            if (row.get(field) or "").strip()
        ]
        if len(set(identifiers)) > 1:
            errors.append(f"{sku}: MPN/manufacturer-SKU fields disagree: {identifiers}")

        raw_features = (row.get("Meta: _dtb_features_json") or "").strip()
        content_version = (row.get("Meta: _dtb_content_schema_version") or "").strip()
        if raw_features or content_version:
            if content_version != "2" or not raw_features:
                errors.append(f"{sku}: structured Features requires schema version 2 and JSON payload")
            else:
                try:
                    features_doc = json.loads(raw_features)
                    if (not isinstance(features_doc, dict)
                            or list(features_doc) != ["schema_version", "features"]
                            or features_doc["schema_version"] != 1
                            or not isinstance(features_doc["features"], list)
                            or len(features_doc["features"]) > 50):
                        raise ValueError("unexpected feature document shape")
                    for feature in features_doc["features"]:
                        if (not isinstance(feature, dict)
                                or list(feature) != ["label", "description"]
                                or not all(isinstance(feature.get(k), str)
                                           and feature[k].strip()
                                           and len(feature[k]) <= limit
                                           for k, limit in (("label", 160), ("description", 2000)))):
                            raise ValueError("invalid feature entry")
                except (ValueError, TypeError, KeyError) as exc:
                    errors.append(f"{sku}: invalid structured Features: {exc}")

        raw_specs = (row.get("Meta: _dtb_specs_json") or "").strip()
        try:
            specs = json.loads(raw_specs)
            if not isinstance(specs, list):
                raise ValueError("must be a JSON array")
        except (json.JSONDecodeError, ValueError) as exc:
            errors.append(f"{sku}: invalid _dtb_specs_json: {exc}")

        parent = (row.get("Parent") or "").strip()
        dtb_parent = (row.get("Meta: _dtb_parent_product_sku") or "").strip()
        if kind == "variation":
            required = {
                "Parent": parent,
                "_dtb_parent_product_sku": dtb_parent,
                "_dtb_variation_axis": (row.get("Meta: _dtb_variation_axis") or "").strip(),
                "_dtb_variation_value": (row.get("Meta: _dtb_variation_value") or "").strip(),
                "_dtb_variation_sort": (row.get("Meta: _dtb_variation_sort") or "").strip(),
            }
            missing = [name for name, field_value in required.items() if not field_value]
            if missing:
                errors.append(f"{sku}: variation missing required fields: {', '.join(missing)}")
            if parent != dtb_parent:
                errors.append(f"{sku}: Parent and _dtb_parent_product_sku disagree")
            if parent and (by_sku.get(parent) or {}).get("Type") != "variable":
                errors.append(f"{sku}: variation parent {parent!r} is not a variable product")
            elif parent:
                parent_row = by_sku[parent]
                parent_axis = (parent_row.get("Attribute 1 name") or "").strip()
                variation_axis = (row.get("Attribute 1 name") or "").strip()
                variation_value = (row.get("Attribute 1 value(s)") or "").strip()
                parent_values = [
                    option.strip()
                    for option in (parent_row.get("Attribute 1 value(s)") or "").split(",")
                    if option.strip()
                ]
                if variation_axis != parent_axis:
                    errors.append(
                        f"{sku}: variation attribute {variation_axis!r} does not match parent {parent_axis!r}"
                    )
                if variation_value not in parent_values:
                    errors.append(
                        f"{sku}: variation value {variation_value!r} is not an exact parent option"
                    )
        elif parent or dtb_parent:
            errors.append(f"{sku}: non-variation contains a parent SKU")

        default_variation = (row.get("Meta: _dtb_default_variation_sku") or "").strip()
        if kind == "variable":
            if not default_variation:
                errors.append(f"{sku}: variable product lacks _dtb_default_variation_sku")
            elif (by_sku.get(default_variation) or {}).get("Parent") != sku:
                errors.append(f"{sku}: default variation {default_variation!r} is not its child")
        elif default_variation:
            errors.append(f"{sku}: _dtb_default_variation_sku only applies to variable products")

        for slot in range(20):
            name = (row.get(f"Meta: _includes_{slot}_name") or "").strip()
            component_sku = (row.get(f"Meta: _includes_{slot}_sku") or "").strip()
            if component_sku and not name:
                errors.append(f"{sku}: include slot {slot} has a SKU without a name")
            if name and not component_sku:
                observed_gaps[(sku, slot)] = name

    for key, name in sorted(observed_gaps.items()):
        if approved_gaps.get(key) != name:
            errors.append(f"{key[0]}: unreviewed include-name gap at slot {key[1]}: {name!r}")
    for key, name in sorted(approved_gaps.items()):
        if observed_gaps.get(key) != name:
            errors.append(f"include-gap audit is stale for {key[0]} slot {key[1]}")

    if errors:
        preview = "\n - ".join(errors[:50])
        remainder = f"\n - ... {len(errors) - 50} additional error(s)" if len(errors) > 50 else ""
        raise CatalogValidationError(f"{catalog_path}: {len(errors)} validation error(s):\n - {preview}{remainder}")
    return {
        "columns": len(fields),
        "rows": len(rows),
        "include_name_without_sku_reviewed": len(observed_gaps),
    }

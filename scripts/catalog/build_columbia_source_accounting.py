#!/usr/bin/env python3
"""Build a complete product/variant accounting for the current Columbia export.

The report is review evidence only. It does not change the working reference,
the official catalog, WooCommerce, or Veeqo.
"""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter, defaultdict
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
AUDIT = ROOT / "docs" / "catalog" / "columbia-catalog-audit"
EXPORT = AUDIT / "columbia-catalog-export" / "products.json"
SELECTED = AUDIT / "review" / "manufacturer-rebuild-dispositions.csv"
EXCLUDED_KITS = AUDIT / "review" / "excluded-manufacturer-part-kits.csv"
EXCLUDED_PACKS = AUDIT / "review" / "excluded-manufacturer-packs.csv"
TSW_COSTS = ROOT / "products" / "catalog" / "source" / "tsw-costs.csv"
CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
OUTPUT = AUDIT / "review" / "current-manufacturer-source-accounting.csv"
SUMMARY = AUDIT / "review" / "current-manufacturer-source-accounting-summary.json"
PROVENANCE = AUDIT / "review" / "current-manufacturer-source-provenance.csv"
EXCEPTIONS = AUDIT / "review" / "current-manufacturer-source-exceptions.csv"

FIELDS = [
    "source_product_id",
    "source_variant_id",
    "source_sku",
    "source_name",
    "source_product_type",
    "source_variant_title",
    "source_price",
    "source_handle",
    "source_url",
    "source_image_files",
    "source_collections",
    "historical_selection",
    "historical_disposition",
    "dtb_candidate_sku",
    "dtb_candidate_name",
    "canonical_exact_sku_matches",
    "canonical_exact_identifier_matches",
    "historical_exact_sku_supplier_cost",
    "historical_supplier_cost_product_name",
    "supplier_cost_evidence_status",
    "source_sku_occurrences",
    "source_prices_for_sku",
    "current_disposition",
    "disposition_reason",
    "blocking_facts",
]


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> int:
    products = json.loads(EXPORT.read_text(encoding="utf-8-sig"))
    selected = {row["source_product_id"]: row for row in read_csv(SELECTED)}
    historical_exclusions = {
        row["source_product_id"]: (row, "historically_excluded")
        for row in read_csv(EXCLUDED_KITS)
    }
    historical_exclusions.update(
        {
            row["source_product_id"]: (row, "historically_excluded")
            for row in read_csv(EXCLUDED_PACKS)
        }
    )
    catalog = read_csv(CATALOG)
    supplier_cost_rows = read_csv(TSW_COSTS)
    supplier_costs: dict[str, list[dict[str, str]]] = defaultdict(list)
    for cost_row in supplier_cost_rows:
        if cost_row.get("brand", "").strip() == "Columbia Tools" and cost_row.get("sku", "").strip():
            supplier_costs[cost_row["sku"].strip().upper()].append(cost_row)
    catalog_skus: dict[str, list[dict[str, str]]] = defaultdict(list)
    catalog_identifiers: dict[str, list[tuple[str, dict[str, str]]]] = defaultdict(list)
    identifier_fields = [
        field for field in catalog[0]
        if field == "SKU"
        or any(token in field.casefold() for token in ("mpn", "manufacturer_sku", "gtin", "part_number"))
    ]
    for row in catalog:
        sku = (row.get("SKU") or "").strip()
        if sku:
            catalog_skus[sku.upper()].append(row)
        if row.get("Brands") != "Columbia Tools":
            continue
        for field in identifier_fields:
            identifier = (row.get(field) or "").strip()
            if identifier:
                catalog_identifiers[identifier.upper()].append((field, row))

    variants = [
        (product, variant)
        for product in products
        for variant in (product.get("variants") or [{}])
    ]
    sku_products: dict[str, list[tuple[dict, dict]]] = defaultdict(list)
    for product, variant in variants:
        sku = str(variant.get("sku") or "").strip()
        if sku:
            sku_products[sku.upper()].append((product, variant))

    output_rows: list[dict[str, str]] = []
    disposition_counts: Counter[str] = Counter()
    source_type_counts: Counter[str] = Counter()
    for product, variant in variants:
        product_id = str(product.get("id") or "")
        variant_id = str(variant.get("id") or "")
        sku = str(variant.get("sku") or "").strip()
        normalized_sku = sku.upper()
        product_type = str(product.get("product_type") or "")
        source_type_counts[product_type] += 1
        prior = selected.get(product_id)
        excluded_record = historical_exclusions.get(product_id)
        exact_catalog = catalog_skus.get(normalized_sku, []) if sku else []
        exact_identifier_hits = catalog_identifiers.get(normalized_sku, []) if sku else []
        exact_supplier_costs = supplier_costs.get(normalized_sku, []) if sku else []
        supplier_cost = ""
        supplier_cost_product_name = ""
        supplier_cost_status = "no_exact_supplier_cost_snapshot_row"
        if len(exact_supplier_costs) == 1:
            supplier_cost = exact_supplier_costs[0].get("supplier_cost", "")
            supplier_cost_product_name = exact_supplier_costs[0].get("product_name", "")
            supplier_cost_status = "historical_exact_sku_cost_snapshot_unverified_current"
        elif len(exact_supplier_costs) > 1:
            supplier_cost_status = "duplicate_exact_sku_cost_snapshot_rows_review"
        exact_identifier_evidence = []
        seen_identifier_rows = set()
        for field, matched_row in exact_identifier_hits:
            row_sku = (matched_row.get("SKU") or "").strip()
            row_identity = (row_sku, matched_row.get("Name", ""))
            if row_identity in seen_identifier_rows:
                continue
            seen_identifier_rows.add(row_identity)
            matched_fields = sorted(
                hit_field for hit_field, hit_row in exact_identifier_hits
                if hit_row is matched_row
            )
            exact_identifier_evidence.append(
                f"{row_sku} ({matched_row.get('Name', '')}) via {', '.join(matched_fields)}"
            )
        source_occurrences = sku_products.get(normalized_sku, []) if sku else []
        conflicting_prices = sorted(
            {
                str(source_variant.get("price") or "")
                for _, source_variant in source_occurrences
            }
        )

        candidate_sku = ""
        candidate_name = ""
        historical_disposition = ""
        historical_selection = "selected_in_1006_row_reference"
        if prior:
            candidate_sku = prior.get("dtb_sku", "")
            candidate_name = prior.get("dtb_name", "")
            historical_disposition = prior.get("disposition", "")
            current_disposition = historical_disposition or "unresolved_missing_historical_disposition"
            reason = "Existing manufacturer disposition retained as review evidence; candidate status does not establish identity."
            blockers = "Existing field, identity, taxonomy, media, pricing/cost, and commercial review gates still apply."
        elif excluded_record:
            historical, _ = excluded_record
            historical_selection = "historically_excluded_from_1006_row_reference"
            historical_disposition = historical.get("reason", "")
            if product_type == "Parts Kit":
                current_disposition = "include_candidate_pending_taxonomy_and_cost"
                reason = "Reopened for inclusion review: manufacturer lists a discrete parts-kit SKU; the historic type-only exclusion is not accepted as final disposition."
                blockers = "No exact canonical SKU row; verify product kind/category and a valid supplier cost before publication."
            else:
                current_disposition = "include_candidate_pending_taxonomy_and_cost"
                reason = "Reopened for inclusion review: manufacturer lists a discrete professional joint-knife blister-pack SKU; the historic pack exclusion is not accepted as final disposition."
                blockers = "No exact canonical SKU row; verify product kind/category, pack contents, sellable unit, and a valid supplier cost before publication."
        else:
            historical_selection = "not_in_1006_row_reference_or_historical_exclusion_list"
            current_disposition = "exclude_merchandise_out_of_scope"
            reason = "Columbia merchandise (apparel/accessory), outside DTB's contractor tool, parts, and service catalog scope; manufacturer provides no SKU."
            blockers = "No manufacturer SKU; no candidate catalog row will be created."

        if sku and len(source_occurrences) > 1:
            current_disposition = "unresolved_duplicate_manufacturer_sku"
            reason = "Manufacturer export contains multiple product records for the same exact SKU; do not choose a record or price by row order."
            blockers = "Resolve duplicate manufacturer identity and price from current official product evidence before catalog inclusion."

        disposition_counts[current_disposition] += 1
        output_rows.append(
            {
                "source_product_id": product_id,
                "source_variant_id": variant_id,
                "source_sku": sku,
                "source_name": str(product.get("title") or ""),
                "source_product_type": product_type,
                "source_variant_title": str(variant.get("title") or ""),
                "source_price": str(variant.get("price") or ""),
                "source_handle": str(product.get("handle") or ""),
                "source_url": (
                    f"https://shopcolumbiatools.com/products/{product.get('handle')}"
                    if product.get("handle")
                    else ""
                ),
                "source_image_files": " | ".join(
                    str(image.get("src") or image.get("url") or image.get("file") or "")
                    if isinstance(image, dict)
                    else str(image)
                    for image in (product.get("images") or [])
                ),
                "source_collections": " | ".join(
                    str(item.get("title") or item.get("handle") or "")
                    if isinstance(item, dict)
                    else str(item)
                    for item in (product.get("collections") or [])
                ),
                "historical_selection": historical_selection,
                "historical_disposition": historical_disposition,
                "dtb_candidate_sku": candidate_sku,
                "dtb_candidate_name": candidate_name,
                "canonical_exact_sku_matches": str(len(exact_catalog)),
                "canonical_exact_identifier_matches": " | ".join(exact_identifier_evidence),
                "historical_exact_sku_supplier_cost": supplier_cost,
                "historical_supplier_cost_product_name": supplier_cost_product_name,
                "supplier_cost_evidence_status": supplier_cost_status,
                "source_sku_occurrences": str(len(source_occurrences)),
                "source_prices_for_sku": " | ".join(conflicting_prices),
                "current_disposition": current_disposition,
                "disposition_reason": reason,
                "blocking_facts": blockers,
            }
        )

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with OUTPUT.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(
            handle, fieldnames=FIELDS, extrasaction="raise", lineterminator="\r\n"
        )
        writer.writeheader()
        writer.writerows(output_rows)

    provenance_fields = [
        "source_product_id", "source_variant_id", "source_sku", "source_name",
        "source_product_type", "source_variant_title", "source_price", "source_handle",
        "source_url", "source_image_files", "source_collections", "manufacturer_source_file",
        "historical_dtb_sku_candidate", "historical_dtb_name_candidate",
        "historical_disposition", "provenance_note",
    ]
    with PROVENANCE.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(
            handle, fieldnames=provenance_fields, extrasaction="ignore", lineterminator="\r\n"
        )
        writer.writeheader()
        for row in output_rows:
            writer.writerow(
                {
                    **row,
                    "manufacturer_source_file": str(EXPORT.relative_to(ROOT)).replace("\\", "/"),
                    "historical_dtb_sku_candidate": row["dtb_candidate_sku"],
                    "historical_dtb_name_candidate": row["dtb_candidate_name"],
                    "provenance_note": "Product and variant facts come from the source JSON export; proposed disposition is separately recorded and is not identity proof.",
                }
            )

    exception_rows = [
        row
        for row in output_rows
        if row["current_disposition"].startswith("unresolved_")
        or row["current_disposition"].startswith("include_candidate_")
        or row["current_disposition"] in {"new_product_candidate", "unresolved_possible_match", "unresolved_alternate_identifier", "unresolved_identifier_conflict", "unresolved_catalog_sku_collision", "unresolved_source_identity"}
        or not row["source_product_type"].strip()
    ]
    with EXCEPTIONS.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(
            handle, fieldnames=FIELDS, extrasaction="raise", lineterminator="\r\n"
        )
        writer.writeheader()
        writer.writerows(exception_rows)

    product_dispositions: dict[str, str] = {}
    for row in output_rows:
        product_id = row["source_product_id"]
        current = row["current_disposition"]
        if product_id not in product_dispositions or current == "unresolved_duplicate_manufacturer_sku":
            product_dispositions[product_id] = current

    summary = {
        "schema_version": 1,
        "purpose": "complete current manufacturer product/variant accounting; review evidence only, not import-ready",
        "source_products": len(products),
        "source_variant_rows": len(variants),
        "source_variant_rows_with_sku": sum(bool(str(v.get("sku") or "").strip()) for _, v in variants),
        "source_variant_rows_without_sku": sum(not bool(str(v.get("sku") or "").strip()) for _, v in variants),
        "historical_selected_product_count": len(selected),
        "historical_excluded_product_count": len(historical_exclusions),
        "current_dispositions_by_variant": dict(sorted(disposition_counts.items())),
        "current_dispositions_by_product": dict(sorted(Counter(product_dispositions.values()).items())),
        "review_exception_variant_rows": len(exception_rows),
        "product_type_variant_counts": dict(sorted(source_type_counts.items())),
        "duplicate_manufacturer_skus": {
            sku: {
                "occurrences": len(records),
                "product_ids": sorted({str(product.get("id") or "") for product, _ in records}),
                "names": sorted({str(product.get("title") or "") for product, _ in records}),
                "prices": sorted({str(variant.get("price") or "") for _, variant in records}),
            }
            for sku, records in sorted(sku_products.items())
            if len(records) > 1
        },
        "input_sha256": {
            "products_json": sha256(EXPORT),
            "selected_dispositions": sha256(SELECTED),
            "excluded_kits": sha256(EXCLUDED_KITS),
            "excluded_packs": sha256(EXCLUDED_PACKS),
            "historical_tsw_cost_snapshot": sha256(TSW_COSTS),
            "canonical_catalog": sha256(CATALOG),
        },
        "historical_exact_sku_tsw_cost_snapshot": {
            "source_file": str(TSW_COSTS.relative_to(ROOT)).replace("\\", "/"),
            "source_last_committed": "2026-09-09",
            "authority_note": "Historical repository snapshot only; the Columbia cost audit found no live quote, invoice, or current purchase order. Do not use as current cost without reconfirmation.",
            "unique_source_skus_with_one_exact_cost_row": sum(
                1 for sku in {row["source_sku"].upper() for row in output_rows if row["source_sku"]}
                if len(supplier_costs.get(sku, [])) == 1
            ),
            "rows_with_exact_cost_snapshot": sum(
                row["supplier_cost_evidence_status"] == "historical_exact_sku_cost_snapshot_unverified_current"
                for row in output_rows
            ),
            "rows_with_duplicate_cost_snapshot": sum(
                row["supplier_cost_evidence_status"] == "duplicate_exact_sku_cost_snapshot_rows_review"
                for row in output_rows
            ),
        },
        "output_csv": str(OUTPUT.relative_to(ROOT)).replace("\\", "/"),
        "provenance_csv": str(PROVENANCE.relative_to(ROOT)).replace("\\", "/"),
        "exceptions_csv": str(EXCEPTIONS.relative_to(ROOT)).replace("\\", "/"),
    }
    SUMMARY.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

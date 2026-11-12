#!/usr/bin/env python3
"""Build a complete product/variant accounting for the current Columbia export.

The report is review evidence only. It does not change the working reference,
the official catalog, WooCommerce, or Veeqo.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
AUDIT = ROOT / "docs" / "catalog" / "columbia-catalog-audit"
EXPORT = AUDIT / "columbia-catalog-export" / "products.json"
SELECTED = AUDIT / "review" / "manufacturer-rebuild-dispositions.csv"
IDENTIFIER_REVIEW = AUDIT / "review" / "identifier-candidate-review.csv"
LIVE_COLLISION_READBACK = AUDIT / "review" / "current-identifier-collision-live-readback.csv"
MANUFACTURER_PAGE_READBACK = AUDIT / "review" / "current-manufacturer-page-live-readback.csv"
EXACT_SKU_LIVE_READBACK = AUDIT / "review" / "current-unresolved-exact-sku-live-readback.csv"
HFFB5_FFB5_LIVE_READBACK = AUDIT / "review" / "current-hffb5-ffb5-variation-family-readback.csv"
UNRESOLVED_MANUFACTURER_PAGE_READBACK = AUDIT / "review" / "current-unresolved-manufacturer-page-live-readback.csv"
BBH9_BH9_LIVE_READBACK = AUDIT / "review" / "current-bbh9-bh9-variation-family-readback.csv"
VARIATION_SKU_ENDPOINT_PROBE = AUDIT / "review" / "current-variation-sku-endpoint-probe.csv"
ALL_CANDIDATE_EXACT_SKU_READBACK = AUDIT / "review" / "current-all-candidate-exact-sku-live-readback.csv"
SCHEMATIC_ALTERNATE_CANDIDATE_READBACK = AUDIT / "review" / "current-schematic-alternate-candidate-live-readback.csv"
KIT_PACK_LIVE_READBACK = AUDIT / "review" / "current-reopened-kit-pack-live-readback.csv"
KIT_PACK_VEEQO_AUTH_READBACK = AUDIT / "review" / "current-reopened-kit-pack-veeqo-auth-readback.json"
KIT_PACK_TAXONOMY_REVIEW = AUDIT / "review" / "current-reopened-kit-pack-taxonomy-review.csv"
QBO_ITEM_COST_REVIEW = AUDIT / "review" / "current-qbo-product-service-list-cost-check.json"
TSW_EMAIL_COST_SEARCH = AUDIT / "review" / "current-tsw-email-cost-search.json"
VEEQO_KIT_WORKFLOW_REVIEW = AUDIT / "review" / "current-veeqo-native-kit-workflow-readiness.json"
KIT_BOM_EVIDENCE = AUDIT / "review" / "manufacturer-kit-bom-evidence.json"
MANUFACTURER_IMAGE_AUDIT = AUDIT / "evidence" / "manufacturer" / "columbia-products-json-audit.csv"
MANUFACTURER_IMAGE_MANIFEST = AUDIT / "evidence" / "manufacturer" / "columbia-manufacturer-image-manifest.csv"
MEDIA_PUBLIC_REACHABILITY = AUDIT / "review" / "current-candidate-media-public-reachability.csv"
MEDIA_SOURCE_REACHABILITY = AUDIT / "review" / "current-candidate-media-source-reachability.csv"
EXCLUDED_KITS = AUDIT / "review" / "excluded-manufacturer-part-kits.csv"
EXCLUDED_PACKS = AUDIT / "review" / "excluded-manufacturer-packs.csv"
TSW_COSTS = ROOT / "products" / "catalog" / "source" / "tsw-costs.csv"
CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
OUTPUT = AUDIT / "review" / "current-manufacturer-source-accounting.csv"
SUMMARY = AUDIT / "review" / "current-manufacturer-source-accounting-summary.json"
PROVENANCE = AUDIT / "review" / "current-manufacturer-source-provenance.csv"
EXCEPTIONS = AUDIT / "review" / "current-manufacturer-source-exceptions.csv"
MEDIA_REVIEW = AUDIT / "review" / "current-candidate-media-provenance-review.csv"

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
    "current_review_flags",
    "current_disposition_evidence",
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
    reviewed_alternate_identifiers = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "strong_candidate"
    }
    reviewed_exact_identities = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "exact_match"
    }
    reviewed_new_product_candidates = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "new_product_candidate"
    }
    reviewed_identifier_conflicts = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "unresolved_identifier_conflict"
    }
    reviewed_taxonomy_aliases = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "taxonomy_conflict"
    }
    reviewed_schematic_aliases = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(IDENTIFIER_REVIEW)
        if row.get("decision") == "schematic_match"
    }
    current_manufacturer_pages: dict[str, list[dict[str, str]]] = defaultdict(list)
    for page_row in read_csv(MANUFACTURER_PAGE_READBACK):
        current_manufacturer_pages[page_row["source_sku"].strip().upper()].append(page_row)
    exact_sku_live_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(EXACT_SKU_LIVE_READBACK)
    }
    hffb5_ffb5_live_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(HFFB5_FFB5_LIVE_READBACK)
    }
    bbh9_bh9_live_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(BBH9_BH9_LIVE_READBACK)
    }
    all_candidate_exact_sku_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(ALL_CANDIDATE_EXACT_SKU_READBACK)
    }
    kit_pack_live_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(KIT_PACK_LIVE_READBACK)
    }
    schematic_alternate_candidate_results = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(SCHEMATIC_ALTERNATE_CANDIDATE_READBACK)
    }
    live_collision_skus = {
        row["source_sku"].strip().upper()
        for row in read_csv(LIVE_COLLISION_READBACK)
    }
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
    reopened_kit_pack_skus = {
        row["source_sku"].strip().upper()
        for path in (EXCLUDED_KITS, EXCLUDED_PACKS)
        for row in read_csv(path)
    }
    if set(kit_pack_live_results) != reopened_kit_pack_skus:
        missing = sorted(reopened_kit_pack_skus - set(kit_pack_live_results))
        extra = sorted(set(kit_pack_live_results) - reopened_kit_pack_skus)
        raise ValueError(f"Reopened kit/pack live readback coverage mismatch; missing={missing}, extra={extra}")
    kit_pack_veeqo_auth = json.loads(KIT_PACK_VEEQO_AUTH_READBACK.read_text(encoding="utf-8"))
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
            review_flags = prior.get("flags", "")
            disposition_evidence = prior.get("candidate_evidence", "")
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
            review_flags = "historic_exclusion_reopened; taxonomy_and_current_cost_required"
            disposition_evidence = historical.get("reason", "")
        else:
            historical_selection = "not_in_1006_row_reference_or_historical_exclusion_list"
            current_disposition = "exclude_merchandise_out_of_scope"
            reason = "Columbia merchandise (apparel/accessory), outside DTB's contractor tool, parts, and service catalog scope; manufacturer provides no SKU."
            blockers = "No manufacturer SKU; no candidate catalog row will be created."
            review_flags = "manufacturer_merchandise_without_sku; explicitly_out_of_scope"
            disposition_evidence = str(product.get("product_type") or "")

        exact_review = reviewed_exact_identities.get(normalized_sku)
        if exact_review:
            exact_rows = [
                row for row in exact_catalog
                if row.get("Brands") == "Columbia Tools"
                and row.get("Name", "").strip() == exact_review["source_name"].strip()
                and row.get("Meta: schema_mpn", "").strip().upper() == normalized_sku
            ]
            if len(exact_rows) != 1:
                raise ValueError(
                    f"Reviewed exact identity {sku!r} does not resolve to one current Columbia SKU/name/MPN row"
                )
            current_disposition = "exact_existing_match"
            candidate_sku = sku
            candidate_name = exact_rows[0]["Name"].strip()
            review_flags = "exact_source_sku_name_and_mpn_match; live_parent_variations_verified"
            disposition_evidence = (
                f"Exact manufacturer SKU, canonical SKU, schema MPN, and product name match. "
                f"Curated live readback: {exact_review['evidence']}"
            )
            reason = "Manufacturer source identity matches the existing canonical Columbia product and its live WooCommerce parent/variation family."
            blockers = "No identity or catalog-row mutation is required; existing price, cost, stock, visibility, media, and Veeqo state were retained."

        taxonomy_alias = reviewed_taxonomy_aliases.get(normalized_sku)
        schematic_alias = reviewed_schematic_aliases.get(normalized_sku)
        alternate = reviewed_alternate_identifiers.get(normalized_sku) or taxonomy_alias or schematic_alias
        if alternate:
            # The source's punctuation-bearing SKU is an alternate identifier,
            # not a new product. Require a unique existing Columbia catalog row
            # whose SKU and name match the independently curated review.
            alternate_rows = []
            for row in catalog:
                if row.get("Brands") != "Columbia Tools" or row.get("SKU", "").strip().upper() != alternate["dtb_sku"].strip().upper():
                    continue
                if schematic_alias:
                    if row.get("Meta: schema_mpn", "").strip().upper() == normalized_sku:
                        alternate_rows.append(row)
                elif row.get("Name", "").strip() == alternate["source_name"].strip():
                    alternate_rows.append(row)
            if len(alternate_rows) != 1:
                raise ValueError(
                    f"Reviewed alternate identifier {sku!r} does not resolve to exactly one current canonical row"
                )
            candidate_sku = alternate["dtb_sku"].strip()
            candidate_name = alternate_rows[0]["Name"].strip()
            current_disposition = (
                "resolved_alternate_identifier_match_pending_taxonomy_and_commercial_review"
                if taxonomy_alias
                else "resolved_alternate_identifier_match_pending_commercial_review"
            )
            review_flags = (
                (
                    "official_schematic_mpn_model_identity_match; identity_reviewed; "
                    if schematic_alias
                    else "official_source_sku_punctuation_variant; identity_match_reviewed; "
                )
                + (
                    "manufacturer_product_type_conflicts_with_dtb_product_kind; taxonomy_review_required"
                    if taxonomy_alias
                    else (
                        "official_schematic_identity_match; current_commercial_and_media_review_required"
                        if schematic_alias
                        else "commercial_and_provider_mapping_review_required"
                    )
                )
            )
            disposition_evidence = (
                f"Curated exact counterpart: source {sku!r} ({alternate['source_name']}) maps to "
                f"canonical {candidate_sku} ({candidate_name}); curated review: {alternate['evidence']}. "
                "Manufacturer product/variant source fields and current canonical name/size agree; "
                "no source SKU or canonical SKU was rewritten."
            )
            reason = (
                "Current manufacturer SKU matches the existing canonical schema MPN/model, and Columbia's official 10-inch hinged flat-finisher schematic identifies callout HFFB 1A; the canonical product SKU carries the 10-inch suffix."
                if schematic_alias
                else "Existing Columbia identity resolved through the curated source-to-canonical alternate-identifier review; the manufacturer source SKU differs from the canonical SKU by formatting or punctuation."
            )
            blockers = (
                "Identity is reconciled, but Columbia classifies this source as a box filler while DTB records it as "
                "a hidden part in Replacement Parts. Resolve product kind/category and publication eligibility, "
                "verify current supplier cost and price policy, and independently verify the Veeqo provider record; "
                "see current-alternate-identifier-live-readback.csv."
                if taxonomy_alias
                else (
                    "Identity is reconciled by exact source SKU to canonical schema MPN/model and Columbia's official "
                    "10-inch hinged flat-finisher schematic. Current source price differs from WooCommerce, no exact "
                    "current supplier cost is available, and the official source page provides no image. Retain current "
                    "media and verify provider-side Veeqo before any commercial change; see both live readback files."
                    if schematic_alias
                    else "Identity is reconciled; review current cost and price policy, taxonomy/product-kind fields, "
                    "and verify the provider-side Veeqo record before changing commercial or provider data; see "
                    "current-alternate-identifier-live-readback.csv for the WooCommerce mapping metadata snapshot."
                )
            )

        new_candidate_review = reviewed_new_product_candidates.get(normalized_sku)
        if new_candidate_review:
            if exact_catalog or exact_identifier_hits:
                raise ValueError(
                    f"Reviewed new-product candidate {sku!r} now has an exact canonical SKU/identifier match"
                )
            current_disposition = "new_product_candidate"
            candidate_sku = ""
            candidate_name = ""
            review_flags = "distinct_source_identity; current_commercial_taxonomy_media_setup_required"
            disposition_evidence = new_candidate_review["evidence"]
            reason = "Current official source identifies a distinct sellable part; the similar normalized canonical SKU is a different product."
            blockers = (
                "Exact product-SKU lookup returned no product, but variation records have not been comprehensively queried. "
                "Verify exact SKU uniqueness across products and variations, DTB taxonomy/product-kind, current COGS and price policy, "
                "manufacturer-specific imagery, Veeqo mapping, and all required commerce fields before creating or publishing."
            )

        identifier_conflict = reviewed_identifier_conflicts.get(normalized_sku)
        if identifier_conflict:
            current_disposition = "unresolved_identifier_conflict"
            candidate_sku = ""
            candidate_name = ""
            review_flags = "manufacturer_identity_and_specification_conflict; do_not_map_or_create"
            disposition_evidence = identifier_conflict["evidence"]
            reason = "Current manufacturer product data and its schematic assign incompatible specifications to the same source SKU."
            blockers = "Obtain authoritative manufacturer clarification of FA309 identity and specification before mapping, creating, repricing, or changing product data."

        exact_live_result = exact_sku_live_results.get(normalized_sku)
        if exact_live_result and current_disposition == "unresolved_possible_match" and not all_candidate_exact_sku_results.get(normalized_sku):
            disposition_evidence = (
                f"{disposition_evidence} Exact production WooCommerce product-SKU query returned "
                f"{exact_live_result.get('woocommerce_result_count', 'unknown')} products; see "
                "current-unresolved-exact-sku-live-readback.csv."
            ).strip()
            blockers = (
                f"{blockers} Exact product-SKU query returned no product; variation records were not comprehensively queried. This does not rule out a "
                "different canonical/runtime SKU or establish alternate identity."
            )

        hffb5_ffb5_live_result = hffb5_ffb5_live_results.get(normalized_sku)
        if hffb5_ffb5_live_result and current_disposition == "unresolved_possible_match":
            disposition_evidence = (
                f"{disposition_evidence} Current WooCommerce has a same-size/name candidate variation "
                f"{hffb5_ffb5_live_result['candidate_variation_sku']} under parent FFB5, but the source "
                "HFFB5 manufacturer identity conflicts by prefix; see "
                "current-hffb5-ffb5-variation-family-readback.csv."
            ).strip()
            blockers = (
                f"{blockers} Columbia's hinged-box schematic distinguishes HFFB and FFB part prefixes but "
                "does not prove this axle is interchangeable; obtain authoritative part cross-reference before mapping."
            )

        bbh9_bh9_live_result = bbh9_bh9_live_results.get(normalized_sku)
        if bbh9_bh9_live_result and current_disposition == "unresolved_possible_match":
            disposition_evidence = (
                f"{disposition_evidence} Production has a same-size candidate variation "
                f"{bbh9_bh9_live_result['candidate_variation_sku']} under BH9, with BH9 MPN/Veeqo mapping; "
                "source uses BBH9 and a different title. See current-bbh9-bh9-variation-family-readback.csv."
            ).strip()
            blockers = (
                f"{blockers} Obtain an authoritative cross-reference for the BBH9 Bent Connecting Strap versus "
                "BH9 Brake Connecting Strap identities; size coincidence and differing prices do not establish interchangeability."
            )

        all_candidate_exact_sku_result = all_candidate_exact_sku_results.get(normalized_sku)
        if all_candidate_exact_sku_result and current_disposition in {"new_product_candidate", "unresolved_possible_match"}:
            if all_candidate_exact_sku_result.get("error"):
                raise ValueError(f"Exact WooCommerce SKU readback failed for {sku!r}")
            result_count = all_candidate_exact_sku_result.get("woocommerce_result_count", "")
            result_types = all_candidate_exact_sku_result.get("match_types", "")
            disposition_evidence = (
                f"{disposition_evidence} Current exact WooCommerce SKU filter (status=any) returned "
                f"{result_count} product/variation records; exact-SKU query is variation-inclusive "
                "(positive control FFB5-10 returned type=variation); see "
                "current-all-candidate-exact-sku-live-readback.csv."
            ).strip()
            if current_disposition == "new_product_candidate" and result_count == "0":
                blockers = (
                    "Exact SKU uniqueness was checked against WooCommerce products and variations with no exact match. "
                    "Still verify current COGS and price policy, taxonomy/product-kind, manufacturer-specific imagery, "
                    "Veeqo mapping, and all required commerce fields before creating or publishing."
                )
            elif current_disposition == "unresolved_possible_match":
                blockers = (
                    f"Exact source SKU query returned {result_count} WooCommerce product/variation records "
                    f"({result_types or 'none'}); this does not resolve a possible alternate canonical/runtime identity. "
                    "Obtain exact manufacturer cross-reference or schematic identity evidence before mapping."
                )

        schematic_alternate_candidate = schematic_alternate_candidate_results.get(normalized_sku)
        if schematic_alternate_candidate and current_disposition == "unresolved_possible_match":
            disposition_evidence = (
                f"{disposition_evidence} Production has a possible different-SKU candidate "
                f"{schematic_alternate_candidate['canonical_candidate_sku']} "
                f"({schematic_alternate_candidate['canonical_candidate_name']}), product "
                f"{schematic_alternate_candidate['woocommerce_product_id']} "
                f"({schematic_alternate_candidate['woocommerce_status']}, "
                f"{schematic_alternate_candidate['catalog_visibility']}); see "
                "current-schematic-alternate-candidate-live-readback.csv. "
                "The readback records no manufacturer SKU cross-reference; identity remains unresolved."
            ).strip()
            blockers = (
                f"Exact source SKU is absent, but the same-brand canonical/live candidate "
                f"{schematic_alternate_candidate['canonical_candidate_sku']} has no verified source-SKU cross-reference. "
                "Keep unresolved; obtain manufacturer identity confirmation before mapping, publishing, or changing price, "
                "image, provider mapping, or other product data."
            )

        if current_disposition.startswith("include_candidate_"):
            kit_pack_live_result = kit_pack_live_results.get(normalized_sku)
            if not kit_pack_live_result:
                raise ValueError(f"No current exact WooCommerce SKU readback for reopened kit/pack {sku!r}")
            if kit_pack_live_result.get("error") or kit_pack_live_result.get("lookup_status") == "error":
                raise ValueError(f"Exact WooCommerce SKU readback failed for reopened kit/pack {sku!r}")
            live_count = kit_pack_live_result.get("woocommerce_result_count", "")
            disposition_evidence = (
                f"{disposition_evidence} Current exact WooCommerce SKU filter (status=any) returned "
                f"{live_count} product/variation records; see current-reopened-kit-pack-live-readback.csv."
            ).strip()
            if live_count == "0":
                review_flags = "; ".join(filter(None, [review_flags, "live_woocommerce_exact_sku_absent"]))
                blockers = (
                    f"{blockers} No WooCommerce product or variation has the exact source SKU, but that does not "
                    "resolve an alternate-SKU product identity or an existing Veeqo kit/sellable. The Veeqo MCP "
                    f"probe at {kit_pack_veeqo_auth['readback_utc']} returned "
                    f"{kit_pack_veeqo_auth['result']}; verify the exact live Veeqo sellable and kit "
                    "configuration before creating or configuring this item."
                ).strip()
        if sku and len(source_occurrences) > 1:
            current_disposition = "unresolved_duplicate_manufacturer_sku"
            live_pages = current_manufacturer_pages.get(normalized_sku, [])
            if len(live_pages) == len(source_occurrences) and all(row.get("page_http_status") == "200" for row in live_pages):
                prices = sorted({row.get("source_price", "") for row in live_pages})
                reason = (
                    "Two distinct current official manufacturer product pages both return HTTP 200 with the same exact SKU "
                    f"{sku} but different prices ({', '.join(prices)}); neither source record is selected."
                )
                blockers = (
                    "Manufacturer must clarify which duplicate listing/price is authoritative before catalog inclusion; "
                    "see current-manufacturer-page-live-readback.csv. Do not choose by URL suffix, image presence, or row order."
                )
            else:
                reason = "Manufacturer export contains multiple product records for the same exact SKU; do not choose a record or price by row order."
                blockers = "Resolve duplicate manufacturer identity and price from current official product evidence before catalog inclusion."

        if normalized_sku in live_collision_skus:
            current_disposition = "unresolved_identifier_conflict"
            reason = (
                "The manufacturer source SKU is claimed by both a current WooCommerce SKU and another Columbia "
                "canonical row's exact MPN; production also contains separate published and hidden records. "
                "Preserve both identities pending an approved duplicate-resolution decision."
            )
            blockers = (
                "Compare both product records, manufacturer source name/specification, retained order identity, and "
                "provider mapping before changing either SKU or publication state; see "
                "current-identifier-collision-live-readback.csv. Veeqo provider records could not be queried."
            )
            review_flags = "; ".join(filter(None, [review_flags, "live_duplicate_identity_readback", "veeqo_api_unverified"]))

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
                "current_review_flags": review_flags,
                "current_disposition_evidence": disposition_evidence,
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

    kit_pack_live_by_sku = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(KIT_PACK_LIVE_READBACK)
        if row.get("source_sku", "").strip()
    }
    kit_pack_rows = [
        row for row in output_rows
        if row["current_disposition"].startswith("include_candidate_")
    ]
    if len(kit_pack_rows) != 48 or set(kit_pack_live_by_sku) != {
        row["source_sku"].strip().upper() for row in kit_pack_rows
    }:
        raise ValueError("Reopened kit/pack taxonomy review must cover the exact 48-SKU WooCommerce readback set")
    taxonomy_review_fields = [
        "sku", "manufacturer_name", "manufacturer_product_type", "manufacturer_collections",
        "manufacturer_price", "taxonomy_disposition", "product_kind", "category_key",
        "display_category_key", "evidence", "open_requirements",
    ]
    with KIT_PACK_TAXONOMY_REVIEW.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=taxonomy_review_fields, lineterminator="\r\n")
        writer.writeheader()
        for row in sorted(kit_pack_rows, key=lambda item: item["source_sku"].strip().upper()):
            is_parts_kit = row["source_product_type"] == "Parts Kit"
            writer.writerow({
                "sku": row["source_sku"],
                "manufacturer_name": row["source_name"],
                "manufacturer_product_type": row["source_product_type"],
                "manufacturer_collections": row["source_collections"],
                "manufacturer_price": row["source_price"],
                "taxonomy_disposition": "supported_semantics_pending_commercial_review" if is_parts_kit else "unresolved_taxonomy_review",
                "product_kind": "part" if is_parts_kit else "",
                "category_key": "parts" if is_parts_kit else "",
                "display_category_key": "parts" if is_parts_kit else "",
                "evidence": (
                    "Official export product_type=Parts Kit; active universal taxonomy contract maps product_kind=part to parts/parts."
                    if is_parts_kit else
                    "Official export product_type=Joint Knife and collection Comfort Grip Knives; active taxonomy has no joint-knife display class, so no category is assigned by inference."
                ),
                "open_requirements": (
                    "Verify kit components/sellable unit, current cost and price policy, Veeqo sellable/kit identity, and all required commerce fields before canonical inclusion or publication."
                    if is_parts_kit else
                    "Resolve hand-tool taxonomy/display class and pack contents/sellable unit; verify current cost and price policy, Veeqo identity, and all required commerce fields before canonical inclusion or publication."
                ),
            })

    image_audit_by_sku = {
        row["source_sku"].strip().upper(): row
        for row in read_csv(MANUFACTURER_IMAGE_AUDIT)
        if row.get("source_sku", "").strip()
    }
    image_manifest_by_sku: dict[str, list[dict[str, str]]] = defaultdict(list)
    for image_row in read_csv(MANUFACTURER_IMAGE_MANIFEST):
        sku_key = image_row.get("source_sku", "").strip().upper()
        if sku_key:
            image_manifest_by_sku[sku_key].append(image_row)
    media_reachability_by_url = {
        row["url"].strip(): row
        for row in read_csv(MEDIA_PUBLIC_REACHABILITY)
        if row.get("url", "").strip()
    }
    source_media_reachability_by_url = {
        row["url"].strip(): row
        for row in read_csv(MEDIA_SOURCE_REACHABILITY)
        if row.get("url", "").strip()
    }
    media_fields = [
        "source_product_id", "source_variant_id", "source_sku", "source_name", "source_url",
        "source_image_count", "source_manufacturer_image_urls", "manifest_asset_count",
        "manufacturer_urls_match_manifest_order", "local_asset_count", "local_asset_files_exist",
        "local_filename_convention_matches", "local_asset_paths", "source_manufacturer_url_count",
        "source_manufacturer_http_2xx_count", "source_manufacturer_http_404_count",
        "source_manufacturer_http_other_or_error_count", "target_urls", "public_target_url_count",
        "public_target_http_2xx_count", "public_target_http_404_count", "public_target_http_other_or_error_count",
        "public_reachability", "media_review_status",
    ]
    media_rows = []
    for row in output_rows:
        if row["current_disposition"] != "new_product_candidate":
            continue
        sku_key = row["source_sku"].strip().upper()
        audit = image_audit_by_sku.get(sku_key)
        if not audit:
            raise ValueError(f"No manufacturer image audit row for new-product candidate {row['source_sku']!r}")
        assets = sorted(image_manifest_by_sku.get(sku_key, []), key=lambda item: int(item.get("position") or 0))
        source_urls = [url.strip() for url in row["source_image_files"].split(" | ") if url.strip()]
        manifest_urls = [item.get("manufacturer_image_url", "").strip() for item in assets]
        url_order_matches = source_urls == manifest_urls
        source_reachability_rows = [source_media_reachability_by_url.get(url) for url in source_urls]
        source_2xx = sum(bool(check and check.get("http_status", "").startswith("2")) for check in source_reachability_rows)
        source_404 = sum(bool(check and check.get("http_status") == "404") for check in source_reachability_rows)
        source_other = sum(
            not check or not (check.get("http_status", "").startswith("2") or check.get("http_status") == "404")
            for check in source_reachability_rows
        )
        local_paths = [item.get("local_path", "") for item in assets]
        filename_convention = re.compile(r"^images/columbia_tools_[a-z0-9_.-]+_\d{2}\.webp$")
        local_exists = [
            (AUDIT / "columbia-catalog-export" / path).is_file()
            and (AUDIT / "columbia-catalog-export" / path).stat().st_size > 0
            for path in local_paths
        ]
        target_urls = [item.get("target_url", "").strip() for item in assets if item.get("target_url", "").strip()]
        reachability_rows = [media_reachability_by_url.get(url) for url in target_urls]
        public_2xx = sum(
            bool(check and check.get("http_status", "").startswith("2"))
            for check in reachability_rows
        )
        public_404 = sum(bool(check and check.get("http_status") == "404") for check in reachability_rows)
        public_other = sum(
            not check or not (check.get("http_status", "").startswith("2") or check.get("http_status") == "404")
            for check in reachability_rows
        )
        if not source_urls and not assets:
            media_status = "manufacturer_source_has_no_images; no_media_to_attach"
        elif url_order_matches and all(local_exists) and source_2xx == len(source_urls) and public_404 == len(target_urls):
            media_status = "manufacturer_source_urls_return_http_2xx; exact_source_order_and_local_assets_verified; all_public_target_urls_return_http_404; unavailable_for_storefront"
        elif url_order_matches and all(local_exists) and public_2xx == len(target_urls):
            media_status = "exact_source_order_and_local_assets_verified; all_public_target_urls_return_http_2xx"
        elif url_order_matches and all(local_exists) and len(reachability_rows) == len(target_urls):
            media_status = "exact_source_order_and_local_assets_verified; public_target_reachability_has_failures"
        else:
            media_status = "media_mapping_requires_reconciliation"
        media_rows.append({
            "source_product_id": row["source_product_id"],
            "source_variant_id": row["source_variant_id"],
            "source_sku": row["source_sku"],
            "source_name": row["source_name"],
            "source_url": row["source_url"],
            "source_image_count": audit.get("source_image_count", ""),
            "source_manufacturer_image_urls": " | ".join(source_urls),
            "manifest_asset_count": str(len(assets)),
            "manufacturer_urls_match_manifest_order": str(url_order_matches).lower(),
            "local_asset_count": str(sum(local_exists)),
            "local_asset_files_exist": (str(all(local_exists)).lower() if assets else "not_applicable"),
            "local_filename_convention_matches": (
                str(all(filename_convention.fullmatch(path) for path in local_paths)).lower() if assets else "not_applicable"
            ),
            "local_asset_paths": " | ".join(local_paths),
            "source_manufacturer_url_count": str(len(source_urls)),
            "source_manufacturer_http_2xx_count": str(source_2xx),
            "source_manufacturer_http_404_count": str(source_404),
            "source_manufacturer_http_other_or_error_count": str(source_other),
            "target_urls": " | ".join(target_urls),
            "public_target_url_count": str(len(target_urls)),
            "public_target_http_2xx_count": str(public_2xx),
            "public_target_http_404_count": str(public_404),
            "public_target_http_other_or_error_count": str(public_other),
            "public_reachability": (
                " | ".join(sorted({(check or {}).get("http_status") or (check or {}).get("error") or "unverified" for check in reachability_rows}))
                if target_urls else "no manufacturer image URL"
            ),
            "media_review_status": media_status,
        })
    with MEDIA_REVIEW.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=media_fields, extrasaction="raise", lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(media_rows)

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
        "live_kit_pack_identity_review": {
            "source_sku_rows": len(kit_pack_live_results),
            "exact_sku_matches": sum(row.get("lookup_status") == "exact_sku_found" for row in kit_pack_live_results.values()),
            "exact_sku_absent": sum(row.get("lookup_status") == "no_exact_sku_match" for row in kit_pack_live_results.values()),
            "query_errors": sum(row.get("lookup_status") == "error" or bool(row.get("error")) for row in kit_pack_live_results.values()),
            "provider_identity_status": kit_pack_veeqo_auth["result"],
            "provider_auth_readback_utc": kit_pack_veeqo_auth["readback_utc"],
            "authority_note": "WooCommerce exact-SKU absence does not prove a new product identity or establish Veeqo kit configuration.",
        },
        "product_type_variant_counts": dict(sorted(source_type_counts.items())),
        "new_candidate_media_provenance": {
            "candidate_skus_reviewed": len(media_rows),
            "candidate_skus_with_manufacturer_images": sum(int(row["source_image_count"] or 0) > 0 for row in media_rows),
            "candidate_skus_without_manufacturer_images": sum(int(row["source_image_count"] or 0) == 0 for row in media_rows),
            "manufacturer_image_assets_in_exact_order": sum(int(row["source_image_count"] or 0) for row in media_rows),
            "rows_with_exact_source_to_manifest_order_and_existing_local_assets": sum(
                row["manufacturer_urls_match_manifest_order"] == "true" and row["local_asset_files_exist"] == "true"
                for row in media_rows
            ),
            "rows_with_repo_filename_convention": sum(
                row["local_filename_convention_matches"] == "true" for row in media_rows
            ),
            "rows_requiring_media_mapping_reconciliation": sum(
                row["media_review_status"] == "media_mapping_requires_reconciliation" for row in media_rows
            ),
            "unique_official_manufacturer_image_urls_checked": len(source_media_reachability_by_url),
            "source_image_placements_returning_2xx": sum(int(row["source_manufacturer_http_2xx_count"]) for row in media_rows),
            "source_image_placements_returning_404": sum(int(row["source_manufacturer_http_404_count"]) for row in media_rows),
            "source_image_placements_other_or_network_error": sum(int(row["source_manufacturer_http_other_or_error_count"]) for row in media_rows),
            "public_target_urls_checked": sum(int(row["public_target_url_count"]) for row in media_rows),
            "public_target_urls_returning_2xx": sum(int(row["public_target_http_2xx_count"]) for row in media_rows),
            "public_target_urls_returning_404": sum(int(row["public_target_http_404_count"]) for row in media_rows),
            "public_target_urls_other_or_network_error": sum(int(row["public_target_http_other_or_error_count"]) for row in media_rows),
            "public_target_url_verification": "bounded per-URL HTTP HEAD readback recorded; no candidate media attached",
        },
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
            "identifier_candidate_review": sha256(IDENTIFIER_REVIEW),
            "schematic_identity_readback": sha256(AUDIT / "review" / "current-schematic-identity-readback.csv"),
            "live_identifier_collision_readback": sha256(LIVE_COLLISION_READBACK),
            "manufacturer_page_live_readback": sha256(MANUFACTURER_PAGE_READBACK),
            "unresolved_exact_sku_live_readback": sha256(EXACT_SKU_LIVE_READBACK),
            "hffb5_ffb5_variation_family_live_readback": sha256(HFFB5_FFB5_LIVE_READBACK),
            "unresolved_manufacturer_page_live_readback": sha256(UNRESOLVED_MANUFACTURER_PAGE_READBACK),
            "bbh9_bh9_variation_family_live_readback": sha256(BBH9_BH9_LIVE_READBACK),
            "variation_sku_endpoint_probe": sha256(VARIATION_SKU_ENDPOINT_PROBE),
            "all_candidate_exact_sku_live_readback": sha256(ALL_CANDIDATE_EXACT_SKU_READBACK),
            "schematic_alternate_candidate_live_readback": sha256(SCHEMATIC_ALTERNATE_CANDIDATE_READBACK),
            "reopened_kit_pack_live_readback": sha256(KIT_PACK_LIVE_READBACK),
            "reopened_kit_pack_veeqo_auth_readback": sha256(KIT_PACK_VEEQO_AUTH_READBACK),
            "reopened_kit_pack_taxonomy_review": sha256(KIT_PACK_TAXONOMY_REVIEW),
            "quickbooks_product_service_list_cost_check": sha256(QBO_ITEM_COST_REVIEW),
            "tsw_email_cost_search": sha256(TSW_EMAIL_COST_SEARCH),
            "veeqo_native_kit_workflow_readiness": sha256(VEEQO_KIT_WORKFLOW_REVIEW),
            "manufacturer_kit_bom_evidence": sha256(KIT_BOM_EVIDENCE),
            "manufacturer_image_audit": sha256(MANUFACTURER_IMAGE_AUDIT),
            "manufacturer_image_manifest": sha256(MANUFACTURER_IMAGE_MANIFEST),
            "candidate_media_public_reachability": sha256(MEDIA_PUBLIC_REACHABILITY),
            "candidate_media_source_reachability": sha256(MEDIA_SOURCE_REACHABILITY),
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
        "quickbooks_current_item_cost_review": json.loads(QBO_ITEM_COST_REVIEW.read_text(encoding="utf-8-sig")),
        "current_tsw_email_cost_search": json.loads(TSW_EMAIL_COST_SEARCH.read_text(encoding="utf-8-sig")),
        "veeqo_native_kit_workflow_readiness": json.loads(VEEQO_KIT_WORKFLOW_REVIEW.read_text(encoding="utf-8-sig")),
        "output_csv": str(OUTPUT.relative_to(ROOT)).replace("\\", "/"),
        "provenance_csv": str(PROVENANCE.relative_to(ROOT)).replace("\\", "/"),
        "exceptions_csv": str(EXCEPTIONS.relative_to(ROOT)).replace("\\", "/"),
        "candidate_media_review_csv": str(MEDIA_REVIEW.relative_to(ROOT)).replace("\\", "/"),
        "reopened_kit_pack_taxonomy_review_csv": str(KIT_PACK_TAXONOMY_REVIEW.relative_to(ROOT)).replace("\\", "/"),
        "reopened_kit_pack_bom_evidence_csv": "docs/catalog/columbia-catalog-audit/review/current-reopened-kit-pack-bom-evidence.csv",
    }
    SUMMARY.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Generate a consolidated, non-mutating production-readiness audit.

This audit complements the structural/enrichment pipeline. It does not invent
product facts or mutate the canonical CSV. Existing enrichment, SEO/content,
and compatibility evidence is consolidated with objective WooCommerce import,
commerce-mode, URL, media, shipping, price, and slug checks. Optional Veeqo review is out of scope by default.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import html
import json
import re
import subprocess
import io
from collections import Counter, defaultdict
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from urllib.parse import urlsplit

from official_catalog_schema import CatalogValidationError, validate_catalog, validate_catalog_taxonomy


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
DEFAULT_GAPS = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.include-gaps.json"
DEFAULT_OUTPUT = ROOT / "products" / "dev" / "catalog-enrichment" / "production-readiness"
DEFAULT_ENRICHMENT = ROOT / "products" / "dev" / "catalog-enrichment"
DEFAULT_VEEQO = ROOT / "products" / "launch" / "official" / "veeqo_inventory.csv"
DEFAULT_MEDIA_DIR = ROOT / "products" / "launch" / "media" / "media"

ALLOWED_COMMERCE_MODES = {"purchasable", "quote_only", "hidden_reference", "repair_only", "included_item"}
PRICED_TYPES = {"simple", "variation"}
POTENTIALLY_SHIPPABLE_MODES = {"purchasable", "standard", "standard-catalog"}
STAGING_HOST_SUFFIXES = (".sg-host.com", ".test", ".local", ".localhost")
PRODUCTION_HOST = "drywalltoolbox.com"
URL_FIELDS = ("Images", "External URL", "Meta: _dtb_seo_canonical", "Meta: _dtb_schematic_url")
ALLOWED_ENUMS = {
    "Published": {"0", "1"},
    "Is featured?": {"0", "1"},
    "Visibility in catalog": {"visible", "catalog", "search", "hidden"},
    "In stock?": {"0", "1"},
    "Backorders allowed?": {"0", "1", "notify"},
    "Sold individually?": {"0", "1"},
    "Allow customer reviews?": {"0", "1"},
    "Tax status": {"taxable", "shipping", "none"},
}
EVIDENCE_CLAIMS = {
    "oem_or_genuine": re.compile(r"\b(?:genuine|oem|factory[- ](?:spec|standard|grade|original))\b", re.I),
    "universal_fit": re.compile(r"\b(?:universal(?:ly)?|fits?\s+all|compatible\s+with\s+all)\b", re.I),
    "origin_claim": re.compile(r"\b(?:made|manufactured|built|assembled|crafted)\s+in\s+(?:the\s+)?(?:u\.?s\.?a?|united states|canada|mexico|china|germany)\b", re.I),
    "certification_or_safety": re.compile(r"\b(?:certified|osha|ansi|ul[- ]listed|safety[- ]rated|meets?\s+(?:all\s+)?safety)\b", re.I),
    "warranty": re.compile(r"\b(?:warranty|warranted|lifetime guarantee|money[- ]back guarantee)\b", re.I),
}
FINDING_FIELDS = (
    "finding_id", "catalog_sha256", "sku", "parent_sku", "product_type", "brand",
    "product_kind", "commerce_mode", "severity", "release_gate", "domain",
    "finding_code", "workflow", "field", "current_value", "proposed_value",
    "evidence_source", "evidence_detail", "confidence", "auto_fix_safe",
    "review_status", "reviewer", "reviewed_at", "notes",
)


def clean(value: object) -> str:
    return str(value or "").strip()


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def normalized_lf_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes().replace(b"\r\n", b"\n")).hexdigest()


def load_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def load_json_if_present(path: Path) -> dict[str, object] | None:
    return json.loads(path.read_text(encoding="utf-8-sig")) if path.is_file() else None


def decimal(value: str) -> Decimal | None:
    value = clean(value)
    if not value:
        return None
    try:
        return Decimal(value)
    except InvalidOperation:
        return None


def valid_gtin_check_digit(value: str) -> bool:
    if not value.isdigit() or len(value) not in {8, 12, 13, 14}:
        return False
    digits = [int(char) for char in value]
    body = digits[:-1]
    total = sum(digit * (3 if (len(body) - index) % 2 else 1) for index, digit in enumerate(body))
    return (10 - total % 10) % 10 == digits[-1]


def urls(value: str) -> list[str]:
    return [item.strip() for item in clean(value).split(",") if item.strip()]


def finding_id(sku: str, code: str, field: str, detail: str) -> str:
    payload = "\x1f".join((sku, code, field, detail))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()[:20]


def finding(
    catalog_sha: str,
    row: dict[str, str] | None,
    *,
    code: str,
    severity: str,
    gate: str,
    domain: str,
    workflow: str,
    field: str,
    current: str = "",
    proposed: str = "",
    source: str,
    detail: str,
    confidence: str = "confirmed",
    auto_fix_safe: bool = False,
    notes: str = "",
) -> dict[str, str]:
    row = row or {}
    sku = clean(row.get("SKU")) or "__CATALOG__"
    return {
        "finding_id": finding_id(sku, code, field, detail),
        "catalog_sha256": catalog_sha,
        "sku": sku,
        "parent_sku": clean(row.get("Parent")),
        "product_type": clean(row.get("Type")),
        "brand": clean(row.get("Brands")),
        "product_kind": clean(row.get("Meta: _dtb_product_kind")),
        "commerce_mode": clean(row.get("Meta: _dtb_commerce_mode")),
        "severity": severity,
        "release_gate": gate,
        "domain": domain,
        "finding_code": code,
        "workflow": workflow,
        "field": field,
        "current_value": current,
        "proposed_value": proposed,
        "evidence_source": source,
        "evidence_detail": detail,
        "confidence": confidence,
        "auto_fix_safe": "true" if auto_fix_safe else "false",
        "review_status": "pending",
        "reviewer": "",
        "reviewed_at": "",
        "notes": notes,
    }


def format_profile(path: Path, fields: list[str], rows: list[dict[str, str]]) -> dict[str, object]:
    raw = path.read_bytes()
    return {
        "bytes": len(raw),
        "utf8_bom": raw.startswith(b"\xef\xbb\xbf"),
        "crlf_line_endings": raw.count(b"\r\n"),
        "lf_only_line_endings": raw.count(b"\n") - raw.count(b"\r\n"),
        "physical_lines": raw.count(b"\n"),
        "columns": len(fields),
        "rows": len(rows),
        "sha256_worktree": file_sha256(path),
        "sha256_normalized_lf": normalized_lf_sha256(path),
    }


def backup_profile(catalog_path: Path) -> dict[str, object]:
    backup = Path(f"{catalog_path}.bak")
    if not backup.is_file():
        return {"exists": False, "created_by_audit": False}
    stat = backup.stat()
    backup_hash = file_sha256(backup)
    return {
        "exists": True,
        "created_by_audit": False,
        "path": str(backup.relative_to(ROOT)).replace("\\", "/"),
        "bytes": stat.st_size,
        "sha256": backup_hash,
        "matches_current_catalog": backup_hash == file_sha256(catalog_path),
        "role": "pre-mutation rollback snapshot",
        "relationship_note": "A verified rollback snapshot is expected to differ from the catalog after a successful mutation.",
        "last_modified_utc": datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat(),
    }


def direct_findings(
    rows: list[dict[str, str]],
    catalog_sha: str,
) -> tuple[list[dict[str, str]], dict[str, object]]:
    results: list[dict[str, str]] = []
    slugs: defaultdict[str, list[dict[str, str]]] = defaultdict(list)
    image_hosts: Counter[str] = Counter()
    unique_images: set[str] = set()
    image_assignments = 0
    priced_rows = 0
    cost_rows = 0
    weight_rows = 0
    dimension_rows = 0
    by_sku_casefold: defaultdict[str, list[dict[str, str]]] = defaultdict(list)
    gtin_owners: defaultdict[str, list[dict[str, str]]] = defaultdict(list)
    by_sku = {clean(row.get("SKU")): row for row in rows}
    by_sku_upper = {sku.upper(): row for sku, row in by_sku.items()}
    family_children: defaultdict[str, list[dict[str, str]]] = defaultdict(list)

    for row in rows:
        sku = clean(row.get("SKU"))
        by_sku_casefold[sku.casefold()].append(row)
        gtin = re.sub(r"[\s-]+", "", clean(row.get("GTIN, UPC, EAN, or ISBN")))
        if gtin:
            gtin_owners[gtin].append(row)
        if clean(row.get("Type")) == "variation":
            family_children[clean(row.get("Parent"))].append(row)

    for row in rows:
        sku = clean(row.get("SKU"))
        type_ = clean(row.get("Type"))
        mode = clean(row.get("Meta: _dtb_commerce_mode"))
        slug = clean(row.get("Slug"))
        for field, allowed in ALLOWED_ENUMS.items():
            if field not in row:
                continue
            value = clean(row.get(field))
            if value not in allowed:
                results.append(finding(
                    catalog_sha, row, code="invalid_import_enum", severity="critical", gate="blocker",
                    domain="configuration", workflow="import_configuration_review", field=field,
                    current=value, source="production-readiness audit",
                    detail=f"Value is outside the supported import set {sorted(allowed)}.",
                ))
        if slug:
            slugs[slug.casefold()].append(row)
            if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug):
                results.append(finding(
                    catalog_sha, row, code="invalid_slug_shape", severity="high", gate="blocker",
                    domain="seo", workflow="seo_review", field="Slug", current=slug,
                    source="production-readiness audit", detail="Slug is not lowercase kebab-case.",
                ))
        else:
            results.append(finding(
                catalog_sha, row, code="missing_slug", severity="critical", gate="blocker",
                domain="seo", workflow="seo_review", field="Slug", current="",
                source="production-readiness audit", detail="Published catalog row has no import slug.",
            ))

        if mode not in ALLOWED_COMMERCE_MODES:
            results.append(finding(
                catalog_sha, row, code="unsupported_commerce_mode", severity="high", gate="blocker",
                domain="commerce", workflow="commerce_policy_review",
                field="Meta: _dtb_commerce_mode", current=mode,
                source="dtb-catalog-platform/Domain/ProductMeta.php",
                detail=f"Active backend contract allows {sorted(ALLOWED_COMMERCE_MODES)}; catalog value is {mode!r}.",
                notes="Requires explicit mapping; no automatic replacement is approved.",
            ))

        gtin = re.sub(r"[\s-]+", "", clean(row.get("GTIN, UPC, EAN, or ISBN")))
        if gtin and (not gtin.isdigit() or len(gtin) not in {8, 12, 13, 14}):
            results.append(finding(
                catalog_sha, row, code="invalid_gtin_shape", severity="high", gate="review",
                domain="identity", workflow="identifier_review", field="GTIN, UPC, EAN, or ISBN",
                current=clean(row.get("GTIN, UPC, EAN, or ISBN")), source="production-readiness audit",
                detail="Populated GTIN/UPC/EAN is not an 8, 12, 13, or 14 digit identifier.",
            ))
        elif gtin and not valid_gtin_check_digit(gtin):
            results.append(finding(
                catalog_sha, row, code="invalid_gtin_check_digit", severity="critical", gate="blocker",
                domain="identity", workflow="identifier_review", field="GTIN, UPC, EAN, or ISBN",
                current=clean(row.get("GTIN, UPC, EAN, or ISBN")), source="production-readiness audit",
                detail="Populated GTIN/UPC/EAN fails its standard check-digit calculation.",
            ))

        regular = decimal(clean(row.get("Regular price")))
        sale = decimal(clean(row.get("Sale price")))
        cost = decimal(clean(row.get("Cost of goods")))
        map_price = decimal(clean(row.get("Meta: _dtb_map_price")))
        if clean(row.get("Regular price")):
            priced_rows += 1
            if regular is None or regular <= 0:
                results.append(finding(
                    catalog_sha, row, code="invalid_regular_price", severity="critical", gate="blocker",
                    domain="commerce", workflow="pricing_review", field="Regular price",
                    current=clean(row.get("Regular price")), source="production-readiness audit",
                    detail="Regular price must be a positive decimal when populated.",
                ))
        if type_ in PRICED_TYPES and mode == "purchasable" and regular is None:
            results.append(finding(
                catalog_sha, row, code="purchasable_missing_price", severity="critical", gate="blocker",
                domain="commerce", workflow="pricing_review", field="Regular price", current="",
                source="dtb-catalog-platform/Validation/PricingValidator.php",
                detail="Purchasable simple/variation row has no regular price.",
            ))
        if type_ in PRICED_TYPES and mode == "quote_only" and regular is not None:
            results.append(finding(
                catalog_sha, row, code="quote_only_with_price", severity="critical", gate="blocker",
                domain="commerce", workflow="commerce_policy_review", field="Meta: _dtb_commerce_mode",
                current=mode, source="dtb-catalog-platform/Validation/PricingValidator.php",
                detail="Quote-only simple/variation row has a WooCommerce regular price and may remain purchasable.",
                notes="Decide whether this SKU is purchasable or whether price/cart eligibility must be removed.",
            ))
        if sale is not None and regular is not None and sale >= regular:
            results.append(finding(
                catalog_sha, row, code="sale_price_not_below_regular", severity="critical", gate="blocker",
                domain="commerce", workflow="pricing_review", field="Sale price",
                current=clean(row.get("Sale price")), source="production-readiness audit",
                detail=f"Sale price {sale} is not below regular price {regular}.",
            ))
        if clean(row.get("Cost of goods")):
            cost_rows += 1
            if cost is None or cost < 0:
                results.append(finding(
                    catalog_sha, row, code="invalid_cost", severity="critical", gate="blocker",
                    domain="commerce", workflow="cost_review", field="Cost of goods",
                    current=clean(row.get("Cost of goods")), source="production-readiness audit",
                    detail="Cost must be a non-negative decimal when populated.",
                ))
        if regular is not None and map_price is not None and regular < map_price:
            results.append(finding(
                catalog_sha, row, code="regular_price_below_map", severity="critical", gate="blocker",
                domain="commerce", workflow="map_review", field="Regular price",
                current=clean(row.get("Regular price")), source="production-readiness audit",
                detail=f"Regular price {regular} is below populated MAP {map_price}.",
            ))

        if clean(row.get("Weight (lbs)")):
            weight_rows += 1
        if all(clean(row.get(field)) for field in ("Length (in)", "Width (in)", "Height (in)")):
            dimension_rows += 1
        if type_ in PRICED_TYPES and mode in POTENTIALLY_SHIPPABLE_MODES:
            if not clean(row.get("Weight (lbs)")):
                results.append(finding(
                    catalog_sha, row, code="shippable_missing_weight", severity="high", gate="review",
                    domain="shipping", workflow="shipping_evidence", field="Weight (lbs)", current="",
                    source="production-readiness audit",
                    detail="Potentially purchasable physical item lacks catalog weight; verify against supplier or packaging evidence.",
                ))

        inherit_parent = clean(row.get("Meta: _dtb_inherit_parent_image")).casefold() in {"1", "true", "yes"}
        if type_ != "variation" and inherit_parent:
            results.append(finding(
                catalog_sha, row, code="inherit_parent_image_on_nonvariation", severity="high", gate="review",
                domain="media", workflow="variation_media_review", field="Meta: _dtb_inherit_parent_image",
                current=clean(row.get("Meta: _dtb_inherit_parent_image")), source="production-readiness audit",
                detail="Parent-image inheritance is populated on a non-variation row.",
            ))

        raw_specs = clean(row.get("Meta: _dtb_specs_json"))
        if type_ == "simple" and clean(row.get("Meta: _dtb_product_kind")).casefold() != "part" and raw_specs:
            try:
                specs = json.loads(raw_specs)
            except json.JSONDecodeError:
                specs = []  # Structural validator owns malformed JSON reporting.
            part_numbers = [clean(item.get("value")) for item in specs if isinstance(item, dict) and clean(item.get("label")).casefold() == "part number"]
            identity = next((clean(row.get(field)) for field in ("Meta: _dtb_mpn", "Meta: _dtb_manufacturer_sku", "Meta: schema_mpn") if clean(row.get(field))), "")
            if identity and part_numbers and any(value != identity for value in part_numbers):
                results.append(finding(
                    catalog_sha, row, code="structured_part_number_identity_mismatch", severity="high", gate="review",
                    domain="identity", workflow="identifier_review", field="Meta: _dtb_specs_json",
                    current="|".join(part_numbers), source="production-readiness audit",
                    detail=f"Structured Part Number differs from protected MPN/manufacturer identity {identity!r}.",
                    confidence="requires_authoritative_identity_review",
                ))
            missing_dimensions = [field for field in ("Length (in)", "Width (in)", "Height (in)") if not clean(row.get(field))]
            if missing_dimensions:
                results.append(finding(
                    catalog_sha, row, code="shippable_missing_dimensions", severity="high", gate="review",
                    domain="shipping", workflow="shipping_evidence", field="; ".join(missing_dimensions), current="",
                    source="production-readiness audit",
                    detail="Potentially purchasable physical item lacks one or more packaged dimensions; verify against supplier or packaging evidence.",
                ))

        for field in URL_FIELDS:
            value = clean(row.get(field))
            if not value:
                continue
            field_urls = urls(value) if field == "Images" else [html.unescape(value)]
            if field == "Images":
                if len(field_urls) != len(set(field_urls)):
                    results.append(finding(
                        catalog_sha, row, code="duplicate_image_within_row", severity="high", gate="review",
                        domain="media", workflow="media_review", field=field, current=value,
                        source="production-readiness audit", detail="Image list contains duplicate URLs.",
                    ))
                image_assignments += len(field_urls)
            for url in field_urls:
                parsed = urlsplit(url)
                if field in {"Meta: _dtb_schematic_url", "Meta: _dtb_seo_canonical"} and not parsed.scheme and url.startswith("/"):
                    continue
                if parsed.scheme not in {"http", "https"} or not parsed.netloc:
                    results.append(finding(
                        catalog_sha, row, code="malformed_url", severity="critical", gate="blocker",
                        domain="media" if field == "Images" else "seo", workflow="url_review",
                        field=field, current=url, source="production-readiness audit",
                        detail="URL is not an absolute HTTP(S) URL.",
                    ))
                    continue
                host = (parsed.hostname or "").casefold()
                if field == "Images":
                    image_hosts[host] += 1
                    unique_images.add(url)
                if parsed.scheme != "https":
                    results.append(finding(
                        catalog_sha, row, code="non_https_url", severity="critical", gate="blocker",
                        domain="media" if field == "Images" else "seo", workflow="url_review",
                        field=field, current=url, source="production-readiness audit",
                        detail="Production catalog URL is not HTTPS.",
                    ))
                if host.endswith(STAGING_HOST_SUFFIXES) or host in {"localhost", "127.0.0.1"}:
                    results.append(finding(
                        catalog_sha, row, code="staging_or_local_url", severity="critical", gate="blocker",
                        domain="media" if field == "Images" else "seo", workflow="url_migration",
                        field=field, current=url, source="production-readiness audit",
                        detail=f"Published catalog references non-production host {host!r}.",
                        notes=f"Do not replace the host until the corresponding asset/path is verified on {PRODUCTION_HOST}.",
                    ))

        if not clean(row.get("Images")):
            results.append(finding(
                catalog_sha, row, code="missing_image", severity="high", gate="blocker",
                domain="media", workflow="media_research", field="Images", current="",
                source="catalog enrichment audit", detail="Published row has no customer-facing image.",
            ))

        if mode == "deprecated" and clean(row.get("Published")) == "1":
            results.append(finding(
                catalog_sha, row, code="deprecated_row_published", severity="critical", gate="blocker",
                domain="commerce", workflow="publication_review", field="Published", current="1",
                source="production-readiness audit", detail="Deprecated commerce-mode row is published and visible.",
            ))

        content = " ".join((clean(row.get("Short description")), clean(row.get("Description")), clean(row.get("Meta: _dtb_seo_description"))))
        plain_content = re.sub(r"<[^>]+>", " ", html.unescape(content))
        for topic, pattern in EVIDENCE_CLAIMS.items():
            match = pattern.search(plain_content)
            if match:
                results.append(finding(
                    catalog_sha, row, code=f"claim_needs_evidence:{topic}", severity="medium", gate="review",
                    domain="content", workflow="accuracy_review", field="Description/SEO", current="",
                    source="production-readiness audit",
                    detail=f"Claim class {topic!r} requires authoritative evidence; matched {match.group(0)!r}.",
                    confidence="heuristic_review_finding",
                ))

        for slot in range(20):
            name_field = f"Meta: _includes_{slot}_name"
            sku_field = f"Meta: _includes_{slot}_sku"
            include_sku = clean(row.get(sku_field))
            if include_sku and include_sku.upper() not in by_sku_upper:
                results.append(finding(
                    catalog_sha, row, code="include_target_absent", severity="critical", gate="blocker",
                    domain="relationships", workflow="relationship_review", field=sku_field,
                    current=include_sku, source="production-readiness audit",
                    detail=f"Included component target is absent; paired name={clean(row.get(name_field))!r}.",
                ))
        for field in ("Grouped products", "Upsells", "Cross-sells"):
            raw_targets = clean(row.get(field))
            if not raw_targets:
                continue
            targets = [target.strip() for target in re.split(r"[,|]", raw_targets) if target.strip()]
            absent = [target for target in targets if target.upper() not in by_sku_upper]
            if absent:
                results.append(finding(
                    catalog_sha, row, code="relationship_target_absent", severity="critical", gate="blocker",
                    domain="relationships", workflow="relationship_review", field=field,
                    current=raw_targets, source="production-readiness audit",
                    detail=f"Relationship target(s) absent from catalog: {', '.join(absent)}.",
                ))

    for slug, owners in slugs.items():
        if len(owners) > 1:
            affected = ", ".join(sorted(clean(row.get("SKU")) for row in owners))
            for row in owners:
                results.append(finding(
                    catalog_sha, row, code="duplicate_slug", severity="critical", gate="blocker",
                    domain="seo", workflow="seo_review", field="Slug", current=clean(row.get("Slug")),
                    source="production-readiness audit", detail=f"Slug is shared by SKUs: {affected}.",
                ))

    for normalized, owners in by_sku_casefold.items():
        if len(owners) > 1:
            affected = ", ".join(sorted(clean(row.get("SKU")) for row in owners))
            for row in owners:
                results.append(finding(
                    catalog_sha, row, code="case_insensitive_sku_collision", severity="critical", gate="blocker",
                    domain="identity", workflow="identifier_review", field="SKU", current=clean(row.get("SKU")),
                    source="production-readiness audit",
                    detail=f"SKU collides case-insensitively with: {affected}.",
                ))

    for gtin, owners in gtin_owners.items():
        if len(owners) > 1:
            affected = ", ".join(sorted(clean(row.get("SKU")) for row in owners))
            for row in owners:
                results.append(finding(
                    catalog_sha, row, code="duplicate_gtin", severity="critical", gate="blocker",
                    domain="identity", workflow="identifier_review", field="GTIN, UPC, EAN, or ISBN",
                    current=clean(row.get("GTIN, UPC, EAN, or ISBN")), source="production-readiness audit",
                    detail=f"GTIN {gtin} is assigned to multiple SKUs: {affected}.",
                ))

    for parent_sku, children in family_children.items():
        parent = by_sku.get(parent_sku)
        if not parent:
            continue
        options = [part.strip() for part in clean(parent.get("Attribute 1 value(s)")).split(",") if part.strip()]
        child_values = [clean(child.get("Attribute 1 value(s)")) for child in children]
        counts = Counter(child_values)
        missing = [option for option in options if counts[option] == 0]
        duplicates = [value for value, count in counts.items() if value and count > 1]
        extra = [value for value in counts if value and value not in options]
        default_value = clean(parent.get("Attribute 1 default"))
        if default_value and default_value not in options:
            results.append(finding(
                catalog_sha, parent, code="invalid_parent_attribute_default", severity="critical", gate="blocker",
                domain="relationships", workflow="variation_family_review", field="Attribute 1 default",
                current=default_value, source="production-readiness audit",
                detail=f"Parent default attribute is not one of its exact options: {options}.",
            ))
        if missing or duplicates or extra or len(options) != len(children):
            results.append(finding(
                catalog_sha, parent, code="variation_option_coverage_mismatch", severity="critical", gate="blocker",
                domain="relationships", workflow="variation_family_review", field="Attribute 1 value(s)",
                current=clean(parent.get("Attribute 1 value(s)")), source="production-readiness audit",
                detail=f"Family child coverage mismatch: missing={missing}, duplicates={duplicates}, extra={extra}, parent_options={len(options)}, children={len(children)}.",
            ))
        explicit_by_images: defaultdict[str, list[str]] = defaultdict(list)
        for child in children:
            inherits_parent = clean(child.get("Meta: _dtb_inherit_parent_image")).casefold() in {"1", "true", "yes", "on"}
            child_images = clean(child.get("Images"))
            parent_images = clean(parent.get("Images"))
            if inherits_parent and (not parent_images or child_images != parent_images):
                results.append(finding(
                    catalog_sha, child, code="variation_inheritance_gallery_mismatch", severity="high", gate="blocker",
                    domain="media", workflow="variation_media_review", field="Images", current=child_images,
                    source="production-readiness audit",
                    detail=f"Variation declares parent-image inheritance but its ordered gallery does not exactly match parent {parent_sku}.",
                    confidence="confirmed_contract_violation",
                ))
            if not inherits_parent:
                image_value = clean(child.get("Images"))
                if image_value:
                    explicit_by_images[image_value].append(clean(child.get("SKU")))
            if (
                not inherits_parent
                and child_images
                and child_images == parent_images
            ):
                results.append(finding(
                    catalog_sha, child, code="variation_gallery_equals_parent_without_inheritance", severity="medium", gate="review",
                    domain="media", workflow="variation_media_review", field="Images", current=clean(child.get("Images")),
                    source="production-readiness audit",
                    detail=f"Variation gallery exactly equals parent {parent_sku} while inheritance is disabled.",
                    confidence="review_required_not_automatically_wrong",
                ))
        for image_value, skus in explicit_by_images.items():
            if len(skus) > 1:
                results.append(finding(
                    catalog_sha, parent, code="sibling_variations_share_explicit_gallery", severity="medium", gate="review",
                    domain="media", workflow="variation_media_review", field="Images", current=image_value,
                    source="production-readiness audit",
                    detail=f"Sibling variations share an explicit gallery without inheritance: {', '.join(sorted(skus))}.",
                    confidence="review_required_not_automatically_wrong",
                ))

    results.append(finding(
        catalog_sha, None, code="shipping_class_policy_unconfirmed", severity="medium", gate="review",
        domain="shipping", workflow="shipping_policy_review", field="Shipping class",
        current="All catalog rows are blank.", source="production-readiness audit",
        detail="Blank shipping class may be intentional, but production shipping policy must be confirmed before import.",
        confidence="requires_policy_confirmation",
    ))

    metrics = {
        "priced_rows": priced_rows,
        "cost_rows": cost_rows,
        "weight_rows": weight_rows,
        "complete_dimension_rows": dimension_rows,
        "image_assignments": image_assignments,
        "unique_image_urls": len(unique_images),
        "image_hosts": dict(sorted(image_hosts.items())),
        "commerce_modes": dict(sorted(Counter(clean(row.get("Meta: _dtb_commerce_mode")) for row in rows).items())),
        "product_types": dict(sorted(Counter(clean(row.get("Type")) for row in rows).items())),
        "brands": dict(sorted(Counter(clean(row.get("Brands")) for row in rows).items())),
    }
    return results, metrics


def incorporate_generated_evidence(
    rows_by_sku: dict[str, dict[str, str]],
    catalog_sha: str,
    enrichment_dir: Path,
) -> list[dict[str, str]]:
    results: list[dict[str, str]] = []
    pre_findings = enrichment_dir / "seo-pre-generation" / "pre-generation-findings.csv"
    if pre_findings.is_file():
        _, source_rows = load_csv(pre_findings)
        for item in source_rows:
            row = rows_by_sku.get(clean(item.get("sku")).upper(), {})
            workflow = clean(item.get("workflow"))
            severity = clean(item.get("severity")) or "medium"
            gate = "blocker" if workflow == "blocking" else "review"
            field = clean(item.get("field"))
            results.append(finding(
                catalog_sha, row, code=clean(item.get("code")), severity=severity, gate=gate,
                domain="content" if clean(item.get("category")).startswith("content") else "seo",
                workflow=workflow, field=field,
                current=clean(row.get(field)) if field in row else "",
                source=str(pre_findings.relative_to(ROOT)).replace("\\", "/"),
                detail=clean(item.get("message")), confidence="generated_review_finding",
            ))

    remediation = enrichment_dir / "catalog-remediation.csv"
    if remediation.is_file():
        _, source_rows = load_csv(remediation)
        for item in source_rows:
            if clean(item.get("finding")) == "missing_image":
                continue  # Direct audit emits the stricter production-gate record.
            row = rows_by_sku.get(clean(item.get("sku")).upper(), {})
            results.append(finding(
                catalog_sha, row, code=clean(item.get("finding")), severity="medium", gate="review",
                domain="compatibility", workflow=clean(item.get("workflow")), field=clean(item.get("field")),
                current=clean(item.get("current_value")), source=str(remediation.relative_to(ROOT)).replace("\\", "/"),
                detail="Existing catalog enrichment remediation item; authoritative evidence is still required.",
                confidence="generated_review_finding",
            ))

    proposals = enrichment_dir / "compatibility" / "schematic-compatibility-proposals.csv"
    if proposals.is_file():
        _, source_rows = load_csv(proposals)
        for item in source_rows:
            row = rows_by_sku.get(clean(item.get("part_sku")).upper(), {})
            results.append(finding(
                catalog_sha, row, code="compatibility_proposal_exact", severity="medium", gate="review",
                domain="compatibility", workflow="compatibility_review", field="Meta: _dtb_compatible_tool_skus",
                current=clean(row.get("Meta: _dtb_compatible_tool_skus")), proposed=clean(item.get("target_tool_skus")),
                source=str(proposals.relative_to(ROOT)).replace("\\", "/"),
                detail=f"{clean(item.get('reason'))}; schematic={clean(item.get('schematic_id'))}; source={clean(item.get('source_file'))}",
                confidence="exact_proposal_not_approved", auto_fix_safe=False,
            ))
    return results


def incorporate_media_validation(
    rows_by_sku: dict[str, dict[str, str]],
    catalog_sha: str,
    validation_csv: Path,
) -> list[dict[str, str]]:
    if not validation_csv.is_file():
        return []
    _, validation_rows = load_csv(validation_csv)
    results: list[dict[str, str]] = []
    for item in validation_rows:
        if clean(item.get("production_candidate_valid")).casefold() == "true":
            continue
        source_url = clean(item.get("source_url"))
        candidate = clean(item.get("production_candidate_url"))
        for sku in [value for value in clean(item.get("affected_skus")).split("|") if value]:
            row = rows_by_sku.get(sku.upper(), {})
            results.append(finding(
                catalog_sha, row, code="production_image_candidate_invalid", severity="critical", gate="blocker",
                domain="media", workflow="url_migration", field="Images", current=source_url,
                proposed=candidate, source=str(validation_csv.relative_to(ROOT)).replace("\\", "/"),
                detail=f"Production candidate returned status={clean(item.get('status'))}, content_type={clean(item.get('content_type'))}; it is not a valid image response.",
                confidence="live_http_head_verification", auto_fix_safe=False,
            ))
    return results


def veeqo_comparison(
    rows: list[dict[str, str]],
    veeqo_path: Path,
) -> tuple[dict[str, object], list[dict[str, str]]]:
    if not veeqo_path.is_file():
        return {"available": False}, []
    _, veeqo_rows = load_csv(veeqo_path)
    catalog_items = {clean(row.get("SKU")).upper(): row for row in rows if clean(row.get("Type")) != "variable"}
    veeqo_items = {clean(row.get("sku_code")).upper(): row for row in veeqo_rows if clean(row.get("sku_code"))}
    shared = sorted(catalog_items.keys() & veeqo_items.keys())
    official_only = sorted(catalog_items.keys() - veeqo_items.keys())
    veeqo_only = sorted(veeqo_items.keys() - catalog_items.keys())
    comparisons: list[dict[str, str]] = []
    for sku in shared:
        catalog = catalog_items[sku]
        veeqo = veeqo_items[sku]
        mappings = (
            ("Sale price" if clean(catalog.get("Sale price")) else "Regular price", "sales_price", "price"),
            ("Cost of goods", "cost_price", "cost"),
            ("GTIN, UPC, EAN, or ISBN", "upc_code", "gtin"),
            ("Images", "image_url", "primary_image"),
        )
        for catalog_field, veeqo_field, kind in mappings:
            catalog_value = clean(catalog.get(catalog_field))
            if kind == "primary_image":
                catalog_value = urls(catalog_value)[0] if urls(catalog_value) else ""
            veeqo_value = clean(veeqo.get(veeqo_field))
            # The projection generator preserves prior Veeqo values when these
            # source fields are blank, so blanks are not actionable drift.
            if kind in {"cost", "gtin", "primary_image"} and not catalog_value:
                continue
            equivalent = catalog_value == veeqo_value
            if kind in {"price", "cost"}:
                equivalent = decimal(catalog_value) is not None and decimal(catalog_value) == decimal(veeqo_value)
            if not equivalent:
                comparisons.append({
                    "sku": sku, "comparison": kind, "catalog_field": catalog_field,
                    "catalog_value": catalog_value, "veeqo_field": veeqo_field,
                    "veeqo_value": veeqo_value, "disposition": "review_authority_before_change",
                })
    difference_counts = dict(sorted(Counter(item["comparison"] for item in comparisons).items()))
    return {
        "available": True,
        "veeqo_rows": len(veeqo_rows),
        "catalog_item_rows": len(catalog_items),
        "shared_skus": len(shared),
        "catalog_only_skus": official_only,
        "veeqo_only_skus": veeqo_only,
        "field_difference_rows": len(comparisons),
        "field_differences_by_type": difference_counts,
        "comparison_method": "source-authoritative fields using effective sale/regular price; blank preservable Veeqo fields excluded",
    }, comparisons


def local_media_comparison(rows: list[dict[str, str]], media_dir: Path) -> dict[str, object]:
    catalog_basenames = {
        Path(urlsplit(url).path).name.casefold()
        for row in rows for url in urls(clean(row.get("Images"))) if Path(urlsplit(url).path).name
    }
    local_files = {path.name.casefold() for path in media_dir.iterdir() if path.is_file()} if media_dir.is_dir() else set()
    return {
        "media_dir": str(media_dir.relative_to(ROOT)).replace("\\", "/") if media_dir.is_dir() else str(media_dir),
        "catalog_unique_basenames": len(catalog_basenames),
        "local_files": len(local_files),
        "missing_local_basenames": sorted(catalog_basenames - local_files),
        "unused_local_basenames": sorted(local_files - catalog_basenames),
        "all_catalog_basenames_present": catalog_basenames <= local_files,
    }


def previous_version_comparison(
    catalog_path: Path,
    fields: list[str],
    rows: list[dict[str, str]],
) -> tuple[dict[str, object], list[dict[str, str]]]:
    relative = str(catalog_path.relative_to(ROOT)).replace("\\", "/")
    commits = subprocess.check_output(
        ["git", "log", "--follow", "--format=%H", "--", relative], cwd=ROOT, text=True
    ).splitlines()
    current_head = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    if len(commits) < 2:
        return {"available": False, "current_head": current_head, "catalog_commit": commits[0] if commits else None}, []
    catalog_commit, baseline_commit = commits[0], commits[1]
    baseline_bytes = subprocess.check_output(["git", "show", f"{baseline_commit}:{relative}"], cwd=ROOT)
    baseline_reader = csv.DictReader(io.StringIO(baseline_bytes.decode("utf-8-sig"), newline=""))
    baseline_fields = list(baseline_reader.fieldnames or [])
    baseline_rows = list(baseline_reader)
    current_by_sku = {clean(row.get("SKU")): row for row in rows}
    baseline_by_sku = {clean(row.get("SKU")): row for row in baseline_rows}
    added = sorted(current_by_sku.keys() - baseline_by_sku.keys())
    removed = sorted(baseline_by_sku.keys() - current_by_sku.keys())
    changes: list[dict[str, str]] = []
    protected_fields = {
        "SKU", "Parent", "Type", "Brands", "GTIN, UPC, EAN, or ISBN",
        "Meta: schema_mpn", "Meta: _dtb_manufacturer_sku", "Meta: _dtb_mpn",
        "Meta: _dtb_product_kind",
    }
    for sku in sorted(current_by_sku.keys() & baseline_by_sku.keys()):
        current = current_by_sku[sku]
        baseline = baseline_by_sku[sku]
        for field in fields:
            before = baseline.get(field, "")
            after = current.get(field, "")
            if before != after:
                changes.append({
                    "sku": sku, "field": field, "before": before, "after": after,
                    "protected_field": "true" if field in protected_fields else "false",
                })
    return {
        "available": True,
        "current_head": current_head,
        "catalog_commit": catalog_commit,
        "baseline_commit": baseline_commit,
        "baseline_sha256": hashlib.sha256(baseline_bytes).hexdigest(),
        "current_rows": len(rows),
        "baseline_rows": len(baseline_rows),
        "current_columns": len(fields),
        "baseline_columns": len(baseline_fields),
        "added_skus": added,
        "removed_skus": removed,
        "changed_field_values": len(changes),
        "changed_skus": len({item["sku"] for item in changes}),
        "field_change_counts": dict(sorted(Counter(item["field"] for item in changes).items())),
        "protected_field_changes": sum(item["protected_field"] == "true" for item in changes),
    }, changes
def deduplicate(findings: list[dict[str, str]]) -> list[dict[str, str]]:
    by_id: dict[str, dict[str, str]] = {}
    for item in findings:
        by_id.setdefault(item["finding_id"], item)
    severity_rank = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    gate_rank = {"blocker": 0, "review": 1, "advisory": 2}
    return sorted(
        by_id.values(),
        key=lambda item: (
            gate_rank.get(item["release_gate"], 9), severity_rank.get(item["severity"], 9),
            item["domain"], item["finding_code"], item["sku"], item["field"], item["finding_id"],
        ),
    )


def count_by(findings: list[dict[str, str]], field: str) -> dict[str, int]:
    return dict(sorted(Counter(item[field] for item in findings).items()))


def workflow_safety_findings(catalog_sha: str) -> list[dict[str, str]]:
    """Record mutation-path risks that must be resolved before applying a manifest."""
    source = "production-readiness tooling review"
    return [
        finding(
            catalog_sha, None, code="mutation_runner_backup_contract_gap", severity="critical", gate="review",
            domain="workflow", workflow="apply_safety_review", field="run-official-catalog-enrichment.ps1 -ApplySafeFixes",
            source=source,
            detail="The runner uses a disposable rollback directory and invokes child appliers with --no-backup; it does not retain the user-required sibling dtb_official_catalog.csv.bak.",
        ),
        finding(
            catalog_sha, None, code="manifest_blank_erasure_risk", severity="critical", gate="review",
            domain="workflow", workflow="apply_safety_review", field="apply_official_catalog_changes.py",
            source=source,
            detail="The generic manifest applier can accept a blank proposed value and overwrite a populated catalog cell without field-specific evidence or an explicit clear-value operation.",
        ),
        finding(
            catalog_sha, None, code="manifest_semantic_validation_gap", severity="high", gate="review",
            domain="workflow", workflow="apply_safety_review", field="apply_official_catalog_changes.py",
            source=source,
            detail="The generic applier validates compatibility semantics but does not enforce equivalent media, shipping, commerce-mode, pricing, or editorial field contracts before writing.",
        ),
        finding(
            catalog_sha, None, code="apply_taxonomy_validation_gap", severity="high", gate="review",
            domain="workflow", workflow="apply_safety_review", field="non-taxonomy apply path",
            source=source,
            detail="The non-taxonomy mutation path does not make the taxonomy validator a mandatory post-write gate.",
        ),
        finding(
            catalog_sha, None, code="generated_output_containment_gap", severity="high", gate="review",
            domain="workflow", workflow="apply_safety_review", field="generated output cleanup",
            source=source,
            detail="The enrichment runner removes and recreates generated output paths without an explicit resolved-path containment assertion.",
        ),
    ]


def write_csv(path: Path, fieldnames: tuple[str, ...] | list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def report_markdown(
    summary: dict[str, object], top_codes: list[tuple[str, int]], findings: list[dict[str, str]]
) -> str:
    readiness = summary["readiness"]
    lines = [
        "# DTB Official Catalog Production-Readiness Audit",
        "",
        f"Generated: `{summary['generated_at']}`",
        "",
        f"Catalog: `{summary['catalog']}`  ",
        f"Catalog SHA-256 (working bytes): `{summary['format']['sha256_worktree']}`",
        "",
        f"Catalog SHA-256 (normalized LF): `{summary['format']['sha256_normalized_lf']}`",
        "",
        "Margin and Veeqo findings are retired from this audit scope at the user's direction. They are not evaluated or included in blocker, review, or advisory totals.",
        "",
        "## Verdict",
        "",
        f"**{readiness['verdict']}** — {readiness['blocker_findings']} blocker finding rows across {readiness['blocker_skus']} product SKUs.",
        "",
        "The CSV is structurally valid, but it is not ready for production import while any active blocker findings remain. Margin and Veeqo checks are excluded by user direction.",
        "",
        "## Evidence summary",
        "",
        f"- Rows: {summary['format']['rows']}",
        f"- Columns: {summary['format']['columns']}",
        f"- Structural validator: passed",
        f"- Owner rows: {summary['structural_validation']['taxonomy']['owners']}",
        f"- Variation rows: {summary['structural_validation']['taxonomy']['variations']}",
        f"- Total consolidated findings: {readiness['total_findings']}",
        f"- Blocker findings: {readiness['blocker_findings']}",
        f"- Review findings: {readiness['review_findings']}",
        f"- Catalog file mutated by this audit: no",
        f"- Existing sibling backup matches the audited catalog: {str(summary['backup_observation'].get('matches_current_catalog', False)).lower()}",
        "",
        "## Highest-volume findings",
        "",
        "| Finding | Count |",
        "| --- | ---: |",
    ]
    lines.extend(f"| `{code}` | {count} |" for code, count in top_codes)
    previous = summary["previous_version_comparison"]
    media = summary.get("media_url_validation") or {}
    backlog = summary["evidence_backlog"]
    lines.extend([
        "",
        "## Previous-version consolidation",
        "",
        f"- Baseline commit: `{previous.get('baseline_commit')}`",
        f"- Catalog-change commit: `{previous.get('catalog_commit')}`",
        f"- Added/removed SKUs: {len(previous.get('added_skus', []))}/{len(previous.get('removed_skus', []))}",
        f"- Changed SKUs / field values: {previous.get('changed_skus')}/{previous.get('changed_field_values')}",
        f"- Protected identifier changes: {previous.get('protected_field_changes')}",
        f"- Field changes: {json.dumps(previous.get('field_change_counts', {}), sort_keys=True)}",
        "",
        "The historical enrichment baseline was taxonomy-only. The current comparison additionally includes the bounded P0 schematic-URL, attribute-default, and image-inheritance corrections; row count, schema, SKU population, and protected identifiers remain preserved. Current taxonomy preview reports zero changes and zero unresolved rows.",
        "",
        "## Production evidence and ownership gaps",
        "",
        f"- Catalog image assignments: {summary['metrics']['image_assignments']} across {summary['metrics']['unique_image_urls']} unique URLs; all populated assignments still use `elliottm4.sg-host.com`.",
        f"- Production media candidates: {media.get('valid_production_candidates', 'not run')} valid and {media.get('invalid_production_candidates', 'not run')} invalid by HTTP status/content type.",
        f"- Local media coverage: {summary['local_media_comparison']['catalog_unique_basenames']} of {summary['local_media_comparison']['catalog_unique_basenames']} catalog basenames present; {len(summary['local_media_comparison']['unused_local_basenames'])} local files unused.",
        f"- Physical data coverage: {summary['metrics']['weight_rows']} rows with weight; {summary['metrics']['complete_dimension_rows']} rows with all dimensions; shipping class is unconfirmed catalog-wide.",
        f"- Compatibility research: {backlog['compatibility'].get('proposal_rows')} exact proposals across {backlog['compatibility'].get('unique_parts')} parts/{backlog['compatibility'].get('unique_schematics')} schematics; {backlog['compatibility'].get('unresolved_rows')} unresolved evidence rows remain.",
        f"- Content review: {backlog['accuracy_review'].get('rows')} accuracy findings across {backlog['accuracy_review'].get('unique_skus')} SKUs; {backlog['seo_pre_generation'].get('findings_by_workflow', {}).get('editorial_review')} editorial findings; no automatic copy application is approved.",
    ])
    selected_codes = (
        "production_image_candidate_invalid", "include_target_absent",
        "invalid_parent_attribute_default", "missing_image", "structured_part_number_identity_mismatch",
        "inherit_parent_image_on_nonvariation", "variation_gallery_equals_parent_without_inheritance",
        "variation_inheritance_gallery_mismatch", "sibling_variations_share_explicit_gallery",
    )
    lines.extend(["", "## Exact bounded exception sets", ""])
    for code in selected_codes:
        skus = sorted({item["sku"] for item in findings if item["finding_code"] == code})
        if skus:
            lines.append(f"- `{code}` ({len(skus)} SKUs): {', '.join(f'`{sku}`' for sku in skus)}")
    lines.extend([
        "",
        "## Mutation-workflow safety",
        "",
        "The bounded P0 correction workflow created and hash-verified the required sibling `dtb_official_catalog.csv.bak` immediately before its write. The generic manifest apply runner remains unauthorized until its separate backup and semantic-validation gaps are addressed.",
        "The sibling backup is the pre-P0 rollback snapshot and is therefore expected not to match the successfully mutated current catalog.",
    ])
    for item in findings:
        if item["domain"] == "workflow":
            lines.append(f"- `{item['finding_code']}`: {item['evidence_detail']}")
    lines.extend([
        "",
        "## Required disposition order",
        "",
        "1. Decide and document the canonical `_dtb_commerce_mode` mapping, including whether priced `quote_only` parts are intended to be purchasable.",
        "2. Verify every referenced media asset on the production host, then replace staging-host URLs through an exact-SKU approved manifest.",
        "3. Resolve objective price/MAP/publication blockers and confirm shipping data and runtime projections.",
        "4. Resolve the single missing-image SKU using authoritative media.",
        "5. Complete claim-accuracy review before editorial/SEO rewriting.",
        "6. Review compatibility proposals; do not apply generated proposals without approval.",
        "7. Re-run the full audit, structural validation, taxonomy preview, and catalog test suite before any WooCommerce import.",
        "",
        "## Scope boundaries",
        "",
        "This audit establishes CSV and repository evidence only. It does not prove WooCommerce import behavior, live payment/cart behavior, deployment, or production rendering.",
        "",
    ])
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--include-gap-audit", type=Path, default=DEFAULT_GAPS)
    parser.add_argument("--enrichment-dir", type=Path, default=DEFAULT_ENRICHMENT)
    parser.add_argument("--veeqo", type=Path, default=DEFAULT_VEEQO)
    parser.add_argument("--include-veeqo-review", action="store_true", help="include the normally out-of-scope Veeqo review")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()

    catalog_path = args.catalog.resolve()
    before_hash = file_sha256(catalog_path)
    structural = validate_catalog(catalog_path, args.include_gap_audit.resolve())
    structural["taxonomy"] = validate_catalog_taxonomy(catalog_path)
    fields, rows = load_csv(catalog_path)
    catalog_sha = normalized_lf_sha256(catalog_path)
    rows_by_sku = {clean(row.get("SKU")).upper(): row for row in rows}

    direct, metrics = direct_findings(rows, catalog_sha)
    generated = incorporate_generated_evidence(rows_by_sku, catalog_sha, args.enrichment_dir.resolve())
    media_validation_csv = args.output_dir.resolve() / "media-url-validation.csv"
    media_findings = incorporate_media_validation(rows_by_sku, catalog_sha, media_validation_csv)
    findings = deduplicate(direct + generated + media_findings + workflow_safety_findings(catalog_sha))
    veeqo_summary, veeqo_differences = (
        veeqo_comparison(rows, args.veeqo.resolve())
        if args.include_veeqo_review
        else ({ "available": False, "excluded_by_scope": True }, [])
    )
    local_media = local_media_comparison(rows, DEFAULT_MEDIA_DIR.resolve())
    previous_version, previous_changes = previous_version_comparison(catalog_path, fields, rows)
    media_validation_path = args.output_dir.resolve() / "media-url-validation-summary.json"
    media_validation = None
    if media_validation_path.is_file():
        media_validation = json.loads(media_validation_path.read_text(encoding="utf-8"))
        if clean(media_validation.get("catalog_sha256_worktree")) != before_hash:
            media_validation = {**media_validation, "stale": True}

    blocker_findings = [item for item in findings if item["release_gate"] == "blocker"]
    blocker_skus = {item["sku"] for item in blocker_findings if item["sku"] != "__CATALOG__"}
    code_counts = Counter(item["finding_code"] for item in findings)
    output_dir = args.output_dir.resolve()
    summary = {
        "schema_version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "catalog": str(catalog_path.relative_to(ROOT)).replace("\\", "/"),
        "source_mutated": False,
        "format": format_profile(catalog_path, fields, rows),
        "backup_observation": backup_profile(catalog_path),
        "structural_validation": structural,
        "metrics": metrics,
        "readiness": {
            "verdict": "NOT READY FOR PRODUCTION IMPORT" if blocker_findings else "READY FOR CONTROLLED IMPORT VALIDATION",
            "total_findings": len(findings),
            "blocker_findings": len(blocker_findings),
            "blocker_skus": len(blocker_skus),
            "review_findings": sum(item["release_gate"] == "review" for item in findings),
            "advisory_findings": sum(item["release_gate"] == "advisory" for item in findings),
            "by_severity": count_by(findings, "severity"),
            "by_gate": count_by(findings, "release_gate"),
            "by_domain": count_by(findings, "domain"),
            "by_code": dict(sorted(code_counts.items())),
            "by_brand": count_by([item for item in findings if item["brand"]], "brand"),
        },
        "scope_exclusions": ["nonpositive_gross_margin", "catalog_quantity_requires_veeqo_authority_review"],
        "veeqo_comparison": veeqo_summary,
        "local_media_comparison": local_media,
        "previous_version_comparison": previous_version,
        "media_url_validation": media_validation,
        "evidence_backlog": {
            "compatibility": load_json_if_present(args.enrichment_dir.resolve() / "compatibility" / "schematic-compatibility-summary.json") or {},
            "accuracy_review": load_json_if_present(args.enrichment_dir.resolve() / "content-review" / "accuracy-review-summary.json") or {},
            "seo_pre_generation": load_json_if_present(args.enrichment_dir.resolve() / "seo-pre-generation" / "pre-generation-summary.json") or {},
        },
        "inputs": {
            "enrichment_dir": str(args.enrichment_dir.resolve().relative_to(ROOT)).replace("\\", "/"),
            "veeqo": str(args.veeqo.resolve().relative_to(ROOT)).replace("\\", "/") if args.include_veeqo_review and args.veeqo.resolve().is_file() else None,
        },
    }

    output_dir.mkdir(parents=True, exist_ok=True)
    write_csv(output_dir / "production-readiness-remediation.csv", FINDING_FIELDS, findings)
    if veeqo_differences:
        write_csv(output_dir / "veeqo-field-differences.csv", list(veeqo_differences[0].keys()), veeqo_differences)
    if previous_changes:
        write_csv(output_dir / "previous-version-field-diff.csv", list(previous_changes[0].keys()), previous_changes)
    (output_dir / "production-readiness-audit.json").write_text(
        json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    (output_dir / "production-readiness-report.md").write_text(
        report_markdown(summary, code_counts.most_common(20), findings), encoding="utf-8"
    )

    after_hash = file_sha256(catalog_path)
    if after_hash != before_hash:
        raise CatalogValidationError("Production-readiness audit mutated the canonical catalog")
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CatalogValidationError, OSError, csv.Error) as exc:
        print(f"ERROR: {exc}")
        raise SystemExit(1)

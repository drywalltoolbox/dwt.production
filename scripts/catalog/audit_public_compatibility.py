#!/usr/bin/env python3
"""Read-only coverage audit of the public DTB parts and schematics APIs.

Usage:
  python scripts/catalog/audit_public_compatibility.py --output reports/hotspots/public-compatibility-2026-10-20.json

The result measures published API projections. It does not infer compatibility
from matching names, part numbers, or diagram proximity.
"""

from __future__ import annotations

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import json
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen


API_ROOT = "https://drywalltoolbox.com/wp-json/dtb/v1"
PAGE_SIZE = 100
MAX_SCHEMATICS = 200


def get_json(path: str, params: dict[str, int] | None = None) -> dict:
    url = f"{API_ROOT}{path}"
    if params:
        url = f"{url}?{urlencode(params)}"
    request = Request(url, headers={"Accept": "application/json", "User-Agent": "DTB-ReadOnly-Coverage-Audit/1.0"})
    with urlopen(request, timeout=30) as response:
        return json.load(response)


def get_catalog_items(is_parts: bool) -> tuple[list[dict], dict]:
    parameters = {"per_page": PAGE_SIZE, "page": 1}
    if is_parts:
        parameters["is_parts"] = 1
    first = get_json("/catalog/products", parameters)
    pagination = first["pagination"]
    total_pages = int(pagination["totalPages"])
    if total_pages > 100:
        raise ValueError(f"Catalog audit exceeds 100 pages: {total_pages}")
    items = list(first["items"])
    for page in range(2, total_pages + 1):
        parameters["page"] = page
        items.extend(get_json("/catalog/products", parameters)["items"])
    if len(items) != int(pagination["total"]):
        raise ValueError(f"Catalog page count changed during audit: expected {pagination['total']}, read {len(items)}")
    return items, pagination


def schematic_summary(item: dict) -> dict:
    detail = get_json(f"/schematics/{item['id']}")
    parts = detail.get("parts", [])
    states = Counter(str(part.get("resolution_state") or "unknown") for part in parts)
    return {
        "id": item["id"],
        "title": item.get("title", ""),
        "brand": (item.get("brand") or {}).get("name", ""),
        "pages": len(detail.get("pages", [])),
        "parts": len(parts),
        "linked_products": sum(part.get("product") is not None for part in parts),
        "available_parts": sum(part.get("available") is True for part in parts),
        "resolution_states": dict(sorted(states.items())),
    }


def build_audit() -> dict:
    products, product_pagination = get_catalog_items(False)
    parts, parts_pagination = get_catalog_items(True)
    schematic_catalog = get_json("/schematics")
    schematic_items = schematic_catalog["items"]
    if len(schematic_items) > MAX_SCHEMATICS:
        raise ValueError(f"Schematic audit exceeds {MAX_SCHEMATICS} records")

    with ThreadPoolExecutor(max_workers=4) as pool:
        schematics = list(pool.map(schematic_summary, schematic_items))
    schematics.sort(key=lambda row: row["id"])

    brand_counts = Counter(row["brand"] for row in schematics)
    resolution_states = Counter()
    for row in schematics:
        resolution_states.update(row["resolution_states"])

    return {
        "audited_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "api_root": API_ROOT,
        "method": "Public GET endpoints only; no compatibility inferred from identifiers or titles.",
        "catalog": {
            "published_parent_products": int(product_pagination["total"]),
            "retrieved_parent_products": len(products),
            "nonempty_skus": sum(bool(row.get("sku")) for row in products),
            "hyphenated_skus": sum("-" in str(row.get("sku") or "") for row in products),
            "published_parts": int(parts_pagination["total"]),
            "retrieved_parts": len(parts),
            "parts_with_compatible_tool_skus": sum(bool((row.get("compatibility") or {}).get("compatibleToolSkus")) for row in parts),
            "parts_with_replacement_part_for": sum(bool((row.get("compatibility") or {}).get("replacementPartFor")) for row in parts),
            "parts_with_schematic_brand_and_group": sum(
                bool((row.get("schematics") or {}).get("brand")) and bool((row.get("schematics") or {}).get("group"))
                for row in parts
            ),
        },
        "schematics": {
            "published_count": schematic_catalog["count"],
            "retrieved_details": len(schematics),
            "brand_counts": dict(sorted(brand_counts.items())),
            "total_part_references": sum(row["parts"] for row in schematics),
            "linked_product_references": sum(row["linked_products"] for row in schematics),
            "available_part_references": sum(row["available_parts"] for row in schematics),
            "resolution_states": dict(sorted(resolution_states.items())),
            "by_schematic": schematics,
        },
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True, help="Path for the JSON audit report")
    args = parser.parse_args()
    audit = build_audit()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(audit, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"catalog": audit["catalog"], "schematics": {key: value for key, value in audit["schematics"].items() if key != "by_schematic"}}, indent=2))


if __name__ == "__main__":
    main()

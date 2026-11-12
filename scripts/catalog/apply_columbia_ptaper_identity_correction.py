#!/usr/bin/env python3
"""Apply the explicitly authorized Columbia PTAPER -> C1PTAPER source correction.

The change updates the canonical SKU and aligned catalog identity fields,
customer-facing part-number text, and exact Columbia compatibility references.
It deliberately leaves WooCommerce, Veeqo, historical snapshots, model,
prices, cost, inventory, taxonomy, media, parentage, and permalinks untouched.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import shutil
import sys
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "catalog"))
from official_catalog_schema import (  # noqa: E402
    CatalogValidationError,
    create_catalog_backup,
    validate_catalog,
    write_catalog_atomic,
)

CATALOG = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.csv"
GAP_AUDIT = ROOT / "products" / "launch" / "official" / "dtb_official_catalog.include-gaps.json"
AUDIT = ROOT / "docs" / "catalog" / "columbia-catalog-audit"
ROLLBACKS = AUDIT / "archive" / "rollback-snapshots"
REPORT = AUDIT / "review" / "ptaper-c1ptaper-catalog-migration.json"
OLD_SKU = "PTAPER"
NEW_SKU = "C1PTAPER"
COMPAT_FIELD = "Meta: _dtb_compatible_tool_skus"


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_catalog(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def prepare(fields: list[str], rows: list[dict[str, str]]) -> tuple[list[dict[str, str]], dict]:
    if "SKU" not in fields or COMPAT_FIELD not in fields:
        raise CatalogValidationError("catalog is missing SKU or compatibility fields")
    old_rows = [row for row in rows if row.get("SKU", "").strip() == OLD_SKU]
    new_rows = [row for row in rows if row.get("SKU", "").strip() == NEW_SKU]
    if len(old_rows) != 1:
        raise CatalogValidationError(f"expected one {OLD_SKU} catalog row; found {len(old_rows)}")
    if new_rows:
        raise CatalogValidationError(f"{NEW_SKU} already exists in the catalog")

    target = old_rows[0]
    if target.get("Name") != "Columbia One Predator Taper" or target.get("Type") != "simple":
        raise CatalogValidationError("PTAPER row no longer matches the verified simple-product identity")
    if target.get("Meta: _dtb_manufacturer_sku") != OLD_SKU:
        raise CatalogValidationError("manufacturer SKU field changed from the reviewed baseline")
    for field in ("Meta: schema_mpn", "Meta: _dtb_mpn"):
        if target.get(field) != OLD_SKU:
            raise CatalogValidationError(f"{field} changed from the reviewed baseline")

    candidate = [row.copy() for row in rows]
    target_candidate = next(row for row in candidate if row.get("SKU") == OLD_SKU)
    target_candidate["SKU"] = NEW_SKU
    target_candidate["Meta: _dtb_manufacturer_sku"] = NEW_SKU
    target_candidate["Meta: schema_mpn"] = NEW_SKU
    target_candidate["Meta: _dtb_mpn"] = NEW_SKU

    specs_raw = target_candidate.get("Meta: _dtb_specs_json", "")
    try:
        specs = json.loads(specs_raw)
    except json.JSONDecodeError as exc:
        raise CatalogValidationError("PTAPER specifications are not valid JSON") from exc
    part_number = [entry for entry in specs if entry.get("label") == "Part Number"]
    if len(part_number) != 1 or part_number[0].get("value") != OLD_SKU:
        raise CatalogValidationError("expected one PTAPER Part Number specification")
    part_number[0]["value"] = NEW_SKU
    target_candidate["Meta: _dtb_specs_json"] = json.dumps(
        specs, ensure_ascii=False, separators=(",", ":")
    )
    seo_description = target_candidate.get("Meta: _dtb_seo_description", "")
    expected_seo = f"Shop the Columbia One Predator Taper {OLD_SKU},"
    if expected_seo not in seo_description:
        raise CatalogValidationError("SEO description no longer contains the reviewed exact SKU reference")
    target_candidate["Meta: _dtb_seo_description"] = seo_description.replace(
        expected_seo, f"Shop the Columbia One Predator Taper {NEW_SKU},", 1
    )

    compatibility_rows = 0
    for row in candidate:
        raw = row.get(COMPAT_FIELD, "")
        if not raw:
            continue
        try:
            values = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise CatalogValidationError(
                f"{row.get('SKU')}: compatibility data is not valid JSON"
            ) from exc
        if not isinstance(values, list) or any(not isinstance(value, str) for value in values):
            raise CatalogValidationError(f"{row.get('SKU')}: compatibility data is not a string list")
        if OLD_SKU not in values:
            continue
        if values.count(OLD_SKU) != 1 or NEW_SKU in values:
            raise CatalogValidationError(f"{row.get('SKU')}: ambiguous old/new taper compatibility values")
        if row.get("Brands") != "Columbia Tools" or row.get("Meta: _dtb_brand_key") != "columbia-taping-tools":
            raise CatalogValidationError(
                f"{row.get('SKU')}: PTAPER compatibility reference is not a Columbia-owned row"
            )
        updated = [NEW_SKU if value == OLD_SKU else value for value in values]
        row[COMPAT_FIELD] = json.dumps(updated, ensure_ascii=False, separators=(",", ":"))
        compatibility_rows += 1

    if compatibility_rows != 76:
        raise CatalogValidationError(
            f"expected the reviewed 76 exact compatibility references; found {compatibility_rows}"
        )
    sku_counts = Counter((row.get("SKU") or "").strip() for row in candidate)
    if sku_counts.get(NEW_SKU) != 1 or sku_counts.get(OLD_SKU, 0) != 0:
        raise CatalogValidationError("candidate does not contain C1PTAPER exactly once and PTAPER zero times as SKU")
    return candidate, {
        "target_row_count": 1,
        "compatibility_rows_updated": compatibility_rows,
        "sku_field": {"from": OLD_SKU, "to": NEW_SKU},
        "manufacturer_sku_field": {"from": OLD_SKU, "to": NEW_SKU},
        "schema_mpn_field": {"from": OLD_SKU, "to": NEW_SKU},
        "dtb_mpn_field": {"from": OLD_SKU, "to": NEW_SKU},
        "specification_part_number": {"from": OLD_SKU, "to": NEW_SKU},
        "seo_sku_reference": {"from": OLD_SKU, "to": NEW_SKU},
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="write the reviewed correction")
    parser.add_argument("--expected-sha256", help="required current catalog digest when applying")
    args = parser.parse_args()
    if args.apply and not args.expected_sha256:
        parser.error("--apply requires --expected-sha256")

    fields, rows = read_catalog(CATALOG)
    before_hash = file_sha256(CATALOG)
    if args.apply and before_hash.casefold() != args.expected_sha256.casefold():
        raise CatalogValidationError("official catalog digest changed since review")
    validate_catalog(CATALOG, GAP_AUDIT)
    candidate, changes = prepare(fields, rows)
    mode = "apply" if args.apply else "preview"
    report = {
        "schema_version": 1,
        "mode": mode,
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "catalog": str(CATALOG.relative_to(ROOT)).replace("\\", "/"),
        "catalog_sha256_before": before_hash,
        "catalog_sha256_after": None,
        "row_count_before": len(rows),
        "row_count_after": len(candidate),
        "changes": changes,
        "preserved_fields": [
            "price", "cost", "stock", "status", "visibility", "category", "parent",
            "permalink slug", "images", "model", "Veeqo mapping",
        ],
        "live_woocommerce_updated": False,
        "import_ready": False,
    }
    if args.apply:
        staged_name = None
        try:
            fd, staged_name = tempfile.mkstemp(
                prefix=".dtb-columbia-identity-candidate-", suffix=".csv", dir=CATALOG.parent
            )
            os.close(fd)
            Path(staged_name).unlink(missing_ok=True)
            write_catalog_atomic(Path(staged_name), fields, candidate)
            validate_catalog(Path(staged_name), GAP_AUDIT)
        finally:
            if staged_name:
                Path(staged_name).unlink(missing_ok=True)
        ROLLBACKS.mkdir(parents=True, exist_ok=True)
        archived = ROLLBACKS / "dtb_official_catalog-before-ptaper-c1ptaper-20261111.csv"
        if archived.exists() and file_sha256(archived) != before_hash:
            raise CatalogValidationError(f"rollback snapshot already exists with different bytes: {archived}")
        if not archived.exists():
            shutil.copy2(CATALOG, archived)
        if file_sha256(archived) != before_hash:
            raise CatalogValidationError("versioned catalog rollback snapshot hash mismatch")
        prior_sibling_backup = CATALOG.with_name(CATALOG.name + ".bak")
        prior_sibling_archive = ROLLBACKS / "dtb_official_catalog-preexisting-sibling-bak-20261111.csv"
        prior_sibling_hash = None
        if prior_sibling_backup.exists():
            prior_sibling_hash = file_sha256(prior_sibling_backup)
            if prior_sibling_archive.exists() and file_sha256(prior_sibling_archive) != prior_sibling_hash:
                prior_receipt = json.loads(REPORT.read_text(encoding="utf-8")) if REPORT.exists() else {}
                is_verified_prior_apply = (
                    prior_receipt.get("mode") == "apply"
                    and prior_receipt.get("catalog_sha256_before") == before_hash
                    and prior_sibling_hash == before_hash
                    and prior_receipt.get("catalog_sha256_after") not in (None, before_hash)
                )
                if not is_verified_prior_apply:
                    raise CatalogValidationError("pre-existing sibling backup archive has different bytes")
            if not prior_sibling_archive.exists():
                shutil.copy2(prior_sibling_backup, prior_sibling_archive)
            if (not REPORT.exists() or json.loads(REPORT.read_text(encoding="utf-8")).get("mode") != "apply") and file_sha256(prior_sibling_archive) != prior_sibling_hash:
                raise CatalogValidationError("pre-existing sibling backup was not preserved exactly")
        backup = create_catalog_backup(CATALOG)
        if file_sha256(backup) != before_hash:
            raise CatalogValidationError("required sibling .bak does not match the pre-change catalog")
        write_catalog_atomic(CATALOG, fields, candidate)
        try:
            validate_catalog(CATALOG, GAP_AUDIT)
            if file_sha256(archived) != before_hash or file_sha256(backup) != before_hash:
                raise CatalogValidationError("a verified catalog rollback snapshot changed after write")
            _, after_rows = read_catalog(CATALOG)
            new_rows = [row for row in after_rows if row.get("SKU") == NEW_SKU]
            old_rows = [row for row in after_rows if row.get("SKU") == OLD_SKU]
            compat_remaining = sum(
                OLD_SKU in json.loads(row.get(COMPAT_FIELD) or "[]")
                for row in after_rows
            )
            compat_updated = sum(
                NEW_SKU in json.loads(row.get(COMPAT_FIELD) or "[]")
                for row in after_rows
            )
            if len(new_rows) != 1 or old_rows or compat_remaining or compat_updated != 76:
                raise CatalogValidationError("catalog read-back did not preserve the intended identity migration")
            report["catalog_sha256_after"] = file_sha256(CATALOG)
            report["versioned_rollback_snapshot"] = str(archived.relative_to(ROOT)).replace("\\", "/")
            report["required_sibling_backup"] = str(backup.relative_to(ROOT)).replace("\\", "/")
            report["preexisting_sibling_backup_archive"] = (
                str(prior_sibling_archive.relative_to(ROOT)).replace("\\", "/")
                if prior_sibling_hash
                else None
            )
        except Exception:
            shutil.copy2(backup, CATALOG)
            raise
    REPORT.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CatalogValidationError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)

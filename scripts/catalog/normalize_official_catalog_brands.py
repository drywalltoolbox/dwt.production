#!/usr/bin/env python3
"""Normalize official catalog brand labels and canonical brand keys.

Default behavior is a read-only preview. Pass --apply and --backup <path> to
write a semantically verified CSV with its original UTF-8 BOM and CRLF endings.
Only the Brands and brand identity metadata columns are eligible to change.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import shutil
import tempfile
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"

BRAND_KEYS = {
    "Columbia Tools": "columbia-taping-tools",
    "TapeTech": "tapetech",
    "Dura-Stilts": "dura-stilts",
    "Platinum Drywall Tools": "platinum",
    "SurPro": "surpro",
    "USG Sheetrock Tools": "usg-sheetrock-tools",
    "LEVEL5": "level5",
}
BRAND_ALIASES = {"Level5": "LEVEL5", "Level 5": "LEVEL5"}
LABEL_FIELDS = ("Meta: schema_brand", "Meta: _dtb_brand_label", "Meta: _dtb_brand")
KEY_FIELD = "Meta: _dtb_brand_key"


def read_catalog(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames:
            raise ValueError("Official catalog has no CSV header")
        return list(reader.fieldnames), list(reader)


def normalize(rows: list[dict[str, str]], fieldnames: list[str]) -> tuple[Counter[str], int]:
    required = {"Brands", KEY_FIELD, *LABEL_FIELDS}
    missing = sorted(required.difference(fieldnames))
    if missing:
        raise ValueError(f"Missing required brand columns: {missing}")

    changes: Counter[str] = Counter()
    for row_number, row in enumerate(rows, start=2):
        source_brand = row["Brands"].strip()
        brand = BRAND_ALIASES.get(source_brand, source_brand)
        if brand not in BRAND_KEYS:
            raise ValueError(f"Row {row_number}: unrecognized official brand {source_brand!r}")

        if row["Brands"] != brand:
            row["Brands"] = brand
            changes["Brands"] += 1

        expected_key = BRAND_KEYS[brand]
        if row[KEY_FIELD] != expected_key:
            row[KEY_FIELD] = expected_key
            changes[KEY_FIELD] += 1

        for field in LABEL_FIELDS:
            if row[field] != brand:
                row[field] = brand
                changes[field] += 1

    return changes, len(rows)


def write_catalog(path: Path, fieldnames: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=fieldnames,
            extrasaction="raise",
            lineterminator="\r\n",
            quoting=csv.QUOTE_MINIMAL,
        )
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=CATALOG)
    parser.add_argument("--apply", action="store_true", help="Write the normalized catalog")
    parser.add_argument("--backup", type=Path, help="Required backup path when applying")
    args = parser.parse_args()

    if args.apply and not args.backup:
        parser.error("--backup is required with --apply")

    source_bytes = args.catalog.read_bytes()
    had_bom = source_bytes.startswith(b"\xef\xbb\xbf")
    if not had_bom or source_bytes.count(b"\r\n") == 0:
        raise ValueError("Expected UTF-8 BOM and CRLF catalog encoding; refusing rewrite")

    fieldnames, original_rows = read_catalog(args.catalog)
    rows = [row.copy() for row in original_rows]
    changes, row_count = normalize(rows, fieldnames)
    report = {
        "catalog": str(args.catalog),
        "rows": row_count,
        "changed_rows": sum(
            1 for before, after in zip(original_rows, rows) if before != after
        ),
        "changed_cells_by_field": dict(sorted(changes.items())),
        "brand_rows": dict(sorted(Counter(row["Brands"] for row in rows).items())),
        "applied": args.apply,
    }

    if args.apply and changes:
        backup = args.backup.resolve()
        backup.parent.mkdir(parents=True, exist_ok=True)
        if backup.exists():
            raise FileExistsError(f"Refusing to overwrite existing backup: {backup}")
        shutil.copy2(args.catalog, backup)

        fd, temporary_name = tempfile.mkstemp(prefix=f"{args.catalog.name}.", suffix=".tmp", dir=args.catalog.parent)
        os.close(fd)
        temporary = Path(temporary_name)
        try:
            write_catalog(temporary, fieldnames, rows)
            check_fields, check_rows = read_catalog(temporary)
            if check_fields != fieldnames or len(check_rows) != row_count:
                raise ValueError("CSV round-trip changed the schema or row count")
            for before, after in zip(original_rows, check_rows):
                if any(before[key] != after[key] for key in fieldnames if key not in {"Brands", KEY_FIELD, *LABEL_FIELDS}):
                    raise ValueError("CSV round-trip changed a non-brand field")
            os.replace(temporary, args.catalog)
        except Exception:
            temporary.unlink(missing_ok=True)
            raise

    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

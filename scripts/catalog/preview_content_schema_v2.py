#!/usr/bin/env python3
"""Preview the proposed DTB catalog content schema v2 without altering the source.

The generated CSV is a review artifact, not an import-ready file. It does not
populate product content, publish AI claims, or touch WooCommerce.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_OUTPUT = ROOT / "products/dev/catalog-content-normalization/catalog-content-schema-v2-preview.csv"
AFTER = "Meta: _dtb_specs_json"
ADDITIONS = ("Meta: _dtb_features_json", "Meta: _dtb_content_schema_version")


def transform(source: Path, output: Path) -> dict:
    source = source.resolve()
    output = output.resolve()
    if source == output:
        raise ValueError("output must not overwrite the canonical source")
    if output.exists():
        raise FileExistsError(f"refusing to overwrite existing preview: {output}")
    with source.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        original_columns = list(reader.fieldnames or [])
        if AFTER not in original_columns:
            raise ValueError(f"missing expected catalog column {AFTER}")
        if any(field in original_columns for field in ADDITIONS):
            raise ValueError("source already contains proposed v2 fields")
        original_rows = list(reader)
    insert_at = original_columns.index(AFTER) + 1
    target_columns = original_columns[:insert_at] + list(ADDITIONS) + original_columns[insert_at:]
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=target_columns, lineterminator="\r\n", extrasaction="raise")
        writer.writeheader()
        for row in original_rows:
            writer.writerow({**row, **{field: "" for field in ADDITIONS}})
    with output.open("r", encoding="utf-8-sig", newline="") as f:
        result = csv.DictReader(f)
        if list(result.fieldnames or []) != target_columns:
            raise RuntimeError("column round trip failed")
        generated_rows = list(result)
    if len(generated_rows) != len(original_rows):
        raise RuntimeError("row count changed during preview")
    for i, (before, after) in enumerate(zip(original_rows, generated_rows), 2):
        if any(before[field] != after[field] for field in original_columns):
            raise RuntimeError(f"source field changed at row {i}")
        if any(after[field] for field in ADDITIONS):
            raise RuntimeError(f"new fields not empty at row {i}")
    summary = {
        "schema": "proposed-v2-preview",
        "input_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
        "rows": len(original_rows),
        "original_columns": len(original_columns),
        "preview_columns": len(target_columns),
        "added_columns": list(ADDITIONS),
        "canonical_source_modified": False,
        "import_approved": False,
    }
    return summary


if __name__ == "__main__":
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--catalog", type=Path, default=SOURCE)
    p.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = p.parse_args()
    print(json.dumps(transform(args.catalog, args.output), sort_keys=True))

#!/usr/bin/env python3
"""Approve one reviewed catalog manifest row and bind approval to its exact payload."""

from __future__ import annotations

import argparse
import csv
import os
import tempfile
from datetime import datetime
from pathlib import Path

from catalog_change_manifest import MANIFEST_FIELDS, approval_sha256
from official_catalog_schema import CatalogValidationError


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--sku", required=True)
    parser.add_argument("--reviewer", required=True)
    parser.add_argument("--reviewed-at", required=True, help="ISO-8601 timestamp including timezone")
    args = parser.parse_args()
    try:
        reviewed_at = datetime.fromisoformat(args.reviewed_at.replace("Z", "+00:00"))
    except ValueError as exc:
        raise CatalogValidationError("reviewed-at must be a valid ISO-8601 timestamp") from exc
    if reviewed_at.tzinfo is None:
        raise CatalogValidationError("reviewed-at must include a timezone")
    if not args.reviewer.strip():
        raise CatalogValidationError("reviewer must not be empty")

    path = args.manifest.resolve()
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if tuple(reader.fieldnames or ()) != MANIFEST_FIELDS:
            raise CatalogValidationError("manifest header does not match the current reviewed-manifest schema")
        rows = list(reader)
    matches = [row for row in rows if (row.get("sku") or "").strip().upper() == args.sku.strip().upper()]
    if len(matches) != 1:
        raise CatalogValidationError(f"expected exactly one manifest row for SKU {args.sku!r}; found {len(matches)}")
    row = matches[0]
    if (row.get("review_status") or "").strip().casefold() not in {"needs_review", "pending"}:
        raise CatalogValidationError("only a pending review row can be approved")
    if not all((row.get(field) or "").strip() for field in ("catalog_sha256", "row_sha256", "workflow", "field", "proposed_value", "evidence")):
        raise CatalogValidationError("approval requires catalog/row digests, field, proposal, and evidence")
    row["review_status"] = "approved"
    row["reviewer"] = args.reviewer.strip()
    row["reviewed_at"] = args.reviewed_at.strip()
    row["approval_sha256"] = approval_sha256(row)

    fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
            writer.writeheader()
            writer.writerows(rows)
        os.replace(temporary_name, path)
    finally:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)
    print(f"Approved {row['sku']} with payload SHA-256 {row['approval_sha256']}.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, csv.Error, CatalogValidationError) as exc:
        raise SystemExit(f"ERROR: {exc}")

#!/usr/bin/env python3
"""Shared deterministic manifest primitives for reviewed catalog mutations."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


MANIFEST_FIELDS = (
    "catalog_sha256",
    "row_sha256",
    "sku",
    "workflow",
    "field",
    "current_value",
    "proposed_value",
    "evidence",
    "review_status",
    "reviewer",
    "reviewed_at",
    "approval_sha256",
)

APPROVAL_FIELDS = (
    "catalog_sha256", "row_sha256", "sku", "workflow", "field", "current_value",
    "proposed_value", "evidence", "review_status", "reviewer", "reviewed_at",
)


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def row_sha256(row: dict[str, str]) -> str:
    payload = json.dumps(row, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def approval_sha256(item: dict[str, str]) -> str:
    payload = json.dumps(
        {key: item.get(key, "") for key in APPROVAL_FIELDS},
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()

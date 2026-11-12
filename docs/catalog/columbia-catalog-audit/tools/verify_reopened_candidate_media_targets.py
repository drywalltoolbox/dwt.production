#!/usr/bin/env python3
"""Read back public media URLs for reopened Columbia kit and pack assets."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs" / "catalog" / "columbia-catalog-audit"
SOURCE = AUDIT / "review" / "current-reopened-kit-pack-media-review.csv"
OUTPUT = AUDIT / "review" / "current-reopened-kit-pack-media-public-reachability.csv"
SUMMARY = AUDIT / "review" / "current-reopened-kit-pack-media-public-reachability-summary.json"
PUBLIC_BASE = "https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/"
MAX_WORKERS = 8
TIMEOUT_SECONDS = 20


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def head(url: str) -> dict[str, str]:
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme != "https" or parsed.hostname != "drywalltoolbox.com":
        return {"http_status": "", "content_type": "", "content_length": "",
                "error": "rejected_untrusted_target_url"}
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "DTB-Catalog-Media-Audit/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
            return {"http_status": str(response.status),
                    "content_type": response.headers.get("Content-Type", ""),
                    "content_length": response.headers.get("Content-Length", ""),
                    "error": ""}
    except urllib.error.HTTPError as exc:
        return {"http_status": str(exc.code),
                "content_type": exc.headers.get("Content-Type", ""),
                "content_length": exc.headers.get("Content-Length", ""),
                "error": ""}
    except Exception as exc:
        return {"http_status": "", "content_type": "", "content_length": "",
                "error": f"{type(exc).__name__}:{str(exc)[:180]}"}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write", action="store_true", help="write the reachability CSV and summary JSON")
    args = parser.parse_args()
    source_rows = read_csv(SOURCE)
    media_rows = [row for row in source_rows if row["manufacturer_image_url"]]
    targets = [PUBLIC_BASE + Path(row["expected_local_asset"]).name for row in media_rows]
    results: list[dict[str, str] | None] = [None] * len(targets)
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {executor.submit(head, url): index for index, url in enumerate(targets)}
        for future in as_completed(futures):
            results[futures[future]] = future.result()

    output_rows = []
    counts: dict[str, int] = {}
    for source, target, result in zip(media_rows, targets, results, strict=True):
        assert result is not None
        status = result["http_status"] or "network_error"
        counts[status] = counts.get(status, 0) + 1
        output_rows.append({
            "source_product_id": source["source_product_id"],
            "source_variant_id": source["source_variant_id"],
            "source_sku": source["source_sku"],
            "source_name": source["source_name"],
            "source_position": source["source_position"],
            "expected_local_asset": source["expected_local_asset"],
            "manufacturer_image_url": source["manufacturer_image_url"],
            "public_target_url": target,
            **result,
        })
    summary = {
        "purpose": "bounded public HTTP HEAD readback for exact-source-verified kit/pack media targets",
        "source_report_sha256": sha256_file(SOURCE),
        "targets_checked": len(output_rows),
        "status_counts": counts,
        "max_workers": MAX_WORKERS,
        "timeout_seconds": TIMEOUT_SECONDS,
        "http_method": "HEAD",
        "uploads_or_media_mutations": 0,
        "report_csv": str(OUTPUT.relative_to(ROOT)).replace("\\", "/"),
    }
    if args.write:
        with OUTPUT.open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=list(output_rows[0]), lineterminator="\r\n")
            writer.writeheader()
            writer.writerows(output_rows)
        SUMMARY.write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

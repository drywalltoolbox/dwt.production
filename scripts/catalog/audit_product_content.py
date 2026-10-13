#!/usr/bin/env python3
"""Audit and propose a non-destructive DTB product-content migration.

No WooCommerce writes and no mutation of the canonical CSV. Outputs are
deterministic review artifacts, not approved catalog truth.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
HEADINGS = {
    "features": "features",
    "key features": "features",
    "product features": "features",
    "features and benefits": "features",
    "set includes": "kit_contents",
    "kit includes": "kit_contents",
    "package includes": "kit_contents",
    "included components": "kit_contents",
    "what's included": "kit_contents",
    "specifications": "specifications",
    "technical specifications": "specifications",
    "technical details": "specifications",
}
PRESENTATION = re.compile(r"<(?:span|font|div)\b|(?:style|class|data-ogsb|data-ogsc|width|align)\s*=", re.I)
LAYOUT = re.compile(r"(?i)(?:width|float|display|grid-template|columns?)\s*:")
HTML = re.compile(r"<[a-z][a-z0-9-]*(?:\s[^>]*)?>", re.I)


class ContentBlocks(HTMLParser):
    """Collect text within semantic paragraph/list/heading blocks in document order."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.blocks = []
        self.active = None
        self.depth = 0
        self.text = []
        self.ignored = 0
        self.malformed = False

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style"):
            self.ignored += 1
            return
        if self.ignored:
            return
        if self.active:
            self.depth += 1
        elif tag in ("p", "h2", "h3", "h4", "h5", "h6", "li", "blockquote"):
            self.active = tag
            self.depth = 1
            self.text = []
        elif tag == "br":
            self.blocks.append(("break", ""))

    def handle_endtag(self, tag):
        if tag in ("script", "style") and self.ignored:
            self.ignored -= 1
            return
        if self.ignored or not self.active:
            return
        self.depth -= 1
        if not self.depth:
            value = " ".join("".join(self.text).split())
            if value:
                self.blocks.append((self.active, value))
            self.active = None
            self.text = []

    def handle_data(self, data):
        if self.ignored:
            return
        if self.active:
            self.text.append(data)
        elif data.strip():
            self.blocks.append(("text", " ".join(data.split())))


def classify(description):
    if not description.strip():
        return {"description": [], "features": [], "kit_contents": [], "specifications": []}, ["missing_description"]
    sections = {"description": [], "features": [], "kit_contents": [], "specifications": []}
    reasons = []
    if not HTML.search(description):
        # Plain text remains intact; do not infer implicit headings or features.
        sections["description"].append(description.strip())
        return sections, []
    parser = ContentBlocks()
    try:
        parser.feed(description)
        parser.close()
    except Exception:
        return {"description": [description], "features": [], "kit_contents": [], "specifications": []}, ["parse_error"]
    current = "description"
    explicit = set()
    for kind, value in parser.blocks:
        label = re.sub(r"\s*:\s*$", "", value).strip().lower()
        if kind in ("h2", "h3", "h4", "h5", "h6", "p") and label in HEADINGS:
            # The <p> case covers <p><strong>Features</strong></p>.
            current = HEADINGS[label]
            explicit.add(current)
            continue
        if kind in ("h2", "h3", "h4", "h5", "h6") and current != "description":
            # Unknown subsection: preserve it in current content, flag for review.
            reasons.append("unmapped_subheading")
        if value:
            sections[current].append(value)
    if not parser.blocks:
        reasons.append("unparsed_markup")
        sections["description"].append(description)
    if PRESENTATION.search(description):
        reasons.append("legacy_markup")
    if LAYOUT.search(description):
        reasons.append("embedded_layout")
    if "features" not in explicit and re.search(r"\bfeatures\b", description, re.I):
        reasons.append("ambiguous_features")
    if "specifications" in explicit:
        reasons.append("specs_in_description_require_reconciliation")
    if not sections["description"]:
        reasons.append("missing_overview")
    return sections, sorted(set(reasons))


def audit_row(row, index):
    original = row.get("Description", "") or ""
    short = row.get("Short description", "") or ""
    sections, reasons = classify(original)
    if not short.strip():
        reasons.append("missing_short_description")
    if row.get("Type") == "variation":
        reasons.append("variation_inheritance_review")
    if not sections["features"] and row.get("Type") == "variable":
        reasons.append("missing_parent_features")
    # Only structured, unambiguous clean records are auto-classifiable;
    # every output remains a proposal, never an automatic CSV write.
    status = ("missing_supporting_data" if "missing_description" in reasons
              else "requires_editorial_review" if reasons
              else "auto_classifiable" if sections["features"] or sections["kit_contents"]
              else "already_compliant")
    key = row.get("SKU", "") or f"ROW-{index:05d}"
    return {
        "row_number": index,
        "sku": key,
        "type": row.get("Type", ""),
        "name": row.get("Name", ""),
        "status": status,
        "reason_codes": sorted(set(reasons)),
        "original_description_sha256": hashlib.sha256(original.encode("utf-8")).hexdigest(),
        "original_description": original,
        "original_short_description": short,
        "proposed_sections": sections,
        "migration": "review_only",
    }


def run(catalog, output):
    output.mkdir(parents=True, exist_ok=True)
    with catalog.open("r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        required = {"Type", "SKU", "Name", "Description", "Short description"}
        if not required.issubset(reader.fieldnames or []):
            raise ValueError(f"missing columns: {sorted(required - set(reader.fieldnames or []))}")
        records = [audit_row(row, index) for index, row in enumerate(reader, start=2)]
    report = output / "product-content-audit.csv"
    proposals = output / "product-content-proposals.jsonl"
    with report.open("w", newline="", encoding="utf-8") as fh:
        fields = ["row_number", "sku", "type", "name", "status", "reason_codes", "original_description_sha256"]
        writer = csv.DictWriter(fh, fieldnames=fields)
        writer.writeheader()
        for record in records:
            writer.writerow({k: "|".join(record[k]) if k == "reason_codes" else record[k] for k in fields})
    with proposals.open("w", encoding="utf-8") as fh:
        for record in records:
            fh.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    counts = {}
    for record in records:
        counts[record["status"]] = counts.get(record["status"], 0) + 1
    summary = {
        "schema_version": 1,
        "catalog_path": str(catalog),
        "catalog_sha256": hashlib.sha256(catalog.read_bytes()).hexdigest(),
        "records": len(records),
        "status_counts": dict(sorted(counts.items())),
        "safe_to_import_without_review": False,
        "outputs": [report.name, proposals.name],
    }
    (output / "product-content-summary.json").write_text(
        json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(json.dumps(summary, sort_keys=True))
    return summary


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--catalog", type=Path, default=CATALOG)
    cli.add_argument("--output", type=Path, default=ROOT / "products/dev/catalog-content-normalization")
    args = cli.parse_args()
    run(args.catalog.resolve(), args.output.resolve())

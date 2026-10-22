"""Build compact, page-cited OptiWrite evidence from local manufacturer PDFs.

Run from the repository root with: python scripts/catalog/build_optiwrite_manufacturer_index.py
Requires PyMuPDF (fitz). This never calls an AI model or Google Search.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
import unicodedata
from pathlib import Path

import fitz


ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
OUTPUT = ROOT / "remix_-optiwrite-ai/src/data/manufacturerCatalogIndex.json"
SOURCES = (
    ("Columbia Tools", "columbia-customercatalogue2024.pdf"),
    ("TapeTech", "TapeTech-Product-Catalog_ENG_web.pdf"),
    ("LEVEL5", "LEVEL5_General_Tools_Catalog_2025_PROOF.pdf"),
    ("SurPro", "SurPro_Stilts_Catalog_24.1.pdf"),
)
# These pages each describe one SurPro material/model family. The earlier overview
# pages combine families, so they are unsuitable for variation-specific evidence.
SURPRO_FAMILY_PAGES = {
    "S1-M": 4, "S1-A": 5, "S1X-M": 7, "S1X-A": 8,
    "S2-M": 10, "S2-A": 11, "S2X-M": 13, "S2X-A": 14,
}
PROSE_VERBS = re.compile(
    r"\b(?:fits?|features?|includes?|contains?|provides?|designed|used|uses?|"
    r"works?|attaches?|applies?|delivers?|allows?|enables?|replaces?|accepts?)\b",
    re.I,
)


def normalized_lines(text: str) -> list[str]:
    return [
        re.sub(r"\s+", " ", unicodedata.normalize("NFKC", line).replace("\ufffd", " ")).strip()
        for line in text.splitlines()
        if line.strip()
    ]


def search_text(text: str) -> str:
    return " ".join(re.findall(r"[a-z0-9]+", unicodedata.normalize("NFKC", text).lower()))


def name_anchors(name: str, brand: str) -> list[str]:
    clean_name = re.sub(r"^" + re.escape(brand) + r"\s+", "", name.strip(), flags=re.I)
    clean_name = clean_name.split(" - ", 1)[0]
    tokens = search_text(clean_name).split()
    anchors = []
    for width in range(min(5, len(tokens)), 1, -1):
        for start in range(len(tokens) - width + 1):
            segment = tokens[start : start + width]
            if sum(token.isalpha() and len(token) > 2 for token in segment) < 2:
                continue
            phrase = " ".join(segment)
            if phrase not in anchors:
                anchors.append(phrase)
    return anchors


def passage(lines: list[str], line_index: int, specific: bool, sku: str = "") -> str:
    if specific:
        if sku:
            joined = " ".join(lines[line_index : min(len(lines), line_index + 3)])
            sentence = next((part for part in re.split(r"(?<=\.)\s+", joined)
                             if re.search(r"(?<![A-Z0-9])" + re.escape(sku.upper()) + r"(?![A-Z0-9])", part.upper())), "")
            if sentence and "." in sentence:
                return sentence[:650]
        return lines[line_index][:650]
    return " ".join(lines[max(0, line_index - 2) : min(len(lines), line_index + 2)])[:220]


def match_page(lines: list[str], sku: str, anchors: list[str], allow_partial_name: bool) -> dict | None:
    exact_sku = re.compile(r"(?<![A-Z0-9])" + re.escape(sku.upper()) + r"(?![A-Z0-9])") if len(sku) >= 2 else None
    sku_hits = []
    name_hits = []
    for index, line in enumerate(lines):
        if exact_sku and exact_sku.search(line.upper()):
            first_sku = exact_sku.search(line.upper())
            preceding_code = re.search(r"\b[A-Z0-9]+(?:-[A-Z0-9]+)*\d[A-Z0-9-]*\b", line[: first_sku.start()].upper())
            specific = (len(re.findall(r"[A-Za-z]+", line)) >= 7 and bool(PROSE_VERBS.search(line))
                        and not preceding_code and len(exact_sku.findall(line.upper())) == 1)
            sku_hits.append({"match": "sku", "detail": "specific" if specific else "listing", "excerpt": passage(lines, index, specific, sku)})
        normalized = search_text(line)
        for anchor in anchors if allow_partial_name else anchors[:1]:
            if f" {anchor} " in f" {normalized} ":
                specific = anchor == anchors[0] and len(normalized.split()) >= 9 and bool(PROSE_VERBS.search(line))
                name_hits.append({"match": "name", "detail": "specific" if specific else "listing", "anchor": anchor, "excerpt": passage(lines, index, specific)})
                break
    if sku_hits:
        return next((hit for hit in sku_hits if hit["detail"] == "specific"), sku_hits[0])
    if name_hits:
        return next((hit for hit in name_hits if hit["detail"] == "specific"), name_hits[0])
    return None


def surpro_family_match(row: dict, pages: list[list[str]]) -> dict | None:
    sku = row.get("SKU", "").strip().upper()
    name = row.get("Name", "")
    model = re.search(r"\b(S1X|S2X|S1|S2)\b", name, re.I)
    material = "M" if re.search(r"\bMagnesium\b", name, re.I) else "A" if re.search(r"\bAluminum\b", name, re.I) else ""
    family = f"{model.group(1).upper()}-{material}" if model and material else None
    if not family:
        return None
    page_number = SURPRO_FAMILY_PAGES[family]
    lines = pages[page_number - 1]
    exact_sku = bool(sku and re.search(r"(?<![A-Z0-9])" + re.escape(sku) + r"(?![A-Z0-9])", "\n".join(lines).upper()))
    is_parent = row.get("Type") == "variable"
    # A missing variation SKU gets family context only. Its own size/weight is unverified.
    if not exact_sku and not is_parent:
        return {"page": page_number, "match": "name", "detail": "listing",
                "excerpt": f"{family} family catalog page; this exact variation SKU is absent."}
    table = []
    body_lines = lines
    header = next((i for i in range(len(lines) - 3) if lines[i:i + 4] ==
                   ["MODEL NO.", "HEIGHT", "UPC", "WEIGHT (EA.)"]), -1)
    if header >= 0:
        model_codes = []
        for line in lines[header + 4:]:
            if re.fullmatch(re.escape(family) + r"-\d{4}", line, re.I):
                model_codes.append(line)
            else:
                break
        values = lines[header + 4 + len(model_codes):]
        for index, code in enumerate(model_codes):
            triple = values[index * 3: index * 3 + 3]
            if len(triple) == 3 and "LBS" in triple[2].upper() and triple[1].isdigit():
                height = re.sub(r"(?<=\d)[^\x00-\x7F]", '"', triple[0])
                table.append(f"{code}: height {height}, UPC {triple[1]}, weight each {triple[2]}")
        if len(table) == len(model_codes) and table:
            body_lines = lines[:header] + lines[header + 4 + len(model_codes) + 3 * len(model_codes):]
    if not is_parent:
        table = [item for item in table if item.startswith(sku + ":")]
    excerpt = ("Verified model table: " + "; ".join(table) + ". " if table else "") + " ".join(body_lines)
    return {"page": page_number, "match": "sku" if exact_sku else "name", "detail": "specific",
            "excerpt": excerpt[:3000]}


def main() -> None:
    with CATALOG.open(encoding="utf-8-sig", newline="") as source:
        products = list(csv.DictReader(source))

    result: dict = {"schemaVersion": 1, "catalogs": [], "products": {}}
    for brand, filename in SOURCES:
        pdf_path = ROOT / filename
        if not pdf_path.is_file():
            raise FileNotFoundError(f"Manufacturer catalog is missing: {pdf_path}")
        document = fitz.open(pdf_path)
        pages = [normalized_lines(page.get_text("text")) for page in document]
        result["catalogs"].append({
            "brand": brand,
            "file": filename,
            "sha256": hashlib.sha256(pdf_path.read_bytes()).hexdigest(),
            "pageCount": len(pages),
            "pages": [{"page": number, "text": "\n".join(lines)} for number, lines in enumerate(pages, start=1)],
        })

        brand_products: dict = {}
        for row in products:
            if row.get("Brands", "").strip().lower() != brand.lower():
                continue
            sku = row.get("SKU", "").strip()
            name = row.get("Name", "").strip()
            if not name:
                continue
            if brand == "SurPro":
                match = surpro_family_match(row, pages)
                if match:
                    brand_products[sku] = {"catalogName": name, "matches": [match]}
                continue
            anchors = name_anchors(name, brand)
            is_part = row.get("Meta: _dtb_product_kind", "").strip().lower() == "part" or row.get("Meta: _dtb_is_parts", "").strip() == "1"
            if is_part and (not anchors or len(anchors[0].split()) < 3):
                anchors = []
            hits = []
            for page_number, lines in enumerate(pages, start=1):
                hit = match_page(lines, sku if row.get("Type") != "variable" else "", anchors, not is_part)
                if hit:
                    hits.append({"page": page_number, **hit})
            if not hits:
                continue
            exact_hits = [hit for hit in hits if hit["match"] == "sku"]
            eligible = exact_hits or hits
            detailed = [hit for hit in eligible if hit["detail"] == "specific"]
            selected = (detailed or eligible)[:2]
            brand_products[sku] = {"catalogName": name, "matches": selected}
        result["products"][brand] = dict(sorted(brand_products.items()))
        print(f"{brand}: {len(brand_products)} product rows indexed from {len(pages)} pages")

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    encoded = json.dumps(result, ensure_ascii=False, separators=(",", ":")) + "\n"
    if not OUTPUT.exists() or OUTPUT.read_text(encoding="utf-8") != encoded:
        OUTPUT.write_text(encoded, encoding="utf-8", newline="\n")
    print(f"Wrote {OUTPUT.relative_to(ROOT)} ({len(encoded):,} characters)")


if __name__ == "__main__":
    main()

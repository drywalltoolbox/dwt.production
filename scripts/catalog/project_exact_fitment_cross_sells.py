#!/usr/bin/env python3
"""Project exact, public part fitment into directional WooCommerce cart cross-sells.

Preview is the default. The optional apply path is digest-bound, additive, field-
allowlisted, and creates the required exact sibling catalog backup.
"""
from __future__ import annotations

import argparse
import csv
import contextlib
import hashlib
import io
import json
import os
import tempfile
from collections import defaultdict
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CATALOG = ROOT / "products/launch/official/dtb_official_catalog.csv"
DEFAULT_REPORT = ROOT / "products/dev/catalog-enrichment/compatibility/exact-fitment-cross-sell-projection.json"
DEFAULT_IMPORT = ROOT / "products/dev/catalog-enrichment/compatibility/exact-fitment-cross-sells-woo-import.csv"
FITMENT_FIELD = "Meta: _dtb_compatible_tool_skus"
CROSS_SELL_FIELD = "Cross-sells"
TRUTHY = {"1", "true", "yes", "y"}


class ProjectionError(ValueError):
    pass


def sha256(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def truthy(row: dict[str, str], field: str) -> bool:
    return (row.get(field) or "").strip().casefold() in TRUTHY


def read_catalog(path: Path) -> tuple[bytes, list[str], list[dict[str, str]], str, bool]:
    original = path.read_bytes()
    bom = original.startswith(b"\xef\xbb\xbf")
    try:
        text = original.decode("utf-8-sig")
    except UnicodeDecodeError as exc:
        raise ProjectionError("official catalog must be UTF-8 or UTF-8 with BOM") from exc
    newline = "\r\n" if b"\r\n" in original else "\n"
    reader = csv.DictReader(io.StringIO(text, newline=""))
    fields = list(reader.fieldnames or [])
    if FITMENT_FIELD not in fields or CROSS_SELL_FIELD not in fields:
        raise ProjectionError("catalog is missing the allowlisted fitment or Cross-sells field")
    rows = list(reader)
    if not rows or any(None in row for row in rows):
        raise ProjectionError("catalog contains no rows or rows with extra unheaded fields")
    by_sku: dict[str, dict[str, str]] = {}
    for row in rows:
        sku = (row.get("SKU") or "").strip()
        if not sku or sku in by_sku:
            raise ProjectionError(f"catalog SKU is blank or duplicated: {sku!r}")
        by_sku[sku] = row
    return original, fields, rows, newline, bom


def _fitment_targets(row: dict[str, str]) -> set[str]:
    raw = (row.get(FITMENT_FIELD) or "").strip()
    if not raw:
        return set()
    try:
        values = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ProjectionError(f"{row.get('SKU')}: fitment metadata is invalid JSON") from exc
    if not isinstance(values, list) or any(not isinstance(value, str) or not value or value != value.strip() for value in values):
        raise ProjectionError(f"{row.get('SKU')}: fitment metadata must be an exact SKU string array")
    if len(set(values)) != len(values):
        raise ProjectionError(f"{row.get('SKU')}: fitment metadata contains duplicate SKUs")
    return set(values)


def _parse_cross_sells(row: dict[str, str], by_sku: dict[str, dict[str, str]]) -> list[str]:
    raw = (row.get(CROSS_SELL_FIELD) or "").strip()
    values = [item.strip() for item in raw.split(",") if item.strip()]
    if len(values) != len(set(values)):
        raise ProjectionError(f"{row.get('SKU')}: duplicate native cross-sell targets")
    for value in values:
        if value not in by_sku:
            raise ProjectionError(f"{row.get('SKU')}: cross-sell target {value!r} is not an exact catalog SKU")
        if value == row.get("SKU"):
            raise ProjectionError(f"{value}: self cross-sell is invalid")
    return values


def build_projection(rows: list[dict[str, str]]) -> tuple[list[dict[str, Any]], dict[str, int]]:
    by_sku = {row["SKU"]: row for row in rows}
    part_targets_by_tool: dict[str, set[str]] = defaultdict(set)
    public_tools_by_part: dict[str, set[str]] = defaultdict(set)
    # Only public, simple replacement-part records are eligible cart targets.
    for part in rows:
        if (
            (part.get("Meta: _dtb_product_kind") or "").strip().casefold() != "part"
            or (part.get("Type") or "").strip().casefold() != "simple"
            or not truthy(part, "Published")
            or (part.get("Visibility in catalog") or "").strip().casefold() != "visible"
        ):
            continue
        for tool_sku in _fitment_targets(part):
            tool = by_sku.get(tool_sku)
            if tool is None:
                raise ProjectionError(f"{part['SKU']}: fitment target {tool_sku!r} is not an exact catalog SKU")
            if (tool.get("Meta: _dtb_product_kind") or "").strip().casefold() != "tool":
                continue
            if not truthy(tool, "Published") or (tool.get("Visibility in catalog") or "").strip().casefold() != "visible":
                continue
            part_targets_by_tool[tool_sku].add(part["SKU"])
            public_tools_by_part[part["SKU"]].add(tool_sku)

    proposals: dict[str, dict[str, Any]] = {}
    for tool in rows:
        sku = tool["SKU"]
        if (
            (tool.get("Meta: _dtb_product_kind") or "").strip().casefold() != "tool"
            or not truthy(tool, "Published")
            or (tool.get("Visibility in catalog") or "").strip().casefold() != "visible"
        ):
            continue
        product_type = (tool.get("Type") or "").strip().casefold()
        if product_type == "simple":
            targets = set(part_targets_by_tool.get(sku, set()))
            scope = "exact_simple_tool_sku"
            evidence_tools = [sku]
        elif product_type == "variable":
            children = [
                row for row in rows
                if (row.get("Type") or "").strip().casefold() == "variation"
                and row.get("Parent") == sku
                and truthy(row, "Published")
                and (row.get("Visibility in catalog") or "").strip().casefold() == "visible"
            ]
            if not children:
                continue
            child_sets = [part_targets_by_tool.get(child["SKU"], set()) for child in children]
            targets = set.intersection(*(set(values) for values in child_sets))
            scope = "intersection_of_all_published_visible_variations"
            evidence_tools = [child["SKU"] for child in children]
        else:
            continue
        if not targets:
            continue
        existing = _parse_cross_sells(tool, by_sku)
        ranked_targets = sorted(
            targets,
            key=lambda part_sku: (len(public_tools_by_part[part_sku]), part_sku),
        )
        proposed = existing + [part_sku for part_sku in ranked_targets if part_sku not in existing]
        if proposed == existing:
            continue
        proposals[sku] = {
            "sku": sku,
            "scope": scope,
            "evidence_tool_skus": evidence_tools,
            "compatible_part_skus": ranked_targets,
            "ranking_signal": "exact_fitment_specificity_then_sku; no sales ranking because current WooCommerce total_sales values are zero",
            "public_tool_fanout_by_part_sku": {
                part_sku: len(public_tools_by_part[part_sku]) for part_sku in ranked_targets
            },
            "current_cross_sells": existing,
            "proposed_cross_sells": proposed,
        }

    return list(proposals.values()), {
        "eligible_public_part_owner_rows": sum(
            1 for row in rows
            if (row.get("Meta: _dtb_product_kind") or "").strip().casefold() == "part"
            and (row.get("Type") or "").strip().casefold() == "simple"
            and truthy(row, "Published")
            and (row.get("Visibility in catalog") or "").strip().casefold() == "visible"
        ),
        "tool_rows_with_additions": len(proposals),
        "cross_sell_edge_additions": sum(
            len(item["proposed_cross_sells"]) - len(item["current_cross_sells"]) for item in proposals.values()
        ),
    }


def serialize(fields: list[str], rows: list[dict[str, str]], newline: str, bom: bool) -> bytes:
    output = io.StringIO(newline="")
    writer = csv.DictWriter(output, fieldnames=fields, lineterminator=newline, extrasaction="raise")
    writer.writeheader()
    writer.writerows(rows)
    payload = output.getvalue().encode("utf-8")
    return (b"\xef\xbb\xbf" + payload) if bom else payload


def serialize_import(proposals: list[dict[str, Any]]) -> bytes:
    output = io.StringIO(newline="")
    writer = csv.DictWriter(output, fieldnames=["SKU", CROSS_SELL_FIELD], lineterminator="\r\n")
    writer.writeheader()
    for proposal in proposals:
        writer.writerow({"SKU": proposal["sku"], CROSS_SELL_FIELD: ", ".join(proposal["proposed_cross_sells"])})
    return b"\xef\xbb\xbf" + output.getvalue().encode("utf-8")


def atomic_write(path: Path, payload: bytes) -> None:
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


@contextlib.contextmanager
def catalog_lock(catalog_path: Path):
    """Serialize official-catalog writes across concurrent projection processes."""
    lock_identity = hashlib.sha256(str(catalog_path).encode("utf-8")).hexdigest()[:20]
    lock_path = Path(tempfile.gettempdir()) / f"dtb-official-catalog-{lock_identity}.lock"
    with lock_path.open("a+b") as handle:
        if os.name == "nt":
            import msvcrt

            handle.seek(0, os.SEEK_END)
            if handle.tell() == 0:
                handle.write(b"\0")
                handle.flush()
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_LOCK, 1)
        else:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            if os.name == "nt":
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                import fcntl

                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path, default=DEFAULT_CATALOG)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--import-csv", type=Path, default=DEFAULT_IMPORT)
    parser.add_argument("--apply", action="store_true", help="apply the previewed, exact fitment-derived additions")
    parser.add_argument("--expected-sha256", help="required with --apply; binds the write to exact input bytes")
    args = parser.parse_args()
    catalog_path = args.catalog.resolve()
    original, fields, rows, newline, bom = read_catalog(catalog_path)
    before = sha256(original)
    if args.apply and (not args.expected_sha256 or args.expected_sha256.casefold() != before):
        raise ProjectionError("--apply requires --expected-sha256 matching the current catalog bytes")
    proposals, counts = build_projection(rows)
    for proposal in proposals:
        by_sku = {row["SKU"]: row for row in rows}
        by_sku[proposal["sku"]][CROSS_SELL_FIELD] = ", ".join(proposal["proposed_cross_sells"])
    projected = serialize(fields, rows, newline, bom) if proposals else original
    report: dict[str, Any] = {
        "schema_version": 1,
        "report_type": "exact_fitment_directional_cart_cross_sell_projection",
        "mode": "apply" if args.apply else "preview",
        "input_sha256": before,
        "projected_sha256": sha256(projected),
        "catalog_rows": len(rows),
        "catalog_columns": len(fields),
        "counts": counts,
        "policy": {
            "source": FITMENT_FIELD,
            "target_field": CROSS_SELL_FIELD,
            "direction": "visible tool to exact compatible visible simple replacement part",
            "variable_tool_rule": "part must have exact fitment to every published visible variation",
            "ordering": "parts that fit fewer published visible tools first, then exact SKU; this is a deterministic relevance heuristic, not purchase evidence",
            "upsells_created": False,
            "similarity_inference_used": False,
            "inventory_or_price_fields_changed": False,
        },
        "proposals": proposals,
        "applied": False,
        "backup": None,
        "import_csv": None,
    }
    if args.apply and proposals:
        with catalog_lock(catalog_path):
            if sha256(catalog_path.read_bytes()) != before:
                raise ProjectionError("catalog changed during projection; refusing write")
            backup_path = catalog_path.with_name("dtb_official_catalog.csv.bak")
            atomic_write(backup_path, original)
            if sha256(backup_path.read_bytes()) != before:
                raise ProjectionError("required sibling backup failed SHA-256 verification")
            if sha256(catalog_path.read_bytes()) != before:
                raise ProjectionError("catalog changed after backup; refusing write")
            atomic_write(catalog_path, projected)
            if sha256(catalog_path.read_bytes()) != sha256(projected):
                atomic_write(catalog_path, original)
                raise ProjectionError("catalog post-write digest mismatch; original bytes restored")
            report["applied"] = True
            report["backup"] = str(backup_path)
    if proposals:
        import_path = args.import_csv.resolve()
        import_path.parent.mkdir(parents=True, exist_ok=True)
        atomic_write(import_path, serialize_import(proposals))
        report["import_csv"] = str(import_path)
    args.report.resolve().parent.mkdir(parents=True, exist_ok=True)
    args.report.resolve().write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({key: report[key] for key in ("mode", "input_sha256", "projected_sha256", "counts", "applied", "backup")}, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ProjectionError, OSError, csv.Error) as exc:
        print(f"ERROR: {exc}")
        raise SystemExit(1)

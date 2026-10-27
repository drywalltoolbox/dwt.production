from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / 'docs/catalog/columbia-catalog-audit'
SOURCE = AUDIT / 'temporary-reference/columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv'
TARGET = AUDIT / 'current-checkpoint/dtb_columbia_catalog_official.csv'
METADATA = AUDIT / 'current-checkpoint/checkpoint-manifest.json'
OFFICIAL = ROOT / 'products/launch/official/dtb_official_catalog.csv'
csv.field_size_limit(10_000_000)


def digest(path: Path) -> str:
    obj = hashlib.sha256()
    with path.open('rb') as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b''):
            obj.update(block)
    return obj.hexdigest()


def inspect_csv(path: Path) -> tuple[list[str], int]:
    with path.open(encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames or len(reader.fieldnames) != len(set(reader.fieldnames)):
            raise ValueError(f'Invalid CSV header: {path}')
        count = 0
        for row in reader:
            if None in row:
                raise ValueError(f'Extra CSV fields: {path}, data row {count + 1}')
            count += 1
        return list(reader.fieldnames), count


def status() -> dict:
    if not TARGET.exists() and not METADATA.exists():
        return {'status': 'not_created'}
    if not TARGET.is_file() or not METADATA.is_file():
        return {'status': 'attention', 'reason': 'checkpoint or metadata is missing'}
    saved = json.loads(METADATA.read_text(encoding='utf8'))
    current = digest(TARGET)
    source_hash = digest(SOURCE) if SOURCE.is_file() else None
    edited = current != saved['checkpoint_sha256']
    source_changed = source_hash != saved['source_sha256']
    return {'status': 'attention' if edited or source_changed else 'ok',
            'checkpoint_created_utc': saved['created_utc'], 'rows': saved['rows'],
            'checkpoint_edited': edited, 'working_source_changed': source_changed}


def refresh() -> dict:
    prior = status()
    if prior['status'] == 'attention' and prior.get('checkpoint_edited', True):
        raise RuntimeError('Checkpoint was edited or its metadata is missing; preserve and reconcile it before refreshing.')
    if not SOURCE.is_file() or not OFFICIAL.is_file():
        raise FileNotFoundError('Working source or official catalog not found.')
    source_hash = digest(SOURCE)
    official_hash = digest(OFFICIAL)
    fields, rows = inspect_csv(SOURCE)
    official_fields, _ = inspect_csv(OFFICIAL)
    if fields != official_fields:
        raise ValueError('Working reference header differs from the official catalog.')
    if digest(SOURCE) != source_hash or digest(OFFICIAL) != official_hash:
        raise RuntimeError('Input changed while inspecting the source.')
    TARGET.parent.mkdir(parents=True, exist_ok=True)
    METADATA.parent.mkdir(parents=True, exist_ok=True)
    temp = None
    try:
        with tempfile.NamedTemporaryFile(dir=TARGET.parent, prefix='.checkpoint-', suffix='.tmp', delete=False) as out:
            temp = Path(out.name)
            with SOURCE.open('rb') as inp:
                for block in iter(lambda: inp.read(1024 * 1024), b''):
                    out.write(block)
            out.flush()
            os.fsync(out.fileno())
        if digest(temp) != source_hash or digest(SOURCE) != source_hash or digest(OFFICIAL) != official_hash:
            raise RuntimeError('Input changed while preparing the checkpoint.')
        final_status = status()
        if prior['status'] == 'not_created':
            if final_status['status'] != 'not_created':
                raise RuntimeError('Checkpoint appeared while preparing the update.')
        elif final_status.get('checkpoint_edited') or final_status.get('reason'):
            raise RuntimeError('Checkpoint changed while preparing the update.')
        os.replace(temp, TARGET)
        info = {'schema_version': 1, 'created_utc': datetime.now(timezone.utc).isoformat(),
                'checkpoint_file': str(TARGET.relative_to(ROOT)).replace('\\', '/'),
                'source_file': str(SOURCE.relative_to(ROOT)).replace('\\', '/'),
                'source_sha256': source_hash, 'checkpoint_sha256': digest(TARGET), 'rows': rows,
                'official_catalog_sha256': official_hash,
                'purpose': 'Review checkpoint of an unfinished working catalog; not an approved import.'}
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf8', newline='\n', dir=METADATA.parent,
                                         prefix='.checkpoint-metadata-', suffix='.tmp', delete=False) as out:
            metadata_temp = Path(out.name)
            json.dump(info, out, indent=2, ensure_ascii=False)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
        os.replace(metadata_temp, METADATA)
        return {'status': 'refreshed', 'rows': rows, 'created_utc': info['created_utc']}
    finally:
        if temp is not None:
            temp.unlink(missing_ok=True)


def main() -> None:
    parser = argparse.ArgumentParser(description='Inspect or refresh the single Columbia catalog checkpoint.')
    parser.add_argument('--refresh', action='store_true', help='Copy the current working catalog into the checkpoint')
    args = parser.parse_args()
    import build_catalog_management as management
    management_state = management.status()
    if not args.refresh:
        result = status()
        result['catalog_management'] = management_state
        if management_state['status'] != 'ok' and result['status'] == 'ok':
            result['status'] = 'attention'
        print(json.dumps(result, indent=2))
        return
    if management_state['status'] == 'attention' and (
            management_state.get('management_file_edited') or management_state.get('reason')):
        raise RuntimeError('The catalog management JSON was edited or its manifest is missing; '
                           'preserve and reconcile it before refreshing the checkpoint.')
    result = refresh()
    try:
        result['catalog_management'] = management.refresh()
    except Exception as exc:
        result['catalog_management'] = {'status': 'not_refreshed', 'reason': str(exc)}
        print(json.dumps(result, indent=2))
        sys.exit(1)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()

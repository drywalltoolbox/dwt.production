import fs from 'node:fs/promises';
import path from 'node:path';

const sourcePath = 'products/launch/enhanced_products.csv';
const targetPath = 'products/launch/official/dtb_official_catalog.csv';
const backupPath = 'products/launch/official/dtb_official_catalog.csv.20261022-enhanced-content-merge.bak';
const sourceCsv = await fs.readFile(sourcePath, 'utf8');
const targetCsv = await fs.readFile(targetPath, 'utf8');
const fieldsToMerge = [
  'Name',
  'Short description',
  'Description',
  'Meta: _dtb_specs_json',
  'Meta: _dtb_features_json',
  'Meta: _dtb_content_schema_version',
  'Meta: _dtb_seo_title',
  'Meta: _dtb_seo_description',
  'Meta: _dtb_seo_focus_kw',
];

function decodeCell(raw) {
  return raw.startsWith('"') && raw.endsWith('"') ? raw.slice(1, -1).replaceAll('""', '"') : raw;
}
function encodeCell(value, keepQuoted) {
  return keepQuoted || /[",\r\n]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value;
}
function parseCsv(csv, label) {
  const records = [];
  let cells = [], fieldStart = 0, quoted = false;
  for (let i = 0; i < csv.length; i++) {
    const ch = csv[i];
    if (ch === '"') {
      if (quoted && csv[i + 1] === '"') i++;
      else quoted = !quoted;
    } else if (!quoted && (ch === ',' || ch === '\r' || ch === '\n')) {
      const raw = csv.slice(fieldStart, i);
      cells.push({ start: fieldStart, end: i, raw, value: decodeCell(raw) });
      if (ch === ',') fieldStart = i + 1;
      else {
        records.push(cells);
        cells = [];
        if (ch === '\r' && csv[i + 1] === '\n') i++;
        fieldStart = i + 1;
      }
    }
  }
  if (quoted) throw new Error(`${label}: unterminated CSV quoted field.`);
  if (fieldStart < csv.length || cells.length) {
    const raw = csv.slice(fieldStart);
    cells.push({ start: fieldStart, end: csv.length, raw, value: decodeCell(raw) });
    records.push(cells);
  }
  if (!records.length) throw new Error(`${label}: no CSV records.`);
  const headers = records[0].map(cell => cell.value.replace(/^\uFEFF/, ''));
  for (const [rowIndex, row] of records.entries()) {
    if (row.length !== headers.length) throw new Error(`${label}: record ${rowIndex + 1} has ${row.length} fields; expected ${headers.length}.`);
  }
  return { records, headers };
}
function makeIndex(headers, required, label) {
  const index = new Map(headers.map((name, i) => [name, i]));
  for (const name of required) if (!index.has(name)) throw new Error(`${label}: required field missing: ${name}`);
  return index;
}

const source = parseCsv(sourceCsv, 'Enhanced catalog');
const target = parseCsv(targetCsv, 'Official catalog');
const sourceIndex = makeIndex(source.headers, ['SKU', ...fieldsToMerge], 'Enhanced catalog');
const targetIndex = makeIndex(target.headers, ['SKU', ...fieldsToMerge], 'Official catalog');
if (source.records.length - 1 !== 1133 || target.records.length - 1 !== 1134) {
  throw new Error(`Unexpected row counts: enhanced=${source.records.length - 1}, official=${target.records.length - 1}.`);
}

const sourceBySku = new Map();
for (const row of source.records.slice(1)) {
  const sku = row[sourceIndex.get('SKU')].value.trim();
  if (!sku || sourceBySku.has(sku)) throw new Error(`Missing or duplicate enhanced SKU: ${sku || '(blank)'}`);
  sourceBySku.set(sku, row);
}
const targetBySku = new Map();
for (const row of target.records.slice(1)) {
  const sku = row[targetIndex.get('SKU')].value.trim();
  if (!sku || targetBySku.has(sku)) throw new Error(`Missing or duplicate official SKU: ${sku || '(blank)'}`);
  targetBySku.set(sku, row);
}
for (const sku of sourceBySku.keys()) if (!targetBySku.has(sku)) throw new Error(`Enhanced SKU is absent from official catalog: ${sku}`);

const sourceOnly = new Set(fieldsToMerge);
const edits = [];
const counts = Object.fromEntries(fieldsToMerge.map(field => [field, 0]));
const skippedBlank = Object.fromEntries(fieldsToMerge.map(field => [field, 0]));
for (const [sku, sourceRow] of sourceBySku) {
  const targetRow = targetBySku.get(sku);
  for (const field of sourceOnly) {
    const sourceValue = sourceRow[sourceIndex.get(field)].value;
    const targetCell = targetRow[targetIndex.get(field)];
    if (!sourceValue.trim()) {
      skippedBlank[field]++;
      continue;
    }
    if (sourceValue === targetCell.value) continue;
    edits.push({ start: targetCell.start, end: targetCell.end, value: encodeCell(sourceValue, targetCell.raw.startsWith('"')) });
    counts[field]++;
  }
}

if (!edits.length) throw new Error('No enhanced content changes found; no file was written.');
await fs.copyFile(targetPath, backupPath, fs.constants.COPYFILE_EXCL);
const merged = targetCsv;
let output = merged;
for (const edit of edits.sort((a, b) => b.start - a.start)) {
  output = output.slice(0, edit.start) + edit.value + output.slice(edit.end);
}
const tempPath = path.join(path.dirname(targetPath), `.dtb_official_catalog.enhanced-merge-${process.pid}.tmp`);
try {
  await fs.writeFile(tempPath, output, 'utf8');
  await fs.rename(tempPath, targetPath);
} catch (error) {
  await fs.rm(tempPath, { force: true });
  throw error;
}
console.log(JSON.stringify({ sourceRows: sourceBySku.size, destinationRows: targetBySku.size, matchedRows: sourceBySku.size, officialOnlyRows: targetBySku.size - sourceBySku.size, changedCells: edits.length, backupPath, counts, skippedBlank }, null, 2));

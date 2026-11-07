/**
 * frontend/src/utils/parseProductCsv.js
 *
 * Parses a WooCommerce product-import CSV (the same format used by
 * WooCommerce → Products → Import and the /dtb/v1/products-csv endpoint).
 *
 * The CSV uses:
 *   • RFC-4180 quoting — fields containing commas/newlines are wrapped in ""
 *   • Pipe ( | ) as a multi-value separator for supported multi-value fields.
 *   • A "Categories" column whose leaf name maps to our internal category keys
 *   • An "Attribute 1 name / value" pair for the Brand
 *   • A "Meta: upc" column for UPC barcodes
 *
 * Output shape matches normalizeProduct() in src/services/api.js so every
 * downstream component gets the same object regardless of source.
 */

// ─── CSV tokenizer ────────────────────────────────────────────────────────────

/**
 * Split one CSV line into field strings, handling RFC-4180 quoting.
 * @param {string} line
 * @returns {string[]}
 */
function tokenizeLine(line) {
  const fields = [];
  let cur = '';
  let inQuote = false;

  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (inQuote) {
      if (ch === '"' && line[i + 1] === '"') {
        cur += '"';
        i++;               // skip escaped quote
      } else if (ch === '"') {
        inQuote = false;   // closing quote
      } else {
        cur += ch;
      }
    } else {
      if (ch === '"') {
        inQuote = true;
      } else if (ch === ',') {
        fields.push(cur);
        cur = '';
      } else {
        cur += ch;
      }
    }
  }
  fields.push(cur);
  return fields;
}

/**
 * Parse a complete CSV text (with header row) into an array of plain objects.
 * Multi-line quoted fields are re-joined before tokenising.
 *
 * @param {string} csvText  Full CSV text
 * @returns {Object[]}
 */
export function parseCsvText(csvText) {
  // Normalise line endings
  const text = csvText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

  // Re-assemble multi-line quoted fields into single logical lines
  const logicalLines = [];
  let buffer = '';
  let openQuotes = 0;

  for (const ch of text) {
    if (ch === '"') openQuotes++;
    if (ch === '\n' && openQuotes % 2 === 0) {
      logicalLines.push(buffer);
      buffer = '';
    } else {
      buffer += ch;
    }
  }
  if (buffer) logicalLines.push(buffer);

  if (logicalLines.length < 2) return [];

  // Strip UTF-8 BOM (\uFEFF) from the start of the first line so that the first
  // column header ("Brands") is keyed correctly in every row object.
  const firstLine = logicalLines[0].replace(/^\uFEFF/, '');
  const headers = tokenizeLine(firstLine);

  const rows = [];
  for (let i = 1; i < logicalLines.length; i++) {
    const line = logicalLines[i].trim();
    if (!line) continue;
    const values = tokenizeLine(line);
    const row = {};
    headers.forEach((h, idx) => {
      const header = h.trim();
      // Upsell relationships are not part of the storefront CSV data model.
      if (/^(?:meta:\s*)?_?upsell(?:s|_ids)?$/i.test(header)) return;
      row[header] = (values[idx] || '').trim();
    });
    rows.push(row);
  }
  return rows;
}

// ─── Category mapping ─────────────────────────────────────────────────────────
// Maps WooCommerce category leaf names → our internal filter key strings
// Exported so api.js can reuse the same mapping for REST API products.

export const CATEGORY_MAP = {
  // Taping — automatic & semi-automatic tapers, tool sets/kits
  'automatic tapers':            'taping',
  'automatic taping tools':      'taping',
  'semi-automatic tools':        'taping',
  'semi-automatic taping tools': 'taping',
  'taping & finishing tools':    'taping',  // legacy leaf (pre-remap)
  'tool sets & bundles':         'taping',  // legacy leaf (pre-remap)
  'tool sets & kits':            'taping',
  // Finishing — flat boxes, handles, knives/blades, trowels, spotters
  'finishing boxes':             'finishing',
  'flat boxes':                  'finishing',
  'handles & extensions':        'finishing',
  'blades & knives':             'finishing',  // legacy leaf
  'knives & blades':             'finishing',
  'finishing trowels':           'finishing',
  'rollers & stands':            'finishing',
  'spotters':                    'finishing',  // legacy leaf
  'nail spotters':               'finishing',
  'accessories & adapters':      'finishing',
  // Corner
  'corner & angle tools':        'corner',    // legacy leaf (pre-remap)
  'corner tools':                'corner',
  'angle tools':                 'corner',
  // Mud boxes / pumps
  'mud boxes & pumps':           'mudboxes',
  'mud pans & compound tubes':   'mudboxes',
  'mud pans & pumps':            'mudboxes',
  'loading pumps':               'mudboxes',
  'pumps & accessories':         'mudboxes',  // legacy leaf (pre-remap)
  // Sanding
  'sanding tools':               'sanding',
  'sanders & poles':             'sanding',
  'sanders':                     'sanding',
  // Stilts — top-level + all production subcategory leaves
  'stilts':                      'stilts',
  'stilt accessories':           'stilts',
  'extension tubes & clamps':    'stilts',
  'legs & brackets':             'stilts',
  'hardware':                    'stilts',
  'springs & bearings':          'stilts',
  'straps & buckles':            'stilts',
  'soles & floor plates':        'stilts',
  'accessories':                 'stilts',
  // Texture / spray
  'texture sprayers':            'texture',
  'applicators & rollers':       'texture',
  'spray tips & nozzles':        'texture',
  'hoses & fittings':            'texture',
  'cleaning accessories':        'texture',
  // Parts — production catalog canonical leaf
  'parts':                       'parts',
  // Tool sets / cases — Columbia uses both 'Tool Sets' and 'Tool Cases' as leaf names
  'tool cases':                  'taping',
  'tool sets':                   'taping',
  // Generic tool leaf (box fillers, adapters, misc accessories)
  'tools':                       'finishing',
};

/**
 * Convert a WooCommerce category path string like
 *   "Drywall Finishing Tools > Asgard > Finishing Boxes"
 * into our internal category key (e.g. "finishing").
 * Exported so api.js can reuse this for REST API categories.
 */
export function mapCategory(categoriesCell) {
  if (!categoriesCell) return '';
  // Take the first category entry (before any pipe)
  const first = categoriesCell.split('|')[0].trim();
  // Leaf segment is after the last >
  const parts = first.split('>');
  const leaf  = parts[parts.length - 1].trim().toLowerCase();
  return CATEGORY_MAP[leaf] || leaf;
}

/**
 * Extract the brand name from a WooCommerce category path string such as
 *   "Drywall Finishing Tools > TapeTech > Parts & Accessories"
 * The brand is the second segment (index 1) between the ">" separators.
 * Returns an empty string when the path has fewer than two segments.
 *
 * @param {string} categoriesCell  Raw "Categories" CSV cell value
 * @returns {string}
 */
function extractBrandFromCategory(categoriesCell) {
  if (!categoriesCell) return '';
  const first   = categoriesCell.split('|')[0].trim();
  const segments = first.split('>');
  return segments.length >= 2 ? segments[1].trim() : '';
}

/**
 * Return true when the category leaf marks this product as a replacement
 * part rather than a complete tool.
 * Exported so api.js can reuse this for REST API categories.
 *
 * @param {string} categoriesCell
 * @returns {boolean}
 */
export function isPartsRow(categoriesCell) {
  if (!categoriesCell) return false;
  const first = categoriesCell.split('|')[0].trim();
  const leaf  = first.split('>').pop().trim().toLowerCase();
  return leaf === 'parts';
}

// ─── HTML → Markdown converter ───────────────────────────────────────────────

/**
 * Converts the WooCommerce product description HTML into clean Markdown.
 *
 * The CSV descriptions use a small, predictable tag set:
 *   <h2>          → ## heading
 *   <p>           → paragraph (with blank line after)
 *   <ul>/<li>     → - bullet list
 *   <strong>      → **bold**
 *   <br />        → line break within a paragraph
 *   <p>| … |</p>  → GFM pipe-table (already almost valid Markdown —
 *                   just needs the <br /> → newline and <p> stripped)
 *
 * Keeping descriptions as Markdown lets ReactMarkdown + remark-gfm handle
 * all rendering (headings, bold, lists, tables) via Tailwind's prose classes,
 * with no dangerouslySetInnerHTML.
 *
 * @param {string} html  Raw HTML description from the CSV
 * @returns {string}     Clean GitHub-Flavoured Markdown
 */
export function htmlToMarkdown(html) {
  if (!html) return '';

  let md = html;

  // ── 0. Normalise escaped newlines ─────────────────────────────────────────
  // WooCommerce CSV exports embed literal \n (backslash + n) escape sequences
  // inside HTML strings (e.g. between <br /> and the next table row).
  // Convert them to real newlines first so all subsequent regex work correctly.
  md = md.replace(/\\n/g, '\n');

  // ── 1. Headings ────────────────────────────────────────────────────────────
  md = md.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, (_, t) => `# ${t.trim()}\n\n`);
  md = md.replace(/<h2[^>]*>([\s\S]*?)<\/h2>/gi, (_, t) => `## ${t.trim()}\n\n`);
  md = md.replace(/<h3[^>]*>([\s\S]*?)<\/h3>/gi, (_, t) => `### ${t.trim()}\n\n`);

  // ── 2. Inline formatting ───────────────────────────────────────────────────
  md = md.replace(/<strong[^>]*>([\s\S]*?)<\/strong>/gi, (_, t) => `**${t.trim()}**`);
  md = md.replace(/<em[^>]*>([\s\S]*?)<\/em>/gi,         (_, t) => `*${t.trim()}*`);
  md = md.replace(/<code[^>]*>([\s\S]*?)<\/code>/gi,     (_, t) => `\`${t.trim()}\``);

  // ── 3. Line breaks inside paragraphs ──────────────────────────────────────
  // <br /> inside a pipe-table paragraph becomes a newline (handled below).
  // <br /> elsewhere becomes a hard line-break (two trailing spaces).
  md = md.replace(/<br\s*\/?>/gi, '\n');

  // ── 4. List items ─────────────────────────────────────────────────────────
  md = md.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, (_, t) => `- ${t.trim()}\n`);
  md = md.replace(/<\/?ul[^>]*>/gi, '\n');
  md = md.replace(/<\/?ol[^>]*>/gi, '\n');

  // ── 5. Paragraphs ─────────────────────────────────────────────────────────
  // A paragraph whose content looks like a pipe-table becomes a bare table
  // block (no surrounding blank-line paragraphs needed).
  md = md.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, (_, inner) => {
    let text = inner.trim();

    // WooCommerce CSV sometimes stores all table rows on a single line with
    // consecutive pipes as the only row separator, e.g.:
    //   | Specification | Detail | | :--- | :--- | | SKU | TACSET |
    // Only apply when the content is a single line (no existing newlines) AND
    // contains a GFM alignment cell (| :--- |), so we don't accidentally split
    // multi-line tables or paragraphs that happen to contain pipe characters.
    if (
      text.startsWith('|') &&
      text.endsWith('|') &&
      !text.includes('\n') &&
      /\|\s*:?-+:?\s*\|/.test(text)
    ) {
      // At a row boundary the last cell ends with `|` and the next row starts
      // with `|`, giving `| |` (two pipes separated only by whitespace).
      // Within a row, adjacent pipes always have cell content between them.
      text = text.replace(/\|\s*\|/g, '|\n|');
    }

    // Detect pipe-table: every non-empty line starts with |
    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
    const isPipeTable = lines.length >= 2 && lines.every(l => l.startsWith('|'));

    if (isPipeTable) {
      // The CSV already has an alignment row (| :--- | :--- |).
      // Just join the lines and add surrounding blank lines for GFM.
      return '\n\n' + lines.join('\n') + '\n\n';
    }

    return text + '\n\n';
  });

  // ── 6. Anchor tags ────────────────────────────────────────────────────────
  md = md.replace(/<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi,
    (_, href, text) => `[${text.trim()}](${href})`);

  // ── 7. Strip any remaining HTML tags ──────────────────────────────────────
  md = md.replace(/<[^>]+>/g, '');

  // ── 8. Decode common HTML entities ────────────────────────────────────────
  md = md
    .replace(/&amp;/g,  '&')
    .replace(/&lt;/g,   '<')
    .replace(/&gt;/g,   '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ');

  // ── 9. Normalise whitespace ────────────────────────────────────────────────
  // Collapse 3+ consecutive blank lines down to 2.
  md = md.replace(/\n{3,}/g, '\n\n').trim();

  return md;
}

// ─── Spec extraction from HTML pipe-table description ─────────────────────────

/**
 * Split a comma-separated includes string into items, respecting parentheses.
 * Local duplicate of parseIncludesList (productSpecifications.js) to keep this
 * module self-contained and free of circular imports.
 *
 * @param {string} str  e.g. '10" Flat Box, Repair Kits (AH-RK, FFBR9-10)'
 * @returns {string[]}
 */
function splitIncludesList(str) {
  if (!str) return [];
  const items = [];
  let cur = '';
  let depth = 0;
  for (let i = 0; i < str.length; i++) {
    const ch = str[i];
    if (ch === '(')      { depth++; cur += ch; }
    else if (ch === ')') { depth--; cur += ch; }
    else if (ch === ',' && depth === 0) {
      const t = cur.trim();
      if (t) items.push(t);
      cur = '';
    } else {
      cur += ch;
    }
  }
  const t = cur.trim();
  if (t) items.push(t);
  return items;
}

/**
 * Try to extract a single product SKU from an include item string.
 *
 * Handles the common WooCommerce export format where a product's SKU is
 * appended in parentheses: "Hammer Automatic Taper (AT01-AD)"
 * Items with multiple values in parens (e.g. "Repair Kits (AH-RK, CTR-1)")
 * are left as-is — no sku is extracted.
 *
 * @param {string} item  Raw include item, e.g. 'Loading Pump (LP01-AD)'
 * @returns {{ name: string, sku?: string }}
 */
const INCLUDE_SKU_RE = /^(.*?)\s*\(([A-Z0-9][A-Z0-9-]+)\)\s*$/;
function parseIncludeItem(item) {
  const m = item.match(INCLUDE_SKU_RE);
  if (m) return { name: m[1].trim(), sku: m[2] };
  return { name: item };
}

/**
 * Scan an HTML product description for a pipe-table <p> that looks like a
 * specifications table, extract the rows as meta_data entries, and return the
 * HTML with that block (and its preceding "Specifications:" heading) removed
 * so the description doesn't render specs twice.
 *
 * WooCommerce CSV descriptions embed specs as a single-line pipe table inside
 * a <p> element, e.g.:
 *   <p>| Specification | Detail | | :--- | :--- | | SKU | TACSET | ...</p>
 *
 * The extracted data is stored as:
 *   _specs_N_label / _specs_N_value  — one pair per spec row
 *   _includes_N_name / _includes_N_sku — one entry per included item
 *
 * @param {string} html  Raw HTML description from the CSV
 * @returns {{ specsMeta: Array<{key:string,value:string}>, strippedHtml: string }}
 */
function extractSpecsFromHtml(html) {
  if (!html) return { specsMeta: [], strippedHtml: html };

  const specsMeta = [];
  let strippedHtml = html;

  // Use matchAll to safely iterate over all <p> matches without exec() state concerns
  for (const match of html.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)) {
    const inner = match[1].trim();

    // Must be a single-line pipe table with an alignment row
    if (
      !inner.startsWith('|') ||
      !inner.endsWith('|') ||
      inner.includes('\n') ||
      !/\|\s*:?-+:?\s*\|/.test(inner)
    ) continue;

    // Split into rows (same approach as htmlToMarkdown)
    const rows = inner.replace(/\|\s*\|/g, '|\n|').split('\n').map(r => r.trim()).filter(Boolean);

    // Need header + alignment + at least one data row; header must look like a spec table
    if (rows.length < 3 || !/specification|sku|brand/i.test(rows[0])) continue;

    // Parse data rows (skip header [0] and alignment [1])
    const specs = [];
    for (let i = 2; i < rows.length; i++) {
      // Split on | — first and last tokens are empty (table edges)
      const cells = rows[i].split('|').map(c => c.trim());
      if (cells.length >= 3 && cells[1]) {
        specs.push({ label: cells[1], value: cells[2] || '' });
      }
    }

    if (specs.length === 0) continue;

    // Build _specs_ meta_data entries
    specs.forEach((spec, i) => {
      specsMeta.push({ key: `_specs_${i}_label`, value: spec.label });
      specsMeta.push({ key: `_specs_${i}_value`, value: spec.value });
    });

    // Build _includes_ meta_data entries for the "Includes" / "Set Includes" row
    const includesSpec = specs.find(s => /^(set\s+)?includes?$/i.test(s.label.trim()));
    if (includesSpec) {
      const includeItems = splitIncludesList(includesSpec.value);
      includeItems.forEach((item, i) => {
        const parsed = parseIncludeItem(item);
        specsMeta.push({ key: `_includes_${i}_name`, value: parsed.name });
        if (parsed.sku) {
          specsMeta.push({ key: `_includes_${i}_sku`, value: parsed.sku });
        }
      });
    }

    // Strip the specs table <p> and its preceding "Specifications:" heading
    strippedHtml = strippedHtml.replace(match[0], '');
    strippedHtml = strippedHtml.replace(
      /<p[^>]*>\s*<(?:strong|b)[^>]*>Specifications?:?<\/(?:strong|b)>\s*<\/p>\s*/gi,
      ''
    );
    break; // Only process the first matching specs table
  }

  return { specsMeta, strippedHtml };
}

// ─── Normalizer ───────────────────────────────────────────────────────────────

/**
 * Convert one raw CSV row object into the internal product shape.
 *
 * @param {Object} row  Plain object produced by parseCsvText()
 * @param {number} idx  Row index (used as fallback ID)
 * @returns {Object}    Normalized product
 */

import { PLACEHOLDER_IMAGE } from '../constants/images.js';
import {
  buildSpecificationsFromCsvRow,
  buildSpecsMetaFromRows,
  mergeSpecMeta,
} from './csvSpecificationMapping.js';
import { buildIncludesMetaFromContent } from './includesExtraction.js';

function hasStructuredIncludes(metaItems = []) {
  return metaItems.some(({ key }) => /^_includes_\d+_(name|sku)$/.test(String(key || '')));
}

function collectCsvMetaData(row) {
  return Object.entries(row)
    .map(([column, value]) => {
      if (!column.startsWith('Meta: ')) return null;
      const key = column.replace(/^Meta:\s*/, '').trim();
      const normalizedValue = String(value || '').trim();
      if (!key || !normalizedValue) return null;
      return { key, value: normalizedValue };
    })
    .filter(Boolean);
}

function normalizeRow(row, idx, attrIndexes = []) {
  // Images: pipe-separated URLs. CSV columns may contain "Images" or "Images (comma separated)"
  const NO_IMAGE = PLACEHOLDER_IMAGE;

  const rawImages = row['Images'] || row['Images (comma separated)'] || '';
  const images = rawImages
    .split('|')
    .map(u => u.trim())
    // Strip known third-party placeholder SVGs (e.g. BigCommerce ProductDefault.svg)
    .filter(u => u && !u.includes('ProductDefault.svg'))
    .map(u => u || NO_IMAGE);
  if (images.length === 0) images.push(NO_IMAGE);

  // Brand: prefer the WooCommerce "Brands" taxonomy column (most authoritative,
  // e.g. "Platinum Drywall Tools"), then fall back to "Attribute 1 value(s)"
  // when Attribute 1 name == "Brand", and finally extract from the category
  // path (e.g. "TapeTech" from
  // "Drywall Finishing Tools > TapeTech > Parts & Accessories").
  // This ensures every product carries its brand even when the CSV rows for
  // parts / repair kits omit the Brand attribute column entirely.
  const brandCol  = (row['Brands']               || '').trim();
  const attrName  = (row['Attribute 1 name']     || '').trim();
  const attrValue = (row['Attribute 1 value(s)'] || '').trim();
  const attrBrand = attrName.toLowerCase() === 'brand' ? attrValue : '';
  const brand     = brandCol || attrBrand || extractBrandFromCategory(row['Categories'] || '');

  // Price — prefer Sale price, then Regular price
  const salePrice    = parseFloat(row['Sale price'])    || 0;
  const regularPrice = parseFloat(row['Regular price']) || 0;
  const price = salePrice || regularPrice;

  // UPC lives in the last column: "Meta: upc"
  const upc = (row['Meta: upc'] || '').trim();

  // SKU is our canonical ID
  const sku = (row['SKU'] || '').trim();

  // Category + parts flag
  const categoriesCell = row['Categories'] || '';
  const category = mapCategory(categoriesCell);
  const is_parts = isPartsRow(categoriesCell);

  // Human-readable leaf category name for category-card grouping (e.g. "Finishing Boxes").
  // This mirrors the display_category field produced by normalizeProduct() in api.js.
  const categoryLeaf = (() => {
    if (!categoriesCell) return '';
    const first = categoriesCell.split('|')[0].trim();
    const parts = first.split('>');
    return parts.length >= 2 ? parts[parts.length - 1].trim() : '';
  })();

  // Tags as array
  const tags = (row['Tags'] || '').split(',').map(t => t.trim()).filter(Boolean);

  // ── Variable-product support ───────────────────────────────────────────────
  // The CSV 'Type' column is 'simple' | 'variable' | 'variation'.
  // - 'variable'  → parent product; attributes list all available option values
  // - 'variation' → child product; its selected variation attributes are on Attribute N columns
  const productType = (row['Type'] || 'simple').trim().toLowerCase();
  const isVariable  = productType === 'variable';

  // For variable products, parse variation attributes from the Attribute columns.
  // Any Attribute N with "used for variations" = 1 is treated as a variation driver.
  const variation_attributes = (() => {
    if (!isVariable) return [];
    const result = [];
    for (const n of attrIndexes) {
      const name  = (row[`Attribute ${n} name`]      || '').trim();
      const vals  = (row[`Attribute ${n} value(s)`]  || '').trim();
      const usedForVar = (row[`Attribute ${n} used for variations`] || '0').trim();
      if (!name || !vals || usedForVar !== '1') continue;
      if (name.toLowerCase() === 'brand') continue;
      const options = vals.split('|').map(v => v.trim()).filter(Boolean);
      if (options.length > 0) result.push({ id: n, name, options });
    }
    return result;
  })();

  // For variation rows: capture the parent SKU and the specific attribute value
  // that identifies this variation.
  const parent_id = productType === 'variation' ? (row['Parent'] || '').trim() : null;
  const variation_attribute_values = (() => {
    if (productType !== 'variation') return null;
    const result = [];
    for (const n of attrIndexes) {
      const name = (row[`Attribute ${n} name`]     || '').trim();
      const val  = (row[`Attribute ${n} value(s)`] || '').trim();
      const usedForVar = (row[`Attribute ${n} used for variations`] || '0').trim();
      if (name && val && usedForVar === '1') result.push({ name, option: val });
    }
    return result.length > 0 ? result : null;
  })();
  const variation_attribute = (() => {
    if (!variation_attribute_values || variation_attribute_values.length === 0) return null;
    return variation_attribute_values[0];
  })();

  // Price range for variable products: collect from pipe-separated prices if ever
  // present; otherwise the catalog CSV doesn't carry variation prices on the parent,
  // so min/max will be null and the UI shows no "From $X" label.
  const min_price = isVariable ? null : null;
  const max_price = isVariable ? null : null;

  // Extract structured specs from HTML description before converting to Markdown.
  // This populates meta_data with _specs_N_label/value and _includes_N_name/sku
  // entries so the TechnicalSpecifications component can render them with proper
  // per-item formatting. The specs <p> block is stripped from the HTML so it
  // doesn't appear twice (once in the description and once in the specs table).
  const rawDescription = row['Description'] || '';
  const { specsMeta: htmlSpecsMeta, strippedHtml } = extractSpecsFromHtml(rawDescription);
  const csvSpecRows = buildSpecificationsFromCsvRow(row, attrIndexes);
  const csvSpecsMeta = buildSpecsMetaFromRows(csvSpecRows);
  const inferredIncludes = buildIncludesMetaFromContent(rawDescription, { sku });
  const explicitMeta = collectCsvMetaData(row);
  const explicitCanonicalSpecMeta = explicitMeta.filter(({ key }) =>
    key === '_dtb_specs_json' ||
    key === 'dtb_specs_json'
  );
  const explicitIndexedSpecMeta = explicitMeta.filter(({ key }) =>
    /^_specs_\d+_(label|value)$/.test(String(key || ''))
  );
  const explicitIncludesMeta = explicitMeta.filter(({ key }) =>
    /^_includes_\d+_(name|sku)$/.test(String(key || ''))
  );
  const explicitOtherMeta = explicitMeta.filter(({ key }) =>
    key !== '_dtb_specs_json' &&
    key !== 'dtb_specs_json' &&
    !/^_specs_\d+_(label|value)$/.test(String(key || '')) &&
    !/^_includes_\d+_(name|sku)$/.test(String(key || ''))
  );
  const specsMeta = mergeSpecMeta(
    mergeSpecMeta(
      mergeSpecMeta(csvSpecsMeta, explicitIndexedSpecMeta),
      htmlSpecsMeta
    ),
    inferredIncludes.specsMeta
  );

  // Build meta_data: UPC first (if present), explicit CSV meta, then extracted spec entries.
  const meta_data = [];
  if (upc) meta_data.push({ key: 'upc', value: upc });
  meta_data.push(...explicitOtherMeta);
  meta_data.push(...explicitCanonicalSpecMeta);
  meta_data.push(...specsMeta);
  if (hasStructuredIncludes(explicitIncludesMeta)) {
    meta_data.push(...explicitIncludesMeta);
  } else if (hasStructuredIncludes(htmlSpecsMeta)) {
    meta_data.push(...htmlSpecsMeta.filter(({ key }) => /^_includes_\d+_(name|sku)$/.test(String(key || ''))));
  } else {
    meta_data.push(...inferredIncludes.includesMeta);
  }

  return {
    // Identity — use SKU; fall back to row index so IDs are always defined
    id:          sku || `csv-${idx}`,
    part_number: sku || `csv-${idx}`,
    sku,
    upc,
    slug:        sku.toLowerCase().replace(/[^a-z0-9]+/g, '-'),

    // Display
    name:   (row['Name']  || sku || `Product ${idx}`).replace(/^"(.*)"$/, '$1'),
    brand,
    category,
    display_category: categoryLeaf,
    is_parts,
    categories: [{ name: categoriesCell.split('>').pop().trim() }],
    tags,

    // Media
    image:  images[0],
    images,

    // Pricing & inventory
    price,
    regular_price: regularPrice,
    sale_price:    salePrice || '',
    on_sale:       salePrice > 0 && salePrice < regularPrice,
    stock_status:  row['In stock?'] === '1' ? 'instock' : 'outofstock',
    manage_stock:  false,
    stock_quantity: row['Stock'] ? (parseInt(row['Stock'], 10) || null) : null,

    // Descriptions — convert HTML → Markdown for ReactMarkdown rendering.
    // strippedHtml has the specs table removed to avoid rendering it twice.
    short_description: (row['Short description'] || '').replace(/<[^>]+>/g, '').slice(0, 200),
    description_full:  htmlToMarkdown(strippedHtml),

    // Attributes / meta preserved for schematic lookups
    // Keep Brand once, then append selected variation attributes.
    attributes: [
      ...(brand ? [{ name: 'Brand', options: [brand] }] : []),
      ...((variation_attribute_values || [])
        .filter((a) => a.name.toLowerCase() !== 'brand')
        .map((a) => ({ name: a.name, option: a.option }))),
    ],
    meta_data,

    // Variable-product fields (mirrors normalizeProduct() in api.js)
    type:                productType,
    is_variable:         isVariable,
    variation_attributes,
    variations:          [],   // variation IDs not available in CSV (fetched via API)
    min_price,
    max_price,
    parent_id,
    variation_attribute,
    variation_attribute_values,

    // Ratings — CSV does not carry ratings
    rating:  0,
    reviews: 0,

    // Mark source so components can show badge differences if desired
    _source: 'csv',
  };
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Parse raw CSV text into an array of normalized product objects.
 *
 * Variation rows (Type=variation) are excluded from the output because they are
 * child products of a variable parent and should not appear as stand-alone items
 * in any product listing — exactly mirroring the WooCommerce REST API behaviour
 * where GET /products returns only parent products (not variations).
 *
 * @param {string} csvText
 * @returns {Object[]}
 */
export function parseProductCsv(csvText) {
  const rows = parseCsvText(csvText);
  if (!rows.length) return [];
  const attrIndexes = Object.keys(rows[0] || {})
    .map((key) => {
      const m = key.match(/^Attribute\s+(\d+)\s+name$/);
      return m ? Number(m[1]) : null;
    })
    .filter((n) => Number.isInteger(n))
    .sort((a, b) => a - b);
  return rows
    .filter(r => r['SKU'] || r['Name']) // skip blank rows
    .filter(r => (r['Type'] || '').trim().toLowerCase() !== 'variation') // skip variation children
    .map((r, i) => normalizeRow(r, i, attrIndexes));
}

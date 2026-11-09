/**
 * includesExtraction.js
 *
 * Extract "Set Includes" / "Kit Includes" style content from catalog product
 * descriptions and convert it into structured metadata.
 */

import { getIncludesOverride } from './includesOverrides.js';

const BRAND_SPLIT_RE = /\b(?:TapeTech|Asgard|Columbia|Level\s*5|SurPro|Platinum|Dura-Stilts)\b/g;

function clean(value) {
  return String(value ?? '').trim();
}

function decodeHtmlEntities(str) {
  return clean(str)
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

export function parseIncludesList(str) {
  if (!str || typeof str !== 'string') return [];

  const items = [];
  let current = '';
  let depth = 0;

  for (let i = 0; i < str.length; i++) {
    const ch = str[i];
    if (ch === '(') {
      depth++;
      current += ch;
    } else if (ch === ')') {
      depth--;
      current += ch;
    } else if (ch === ',' && depth === 0) {
      const trimmed = current.trim();
      if (trimmed) items.push(trimmed);
      current = '';
    } else {
      current += ch;
    }
  }

  const trimmed = current.trim();
  if (trimmed) items.push(trimmed);

  return items;
}

export function parseIncludeItem(item) {
  const raw = clean(item);
  if (!raw) return null;

  const parenSku = raw.match(/^(.*?)\s*\(([A-Z0-9][A-Z0-9-]+)\)\s*$/);
  if (parenSku) {
    return { name: clean(parenSku[1]), sku: clean(parenSku[2]) };
  }

  const prefixSku = raw.match(/^([A-Z0-9-]{3,})\s*-\s*(.+)$/);
  if (prefixSku) {
    return { name: clean(prefixSku[2]), sku: clean(prefixSku[1]) };
  }

  const suffixSku = raw.match(/^(.*?)\s+([A-Z0-9-]{3,})$/);
  if (suffixSku && /\d/.test(suffixSku[2])) {
    return { name: clean(suffixSku[1]), sku: clean(suffixSku[2]) };
  }

  return { name: raw };
}

function htmlToPlainText(html) {
  return decodeHtmlEntities(
    clean(html)
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/li>/gi, '\n')
      .replace(/<\/p>/gi, '\n')
      .replace(/<\/h\d>/gi, '\n')
      .replace(/<[^>]+>/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{2,}/g, '\n')
      .replace(/[ \t]{2,}/g, ' ')
  );
}

function splitRepeatedBrandItems(text) {
  const matches = [...text.matchAll(BRAND_SPLIT_RE)];
  if (matches.length < 2) return [text];

  const items = [];
  for (let i = 0; i < matches.length; i++) {
    const start = matches[i].index;
    const end = i + 1 < matches.length ? matches[i + 1].index : text.length;
    const item = clean(text.slice(start, end));
    if (item) items.push(item);
  }
  return items;
}

function splitNumberedItems(text) {
  const normalized = ` ${clean(text)}`;
  const parts = normalized.split(/\s+(?=\d+\s*-\s*)/).map((part) => clean(part));
  return parts
    .map((part) => part.replace(/^\d+\s*-\s*/, '').trim())
    .filter(Boolean);
}

function normalizeIncludesItems(candidate) {
  const raw = clean(candidate);
  if (!raw) return [];

  let items;
  if (raw.includes(',')) {
    items = parseIncludesList(raw);
  } else if (/\b\d+\s*-\s*/.test(raw)) {
    items = splitNumberedItems(raw);
  } else if ([...raw.matchAll(BRAND_SPLIT_RE)].length > 1) {
    items = splitRepeatedBrandItems(raw);
  } else if (raw.includes(';')) {
    items = raw.split(';').map((part) => clean(part)).filter(Boolean);
  } else {
    items = [raw];
  }

  return items
    .map(parseIncludeItem)
    .filter((item) => item?.name);
}

function findIncludesCandidate(content) {
  const html = clean(content);
  if (!html) return '';

  const htmlPatterns = [
    /<(?:li|p)[^>]*>[\s\S]*?(?:set|kit)\s+includes?\s*:\s*([\s\S]*?)<\/(?:li|p)>/i,
    /<(?:li|p)[^>]*>[\s\S]*?includes?\s*:\s*([\s\S]*?)<\/(?:li|p)>/i,
  ];

  for (const pattern of htmlPatterns) {
    const match = html.match(pattern);
    if (match?.[1]) return clean(match[1]);
  }

  const text = htmlToPlainText(html);
  const lineMatch = text.match(/(?:set|kit)\s+includes?\s*:\s*(.+)$/im)
    || text.match(/includes?\s*:\s*(.+)$/im);

  return clean(lineMatch?.[1] || '');
}

export function extractIncludesFromContent(content) {
  const candidate = findIncludesCandidate(content);
  if (!candidate) return null;

  const items = normalizeIncludesItems(candidate);
  if (items.length === 0) return null;

  return {
    label: 'Set Includes',
    rawValue: candidate,
    items,
  };
}

function buildIncludesMeta(extracted) {
  if (!extracted) return { specsMeta: [], includesMeta: [] };

  const specsMeta = [
    { key: '_specs_9998_label', value: extracted.label },
    { key: '_specs_9998_value', value: extracted.rawValue },
  ];

  const includesMeta = extracted.items.flatMap((item, index) => {
    const entries = [{ key: `_includes_${index}_name`, value: item.name }];
    if (item.sku) entries.push({ key: `_includes_${index}_sku`, value: item.sku });
    return entries;
  });

  return { specsMeta, includesMeta };
}

export function buildIncludesMetaFromContent(content, options = {}) {
  const override = getIncludesOverride(options?.sku);
  if (override) {
    return buildIncludesMeta(override);
  }

  const extracted = extractIncludesFromContent(content);
  if (!extracted) return { specsMeta: [], includesMeta: [] };
  return buildIncludesMeta(extracted);
}

import { useState, useCallback, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import {
  Sparkles,
  Upload,
  FileText,
  Copy,
  Check,
  Download,
  Trash2,
  ShieldCheck,
  Search,
  Zap,
  Loader2,
  X,
  Settings,
  RefreshCw,
  AlertTriangle,
  ExternalLink
} from 'lucide-react';
import { useDropzone } from 'react-dropzone';
import Papa from 'papaparse';
import localforage from 'localforage';
import { marked } from 'marked';
import { cn } from './lib/utils';
import {
  buildItemGenerationInput,
  enhanceDescription,
  formatErrorMessage,
  isBulkGenerationTarget,
  isGenerationComplete,
  mergeEnhancedResult,
  processBulkDescriptions,
  type EnhancedDescription,
  type EnhanceOptions
} from './services/aiService';
import { ProductDescriptionPreview } from './components/ProductDescriptionPreview';

type Tab = 'single' | 'bulk';

const STORAGE_KEYS = {
  items: 'optiwrite_bulk_items',
  results: 'optiwrite_bulk_results',
  progress: 'optiwrite_bulk_progress'
} as const;

const DEFAULT_SEO_KEYS = {
  title: 'Meta: _dtb_seo_title',
  description: 'Meta: _dtb_seo_description',
  focus: 'Meta: _dtb_seo_focus_kw'
} as const;

function cleanKey(key: string): string {
  return key.replace(/^\uFEFF/, '').trim();
}

function sanitizeObjectKeys(obj: any): any {
  if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return obj;
  const result: Record<string, any> = {};
  for (const key of Object.keys(obj)) result[cleanKey(key)] = obj[key];
  return result;
}

function getValue(obj: any, ...aliases: string[]): any {
  if (!obj) return undefined;
  const keys = Object.keys(obj);
  for (const alias of aliases) {
    const found = keys.find(key => key.toLowerCase() === alias.toLowerCase());
    if (found) return obj[found];
  }
  return undefined;
}

function sameProduct(left: any, right: any): boolean {
  if (!left || !right) return false;
  if (left === right) return true;
  const leftSku = String(getValue(left, 'SKU', 'sku', 'MPN', 'mpn', 'id', 'ID') || '').trim().toLowerCase();
  const rightSku = String(getValue(right, 'SKU', 'sku', 'MPN', 'mpn', 'id', 'ID') || '').trim().toLowerCase();
  if (leftSku && rightSku && leftSku === rightSku) return true;
  const leftName = String(getValue(left, 'Name', 'name', 'Title', 'title') || '').trim().toLowerCase();
  const rightName = String(getValue(right, 'Name', 'name', 'Title', 'title') || '').trim().toLowerCase();
  if (leftName && rightName && leftName === rightName) return true;
  return false;
}

function isSuccessfulGenerationResult(result: any): boolean {
  return Boolean(result && !result.error && !result._isSkipped);
}

function findExistingKey(obj: Record<string, any>, aliases: string[]): string | undefined {
  return Object.keys(obj).find(key => aliases.some(alias => key.toLowerCase() === alias.toLowerCase()));
}

function setKnownSeoField(mapped: Record<string, any>, aliases: string[], defaultKey: string, value: string): void {
  const existing = findExistingKey(mapped, aliases);
  mapped[existing || defaultKey] = value;
}

function setExistingOnly(mapped: Record<string, any>, aliases: string[], value: string): void {
  const existing = findExistingKey(mapped, aliases);
  if (existing) mapped[existing] = value;
}

function formatToCleanHtml(rawText: string, isShort = false): string {
  if (!rawText) return '';
  let value = String(rawText).trim();
  const hasHtmlTags = /<[a-z][\s\S]*>/i.test(value);
  if (!hasHtmlTags) value = marked.parse(value, { gfm: true }) as string;
  value = value
    .replace(/\r\n/g, ' ')
    .replace(/\n/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/>\s+</g, '><')
    .trim();
  if (isShort) value = value.replace(/^<p>/i, '').replace(/<\/p>$/i, '').trim();
  return value;
}

export default function App() {
  const [activeTab, setActiveTab] = useState<Tab>('single');
  const [modelTier, setModelTier] = useState<'flash' | 'pro'>('flash');
  const [generateBrand, setGenerateBrand] = useState(false);
  const [generateName, setGenerateName] = useState(false);
  const [generateFullDesc, setGenerateFullDesc] = useState(true);
  const [generateShortDesc, setGenerateShortDesc] = useState(true);
  const [generateSeo, setGenerateSeo] = useState(true);
  const [enableWebSearch, setEnableWebSearch] = useState(false);
  const [enableRepairPass, setEnableRepairPass] = useState(true);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState({ current: 0, total: 0 });
  const [result, setResult] = useState<EnhancedDescription | null>(null);
  const [bulkItems, setBulkItems] = useState<any[]>([]);
  const [bulkResults, setBulkResults] = useState<any[]>([]);
  const [selectedBulkItem, setSelectedBulkItem] = useState<any | null>(null);
  const [copied, setCopied] = useState(false);
  const [abortController, setAbortController] = useState<AbortController | null>(null);
  const [competitorContext, setCompetitorContext] = useState('');
  const [singleError, setSingleError] = useState<string | null>(null);

  const generationOptions = useCallback((overrides: Partial<EnhanceOptions> = {}): EnhanceOptions => ({
    modelTier,
    generateBrand,
    generateName,
    generateFullDesc,
    generateShortDesc,
    generateSeo,
    enableWebSearch,
    enableRepairPass,
    competitorContext: competitorContext.trim() || undefined,
    inventory: bulkItems,
    storefrontProductPathPrefix: '/products',
    ...overrides
  }), [
    modelTier,
    generateBrand,
    generateName,
    generateFullDesc,
    generateShortDesc,
    generateSeo,
    enableWebSearch,
    enableRepairPass,
    competitorContext,
    bulkItems
  ]);

  const hasGenerationSelection = generateBrand || generateName || generateFullDesc || generateShortDesc || generateSeo;

  useEffect(() => {
    const loadData = async () => {
      try {
        const [savedItems, savedResults, savedProgress] = await Promise.all([
          localforage.getItem<any[]>(STORAGE_KEYS.items),
          localforage.getItem<any[]>(STORAGE_KEYS.results),
          localforage.getItem<{ current: number; total: number }>(STORAGE_KEYS.progress)
        ]);
        if (savedItems) setBulkItems(savedItems);
        if (savedResults) setBulkResults(savedResults);
        if (savedProgress) setProgress(savedProgress);
      } catch (error) {
        console.error('Failed to load persisted OptiWrite state', error);
      }
    };
    void loadData();
  }, []);

  useEffect(() => {
    const save = async () => {
      try {
        if (bulkItems.length) await localforage.setItem(STORAGE_KEYS.items, bulkItems);
        else await localforage.removeItem(STORAGE_KEYS.items);
      } catch (error) {
        console.error('Failed to persist bulk items', error);
      }
    };
    void save();
  }, [bulkItems]);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try {
        if (bulkResults.length) await localforage.setItem(STORAGE_KEYS.results, bulkResults);
        else await localforage.removeItem(STORAGE_KEYS.results);
      } catch (error) {
        console.error('Failed to persist bulk results', error);
      }
    }, 1200);
    return () => window.clearTimeout(timer);
  }, [bulkResults]);

  useEffect(() => {
    const timer = window.setTimeout(async () => {
      try {
        if (progress.total > 0) await localforage.setItem(STORAGE_KEYS.progress, progress);
        else await localforage.removeItem(STORAGE_KEYS.progress);
      } catch (error) {
        console.error('Failed to persist bulk progress', error);
      }
    }, 1200);
    return () => window.clearTimeout(timer);
  }, [progress]);

  const handleEnhance = async () => {
    if (!input.trim() || !hasGenerationSelection) {
      if (!hasGenerationSelection) alert('Please select at least one generation option.');
      return;
    }

    const controller = new AbortController();
    setAbortController(controller);
    setLoading(true);
    setResult(null);
    setSingleError(null);
    try {
      const enhanced = await enhanceDescription(input, generationOptions({ abortSignal: controller.signal }));
      setResult(enhanced);
    } catch (error: any) {
      if (error?.name !== 'AbortError') {
        console.error(error);
        const friendlyMessage = formatErrorMessage(error);
        setSingleError(friendlyMessage);
      }
    } finally {
      setLoading(false);
      setAbortController(null);
    }
  };

  const onDrop = useCallback((acceptedFiles: File[]) => {
    const file = acceptedFiles[0];
    if (!file) return;

    const applyRows = (rows: any[]) => {
      const sanitized = rows.map(sanitizeObjectKeys).filter(Boolean);
      setBulkItems(sanitized);
      setBulkResults([]);
      setProgress({ current: 0, total: sanitized.filter(isBulkGenerationTarget).length });
      setSelectedBulkItem(null);
    };

    if (file.type === 'application/json' || file.name.toLowerCase().endsWith('.json')) {
      const reader = new FileReader();
      reader.onload = event => {
        try {
          let json = JSON.parse(String(event.target?.result || ''));
          if (!Array.isArray(json) && json && typeof json === 'object') {
            const arrayKey = Object.keys(json).find(key => Array.isArray(json[key]));
            json = arrayKey ? json[arrayKey] : [json];
          }
          if (!Array.isArray(json)) throw new Error('Expected an array/object payload.');
          applyRows(json);
        } catch (error) {
          console.error(error);
          alert('Invalid JSON file.');
        }
      };
      reader.readAsText(file);
      return;
    }

    Papa.parse(file, {
      header: true,
      skipEmptyLines: true,
      complete: results => applyRows(results.data as any[]),
      error: error => {
        console.error(error);
        alert('Error parsing CSV.');
      }
    });
  }, []);

  const handleRetrySingleItem = async (index: number) => {
    const item = bulkItems[index];
    if (!item || !isBulkGenerationTarget(item)) return;

    const controller = new AbortController();
    const optimistic = [...bulkResults];
    optimistic[index] = { ...item, _isRetrying: true };
    setBulkResults(optimistic);

    try {
      const inputText = buildItemGenerationInput(bulkItems, bulkResults, index);
      const competitorKey = Object.keys(item).find(key => key.toLowerCase().includes('competitor'));
      const enhanced = await enhanceDescription(
        inputText,
        generationOptions({
          abortSignal: controller.signal,
          competitorContext: (competitorKey ? String(item[competitorKey] || '').trim() : '') || competitorContext.trim() || undefined,
          inventory: bulkItems
        })
      );

      const finalItem = mergeEnhancedResult(item, enhanced, generationOptions());
      delete finalItem.error;
      delete finalItem._isRetrying;
      delete finalItem._isProcessing;

      setBulkResults(previous => {
        const next = [...previous];
        next[index] = finalItem;
        return next;
      });
      setSelectedBulkItem(previous => sameProduct(previous, item) ? finalItem : previous);
    } catch (error: any) {
      console.error(`Retry failed for item ${index + 1}`, error);
      const friendlyMessage = formatErrorMessage(error);
      const failed = { ...item, error: friendlyMessage, audit: error?.audit };
      setBulkResults(previous => {
        const next = [...previous];
        next[index] = failed;
        return next;
      });
      setSelectedBulkItem(previous => sameProduct(previous, item) ? failed : previous);
    }
  };

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: {
      'application/json': ['.json'],
      'text/csv': ['.csv']
    },
    multiple: false
  });

  const handleBulkProcess = async () => {
    if (!bulkItems.length) return;
    if (!hasGenerationSelection) {
      alert('Please select at least one generation option.');
      return;
    }

    const targetIndices = bulkItems.flatMap((item, index) => isBulkGenerationTarget(item) ? [index] : []);
    if (!targetIndices.length) {
      alert('No published or draft products were found. Rows with private or unsupported status are excluded.');
      return;
    }
    if (!window.confirm(
      `Generate content for ${targetIndices.length.toLocaleString()} published or draft rows? ` +
      `This uses Gemini quota. Transient retries and automatic validation repair can make additional requests. ` +
      `Other rows will stay unchanged and remain in the exported catalog.`
    )) return;

    const controller = new AbortController();
    setAbortController(controller);
    setLoading(true);

    const opts = generationOptions({ abortSignal: controller.signal, inventory: bulkItems });
    const targetSet = new Set(targetIndices);
    const completedBeforeStart = bulkResults.reduce((count, row, index) =>
      count + (targetSet.has(index) && (isGenerationComplete(row, opts) || row?.error) ? 1 : 0), 0);
    setProgress({ current: completedBeforeStart, total: targetIndices.length });

    let lastUiUpdate = 0;
    try {
      const processed = await processBulkDescriptions(
        bulkItems,
        opts,
        (current, total, results) => {
          if (controller.signal.aborted) return;
          const now = Date.now();
          if (now - lastUiUpdate >= 350 || current === total) {
            setProgress({ current, total });
            setBulkResults(results);
            lastUiUpdate = now;
          }
        },
        bulkResults,
        controller.signal,
        targetIndices
      );
      setBulkResults(processed);
      const completed = processed.reduce((count, row, index) =>
        count + (targetSet.has(index) && (isGenerationComplete(row, opts) || row?.error) ? 1 : 0), 0);
      setProgress({ current: completed, total: targetIndices.length });
    } catch (error: any) {
      if (error?.name !== 'AbortError') {
        console.error(error);
        const friendlyMessage = formatErrorMessage(error);
        alert(friendlyMessage);
      }
    } finally {
      setLoading(false);
      setAbortController(null);
    }
  };

  const handleStopProcessing = () => abortController?.abort();

  const handleClearAll = async () => {
    if (!window.confirm('Clear all uploaded items, generated results and progress from this browser?')) return;
    abortController?.abort();
    setBulkItems([]);
    setBulkResults([]);
    setProgress({ current: 0, total: 0 });
    setSelectedBulkItem(null);
    try {
      await Promise.all(Object.values(STORAGE_KEYS).map(key => localforage.removeItem(key)));
    } catch (error) {
      console.error('Failed to clear local persistence', error);
    }
  };

  const handleExport = () => {
    if (!bulkItems.length) {
      alert('No items to export.');
      return;
    }

    const hasAnyProcessed = bulkResults.some(isSuccessfulGenerationResult);
    if (!hasAnyProcessed) {
      alert('No successful processed data to export.');
      return;
    }

    // Helper functions to inspect item identity and relationships
    const getItemSku = (item: any): string =>
      String(getValue(item, 'SKU', 'sku', 'ID', 'id') || '').trim().toLowerCase();

    const getItemId = (item: any): string =>
      String(getValue(item, 'ID', 'id') || '').trim().toLowerCase();

    const getItemParentRef = (item: any): string =>
      String(
        getValue(
          item,
          'Parent',
          'parent',
          'Parent SKU',
          'parent_sku',
          'Parent_SKU',
          'Meta: _dtb_parent_product_sku',
          'meta:_dtb_parent_product_sku',
          'Parent ID',
          'parent_id'
        ) || ''
      ).trim().toLowerCase();

    const getItemType = (item: any): string =>
      String(getValue(item, 'Type', 'type', 'Product Type', 'product_type') || '').trim().toLowerCase();

    // Map parent identifiers to child variation indices
    const childrenByParent = new Map<string, number[]>();
    const childIndices = new Set<number>();

    bulkItems.forEach((item, index) => {
      const sku = getItemSku(item);
      const id = getItemId(item);
      const parentRef = getItemParentRef(item);
      const type = getItemType(item);

      const isChild =
        type === 'variation' ||
        type === 'variation_child' ||
        (Boolean(parentRef) && parentRef !== sku && parentRef !== id);

      if (isChild && parentRef) {
        childIndices.add(index);
        if (!childrenByParent.has(parentRef)) {
          childrenByParent.set(parentRef, []);
        }
        childrenByParent.get(parentRef)!.push(index);
      }
    });

    // Detect and group variation products immediately under their parent SKUs
    const orderedIndices: number[] = [];
    const visited = new Set<number>();

    const addIndex = (idx: number) => {
      if (idx >= 0 && idx < bulkItems.length && !visited.has(idx)) {
        visited.add(idx);
        orderedIndices.push(idx);
      }
    };

    bulkItems.forEach((item, index) => {
      if (visited.has(index)) return;

      const sku = getItemSku(item);
      const id = getItemId(item);
      const type = getItemType(item);
      const parentRef = getItemParentRef(item);
      const isExplicitChild = childIndices.has(index);

      // Collect any matching child variation indices for this item
      const matchingChildren: number[] = [];
      if (sku && childrenByParent.has(sku)) {
        matchingChildren.push(...(childrenByParent.get(sku) || []));
      }
      if (id && id !== sku && childrenByParent.has(id)) {
        matchingChildren.push(...(childrenByParent.get(id) || []));
      }

      const isParent =
        type === 'variable' ||
        type === 'variable_parent' ||
        matchingChildren.length > 0;

      if (isParent) {
        // Place the parent SKU first
        addIndex(index);
        // Place all its variation children directly under it
        for (const childIdx of matchingChildren) {
          addIndex(childIdx);
        }
      } else if (!isExplicitChild) {
        // Standalone simple product
        addIndex(index);
      } else {
        // Child variation encountered before its parent (or parent elsewhere in catalog)
        const parentIndex = bulkItems.findIndex(candidate => {
          const cSku = getItemSku(candidate);
          const cId = getItemId(candidate);
          return Boolean(parentRef) && (parentRef === cSku || parentRef === cId);
        });

        if (parentIndex >= 0) {
          // Add the parent first
          addIndex(parentIndex);
          // Then add all its children
          const pSku = getItemSku(bulkItems[parentIndex]);
          const pId = getItemId(bulkItems[parentIndex]);
          const siblings = [
            ...(pSku ? childrenByParent.get(pSku) || [] : []),
            ...(pId && pId !== pSku ? childrenByParent.get(pId) || [] : [])
          ];
          for (const sibIdx of siblings) {
            addIndex(sibIdx);
          }
        } else {
          // Orphan variation: group with other sibling variations of the same parentRef
          const orphanSiblings = childrenByParent.get(parentRef) || [index];
          for (const orphanIdx of orphanSiblings) {
            addIndex(orphanIdx);
          }
        }
      }
    });

    // Append any unvisited rows
    bulkItems.forEach((_, index) => {
      if (!visited.has(index)) addIndex(index);
    });

    // Check which items should be exported and map fields
    const exportData = orderedIndices.map(index => {
      const item = bulkItems[index];
      const processed = bulkResults[index];
      const isProcessed = isSuccessfulGenerationResult(processed);

      // Ensure product family structure remains intact:
      // If an item was processed, or if ANY member of its family (parent or variation) was processed,
      // include this row (with original data if not enhanced) so the WooCommerce/Shopify parent-child tree is never broken.
      const sku = getItemSku(item);
      const id = getItemId(item);
      const parentRef = getItemParentRef(item);

      const isPartOfProcessedFamily =
        isProcessed ||
        // Check if parent or sibling variation was processed
        (Boolean(parentRef) &&
          bulkItems.some((cand, cIdx) => {
            const cSku = getItemSku(cand);
            const cId = getItemId(cand);
            const candParent = getItemParentRef(cand);
            const candProcessed = bulkResults[cIdx];
            return (
            isSuccessfulGenerationResult(candProcessed) &&
              (cSku === parentRef || cId === parentRef || candParent === parentRef)
            );
          })) ||
        // Check if this is a parent and any of its children were processed
        bulkItems.some((cand, cIdx) => {
          const candParent = getItemParentRef(cand);
          const candProcessed = bulkResults[cIdx];
          return isSuccessfulGenerationResult(candProcessed) && (candParent === sku || candParent === id);
        });

      // Include all items if general bulk processing ran or if part of a processed family
      const shouldInclude = isProcessed || isPartOfProcessedFamily || (bulkResults.length >= bulkItems.length * 0.5);
      if (!shouldInclude) return null;

      const mapped: Record<string, any> = { ...item };
      if (isProcessed) {
        if (generateFullDesc && processed.description_full) {
          const existing = findExistingKey(mapped, ['Description']);
          mapped[existing || 'Description'] = formatToCleanHtml(processed.description_full, false);
        }
        const shortDesc = processed.description_short || processed.shortDescription;
        if (generateShortDesc && shortDesc) {
          const existing = findExistingKey(mapped, ['Short description']);
          mapped[existing || 'Short description'] = formatToCleanHtml(shortDesc, true);
        }
        if (generateName && processed.name) {
          const existing = findExistingKey(mapped, ['Name']);
          mapped[existing || 'Name'] = processed.name;
        }
        if (generateBrand && processed.brand) {
          const existing = findExistingKey(mapped, ['Brands', 'Brand']);
          mapped[existing || 'Brands'] = processed.brand;
        }

        if (generateSeo && processed.seoMeta) {
          // Editorial SEO fields are allowed to change.
          setKnownSeoField(mapped, ['Meta: _dtb_seo_title', 'meta:_dtb_seo_title', 'meta:seo_title'], DEFAULT_SEO_KEYS.title, processed.seoMeta.seo_title || '');
          setKnownSeoField(mapped, ['Meta: _dtb_seo_description', 'meta:_dtb_seo_description', 'meta:seo_description'], DEFAULT_SEO_KEYS.description, processed.seoMeta.seo_description || '');
          setKnownSeoField(mapped, ['Meta: _dtb_seo_focus_kw', 'meta:_dtb_seo_focus_kw', 'meta:seo_focus_keyword'], DEFAULT_SEO_KEYS.focus, processed.seoMeta.seo_focus_keyword || '');

          // Extended search fields are only updated when the imported catalog already owns them.
          setExistingOnly(mapped, ['meta:seo_secondary_keywords', 'Meta: _dtb_seo_secondary_keywords'], processed.seoMeta.seo_secondary_keywords || '');
          setExistingOnly(mapped, ['meta:search_keywords', 'Meta: _dtb_search_keywords'], processed.seoMeta.search_keywords || '');
          setExistingOnly(mapped, ['meta:synonyms', 'Meta: _dtb_synonyms'], processed.seoMeta.synonyms || '');

          // Protected routing/indexation fields are intentionally NOT overwritten:
          // Slug, canonical, robots, product_family, series and model remain source-controlled.
        }
      }

      return mapped;
    }).filter(Boolean) as Record<string, any>[];

    if (!exportData.length) {
      alert('No successful processed data to export.');
      return;
    }

    // Preserve the source header order and append only newly created allowed fields.
    const sourceHeaders = Object.keys(bulkItems[0] || {});
    const extras = Object.keys(exportData[0]).filter(key => !sourceHeaders.includes(key));
    const csv = Papa.unparse({ fields: [...sourceHeaders, ...extras], data: exportData });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'enhanced_products.csv';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const copyToClipboard = async (text: string) => {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.opacity = "0";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      }
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch (error) {
      console.error('Clipboard copy failed', error);
    }
  };

  const renderAudit = (audit: EnhancedDescription['audit'] | any) => {
    if (!audit) return null;
    const issues = Array.isArray(audit.validationIssues) ? audit.validationIssues : [];
    const warnings = Array.isArray(audit.warnings) ? audit.warnings : [];
    return (
      <div className="glass-card p-5 border border-amber-100 bg-amber-50/40">
        <div className="flex items-center justify-between gap-4 mb-3">
          <div className="flex items-center gap-2">
            <ShieldCheck className="w-4 h-4 text-amber-700" />
            <p className="text-xs font-bold uppercase tracking-widest text-amber-800">Generation Audit</p>
          </div>
          <span className="text-[10px] font-mono text-amber-700">{audit.productClass} · confidence {audit.evidenceConfidence}</span>
        </div>
        <div className="text-xs text-amber-900 space-y-1">
          <p>Model: {audit.modelVersion || audit.modelRequested}{audit.repaired ? ' · repaired' : ''}</p>
          <p>Canonical: {String(audit.canonicalAction || 'preserve')}</p>
          {warnings.map((warning: string, index: number) => <p key={`w-${index}`}>• {warning}</p>)}
          {issues.map((issue: any, index: number) => (
            <p key={`i-${index}`} className={issue.severity === 'error' ? 'text-red-700' : ''}>• {issue.field}: {issue.message}</p>
          ))}
          {Array.isArray(audit.sourceUrls) && audit.sourceUrls.length > 0 && (
            <div className="pt-2">
              <p className="font-semibold mb-1">Grounding sources</p>
              {audit.sourceUrls.slice(0, 4).map((url: string, urlIndex: number) => (
                <a key={`source-url-${urlIndex}-${url}`} href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1 underline break-all">
                  <ExternalLink className="w-3 h-3 shrink-0" /> {url}
                </a>
              ))}
            </div>
          )}
        </div>
      </div>
    );
  };

  const seoCard = (seoMeta: any, audit?: any) => (
    <div className="space-y-6">
      <div className="glass-card p-6 bg-brand-primary text-white border-none">
        <h3 className="text-sm font-bold uppercase tracking-widest opacity-60 mb-4 flex items-center gap-2">
          <Search className="w-4 h-4" /> SEO Meta
        </h3>
        <div className="space-y-4">
          <div>
            <p className="text-xs font-bold opacity-40 uppercase mb-1">Protected Route</p>
            <p className="text-sm font-medium">{String(seoMeta.slug || 'Existing slug preserved')}</p>
            <p className="text-xs opacity-70 mt-1 break-all">{String(seoMeta.seo_canonical || 'Runtime canonical / no override')}</p>
            {audit?.canonicalAction && <p className="text-[10px] opacity-60 mt-1">Action: {String(audit.canonicalAction)}</p>}
          </div>
          <div>
            <p className="text-xs font-bold opacity-40 uppercase mb-1">Meta Title</p>
            <p className="text-sm font-medium">{String(seoMeta.seo_title || '')}</p>
            <p className="text-[10px] opacity-50 mt-1">{String(seoMeta.seo_title || '').length}/60</p>
          </div>
          <div>
            <p className="text-xs font-bold opacity-40 uppercase mb-1">Meta Description</p>
            <p className="text-sm opacity-80 leading-snug">{String(seoMeta.seo_description || '')}</p>
            <p className="text-[10px] opacity-50 mt-1">{String(seoMeta.seo_description || '').length}/160</p>
          </div>
          <div>
            <p className="text-xs font-bold opacity-40 uppercase mb-1">Focus Keyword</p>
            <p className="text-sm font-medium">{String(seoMeta.seo_focus_keyword || '')}</p>
          </div>
          {(seoMeta.seo_secondary_keywords || seoMeta.search_keywords || seoMeta.synonyms) && (
            <div>
              <p className="text-xs font-bold opacity-40 uppercase mb-1">Search Vocabulary</p>
              <p className="text-xs opacity-80 leading-snug">
                {[seoMeta.seo_secondary_keywords, seoMeta.search_keywords, seoMeta.synonyms].filter(Boolean).join(' • ')}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen flex flex-col">
      <header className="sticky top-0 z-50 glass-card mx-4 mt-4 px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-10 h-10 bg-brand-primary rounded-xl flex items-center justify-center text-white">
            <Sparkles className="w-6 h-6" />
          </div>
          <span className="text-xl font-bold tracking-tight">OptiWrite AI</span>
        </div>
      </header>

      <main className="flex-1 max-w-5xl mx-auto w-full px-4 py-12">
        <div className="text-center mb-16">
          <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 text-brand-accent text-xs font-bold uppercase tracking-wider mb-6">
            <Zap className="w-3 h-3" /> Evidence-bounded Gemini generation
          </motion.div>
          <motion.h1 initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }} className="text-5xl md:text-6xl font-bold tracking-tight mb-6">
            Product Content <br /><span className="text-brand-accent italic">Optimization</span>
          </motion.h1>
          <motion.p initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }} className="text-xl text-brand-secondary max-w-2xl mx-auto">
            Evidence-aware product descriptions and SEO proposals with protected catalog identity, validation, grounding, and repair controls.
          </motion.p>
        </div>

        <div className="glass-card p-2 mb-12">
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
            <div className="flex gap-1 p-1 bg-gray-100 rounded-xl w-full sm:w-auto">
              <button onClick={() => setActiveTab('single')} className={cn("flex-1 sm:flex-none px-6 flex items-center justify-center gap-2 py-3 rounded-lg text-sm font-semibold transition-all", activeTab === 'single' ? "bg-white shadow-sm text-brand-primary" : "text-brand-secondary hover:text-brand-primary")}>
                <FileText className="w-4 h-4" /> Single Product
              </button>
              <button onClick={() => setActiveTab('bulk')} className={cn("flex-1 sm:flex-none px-6 flex items-center justify-center gap-2 py-3 rounded-lg text-sm font-semibold transition-all", activeTab === 'bulk' ? "bg-white shadow-sm text-brand-primary" : "text-brand-secondary hover:text-brand-primary")}>
                <Upload className="w-4 h-4" /> Bulk Processing
              </button>
            </div>
            <button onClick={() => setIsSettingsOpen(true)} className="flex items-center gap-2 bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm text-sm font-medium text-brand-secondary hover:text-brand-primary hover:border-gray-300 transition-all">
              <Settings className="w-4 h-4" /> Configuration Settings
            </button>
          </div>

          <div className="p-4">
            <AnimatePresence mode="wait">
              {activeTab === 'single' ? (
                <motion.div key="single" initial={{ opacity: 0, x: -10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: 10 }} className="space-y-4">
                  {singleError && (
                    <div className="p-4 rounded-2xl bg-red-50/90 border border-red-200 text-red-800 flex items-start justify-between gap-3 text-sm">
                      <div className="flex items-start gap-2.5">
                        <AlertTriangle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
                        <div>
                          <p className="font-semibold text-red-900">Optimization Failed</p>
                          <p className="mt-0.5 leading-relaxed">{singleError}</p>
                        </div>
                      </div>
                      <button onClick={() => setSingleError(null)} className="p-1 hover:bg-red-100 rounded-lg text-red-500 transition-colors shrink-0">
                        <X className="w-4 h-4" />
                      </button>
                    </div>
                  )}
                  <div className="relative">
                    <textarea value={input} onChange={event => { setInput(event.target.value); if (singleError) setSingleError(null); }} placeholder="Paste structured product data or a product description here..." className="w-full h-48 p-6 rounded-2xl border border-gray-200 focus:ring-2 focus:ring-brand-accent focus:border-transparent outline-none transition-all resize-none text-lg" />
                    <div className="absolute bottom-4 right-4 text-xs text-gray-400 font-mono">{input.length} characters</div>
                  </div>
                  <textarea value={competitorContext} onChange={event => setCompetitorContext(event.target.value)} placeholder="Optional competitor URL or text. Treated as untrusted market context, never as product truth." className="w-full h-24 p-4 rounded-2xl border border-gray-200 focus:ring-2 focus:ring-brand-accent focus:border-transparent outline-none transition-all resize-none text-sm bg-gray-50" />
                  <div className="flex justify-end gap-2">
                    {loading && <button onClick={handleStopProcessing} className="btn-secondary">Stop</button>}
                    <button onClick={handleEnhance} disabled={loading || !input.trim()} className="btn-primary flex items-center gap-2">
                      {loading ? <Loader2 className="w-5 h-5 animate-spin" /> : <Sparkles className="w-5 h-5" />} Optimize Product
                    </button>
                  </div>
                </motion.div>
              ) : (
                <motion.div key="bulk" initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -10 }} className="space-y-6">
                  {!bulkItems.length ? (
                    <div {...getRootProps()} className={cn("border-2 border-dashed rounded-2xl p-12 flex flex-col items-center justify-center gap-4 transition-all cursor-pointer", isDragActive ? "border-brand-accent bg-blue-50/50" : "border-gray-200 hover:border-gray-300")}>
                      <input {...getInputProps()} />
                      <div className="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center text-gray-400"><Upload className="w-8 h-8" /></div>
                      <div className="text-center"><p className="text-lg font-semibold">Drop your CSV or JSON here</p><p className="text-sm text-brand-secondary">Catalog identity is preserved during generation and export</p></div>
                    </div>
                  ) : (
                    <div className="space-y-4">
                      <div className="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200">
                      <div className="flex items-center gap-3">
                          <div className="w-10 h-10 bg-white rounded-lg flex items-center justify-center border border-gray-200"><FileText className="w-5 h-5 text-brand-accent" /></div>
                          <div>
                            <p className="font-semibold">{bulkItems.length.toLocaleString()} Rows Loaded</p>
                            <p className="text-xs text-brand-secondary">{bulkItems.filter(isBulkGenerationTarget).length.toLocaleString()} published/draft rows targeted; {bulkItems.filter(item => !isBulkGenerationTarget(item)).length.toLocaleString()} other-status rows held unchanged</p>
                          </div>
                        </div>
                        <button onClick={() => { setBulkItems([]); setBulkResults([]); setProgress({ current: 0, total: 0 }); }} className="p-2 text-gray-400 hover:text-red-500 transition-colors"><Trash2 className="w-5 h-5" /></button>
                      </div>

                      <div className="flex justify-end gap-3">
                        {!loading && bulkResults.some(isSuccessfulGenerationResult) && <button onClick={handleExport} className="btn-secondary flex items-center gap-2"><Download className="w-5 h-5" /> Export CSV</button>}
                        <div className="flex items-center gap-4 w-full justify-end">
                          {loading && progress.total > 0 && (
                            <div className="flex-1 max-w-xs">
                              <div className="flex justify-between text-xs text-brand-secondary mb-1"><span>Processing...</span><span>{progress.current} / {progress.total}</span></div>
                              <div className="w-full h-2 bg-gray-100 rounded-full overflow-hidden"><div className="h-full bg-brand-accent transition-all duration-300 ease-out" style={{ width: `${progress.total ? (progress.current / progress.total) * 100 : 0}%` }} /></div>
                            </div>
                          )}
                          <div className="flex gap-2">
                            <button onClick={handleClearAll} disabled={loading} className="btn-secondary flex items-center gap-2 border-red-100 text-red-600 hover:bg-red-50"><Trash2 className="w-5 h-5" /> Clear All Data</button>
                            <button onClick={handleBulkProcess} disabled={loading || !bulkItems.some(isBulkGenerationTarget)} className="btn-primary flex items-center gap-2">{loading ? <Loader2 className="w-5 h-5 animate-spin" /> : <Zap className="w-5 h-5" />}{loading ? 'Processing...' : (bulkResults.some(isSuccessfulGenerationResult) ? `Resume ${bulkItems.filter(isBulkGenerationTarget).length.toLocaleString()} Eligible Rows` : `Process ${bulkItems.filter(isBulkGenerationTarget).length.toLocaleString()} Eligible Rows`)}</button>
                            {loading && <button onClick={handleStopProcessing} className="btn-secondary text-orange-600">Stop</button>}
                          </div>
                        </div>
                      </div>
                    </div>
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        </div>

        <AnimatePresence>
          {result && (
            <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-8 mb-12">
              <div className="flex items-center gap-3"><div className="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center text-green-600"><ShieldCheck className="w-5 h-5" /></div><h2 className="text-2xl font-bold">Generated Proposal</h2></div>
              {renderAudit(result.audit)}
              <div className={cn("grid gap-8", generateSeo && result.seoMeta ? "grid-cols-1 md:grid-cols-3" : "grid-cols-1")}>
                <div className={cn("space-y-6", generateSeo && result.seoMeta ? "md:col-span-2" : "")}>
                  <div className="glass-card p-8">
                    <div className="flex justify-between items-start mb-6"><h3 className="text-xl font-bold">Optimized Output</h3><button onClick={() => copyToClipboard([result.brand ? `Brand: ${result.brand}` : '', result.name ? `Name: ${result.name}` : '', result.shortDescription ? `Short Description:\n${result.shortDescription}` : '', result.description_full ? `Full Description:\n${result.description_full}` : ''].filter(Boolean).join('\n\n'))} className="p-2 hover:bg-gray-100 rounded-lg transition-colors text-brand-secondary">{copied ? <Check className="w-5 h-5 text-green-500" /> : <Copy className="w-5 h-5" />}</button></div>
                    {result.brand && <div className="mb-4"><p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Brand Proposal</p><p className="text-lg font-semibold text-brand-primary">{result.brand}</p></div>}
                    {result.name && <div className="mb-8"><p className="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Product Name Proposal</p><p className="text-xl font-bold text-brand-primary">{result.name}</p></div>}
                    {result.shortDescription && <div className="mb-8 p-4 bg-blue-50/50 rounded-xl border border-blue-100"><p className="text-xs font-bold text-brand-accent uppercase tracking-widest mb-2">Short Description</p><p className="text-brand-primary font-medium leading-relaxed">{result.shortDescription}</p></div>}
                    {result.description_full && <ProductDescriptionPreview content={result.description_full} productName={result.name} />}
                  </div>
                </div>
                {generateSeo && result.seoMeta && seoCard(result.seoMeta, result.audit)}
              </div>
            </motion.div>
          )}

          {bulkItems.length > 0 && (
            <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="glass-card overflow-hidden">
              <div className="p-6 border-b border-gray-100 flex items-center justify-between"><h2 className="text-xl font-bold">{loading ? 'Processing Products...' : 'Catalog Review Queue'}</h2><span className="text-xs font-mono text-brand-secondary">{bulkItems.length} items</span></div>
              <div className="overflow-x-auto max-h-[600px] overflow-y-auto">
                <table className="w-full text-sm text-left">
                  <thead className="bg-gray-50 text-brand-secondary uppercase text-[10px] font-bold tracking-wider sticky top-0 z-10">
                    <tr>
                      {Object.keys(bulkItems[0] || {}).slice(0, 4).map((header, hIdx) => (
                        <th key={`th-${hIdx}-${header}`} className="px-6 py-4">{header}</th>
                      ))}
                      <th className="px-6 py-4">Status</th>
                      <th className="px-6 py-4 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {bulkItems.slice(0, 100).map((originalItem, index) => {
                      const processed = bulkResults[index];
                      const display = processed || originalItem;
                      const opts = generationOptions();
                      const targeted = isBulkGenerationTarget(originalItem);
                      const status = !targeted ? 'Excluded' : processed?._isRetrying ? 'Retrying...' : processed?.error ? 'Failed' : processed?._isProcessing ? 'Processing...' : isGenerationComplete(processed, opts) ? (processed?.audit?.validationIssues?.length ? 'Review' : 'Optimized') : loading ? 'Pending' : 'Ready';
                      const statusStyles: Record<string, string> = {
                        Optimized: 'bg-green-50 text-green-600',
                        Review: 'bg-amber-50 text-amber-700',
                        Failed: 'bg-red-50 text-red-600',
                        'Retrying...': 'bg-amber-50 text-amber-600 animate-pulse',
                        'Processing...': 'bg-blue-50 text-blue-600 animate-pulse',
                        Excluded: 'bg-gray-100 text-gray-400',
                        Pending: 'bg-gray-50 text-gray-500',
                        Ready: 'bg-gray-50 text-gray-500'
                      };
                      const headers = Object.keys(bulkItems[0] || {}).slice(0, 4);
                      const itemSku = String(getValue(originalItem, 'SKU', 'sku', 'id', 'ID', 'MPN', 'mpn') || '');
                      return (
                        <tr key={`item-row-${index}-${itemSku}`} onClick={() => setSelectedBulkItem(processed || originalItem)} className="transition-colors hover:bg-gray-50 cursor-pointer">
                          {headers.map((header, colIdx) => (
                            <td key={`cell-${index}-${colIdx}-${header}`} className="px-6 py-4 font-medium truncate max-w-[200px]">
                              {display[header] === undefined || display[header] === null ? '-' : typeof display[header] === 'object' ? JSON.stringify(display[header]) : String(display[header])}
                            </td>
                          ))}
                          <td className="px-6 py-4"><span className={cn("px-2 py-1 rounded-full text-[10px] font-bold uppercase", statusStyles[status])}>{status}</span></td>
                          <td className="px-6 py-4 text-right" onClick={event => event.stopPropagation()}><button onClick={() => handleRetrySingleItem(index)} disabled={loading || !targeted || processed?._isRetrying} className="px-2.5 py-1 text-xs font-semibold bg-gray-100 text-brand-primary hover:bg-brand-accent hover:text-white rounded-lg transition-all flex items-center gap-1 ml-auto disabled:opacity-50"><RefreshCw className={cn("w-3 h-3", processed?._isRetrying ? "animate-spin" : "")} />{!targeted ? 'Excluded' : processed?.error ? 'Retry' : processed?.description_full ? 'Re-run' : 'Run'}</button></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              {bulkItems.length > 100 && <div className="p-4 text-center bg-gray-50 text-xs text-brand-secondary border-t border-gray-100">Showing first 100 items. Export to review all {bulkItems.length} rows.</div>}
            </motion.div>
          )}
        </AnimatePresence>

        <AnimatePresence>
          {selectedBulkItem && (
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-black/40 backdrop-blur-sm" onClick={() => setSelectedBulkItem(null)}>
              <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} onClick={event => event.stopPropagation()} className="bg-white w-full max-w-5xl max-h-[90vh] rounded-3xl shadow-2xl overflow-hidden flex flex-col">
                <div className="flex items-center justify-between p-6 border-b border-gray-100 bg-gray-50/50">
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center text-green-600">
                      <ShieldCheck className="w-5 h-5" />
                    </div>
                    <div>
                      <h2 className="text-xl font-bold">{String(getValue(selectedBulkItem, 'Name', 'name', 'Title') || 'Product Details')}</h2>
                      <p className="text-xs font-medium text-brand-secondary uppercase tracking-wider">{String(getValue(selectedBulkItem, 'Brands', 'Brand', 'brand') || getValue(selectedBulkItem, 'SKU', 'sku') || '')}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => {
                        const idx = bulkItems.findIndex((item, i) => sameProduct(item, selectedBulkItem) || sameProduct(bulkResults[i], selectedBulkItem));
                        if (idx >= 0) void handleRetrySingleItem(idx);
                      }}
                      disabled={loading || !isBulkGenerationTarget(selectedBulkItem) || selectedBulkItem._isRetrying}
                      className="px-3 py-1.5 text-xs font-semibold bg-brand-primary text-white hover:bg-brand-accent rounded-xl transition-all flex items-center gap-1.5 disabled:opacity-50"
                    >
                      <RefreshCw className={cn("w-3.5 h-3.5", selectedBulkItem._isRetrying ? "animate-spin" : "")} />
                      {!isBulkGenerationTarget(selectedBulkItem) ? 'Excluded by status' : selectedBulkItem.error ? 'Retry Generation' : (selectedBulkItem.description_full || selectedBulkItem.description_short) ? 'Re-generate' : 'Generate'}
                    </button>
                    <button onClick={() => setSelectedBulkItem(null)} className="p-2 hover:bg-gray-200 rounded-full transition-colors text-brand-secondary ml-2">
                      <X className="w-5 h-5" />
                    </button>
                  </div>
                </div>
                <div className="p-6 overflow-y-auto flex-1 space-y-6">
                  {selectedBulkItem.error && (
                    <div className="p-4 rounded-xl bg-red-50 text-red-700 flex items-start gap-2">
                      <AlertTriangle className="w-5 h-5 mt-0.5 shrink-0" />
                      <span>{String(selectedBulkItem.error)}</span>
                    </div>
                  )}
                  {renderAudit(selectedBulkItem.audit)}
                  {(selectedBulkItem.description_full || selectedBulkItem.description_short || selectedBulkItem.shortDescription) ? (
                    <div className={cn("grid gap-8", generateSeo && selectedBulkItem.seoMeta ? "grid-cols-1 md:grid-cols-3" : "grid-cols-1")}>
                      <div className={cn("space-y-6", generateSeo && selectedBulkItem.seoMeta ? "md:col-span-2" : "")}>
                        <div className="glass-card p-8">
                          <div className="flex justify-between items-start mb-6">
                            <h3 className="text-xl font-bold">Optimized Output</h3>
                            <button onClick={() => copyToClipboard([selectedBulkItem.description_short || selectedBulkItem.shortDescription, selectedBulkItem.description_full].filter(Boolean).join('\n\n'))} className="p-2 hover:bg-gray-100 rounded-lg transition-colors text-brand-secondary">
                              {copied ? <Check className="w-5 h-5 text-green-500" /> : <Copy className="w-5 h-5" />}
                            </button>
                          </div>
                          {(selectedBulkItem.description_short || selectedBulkItem.shortDescription) && (
                            <div className="mb-8 p-4 bg-blue-50/50 rounded-xl border border-blue-100">
                              <p className="text-xs font-bold text-brand-accent uppercase tracking-widest mb-2">Short Description</p>
                              <p className="text-brand-primary font-medium leading-relaxed">{String(selectedBulkItem.description_short || selectedBulkItem.shortDescription)}</p>
                            </div>
                          )}
                          {selectedBulkItem.description_full && (
                            <ProductDescriptionPreview
                              content={String(selectedBulkItem.description_full)}
                              productName={String(getValue(selectedBulkItem, 'Name', 'name', 'Title') || '')}
                            />
                          )}
                        </div>
                      </div>
                      {generateSeo && selectedBulkItem.seoMeta && seoCard(selectedBulkItem.seoMeta, selectedBulkItem.audit)}
                    </div>
                  ) : (
                    <div className="glass-card p-6 space-y-4">
                      <h3 className="text-base font-bold text-brand-primary">Source Product Data</h3>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {Object.entries(selectedBulkItem).map(([key, val], entryIdx) => {
                          if (key.startsWith('_') || key === 'audit' || key === 'error') return null;
                          return (
                            <div key={`source-prop-${entryIdx}-${key}`} className="p-3 bg-gray-50 rounded-xl border border-gray-100">
                              <p className="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">{key}</p>
                              <p className="text-sm font-medium text-brand-primary break-words">{typeof val === 'object' ? JSON.stringify(val) : String(val || '-')}</p>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>
              </motion.div>
            </div>
          )}
        </AnimatePresence>
      </main>

      <footer className="border-t border-gray-200 py-12 px-4 mt-24"><div className="max-w-5xl mx-auto flex flex-col md:flex-row justify-between items-center gap-8"><div className="flex items-center gap-2"><div className="w-8 h-8 bg-brand-primary rounded-lg flex items-center justify-center text-white"><Sparkles className="w-4 h-4" /></div><span className="font-bold">OptiWrite AI</span></div><p className="text-sm text-brand-secondary">© 2026 OptiWrite AI. Review-first catalog optimization.</p><div className="flex gap-6 text-sm text-brand-secondary"><a href="#" className="hover:text-brand-primary">Privacy</a><a href="#" className="hover:text-brand-primary">Terms</a><a href="#" className="hover:text-brand-primary">API</a></div></div></footer>

      <AnimatePresence>
        {isSettingsOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
              <div className="flex items-center justify-between p-6 border-b border-gray-100"><h2 className="text-xl font-bold flex items-center gap-2"><Settings className="w-5 h-5 text-brand-accent" /> Configuration Settings</h2><button onClick={() => setIsSettingsOpen(false)} className="p-2 hover:bg-gray-100 rounded-full transition-colors"><X className="w-5 h-5 text-gray-500" /></button></div>
              <div className="p-6 space-y-6">
                <div>
                  <h3 className="text-sm font-bold uppercase tracking-widest text-gray-400 mb-2">Active AI Model</h3>
                  <div className="p-3 bg-blue-50/70 rounded-xl border border-blue-100 flex items-center justify-between">
                    <div>
                      <p className="text-sm font-bold text-brand-primary font-mono">gemini-3.1-flash-lite</p>
                      <p className="text-xs text-brand-secondary mt-0.5">High-speed catalog copy &amp; SEO generation</p>
                    </div>
                    <span className="text-[11px] font-semibold uppercase tracking-wider bg-brand-accent text-white px-2 py-0.5 rounded-md">Locked</span>
                  </div>
                </div>

                <div className="pt-6 border-t border-gray-100">
                  <h3 className="text-sm font-bold uppercase tracking-widest text-gray-400 mb-4">Generation Options</h3>
                  <div className="space-y-3">
                    {[
                      ['Generate Brand Proposal', generateBrand, setGenerateBrand],
                      ['Generate Name Proposal', generateName, setGenerateName],
                      ['Generate Full Description', generateFullDesc, setGenerateFullDesc],
                      ['Generate Short Description', generateShortDesc, setGenerateShortDesc],
                      ['Generate SEO Editorial Fields', generateSeo, setGenerateSeo]
                    ].map(([label, checked, setter]) => (
                      <label key={String(label)} className="flex items-center justify-between cursor-pointer group">
                        <span className="text-sm font-medium text-brand-secondary group-hover:text-brand-primary transition-colors">{String(label)}</span>
                        <div className="relative"><input type="checkbox" className="sr-only" checked={Boolean(checked)} onChange={event => (setter as (value: boolean) => void)(event.target.checked)} /><div className={cn("block w-10 h-6 rounded-full transition-colors", checked ? "bg-brand-accent" : "bg-gray-300")} /><div className={cn("absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform", checked ? "translate-x-4" : "")} /></div>
                      </label>
                    ))}
                  </div>
                </div>

                <div className="pt-6 border-t border-gray-100">
                  <h3 className="text-sm font-bold uppercase tracking-widest text-gray-400 mb-4">Quality Controls</h3>
                  <div className="space-y-4">
                    <label className="flex items-center justify-between cursor-pointer group"><div className="flex flex-col pr-4"><span className="text-sm font-medium text-brand-secondary">Enable Web Search Grounding</span><span className="text-xs text-gray-400">Verifies exact products; adds latency and tool usage</span></div><div className="relative"><input type="checkbox" className="sr-only" checked={enableWebSearch} onChange={event => setEnableWebSearch(event.target.checked)} /><div className={cn("block w-10 h-6 rounded-full transition-colors", enableWebSearch ? "bg-brand-accent" : "bg-gray-300")} /><div className={cn("absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform", enableWebSearch ? "translate-x-4" : "")} /></div></label>
                    <label className="flex items-center justify-between cursor-pointer group"><div className="flex flex-col pr-4"><span className="text-sm font-medium text-brand-secondary">Automatic Validation Repair</span><span className="text-xs text-gray-400">Runs one constrained repair pass when generated output violates hard rules</span></div><div className="relative"><input type="checkbox" className="sr-only" checked={enableRepairPass} onChange={event => setEnableRepairPass(event.target.checked)} /><div className={cn("block w-10 h-6 rounded-full transition-colors", enableRepairPass ? "bg-brand-accent" : "bg-gray-300")} /><div className={cn("absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform", enableRepairPass ? "translate-x-4" : "")} /></div></label>
                    <label className="flex flex-col gap-2"><span className="text-sm font-medium text-brand-secondary">Global Competitor Context</span><textarea value={competitorContext} onChange={event => setCompetitorContext(event.target.value)} placeholder="Optional URL or text. Used only as untrusted market-language context." className="w-full h-20 p-3 rounded-xl border border-gray-200 focus:ring-2 focus:ring-brand-accent focus:border-transparent outline-none transition-all resize-none text-sm bg-white" /></label>
                  </div>
                </div>
              </div>
              <div className="p-6 bg-gray-50 border-t border-gray-100 flex justify-end"><button onClick={() => setIsSettingsOpen(false)} className="px-6 py-2 bg-brand-primary text-white rounded-lg font-medium hover:bg-brand-secondary transition-colors">Done</button></div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}

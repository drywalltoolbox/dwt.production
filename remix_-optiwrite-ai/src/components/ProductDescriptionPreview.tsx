import React, { useState, useMemo } from 'react';
import {
  Eye,
  Code,
  Copy,
  Check,
  ChevronDown,
  Layers,
  Sparkles,
  Sliders,
  Link2,
  Package,
  Wrench,
  Grid,
  FileText,
  ShieldCheck,
  ChevronsUpDown
} from 'lucide-react';
import { cn } from '../lib/utils';

interface Props {
  content: string;
  title?: string;
  productName?: string;
}

interface AccordionSection {
  id: string;
  title: string;
  htmlContent: string;
  itemCount?: number;
  type: 'overview' | 'features' | 'specifications' | 'compatibility' | 'includes' | 'options' | 'maintenance' | 'general';
}

function classifySectionType(title: string): AccordionSection['type'] {
  const t = title.toLowerCase();
  if (t.includes('overview') || t.includes('about') || t.includes('description')) return 'overview';
  if (t.includes('feature')) return 'features';
  if (t.includes('spec') || t.includes('dimension') || t.includes('technical')) return 'specifications';
  if (t.includes('compatib') || t.includes('system') || t.includes('fits') || t.includes('mount')) return 'compatibility';
  if (t.includes('include') || t.includes('package') || t.includes('contents') || t.includes('set include') || t.includes('kit include')) return 'includes';
  if (t.includes('option') || t.includes('size') || t.includes('configuration') || t.includes('variant')) return 'options';
  if (t.includes('maint') || t.includes('care') || t.includes('clean') || t.includes('service')) return 'maintenance';
  return 'general';
}

function getSectionIcon(type: AccordionSection['type']) {
  switch (type) {
    case 'overview':
      return FileText;
    case 'features':
      return Sparkles;
    case 'specifications':
      return Sliders;
    case 'compatibility':
      return Link2;
    case 'includes':
      return Package;
    case 'options':
      return Grid;
    case 'maintenance':
      return Wrench;
    default:
      return Layers;
  }
}

function parseAccordionSections(html: string): AccordionSection[] {
  if (!html || !html.trim()) return [];

  const trimmed = html.trim();
  const h3Regex = /<h3[^>]*>([\s\S]*?)<\/h3>/gi;
  const sections: AccordionSection[] = [];
  
  let match: RegExpExecArray | null;
  const headings: Array<{ title: string; index: number; fullMatchLength: number }> = [];

  while ((match = h3Regex.exec(trimmed)) !== null) {
    // Strip any inner HTML tags inside heading
    const cleanHeading = match[1].replace(/<[^>]+>/g, '').trim();
    headings.push({
      title: cleanHeading,
      index: match.index,
      fullMatchLength: match[0].length
    });
  }

  if (headings.length === 0) {
    // No H3 headings: entire content is the Overview section
    const liMatches = trimmed.match(/<li[^>]*>/gi);
    return [{
      id: 'overview',
      title: 'Product Overview',
      htmlContent: trimmed,
      itemCount: liMatches ? liMatches.length : undefined,
      type: 'overview'
    }];
  }

  // Pre-heading content is the Overview
  const preHeadingContent = trimmed.substring(0, headings[0].index).trim();
  if (preHeadingContent) {
    const liMatches = preHeadingContent.match(/<li[^>]*>/gi);
    sections.push({
      id: 'section-overview',
      title: 'Overview',
      htmlContent: preHeadingContent,
      itemCount: liMatches ? liMatches.length : undefined,
      type: 'overview'
    });
  }

  // Each heading and its body
  for (let i = 0; i < headings.length; i++) {
    const current = headings[i];
    const startIndex = current.index + current.fullMatchLength;
    const endIndex = i + 1 < headings.length ? headings[i + 1].index : trimmed.length;
    const body = trimmed.substring(startIndex, endIndex).trim();
    const type = classifySectionType(current.title);
    const liMatches = body.match(/<li[^>]*>/gi);

    sections.push({
      id: `section-${i}-${current.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`,
      title: current.title,
      htmlContent: body,
      itemCount: liMatches ? liMatches.length : undefined,
      type
    });
  }

  return sections;
}

export const ProductDescriptionPreview: React.FC<Props> = ({ content, title = "Full Description", productName }) => {
  const [mode, setMode] = useState<'accordion' | 'preview' | 'html'>('accordion');
  const [copied, setCopied] = useState(false);

  const sections = useMemo(() => parseAccordionSections(content), [content]);

  // Initially open all sections for comprehensive review
  const [openSectionIds, setOpenSectionIds] = useState<Set<string>>(() => {
    return new Set(sections.map(s => s.id));
  });

  const toggleSection = (id: string) => {
    setOpenSectionIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const allOpen = sections.length > 0 && sections.every(s => openSectionIds.has(s.id));

  const toggleAll = () => {
    if (allOpen) {
      setOpenSectionIds(new Set());
    } else {
      setOpenSectionIds(new Set(sections.map(s => s.id)));
    }
  };

  const handleCopy = async () => {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(content);
      } else {
        const textArea = document.createElement("textarea");
        textArea.value = content;
        textArea.style.position = "fixed";
        textArea.style.opacity = "0";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      }
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      console.error('Failed to copy text', err);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-3">
        <div className="flex items-center gap-2">
          <p className="text-xs font-bold text-gray-500 uppercase tracking-widest">{title}</p>
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
            <ShieldCheck className="w-3 h-3 text-emerald-600" />
            DTB PDP Architecture
          </span>
        </div>

        <div className="flex items-center gap-2">
          <div className="flex bg-gray-100 p-0.5 rounded-lg text-xs">
            <button
              onClick={() => setMode('accordion')}
              className={cn(
                "px-2.5 py-1 rounded-md font-medium flex items-center gap-1.5 transition-all",
                mode === 'accordion' ? "bg-white text-brand-primary shadow-xs" : "text-gray-500 hover:text-gray-900"
              )}
              title="Drywall Toolbox PDP Accordion layout"
            >
              <Layers className="w-3.5 h-3.5 text-brand-accent" />
              <span>DTB Accordion</span>
            </button>
            <button
              onClick={() => setMode('preview')}
              className={cn(
                "px-2.5 py-1 rounded-md font-medium flex items-center gap-1.5 transition-all",
                mode === 'preview' ? "bg-white text-brand-primary shadow-xs" : "text-gray-500 hover:text-gray-900"
              )}
              title="Standard rendered HTML view"
            >
              <Eye className="w-3.5 h-3.5" />
              <span>Standard Web</span>
            </button>
            <button
              onClick={() => setMode('html')}
              className={cn(
                "px-2.5 py-1 rounded-md font-medium flex items-center gap-1.5 transition-all",
                mode === 'html' ? "bg-white text-brand-primary shadow-xs" : "text-gray-500 hover:text-gray-900"
              )}
              title="Raw HTML for WooCommerce CSV import"
            >
              <Code className="w-3.5 h-3.5" />
              <span>HTML / CSV</span>
            </button>
          </div>

          <button
            onClick={handleCopy}
            className="p-1.5 hover:bg-gray-100 rounded-lg transition-colors text-brand-secondary text-xs flex items-center gap-1 border border-gray-200 bg-white"
            title="Copy HTML to clipboard"
          >
            {copied ? <Check className="w-3.5 h-3.5 text-green-500" /> : <Copy className="w-3.5 h-3.5" />}
            <span className="hidden sm:inline font-mono text-[11px]">{copied ? 'Copied' : 'Copy HTML'}</span>
          </button>
        </div>
      </div>

      {mode === 'accordion' && (
        <div className="space-y-3">
          <div className="flex items-center justify-between text-xs text-gray-500 px-1">
            <span className="text-[11px] font-medium text-gray-500">
              {sections.length} PDP Accordion {sections.length === 1 ? 'Section' : 'Sections'} Detected
            </span>
            <button
              onClick={toggleAll}
              className="text-xs font-medium text-brand-accent hover:text-blue-700 flex items-center gap-1 transition-colors"
            >
              <ChevronsUpDown className="w-3.5 h-3.5" />
              {allOpen ? 'Collapse All' : 'Expand All'}
            </button>
          </div>

          <div className="border border-gray-200 rounded-2xl overflow-hidden bg-white shadow-xs divide-y divide-gray-100">
            {sections.map((section) => {
              const isOpen = openSectionIds.has(section.id);
              const Icon = getSectionIcon(section.type);

              return (
                <div key={section.id} className="transition-colors">
                  <button
                    onClick={() => toggleSection(section.id)}
                    className="w-full px-5 py-4 flex items-center justify-between text-left hover:bg-gray-50/80 transition-colors focus:outline-none"
                    aria-expanded={isOpen}
                  >
                    <div className="flex items-center gap-3">
                      <div className="w-7 h-7 rounded-lg bg-blue-50 text-brand-accent flex items-center justify-center shrink-0">
                        <Icon className="w-4 h-4" />
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-gray-900 tracking-tight">
                          {section.title}
                        </span>
                        {section.itemCount !== undefined && section.itemCount > 0 && (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-600">
                            {section.itemCount} items
                          </span>
                        )}
                      </div>
                    </div>
                    <ChevronDown
                      className={cn(
                        "w-4 h-4 text-gray-400 transition-transform duration-200",
                        isOpen ? "transform rotate-180 text-brand-accent" : ""
                      )}
                    />
                  </button>

                  {isOpen && (
                    <div className="px-5 pb-5 pt-1 bg-white">
                      <div
                        className="product-description-preview text-sm text-gray-700 leading-relaxed pl-10"
                        dangerouslySetInnerHTML={{ __html: section.htmlContent }}
                      />
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          <div className="p-3 bg-slate-50 border border-slate-200/80 rounded-xl flex items-center justify-between text-xs text-slate-600">
            <span className="font-mono text-[11px]">
              Ready for WooCommerce Post Content &amp; DTB Accordion template rendering.
            </span>
            <span className="text-[10px] uppercase font-bold tracking-wider text-slate-400">
              Clean Semantic HTML
            </span>
          </div>
        </div>
      )}

      {mode === 'preview' && (
        <div 
          className="product-description-preview p-6 bg-white rounded-2xl border border-gray-100 shadow-xs"
          dangerouslySetInnerHTML={{ __html: content }}
        />
      )}

      {mode === 'html' && (
        <div className="relative">
          <pre className="p-4 bg-gray-950 text-gray-100 text-xs font-mono rounded-2xl overflow-x-auto whitespace-pre-wrap break-all max-h-96 leading-relaxed border border-gray-800">
            {content}
          </pre>
        </div>
      )}
    </div>
  );
};

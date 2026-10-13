/**
 * ui/Dropdown.jsx — IndoUI-style generic dropdown
 *
 * Drop-in for SortDropdown / CalcDropdown / FilterPanel selects.
 */

import { useState, useRef, useEffect, useMemo } from 'react';
import { m as Motion, AnimatePresence } from 'framer-motion';
import { ChevronDown, Check } from 'lucide-react';
import { dtbTransition, indicatorTransition } from '../../motion/dtbMotion.js';

const DROPDOWN_OPTION_IDENTITY_ALIASES = {
  columbiatapingtools: 'columbiatools',
};

const VARIABLE_PARENT_SKU_MIN_LENGTH = 12;
const VARIABLE_PARENT_SKU_TERMS = [
  'taper', 'tube', 'box', 'head', 'handle', 'pump', 'flusher', 'applicator',
  'spotter', 'roller', 'stilt', 'sander', 'extension', 'compound',
];

function normalizeDropdownOptionIdentity(option) {
  const source = String(option?.label || option?.value || '')
    .trim()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '');

  return DROPDOWN_OPTION_IDENTITY_ALIASES[source] || source;
}

function normalizeCatalogModelText(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '');
}

function parseCatalogModelOption(option) {
  const label = String(option?.label || option?.value || '').trim();
  const match = label.match(/^(.*)\s[-—]\s([^—-]+)$/);
  if (!match) return null;

  const name = match[1].trim();
  const sku = match[2].trim();
  if (!name || !sku) return null;

  return { label, name, sku, normalizedSku: normalizeCatalogModelText(sku) };
}

function isLikelyVariableParentModelOption(option, allOptions) {
  const parsed = parseCatalogModelOption(option);
  if (!parsed) return false;

  const skuHasDigits = /\d/.test(parsed.normalizedSku);
  const skuHasToolTerm = VARIABLE_PARENT_SKU_TERMS.some((term) => parsed.normalizedSku.includes(term));
  if (skuHasDigits || parsed.normalizedSku.length < VARIABLE_PARENT_SKU_MIN_LENGTH || !skuHasToolTerm) {
    return false;
  }

  const childPrefix = `${parsed.name} - `;
  return allOptions.some((candidate) => {
    if (candidate === option) return false;
    const label = String(candidate?.label || candidate?.value || '').trim();
    return label.startsWith(childPrefix);
  });
}

function dedupeOptions(options) {
  const seen = new Set();
  const uniqueOptions = options.filter((option) => {
    const key = normalizeDropdownOptionIdentity(option);
    if (!key) return true;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });

  return uniqueOptions.filter((option) => !isLikelyVariableParentModelOption(option, uniqueOptions));
}

export default function Dropdown({
  id,
  triggerRef,
  required = false,
  value,
  onChange,
  options = [],
  placeholder = 'Select…',
  label,
  disabled = false,
  fullWidth = false,
  className = '',
  style = {},
}) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef(null);
  const visibleOptions = useMemo(() => dedupeOptions(options), [options]);
  const selectedOption = options.find((o) => o.value === value);

  useEffect(() => {
    if (!isOpen) return;
    const handler = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setIsOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    const handler = (e) => { if (e.key === 'Escape') setIsOpen(false); };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [isOpen]);

  const toggle = () => { if (!disabled) setIsOpen((o) => !o); };
  const select = (val) => { onChange(val); setIsOpen(false); };

  return (
    <div
      ref={containerRef}
      className={`dtb-ui-dropdown${className ? ` ${className}` : ''}`}
      style={{
        position: 'relative', display: fullWidth ? 'block' : 'inline-block',
        width: fullWidth ? '100%' : undefined, ...style,
      }}
    >
      {label && (
        <p style={{
          fontSize: '0.68rem', fontWeight: 700, letterSpacing: '0.09em', textTransform: 'uppercase',
          color: 'rgba(15,23,42,0.45)', marginBottom: '6px',
        }}>
          {label}
        </p>
      )}

      <button
        type="button"
        id={id}
        ref={triggerRef}
        aria-required={required || undefined}
        onClick={toggle}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        style={{
          display: 'flex', alignItems: 'center', gap: '8px', width: fullWidth ? '100%' : undefined,
          padding: '9px 14px', background: 'white',
          border: `1.5px solid ${isOpen ? 'var(--primary-600)' : 'rgba(15,23,42,0.12)'}`,
          borderRadius: '10px',
          boxShadow: isOpen ? '0 0 0 3px rgba(34,85,238,0.12)' : '0 1px 4px rgba(15,23,42,0.06)',
          fontSize: '0.875rem', fontWeight: 600,
          color: selectedOption ? '#0f172a' : 'rgba(15,23,42,0.4)',
          cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1,
          transition: 'border-color var(--dtb-motion-fast), box-shadow var(--dtb-motion-fast)',
          minWidth: '160px', whiteSpace: 'nowrap',
        }}
      >
        <span style={{ flex: 1, minWidth: 0, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          {selectedOption ? selectedOption.label : placeholder}
        </span>
        <Motion.span
          animate={{ rotate: isOpen ? 180 : 0 }}
          transition={indicatorTransition}
          style={{ display: 'flex', flexShrink: 0 }}
        >
          <ChevronDown size={15} style={{ color: 'rgba(15,23,42,0.45)' }} />
        </Motion.span>
      </button>

      <AnimatePresence>
        {isOpen && (
          <Motion.div
            role="listbox"
            initial={{ opacity: 0, y: -8, scale: 0.985 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -4, scale: 0.992 }}
            transition={dtbTransition.standard}
            style={{
              position: 'absolute', top: 'calc(100% + 6px)', left: 0,
              minWidth: '100%', width: fullWidth ? '100%' : undefined,
              background: 'white', border: '1px solid rgba(15,23,42,0.09)', borderRadius: '12px',
              boxShadow: '0 8px 24px rgba(15,23,42,0.12), 0 2px 8px rgba(15,23,42,0.06)',
              overflowX: 'hidden', overflowY: 'auto', overscrollBehavior: 'contain',
              WebkitOverflowScrolling: 'touch', maxHeight: 'min(360px, calc(100vh - 180px))',
              zIndex: 10000, padding: '4px',
            }}
          >
            {visibleOptions.map((option) => {
              const isSelected = option.value === value;
              return (
                <button
                  key={option.value}
                  type="button"
                  role="option"
                  aria-selected={isSelected}
                  onClick={() => select(option.value)}
                  style={{
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px',
                    width: '100%', padding: '9px 12px', borderRadius: '8px', border: 'none',
                    background: isSelected ? 'rgba(34,85,238,0.07)' : 'transparent', cursor: 'pointer',
                    textAlign: 'left', transition: 'background-color var(--dtb-motion-fast)',
                  }}
                  onMouseEnter={(e) => { if (!isSelected) e.currentTarget.style.background = 'rgba(15,23,42,0.04)'; }}
                  onMouseLeave={(e) => { if (!isSelected) e.currentTarget.style.background = 'transparent'; }}
                >
                  <span style={{ minWidth: 0 }}>
                    <span style={{
                      display: 'block', fontSize: '0.875rem', fontWeight: isSelected ? 700 : 500,
                      color: isSelected ? 'var(--primary-700)' : '#0f172a', lineHeight: 1.3,
                      overflowWrap: 'anywhere',
                    }}>
                      {option.label}
                    </span>
                    {option.description && (
                      <span style={{
                        display: 'block', fontSize: '0.72rem', color: 'rgba(15,23,42,0.45)',
                        marginTop: '1px', lineHeight: 1.35,
                      }}>
                        {option.description}
                      </span>
                    )}
                  </span>
                  {isSelected && (
                    <span style={{ color: 'var(--primary-600)', flexShrink: 0 }}><Check size={14} /></span>
                  )}
                </button>
              );
            })}
          </Motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

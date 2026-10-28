/**
 * ui/Accordion.jsx — Reusable animated accordion component.
 */

import { useState } from 'react';
import { AnimatePresence, m as Motion, useReducedMotion } from 'framer-motion';
import { collapseTransition, reducedTransition } from '../../motion/dtbMotion.js';

function AccordionItem({ item, isOpen, onToggle, isMobile = false, variant = 'default' }) {
  const [hovered, setHovered] = useState(false);
  const reduceMotion = useReducedMotion();

  return (
    <div
      className={variant === 'cards' ? 'dtb-accordion__item dtb-accordion__item--card' : 'dtb-accordion__item'}
      data-open={isOpen}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      style={variant === 'cards' ? {
        border: '1px solid var(--machined-border)',
        borderRadius: '8px',
        paddingInline: '0',
        background: hovered && !isOpen ? 'rgba(34,85,238,0.025)' : '#fff',
        transition: 'border-color var(--dtb-motion-standard), background-color var(--dtb-motion-fast)',
      } : {
        borderBottom: '1px solid var(--machined-border)',
        borderLeft: `2px solid ${isOpen ? 'var(--primary-600, #2255ee)' : 'transparent'}`,
        paddingLeft: isOpen ? '14px' : '0',
        transition: 'border-left-color var(--dtb-motion-standard), padding-left var(--dtb-motion-standard), background-color var(--dtb-motion-fast)',
        background: hovered && !isOpen ? 'rgba(34,85,238,0.025)' : 'transparent',
      }}
    >
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
        style={{
          width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          gap: '16px', padding: '20px 0', background: 'none', border: 'none', cursor: 'pointer', textAlign: 'left',
        }}
      >
        <span style={{
          fontSize: 'clamp(0.9rem, 2vw, 0.985rem)', fontWeight: 700,
          color: isOpen ? 'var(--primary-700, #2255ee)' : '#0f172a', lineHeight: 1.45,
          transition: 'color var(--dtb-motion-fast)',
        }}>
          {item.question}
        </span>

        <span
          aria-hidden="true"
          style={{
            flexShrink: 0, width: '26px', height: '26px', borderRadius: '7px',
            border: `1px solid ${isOpen ? 'rgba(34,85,238,0.35)' : 'var(--machined-border)'}`,
            background: isOpen ? 'rgba(34,85,238,0.10)' : 'rgba(15,23,42,0.04)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            transition: 'background-color var(--dtb-motion-fast), border-color var(--dtb-motion-fast)',
          }}
        >
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" style={{ overflow: 'visible' }}>
            <line
              x1="2" y1="6" x2="10" y2="6"
              stroke={isOpen ? '#2255ee' : '#64748b'} strokeWidth="1.6" strokeLinecap="round"
              style={{ transition: 'stroke var(--dtb-motion-fast)' }}
            />
            <line
              x1="6" y1="2" x2="6" y2="10"
              stroke={isOpen ? '#2255ee' : '#64748b'} strokeWidth="1.6" strokeLinecap="round"
              style={{
                transform: isOpen ? 'scaleY(0)' : 'scaleY(1)', transformOrigin: '6px 6px',
                transition: 'transform var(--dtb-motion-standard), stroke var(--dtb-motion-fast)',
              }}
            />
          </svg>
        </span>
      </button>

      <AnimatePresence initial={false}>
        {isOpen && (
          <Motion.div
            key="answer"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={reduceMotion ? reducedTransition : collapseTransition}
            style={{ overflow: 'hidden' }}
          >
            <p style={{
              margin: '0 0 22px 0', fontSize: 'clamp(0.86rem, 2vw, 0.94rem)',
              color: 'rgba(15,23,42,0.62)', lineHeight: 1.72, paddingRight: isMobile ? '0' : '40px',
            }}>
              {item.answer}
            </p>
          </Motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

export default function Accordion({
  items = [], defaultOpen = null, multi = false, isMobile = false, className = '', style = {}, variant = 'default',
}) {
  const [openMap, setOpenMap] = useState(() => (defaultOpen ? { [defaultOpen]: true } : {}));

  const toggle = (id) => {
    setOpenMap((prev) => {
      if (multi) return { ...prev, [id]: !prev[id] };
      return { [id]: !prev[id] };
    });
  };

  return (
    <div className={`${className} ${variant === 'cards' ? 'dtb-accordion dtb-accordion--cards' : 'dtb-accordion'}`} style={{ borderTop: variant === 'cards' ? 0 : '1px solid var(--machined-border)', ...style }}>
      {items.map((item) => (
        <AccordionItem
          key={item.id}
          item={item}
          isOpen={!!openMap[item.id]}
          onToggle={() => toggle(item.id)}
          isMobile={isMobile}
          variant={variant}
        />
      ))}
    </div>
  );
}

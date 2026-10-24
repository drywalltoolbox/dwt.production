/**
 * ui/NavbarTabs.jsx — animated tab navigation shared by desktop and mobile.
 */

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { indicatorTransition, microInteractionTransition, reducedTransition } from '../../motion/dtbMotion.js';

const DRAG_THRESHOLD_PX = 10;

function getActiveButton(container, activeIndex) {
  if (!container) return null;
  return container.querySelectorAll('.dtb-navtab-btn')?.[activeIndex] || null;
}

function calculateIndicatorStyle(container, activeIndex) {
  const active = getActiveButton(container, activeIndex);
  if (!container || !active) return { left: 0, width: 0 };

  const containerRect = container.getBoundingClientRect();
  const btnRect = active.getBoundingClientRect();

  return {
    left: btnRect.left - containerRect.left + container.scrollLeft,
    width: btnRect.width,
  };
}

export default function NavbarTabs({ tabs = [], activeIndex = 0, onChange, className = '', style = {} }) {
  const reduceMotion = useReducedMotion();
  const containerRef = useRef(null);
  const pointerStartRef = useRef({ x: 0, y: 0 });
  const pointerDraggingRef = useRef(false);
  const suppressClickRef = useRef(false);
  const [indicatorStyle, setIndicatorStyle] = useState({ left: 0, width: 0 });

  const syncIndicator = useCallback(() => {
    setIndicatorStyle(calculateIndicatorStyle(containerRef.current, activeIndex));
  }, [activeIndex]);

  const scrollActiveTabIntoView = useCallback(() => {
    const container = containerRef.current;
    const active = getActiveButton(container, activeIndex);
    if (!container || !active) return;

    const targetLeft = active.offsetLeft - (container.clientWidth - active.offsetWidth) / 2;
    const maxLeft = Math.max(0, container.scrollWidth - container.clientWidth);
    const nextLeft = Math.min(Math.max(0, targetLeft), maxLeft);
    container.scrollTo({ left: nextLeft, behavior: reduceMotion ? 'auto' : 'smooth' });
  }, [activeIndex, reduceMotion]);

  useLayoutEffect(() => {
    const container = containerRef.current;
    if (!container) return undefined;

    const frame = window.requestAnimationFrame(() => {
      syncIndicator();
      scrollActiveTabIntoView();
    });

    const handleResize = () => syncIndicator();
    const handleScroll = () => syncIndicator();

    window.addEventListener('resize', handleResize);
    container.addEventListener('scroll', handleScroll, { passive: true });

    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener('resize', handleResize);
      container.removeEventListener('scroll', handleScroll);
    };
  }, [tabs, syncIndicator, scrollActiveTabIntoView]);

  const handlePointerDown = useCallback((event) => {
    pointerStartRef.current = { x: event.clientX, y: event.clientY };
    pointerDraggingRef.current = false;
    suppressClickRef.current = false;
  }, []);

  const handlePointerMove = useCallback((event) => {
    const deltaX = Math.abs(event.clientX - pointerStartRef.current.x);
    const deltaY = Math.abs(event.clientY - pointerStartRef.current.y);
    if (deltaX > DRAG_THRESHOLD_PX && deltaX > deltaY) {
      pointerDraggingRef.current = true;
      suppressClickRef.current = true;
    }
  }, []);

  const handlePointerUp = useCallback(() => {
    if (!pointerDraggingRef.current) {
      suppressClickRef.current = false;
      return;
    }
    window.setTimeout(() => {
      pointerDraggingRef.current = false;
      suppressClickRef.current = false;
    }, 0);
  }, []);

  const handleTabClick = useCallback((event, index) => {
    if (suppressClickRef.current) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    onChange?.(index);
  }, [onChange]);

  return (
    <div
      className={`dtb-navtabs${className ? ` ${className}` : ''}`}
      style={{
        background: 'white', borderRadius: '14px', boxShadow: '0 2px 12px rgba(15,23,42,0.07)',
        border: '1px solid rgba(15,23,42,0.07)', padding: '5px', overflow: 'hidden', ...style,
      }}
    >
      <div
        ref={containerRef}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerUp}
        style={{
          position: 'relative', display: 'flex', overflowX: 'auto', overflowY: 'hidden', gap: '2px',
          scrollbarWidth: 'none', WebkitOverflowScrolling: 'touch', touchAction: 'pan-x pan-y',
          overscrollBehaviorX: 'contain', scrollBehavior: reduceMotion ? 'auto' : 'smooth',
        }}
        className="dtb-navtabs-inner scrollbar-none"
      >
        <Motion.div
          className="dtb-navtab-indicator"
          animate={indicatorStyle}
          transition={reduceMotion ? reducedTransition : indicatorTransition}
          style={{
            position: 'absolute', top: 0, height: '100%',
            background: 'linear-gradient(135deg, var(--primary-600) 0%, var(--primary-700) 100%)',
            borderRadius: '10px', boxShadow: '0 2px 10px rgba(34,85,238,0.35)',
            zIndex: 0, pointerEvents: 'none',
          }}
        />

        {tabs.map((tab, index) => {
          const isActive = activeIndex === index;
          const Icon = tab.icon;
          return (
            <Motion.button
              key={tab.id}
              type="button"
              className="dtb-navtab-btn"
              aria-current={isActive ? "page" : undefined}
              onClick={(event) => handleTabClick(event, index)}
              whileTap={reduceMotion ? undefined : { scale: 0.975 }}
              transition={reduceMotion ? reducedTransition : microInteractionTransition}
              style={{
                position: 'relative', zIndex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center',
                gap: '6px', padding: '8px 15px', borderRadius: '10px', border: 'none', fontSize: '0.83rem',
                fontWeight: isActive ? 700 : 500, whiteSpace: 'nowrap', flex: '0 0 auto', cursor: 'pointer',
                background: 'transparent', color: isActive ? 'white' : 'rgba(15,23,42,0.5)',
                transition: 'color var(--dtb-motion-fast)', userSelect: 'none', WebkitUserSelect: 'none',
              }}
            >
              {Icon && (
                <Icon
                  size={14}
                  style={{ opacity: isActive ? 1 : 0.65, transition: 'opacity var(--dtb-motion-fast)', flexShrink: 0 }}
                />
              )}
              <span className="dtb-navtab-label">{tab.label}</span>
            </Motion.button>
          );
        })}
      </div>
    </div>
  );
}

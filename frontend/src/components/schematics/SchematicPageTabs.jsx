/**
 * frontend/src/components/schematics/SchematicPageTabs.jsx
 *
 * Full ARIA tabs pattern for page switching: Left/Right (or Up/Down) moves
 * focus and selection, Home/End jump to the first/last page, Enter/Space
 * activates. Page labels are rendered straight from React state.
 */
import { useRef } from 'react';

export default function SchematicPageTabs({ pages, activePageId, onSelectPage }) {
  const tabRefs = useRef(new Map());

  if (!pages || pages.length <= 1) return null;

  function focusTabAt(index) {
    const page = pages[index];
    if (!page) return;
    const node = tabRefs.current.get(page.page_id);
    node?.focus();
    onSelectPage(page.page_id);
  }

  function handleKeyDown(event, index) {
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        event.preventDefault();
        focusTabAt((index + 1) % pages.length);
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault();
        focusTabAt((index - 1 + pages.length) % pages.length);
        break;
      case 'Home':
        event.preventDefault();
        focusTabAt(0);
        break;
      case 'End':
        event.preventDefault();
        focusTabAt(pages.length - 1);
        break;
      default:
        break;
    }
  }

  return (
    <div className="dtb-schematic-page-tabs" role="tablist" aria-label="Schematic page selector">
      {pages.map((page, index) => {
        const selected = page.page_id === activePageId;
        return (
          <button
            key={page.page_id}
            ref={(node) => {
              if (node) tabRefs.current.set(page.page_id, node);
              else tabRefs.current.delete(page.page_id);
            }}
            type="button"
            role="tab"
            id={`dtb-schematic-tab-${page.page_id}`}
            aria-selected={selected}
            aria-controls={`dtb-schematic-panel-${page.page_id}`}
            tabIndex={selected ? 0 : -1}
            className={`dtb-schematic-page-tab${selected ? ' dtb-schematic-page-tab--active' : ''}`}
            onClick={() => onSelectPage(page.page_id)}
            onKeyDown={(event) => handleKeyDown(event, index)}
          >
            {page.label || `Page ${page.page_number}`}
          </button>
        );
      })}
    </div>
  );
}

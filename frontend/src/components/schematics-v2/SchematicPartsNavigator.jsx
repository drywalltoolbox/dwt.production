import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronRight, List, Search, X } from 'lucide-react';
import { normalizePartRef } from '../../utils/string.js';

const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), a[href]';

function pageLocations(occurrences) {
  const locations = new Map();
  for (const occurrence of occurrences) {
    const key = normalizePartRef(occurrence.part_ref);
    if (!key || !occurrence.hotspot_id) continue;
    const existing = locations.get(key) || [];
    existing.push(occurrence.hotspot_id);
    locations.set(key, existing);
  }
  return locations;
}

export default function SchematicPartsNavigator({ parts, occurrences, activePage, activePartRef, focusedHotspotId, open, onClose, onSelect }) {
  const [query, setQuery] = useState('');
  const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 900px)').matches);
  const [drag, setDrag] = useState(0);
  const sheetRef = useRef(null);
  const startY = useRef(null);
  const opener = useRef(null);
  const locations = useMemo(() => pageLocations(occurrences), [occurrences]);
  const rows = useMemo(() => parts.filter((part) => {
    const value = `${part.part_ref || ''} ${part.title || ''} ${part.sku || ''} ${part.mpn || ''}`.toLocaleLowerCase();
    return value.includes(query.trim().toLocaleLowerCase());
  }), [parts, query]);

  useEffect(() => { setQuery(''); }, [activePage?.page_id]);

  useEffect(() => {
    const media = window.matchMedia('(max-width: 900px)');
    const update = () => setMobile(media.matches);
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);

  useEffect(() => {
    if (!mobile || !open) return undefined;
    opener.current = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    sheetRef.current?.querySelector('.dtb-parts-nav__close')?.focus({ preventScroll: true });
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
      }
      if (event.key !== 'Tab' || !sheetRef.current) return;
      const controls = Array.from(sheetRef.current.querySelectorAll(FOCUSABLE));
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (!first) return;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKeyDown);
      if (opener.current?.isConnected) opener.current.focus({ preventScroll: true });
    };
  }, [mobile, open, onClose]);

  const onPointerDown = (event) => {
    if (!mobile || !open || !event.target.closest('.dtb-parts-nav__grabber')) return;
    startY.current = event.clientY;
    event.currentTarget.setPointerCapture(event.pointerId);
  };
  const onPointerMove = (event) => {
    if (startY.current === null) return;
    setDrag(Math.max(0, event.clientY - startY.current));
  };
  const onPointerUp = () => {
    if (startY.current !== null && drag > 96) onClose();
    startY.current = null;
    setDrag(0);
  };

  return (
    <>
      {mobile && open && <button type="button" className="dtb-parts-nav__backdrop" aria-label="Close parts list" onClick={onClose} />}
      <aside
        ref={sheetRef}
        id="dtb-schematic-parts"
        className={`dtb-parts-nav${mobile && open ? ' dtb-parts-nav--open' : ''}`}
        role={mobile ? 'dialog' : 'region'}
        aria-modal={mobile && open ? 'true' : undefined}
        aria-labelledby="dtb-parts-nav-heading"
        inert={mobile && !open ? true : undefined}
        style={mobile && drag ? { transform: `translateY(${drag}px)`, transition: 'none' } : undefined}
      >
        <div className="dtb-parts-nav__grab-area" onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={() => { startY.current = null; setDrag(0); }}>
          <span className="dtb-parts-nav__grabber" aria-hidden="true" />
          <div className="dtb-parts-nav__heading-row">
            <div>
              <span className="dtb-parts-nav__eyebrow">SCHEMATIC INDEX</span>
              <h2 id="dtb-parts-nav-heading">Master parts list</h2>
            </div>
            <button type="button" className="dtb-parts-nav__close" aria-label="Close parts list" onClick={onClose}><X size={20} /></button>
          </div>
          <p className="dtb-parts-nav__count"><List size={15} aria-hidden="true" /> {parts.length} {parts.length === 1 ? 'part' : 'parts'} on this page</p>
        </div>
        <label className="dtb-parts-nav__search">
          <Search size={18} aria-hidden="true" />
          <span className="sr-only">Search parts by name or number</span>
          <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search name or part number" />
        </label>
        <div className="dtb-parts-nav__scroll">
          {rows.length ? <ol className="dtb-parts-nav__list">
            {rows.map((part, index) => {
              const refs = locations.get(normalizePartRef(part.part_ref)) || [];
              const focusedIndex = refs.indexOf(focusedHotspotId);
              const nextIndex = focusedIndex >= 0 ? (focusedIndex + 1) % refs.length : 0;
              const targetHotspotId = refs[nextIndex] || null;
              const key = part.part_ref || `part-${index}`;
              const selected = activePartRef && normalizePartRef(activePartRef) === normalizePartRef(part.part_ref);
              const image = part.product?.images?.[0];
              return <li key={key}>
                <button
                  type="button"
                  className={`dtb-parts-nav__item${selected ? ' dtb-parts-nav__item--selected' : ''}`}
                  aria-label={`View ${part.title || 'part'} ${part.part_ref || ''}${refs.length > 1 ? ` (${refs.length} locations; select again for next)` : ''}`}
                  aria-current={selected ? 'true' : undefined}
                  onClick={() => onSelect(part.part_ref, targetHotspotId)}
                >
                  <span className="dtb-parts-nav__media">
                    {typeof image === 'string' && image ? <img src={image} alt="" loading="lazy" /> : <span className="dtb-parts-nav__media-fallback" aria-hidden="true">PART</span>}
                    <span className="dtb-parts-nav__number">{part.part_ref || '—'}</span>
                  </span>
                  <span className="dtb-parts-nav__item-body">
                    <strong>{part.title || `Part ${part.part_ref || ''}`}</strong>
                    <small>{refs.length ? `${refs.length} ${refs.length === 1 ? 'location' : 'locations'} on page ${activePage?.page_number || ''}` : 'Reference only'}{part.product?.id ? ' · Product linked' : ''}</small>
                  </span>
                  <ChevronRight size={17} aria-hidden="true" />
                </button>
              </li>;
            })}
          </ol> : <p className="dtb-parts-nav__empty" role="status">No matching parts. Try another name or number.</p>}
        </div>
        <p className="dtb-parts-nav__footer">Select a part to view its details and diagram location.</p>
      </aside>
    </>
  );
}

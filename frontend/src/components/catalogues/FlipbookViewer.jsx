import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useReducedMotion } from 'framer-motion';
import {
  BookOpen,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  Expand,
  List,
  Maximize2,
  Minimize2,
  RotateCcw,
  ZoomIn,
  ZoomOut,
} from 'lucide-react';
import { cataloguePageUrl } from '../../data/catalogues.js';

const FLIP_MS = 600;
const ZOOM_LEVELS = [1, 1.5, 2, 3];

/** Spreads: landscape catalogues open with the cover alone, then pairs;
 *  portrait catalogues turn one page at a time. */
function buildSpreads(catalogue) {
  const spreads = [];
  if (catalogue.orientation === 'landscape') {
    spreads.push([1]);
    for (let page = 2; page <= catalogue.pageCount; page += 2) {
      spreads.push(page + 1 <= catalogue.pageCount ? [page, page + 1] : [page]);
    }
    return spreads;
  }
  for (let page = 1; page <= catalogue.pageCount; page += 1) {
    spreads.push([page]);
  }
  return spreads;
}

function spreadIndexForPage(spreads, page) {
  return spreads.findIndex((pages) => pages.includes(page));
}

export default function FlipbookViewer({ catalogue }) {
  const reduceMotion = useReducedMotion();
  const spreads = useMemo(() => buildSpreads(catalogue), [catalogue]);
  const [spreadIndex, setSpreadIndex] = useState(0);
  const [flipping, setFlipping] = useState(null); // { to, direction } | null
  const [zoomIndex, setZoomIndex] = useState(0);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [fullscreenNotice, setFullscreenNotice] = useState('');
  const [showContents, setShowContents] = useState(false);
  const [showThumbs, setShowThumbs] = useState(false);
  const [jumpValue, setJumpValue] = useState('');
  const [loadedPages, setLoadedPages] = useState(new Set());
  const [failedPages, setFailedPages] = useState(new Set());
  const viewerRef = useRef(null);
  const flipTimerRef = useRef(null);
  const dragRef = useRef(null);
  const suppressClickRef = useRef(false);

  const currentSpread = spreads[spreadIndex] || [1];
  const zoom = ZOOM_LEVELS[zoomIndex];
  const canPrev = spreadIndex > 0;
  const canNext = spreadIndex < spreads.length - 1;

  const clearFlipTimer = useCallback(() => {
    if (flipTimerRef.current) {
      window.clearTimeout(flipTimerRef.current);
      flipTimerRef.current = null;
    }
  }, []);

  useEffect(() => clearFlipTimer, [clearFlipTimer]);

  const goToSpread = useCallback(
    (target) => {
      const next = Math.max(0, Math.min(spreads.length - 1, target));
      if (next === spreadIndex || flipping) return;
      const direction = next > spreadIndex ? 'next' : 'prev';
      if (reduceMotion) {
        setSpreadIndex(next);
        return;
      }
      setFlipping({ to: next, direction });
      clearFlipTimer();
      flipTimerRef.current = window.setTimeout(() => {
        setSpreadIndex(next);
        setFlipping(null);
        flipTimerRef.current = null;
      }, FLIP_MS);
    },
    [clearFlipTimer, flipping, reduceMotion, spreadIndex, spreads.length],
  );

  const goToPage = useCallback(
    (page) => {
      const index = spreadIndexForPage(spreads, page);
      if (index >= 0) goToSpread(index);
    },
    [goToSpread, spreads],
  );

  const goNext = useCallback(() => goToSpread(spreadIndex + 1), [goToSpread, spreadIndex]);
  const goPrev = useCallback(() => goToSpread(spreadIndex - 1), [goToSpread, spreadIndex]);

  // Keyboard navigation. Ignored while typing in a form field.
  useEffect(() => {
    const onKeyDown = (event) => {
      const target = event.target;
      if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
      if (event.key === 'ArrowRight') { event.preventDefault(); goNext(); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); goPrev(); }
      if (event.key === 'Home') { event.preventDefault(); goToSpread(0); }
      if (event.key === 'End') { event.preventDefault(); goToSpread(spreads.length - 1); }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [goNext, goPrev, goToSpread, spreads.length]);

  // Preload the next spread only — never a fetch-per-item storm.
  useEffect(() => {
    const nextSpread = spreads[spreadIndex + 1];
    if (!nextSpread) return undefined;
    const images = nextSpread.map((page) => {
      const image = new Image();
      image.src = cataloguePageUrl(catalogue, page);
      return image;
    });
    return () => { images.forEach((image) => { image.src = ''; }); };
  }, [catalogue, spreadIndex, spreads]);

  useEffect(() => {
    const onFullscreenChange = () => setIsFullscreen(Boolean(document.fullscreenElement));
    document.addEventListener('fullscreenchange', onFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', onFullscreenChange);
  }, []);

  const toggleFullscreen = useCallback(async () => {
    const element = viewerRef.current;
    if (!element) return;
    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen();
      } else if (element.requestFullscreen) {
        await element.requestFullscreen();
        setFullscreenNotice('');
      } else {
        setFullscreenNotice('Fullscreen is not available in this browser.');
      }
    } catch {
      setFullscreenNotice('Fullscreen could not be started in this browser.');
    }
  }, []);

  const markLoaded = useCallback((page) => {
    setLoadedPages((prev) => (prev.has(page) ? prev : new Set(prev).add(page)));
  }, []);

  const markFailed = useCallback((page) => {
    setFailedPages((prev) => (prev.has(page) ? prev : new Set(prev).add(page)));
  }, []);

  const retryPage = useCallback((page) => {
    setFailedPages((prev) => {
      const next = new Set(prev);
      next.delete(page);
      return next;
    });
  }, []);

  const onPointerDown = (event) => {
    dragRef.current = { x: event.clientX, y: event.clientY, moved: false };
  };

  const onPointerMove = (event) => {
    const drag = dragRef.current;
    if (!drag) return;
    if (Math.abs(event.clientX - drag.x) > 10 || Math.abs(event.clientY - drag.y) > 10) {
      drag.moved = true;
    }
  };

  const onPointerUp = (event) => {
    const drag = dragRef.current;
    dragRef.current = null;
    if (!drag) return;
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    if (drag.moved) {
      // A drag ends with a click event; suppress it so it cannot also turn a page.
      suppressClickRef.current = true;
      window.setTimeout(() => { suppressClickRef.current = false; }, 80);
    }
    if (Math.abs(dx) > 48 && Math.abs(dy) < 80) {
      if (dx < 0) goNext();
      else goPrev();
    }
  };

  // Click zones: left third turns back, right third turns forward.
  const onSpreadClick = (event) => {
    if (suppressClickRef.current) return;
    if (event.target.closest('button, a, input')) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;
    if (ratio > 0.66) goNext();
    else if (ratio < 0.34) goPrev();
  };

  const submitJump = (event) => {
    event.preventDefault();
    const page = Number.parseInt(jumpValue, 10);
    if (Number.isInteger(page) && page >= 1 && page <= catalogue.pageCount) {
      goToPage(page);
      setJumpValue('');
    }
  };

  const spreadLabel = currentSpread.length > 1
    ? `Pages ${currentSpread[0]}–${currentSpread[currentSpread.length - 1]} of ${catalogue.pageCount}`
    : `Page ${currentSpread[0]} of ${catalogue.pageCount}`;

  const flippingSpread = flipping ? spreads[flipping.to] : null;
  const zoomLabel = `${Math.round(zoom * 100)}% zoom`;

  const renderPage = (page) => {
    if (failedPages.has(page)) {
      return (
        <div className="flipbook__page flipbook__page--error" role="alert" key={page}>
          <p>Page {page} did not load.</p>
          <button type="button" onClick={() => retryPage(page)}>Try again</button>
        </div>
      );
    }
    return (
      <img
        key={page}
        className="flipbook__page"
        src={cataloguePageUrl(catalogue, page)}
        alt={`${catalogue.title}, page ${page} of ${catalogue.pageCount}`}
        loading={spreadIndex === 0 && page <= 2 ? 'eager' : 'lazy'}
        decoding="async"
        onLoad={() => markLoaded(page)}
        onError={() => markFailed(page)}
      />
    );
  };

  const spreadLoaded = currentSpread.every((page) => loadedPages.has(page));

  return (
    <section className="flipbook" ref={viewerRef} aria-label={`${catalogue.title} flipbook`}>
      <div className="flipbook__toolbar" role="toolbar" aria-label="Flipbook controls">
        <div className="flipbook__toolbar-group">
          <button type="button" onClick={() => goToSpread(0)} disabled={!canPrev} aria-label="First page">
            <ChevronsLeft size={20} aria-hidden="true" />
          </button>
          <button type="button" onClick={goPrev} disabled={!canPrev} aria-label="Previous page">
            <ChevronLeft size={20} aria-hidden="true" />
          </button>
          <p className="flipbook__status" aria-live="polite">{spreadLabel}</p>
          <button type="button" onClick={goNext} disabled={!canNext} aria-label="Next page">
            <ChevronRight size={20} aria-hidden="true" />
          </button>
          <button type="button" onClick={() => goToSpread(spreads.length - 1)} disabled={!canNext} aria-label="Last page">
            <ChevronsRight size={20} aria-hidden="true" />
          </button>
        </div>
        <div className="flipbook__toolbar-group">
          {catalogue.contents.length > 0 && (
            <button type="button" onClick={() => setShowContents((prev) => !prev)} aria-expanded={showContents} aria-controls="flipbook-contents">
              <List size={18} aria-hidden="true" /> Contents
            </button>
          )}
          <button type="button" onClick={() => setShowThumbs((prev) => !prev)} aria-expanded={showThumbs} aria-controls="flipbook-thumbnails">
            <BookOpen size={18} aria-hidden="true" /> Pages
          </button>
          <button type="button" onClick={() => setZoomIndex((prev) => Math.max(0, prev - 1))} disabled={zoomIndex === 0} aria-label="Zoom out">
            <ZoomOut size={18} aria-hidden="true" />
          </button>
          <span className="flipbook__zoom-label" aria-label={zoomLabel}>{Math.round(zoom * 100)}%</span>
          <button type="button" onClick={() => setZoomIndex((prev) => Math.min(ZOOM_LEVELS.length - 1, prev + 1))} disabled={zoomIndex === ZOOM_LEVELS.length - 1} aria-label="Zoom in">
            <ZoomIn size={18} aria-hidden="true" />
          </button>
          <button type="button" onClick={toggleFullscreen} aria-label={isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}>
            {isFullscreen ? <Minimize2 size={18} aria-hidden="true" /> : <Maximize2 size={18} aria-hidden="true" />}
            <span className="flipbook__button-text">{isFullscreen ? 'Exit' : 'Fullscreen'}</span>
          </button>
        </div>
      </div>

      {fullscreenNotice && <p className="flipbook__notice" role="status">{fullscreenNotice}</p>}

      <form className="flipbook__jump" onSubmit={submitJump}>
        <label htmlFor="flipbook-jump">Go to page</label>
        <input
          id="flipbook-jump"
          type="number"
          min={1}
          max={catalogue.pageCount}
          value={jumpValue}
          onChange={(event) => setJumpValue(event.target.value)}
          placeholder={`1–${catalogue.pageCount}`}
          inputMode="numeric"
        />
        <button type="submit">Go</button>
        <span className="flipbook__jump-hint">Use ← → arrow keys to turn pages.</span>
      </form>

      {showContents && catalogue.contents.length > 0 && (
        <nav id="flipbook-contents" className="flipbook__contents" aria-label="Catalogue contents">
          <h2>Contents</h2>
          <ul>
            {catalogue.contents.map((item) => (
              <li key={item.label}>
                <button type="button" onClick={() => { goToPage(item.page); setShowContents(false); }}>
                  <span>{item.label}</span>
                  <span>Page {item.page}</span>
                </button>
              </li>
            ))}
          </ul>
        </nav>
      )}

      <div className="flipbook__stage" style={{ overflow: zoom > 1 ? 'auto' : 'hidden' }}>
        <div
          className={`flipbook__book flipbook__book--${catalogue.orientation}${flipping ? ` flipbook__book--flipping flipbook__book--flipping-${flipping.direction}` : ''}`}
          style={{ transform: `scale(${zoom})` }}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onClick={onSpreadClick}
          role="region"
          aria-label="Catalogue pages. Drag, swipe, or tap the left or right side to turn pages."
        >
          {!spreadLoaded && !currentSpread.some((page) => failedPages.has(page)) && (
            <p className="flipbook__loading" role="status">Loading pages…</p>
          )}
          {currentSpread.map((page) => renderPage(page))}
          {flipping && flippingSpread && (
            <div className="flipbook__leaf" aria-hidden="true" key={`leaf-${flipping.to}`}>
              <div className="flipbook__leaf-face flipbook__leaf-face--front">
                {renderPage(currentSpread[currentSpread.length - 1])}
              </div>
              <div className="flipbook__leaf-face flipbook__leaf-face--back">
                {renderPage(flippingSpread[0])}
              </div>
            </div>
          )}
        </div>
      </div>

      <p className="flipbook__hint">
        Drag or swipe to turn pages, tap the left or right side of the book, or use the arrow keys.
        {catalogue.shopLink && (
          <>
            {' '}
            <Link to={catalogue.shopLink}>{catalogue.shopLabel}</Link>
          </>
        )}
      </p>

      {showThumbs && (
        <div id="flipbook-thumbnails" className="flipbook__thumbnails" role="group" aria-label="Page thumbnails">
          {Array.from({ length: catalogue.pageCount }, (_, index) => index + 1).map((page) => {
            const isCurrent = currentSpread.includes(page);
            return (
              <button
                key={page}
                type="button"
                className={isCurrent ? 'flipbook__thumb is-current' : 'flipbook__thumb'}
                aria-current={isCurrent ? 'page' : undefined}
                aria-label={`Go to page ${page}`}
                onClick={() => goToPage(page)}
              >
                <img
                  src={cataloguePageUrl(catalogue, page)}
                  alt=""
                  loading="lazy"
                  decoding="async"
                  width={72}
                />
                <span>{page}</span>
              </button>
            );
          })}
        </div>
      )}

      <div className="flipbook__actions">
        <button type="button" onClick={() => goToSpread(0)}>
          <RotateCcw size={16} aria-hidden="true" /> Back to cover
        </button>
        <Link to="/catalogues">All catalogues</Link>
        {catalogue.shopLink && <Link to={catalogue.shopLink} className="flipbook__shop-link">{catalogue.shopLabel} <Expand size={16} aria-hidden="true" /></Link>}
      </div>
    </section>
  );
}

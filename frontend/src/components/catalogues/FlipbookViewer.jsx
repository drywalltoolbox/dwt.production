import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useReducedMotion } from 'framer-motion';
import { PageFlip } from 'page-flip';
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

const ZOOM_LEVELS = [1, 1.25, 1.5, 2];
const BASE_PAGE_HEIGHT = 720;

function pagesInView(page, orientation, pageCount) {
  if (orientation === 'landscape' && page % 2 === 1 && page + 1 < pageCount) {
    return [page, page + 1];
  }
  return [page];
}

function createPageNode({ catalogue, page, onLoaded, onFailed, onImageRef }) {
  const sheet = document.createElement('div');
  sheet.className = 'flipbook__engine-page';
  if (page === 1 || page === catalogue.pageCount) sheet.dataset.density = 'hard';

  const image = document.createElement('img');
  image.className = 'flipbook__engine-image';
  image.src = cataloguePageUrl(catalogue, page);
  image.alt = `${catalogue.title}, page ${page} of ${catalogue.pageCount}`;
  image.draggable = false;
  image.loading = page <= 4 ? 'eager' : 'lazy';
  image.decoding = 'async';
  image.addEventListener('load', () => onLoaded(page));
  image.addEventListener('error', () => {
    image.classList.add('is-failed');
    onFailed(page);
  });
  onImageRef(page, image);
  sheet.appendChild(image);
  if (image.complete) {
    queueMicrotask(() => (image.naturalWidth > 0 ? onLoaded(page) : onFailed(page)));
  }

  return sheet;
}

export default function FlipbookViewer({ catalogue }) {
  const reduceMotion = useReducedMotion();
  const shouldReduceMotion = reduceMotion === true;
  const [currentPage, setCurrentPage] = useState(0);
  const [orientation, setOrientation] = useState('portrait');
  const [coverOffset, setCoverOffset] = useState(0);
  const [zoomIndex, setZoomIndex] = useState(0);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [fullscreenNotice, setFullscreenNotice] = useState('');
  const [showContents, setShowContents] = useState(false);
  const [showThumbs, setShowThumbs] = useState(false);
  const [jumpValue, setJumpValue] = useState('');
  const [loadedPages, setLoadedPages] = useState(() => new Set());
  const [failedPages, setFailedPages] = useState(() => new Set());
  const viewerRef = useRef(null);
  const engineHostRef = useRef(null);
  const pageFlipRef = useRef(null);
  const pageImagesRef = useRef(new Map());

  const zoom = ZOOM_LEVELS[zoomIndex];
  const visiblePages = useMemo(
    () => pagesInView(currentPage, orientation, catalogue.pageCount),
    [catalogue.pageCount, currentPage, orientation],
  );
  const canPrev = currentPage > 0;
  const canNext = currentPage < catalogue.pageCount - 1;
  const pageLabel = visiblePages.length > 1
    ? `Pages ${visiblePages[0] + 1}–${visiblePages[1] + 1} of ${catalogue.pageCount}`
    : `Page ${currentPage + 1} of ${catalogue.pageCount}`;
  const zoomLabel = `${Math.round(zoom * 100)}% zoom`;
  const activePageLoaded = visiblePages.every((page) => loadedPages.has(page + 1));
  const failedVisiblePage = visiblePages.find((page) => failedPages.has(page + 1));
  const activePageFailed = failedVisiblePage !== undefined;

  const markLoaded = useCallback((page) => {
    setLoadedPages((previous) => {
      if (previous.has(page)) return previous;
      const next = new Set(previous);
      next.add(page);
      return next;
    });
    setFailedPages((previous) => {
      if (!previous.has(page)) return previous;
      const next = new Set(previous);
      next.delete(page);
      return next;
    });
  }, []);

  const markFailed = useCallback((page) => {
    setFailedPages((previous) => new Set(previous).add(page));
  }, []);

  const updatePageWindow = useCallback((activePage) => {
    pageImagesRef.current.forEach((image, index) => {
      image.loading = Math.abs(index - activePage) <= 2 ? 'eager' : 'lazy';
    });
  }, []);

  useEffect(() => {
    const host = engineHostRef.current;
    if (!host) return undefined;

    setCurrentPage(0);
    setOrientation('portrait');
    setCoverOffset(0);
    setLoadedPages(new Set());
    setFailedPages(new Set());

    const book = document.createElement('div');
    book.className = 'flipbook__engine-book';
    const pageImages = new Map();
    for (let page = 1; page <= catalogue.pageCount; page += 1) {
      const sheet = createPageNode({
        catalogue,
        page,
        onLoaded: markLoaded,
        onFailed: markFailed,
        onImageRef: (number, image) => pageImages.set(number - 1, image),
      });
      book.appendChild(sheet);
    }
    host.replaceChildren(book);
    pageImagesRef.current = pageImages;

    const ratio = catalogue.pageAspectRatio || 1;
    const baseWidth = Math.round(BASE_PAGE_HEIGHT * ratio);
    let engine;
    let coverObserver;
    const syncCoverOffset = () => {
      if (!engine) return;
      const pageWidth = Number(engine.getBoundsRect()?.pageWidth) || 0;
      setCoverOffset(engine.getOrientation() === 'landscape' ? -pageWidth / 2 : 0);
    };
    try {
      engine = new PageFlip(book, {
        width: baseWidth,
        height: BASE_PAGE_HEIGHT,
        size: 'stretch',
        minWidth: 260,
        maxWidth: Math.round(baseWidth * 2.4),
        minHeight: Math.round(260 / ratio),
        maxHeight: Math.round(BASE_PAGE_HEIGHT * 2.4),
        startPage: 0,
        showCover: true,
        usePortrait: true,
        autoSize: true,
        mobileScrollSupport: false,
        flippingTime: shouldReduceMotion ? 1 : 850,
        drawShadow: true,
        maxShadowOpacity: 0.34,
        swipeDistance: 28,
        clickEventForward: true,
      });
      engine.on('flip', (event) => {
        const page = Number(event.data) || 0;
        setCurrentPage(page);
        updatePageWindow(page);
      });
      engine.on('init', (event) => {
        setCurrentPage(Number(event.data?.page) || 0);
        setOrientation(event.data?.mode || engine.getOrientation());
        updatePageWindow(Number(event.data?.page) || 0);
        requestAnimationFrame(syncCoverOffset);
      });
      engine.on('changeOrientation', (event) => {
        setOrientation(event.data || engine.getOrientation());
        requestAnimationFrame(syncCoverOffset);
      });
      engine.loadFromHTML(book.querySelectorAll('.flipbook__engine-page'));
      pageFlipRef.current = engine;
      coverObserver = new ResizeObserver(syncCoverOffset);
      coverObserver.observe(host);
      requestAnimationFrame(syncCoverOffset);
    } catch {
      host.replaceChildren();
      pageFlipRef.current = null;
      queueMicrotask(() => setFullscreenNotice('The catalogue viewer could not start. Please reload the page.'));
    }

    return () => {
      coverObserver?.disconnect();
      if (engine) {
        engine.off('flip');
        engine.off('init');
        engine.off('changeOrientation');
        engine.destroy();
      }
      if (pageFlipRef.current === engine) pageFlipRef.current = null;
      pageImagesRef.current = new Map();
    };
  }, [catalogue, markFailed, markLoaded, shouldReduceMotion, updatePageWindow]);

  useEffect(() => {
    const onFullscreenChange = () => setIsFullscreen(Boolean(document.fullscreenElement));
    document.addEventListener('fullscreenchange', onFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', onFullscreenChange);
  }, []);

  const goToPage = useCallback((page) => {
    if (!Number.isInteger(page) || page < 1 || page > catalogue.pageCount) return;
    pageFlipRef.current?.turnToPage(page - 1);
  }, [catalogue.pageCount]);

  const goNext = useCallback(() => pageFlipRef.current?.flipNext(), []);
  const goPrev = useCallback(() => pageFlipRef.current?.flipPrev(), []);

  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) return;
      if (event.key === 'ArrowRight') { event.preventDefault(); goNext(); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); goPrev(); }
      if (event.key === 'Home') { event.preventDefault(); goToPage(1); }
      if (event.key === 'End') { event.preventDefault(); goToPage(catalogue.pageCount); }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [catalogue.pageCount, goNext, goPrev, goToPage]);

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

  const retryPage = useCallback((page) => {
    const image = pageImagesRef.current.get(page - 1);
    if (!image) return;
    setFailedPages((previous) => {
      const next = new Set(previous);
      next.delete(page);
      return next;
    });
    image.classList.remove('is-failed');
    image.src = `${cataloguePageUrl(catalogue, page)}?retry=${Date.now()}`;
  }, [catalogue]);

  const submitJump = (event) => {
    event.preventDefault();
    const page = Number.parseInt(jumpValue, 10);
    if (Number.isInteger(page) && page >= 1 && page <= catalogue.pageCount) {
      goToPage(page);
      setJumpValue('');
    }
  };

  return (
    <section className="flipbook" ref={viewerRef} aria-label={`${catalogue.title} flipbook`}>
      <div className="flipbook__toolbar" role="toolbar" aria-label="Flipbook controls">
        <div className="flipbook__toolbar-group">
          <button type="button" onClick={() => goToPage(1)} disabled={!canPrev} aria-label="First page">
            <ChevronsLeft size={20} aria-hidden="true" />
          </button>
          <button type="button" onClick={goPrev} disabled={!canPrev} aria-label="Previous page">
            <ChevronLeft size={20} aria-hidden="true" />
          </button>
          <p className="flipbook__status" aria-live="polite">{pageLabel}</p>
          <button type="button" onClick={goNext} disabled={!canNext} aria-label="Next page">
            <ChevronRight size={20} aria-hidden="true" />
          </button>
          <button type="button" onClick={() => goToPage(catalogue.pageCount)} disabled={!canNext} aria-label="Last page">
            <ChevronsRight size={20} aria-hidden="true" />
          </button>
        </div>
        <div className="flipbook__toolbar-group">
          {catalogue.contents.length > 0 && (
            <button type="button" onClick={() => setShowContents((previous) => !previous)} aria-expanded={showContents} aria-controls="flipbook-contents">
              <List size={18} aria-hidden="true" /> <span className="flipbook__button-text">Contents</span>
            </button>
          )}
          <button type="button" onClick={() => setShowThumbs((previous) => !previous)} aria-expanded={showThumbs} aria-controls="flipbook-thumbnails">
            <BookOpen size={18} aria-hidden="true" /> <span className="flipbook__button-text">Pages</span>
          </button>
          <button type="button" onClick={() => setZoomIndex((previous) => Math.max(0, previous - 1))} disabled={zoomIndex === 0} aria-label="Zoom out">
            <ZoomOut size={18} aria-hidden="true" />
          </button>
          <span className="flipbook__zoom-label" aria-label={zoomLabel}>{Math.round(zoom * 100)}%</span>
          <button type="button" onClick={() => setZoomIndex((previous) => Math.min(ZOOM_LEVELS.length - 1, previous + 1))} disabled={zoomIndex === ZOOM_LEVELS.length - 1} aria-label="Zoom in">
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
                  <span>{item.label}</span><span>Page {item.page}</span>
                </button>
              </li>
            ))}
          </ul>
        </nav>
      )}

      <div className={`flipbook__stage${zoom > 1 ? ' is-zoomed' : ''}${currentPage === 0 && orientation === 'landscape' ? ' is-cover' : ''}`}>
        <div className="flipbook__book-host" ref={engineHostRef} style={{ transform: `scale(${zoom})`, '--cover-offset': `${coverOffset}px` }} />
        {!activePageLoaded && !activePageFailed && <p className="flipbook__loading" role="status">Loading pages…</p>}
        {activePageFailed && (
          <div className="flipbook__error" role="alert">
            <p>Page {failedVisiblePage + 1} did not load.</p>
            <button type="button" onClick={() => retryPage(failedVisiblePage + 1)}>Try again</button>
          </div>
        )}
      </div>

      <p className="flipbook__hint">
        Drag, swipe, or tap a page corner to turn pages. Use the arrow keys to browse.
        {catalogue.shopLink && <>{' '}<Link to={catalogue.shopLink}>{catalogue.shopLabel}</Link></>}
      </p>

      {showThumbs && (
        <div id="flipbook-thumbnails" className="flipbook__thumbnails" role="group" aria-label="Page thumbnails">
          {Array.from({ length: catalogue.pageCount }, (_, index) => index + 1).map((page) => {
            const isCurrent = visiblePages.includes(page - 1);
            return (
              <button
                key={page}
                type="button"
                className={isCurrent ? 'flipbook__thumb is-current' : 'flipbook__thumb'}
                aria-current={isCurrent ? 'page' : undefined}
                aria-label={`Go to page ${page}`}
                onClick={() => goToPage(page)}
              >
                <img src={cataloguePageUrl(catalogue, page)} alt="" loading="lazy" decoding="async" width={72} />
                <span>{page}</span>
              </button>
            );
          })}
        </div>
      )}

      <div className="flipbook__actions">
        <button type="button" onClick={() => goToPage(1)}>
          <RotateCcw size={16} aria-hidden="true" /> Back to cover
        </button>
        <Link to="/catalogues">All catalogues</Link>
        {catalogue.shopLink && <Link to={catalogue.shopLink} className="flipbook__shop-link">{catalogue.shopLabel} <Expand size={16} aria-hidden="true" /></Link>}
      </div>
    </section>
  );
}

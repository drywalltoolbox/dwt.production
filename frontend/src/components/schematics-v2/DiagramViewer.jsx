/**
 * frontend/src/components/schematics-v2/DiagramViewer.jsx
 *
 * Composes DiagramImage + ZoomControls + HotspotLayer and owns desktop
 * (wheel + drag) and touch (pinch + drag) zoom/pan state. Handles explicit
 * missing-page / missing-media / missing-hotspot-data states.
 *
 * Sizing/transform structure ported from the legacy `Schematics.jsx`
 * (docs/_working/frontend-legacy/frontend/src/pages/Schematics.jsx,
 * ~L3792-4077): the diagram is sized with pure CSS `aspect-ratio` on
 * `.schematic-image-bounds` (the hotspot coordinate space) rather than a
 * `ResizeObserver`-measured fixed-px stage, and zoom/pan is a single
 * `transform: scale(...) translate(...)` on `.schematic-image-wrapper`
 * with `transform-origin: center center`. The gesture handling itself
 * (ctrl/cmd+scroll to zoom, pointer-based pinch/pan, not hijacking plain
 * wheel scroll) is a prior, intentional UX fix over the legacy version and
 * is preserved as-is — only the rendering/transform target changed.
 */
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { AlertTriangle, ImageOff } from 'lucide-react';
import DiagramImage from './DiagramImage';
import ZoomControls from './ZoomControls';
import HotspotLayer from './HotspotLayer';
import SchematicPartDialog from './SchematicPartDialog';

const MIN_SCALE = 1;
const MAX_SCALE = 4;

function clampScale(scale) {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale));
}

// Legacy pan clamp used the rendered image/container box (schematicImageRef /
// schematicContainerRef offsetWidth/offsetHeight) — here that's the
// `.schematic-image-bounds` box (imageBoundsRef) sized via aspect-ratio, and
// the outer `.dtb-schematic-diagram` container (containerRef).
function clampOffset(offset, scale, containerSize, boundsSize) {
  if (scale <= 1 || !containerSize || !boundsSize) return { x: 0, y: 0 };
  const maxOffset = {
    x: Math.max(0, ((boundsSize.width * scale) - containerSize.width) / 2),
    y: Math.max(0, ((boundsSize.height * scale) - containerSize.height) / 2),
  };
  return {
    x: Math.min(maxOffset.x, Math.max(-maxOffset.x, offset.x)),
    y: Math.min(maxOffset.y, Math.max(-maxOffset.y, offset.y)),
  };
}

export default function DiagramViewer({ page, parts, schematicContext, onSelectPart, activePart, onCloseActivePart, activePartRef, focusedHotspotId, selectedHotspotId, focusRequest, selectedFromList, partsTrigger }) {
  const containerRef = useRef(null);
  const wrapRef = useRef(null);
  const imgRef = useRef(null);
  const pointers = useRef(new Map());
  const gesture = useRef(null);
  const lastTap = useRef({ time: 0, x: 0, y: 0 });
  const suppressHotspotUntil = useRef(0);

  const imageBoundsRef = useRef(null);

  const [scale, setScale] = useState(1);
  const [offset, setOffset] = useState({ x: 0, y: 0 });
  const [imageLoaded, setImageLoaded] = useState(false);
  const [isGesturing, setIsGesturing] = useState(false);
  // Measured box of `.schematic-image-bounds` — the aspect-ratio-sized,
  // hotspot coordinate-space div (legacy `schematicImageRef.current.offsetWidth/Height`).
  // Only used for pan-clamp math; the div's on-screen size is owned by CSS
  // `aspect-ratio`, not this state.
  const [boundsSize, setBoundsSize] = useState(null);
  const [anchorRect, setAnchorRect] = useState(null);
  const [focusTransition, setFocusTransition] = useState(false);
  const [isMobile, setIsMobile] = useState(
    typeof window !== 'undefined' ? window.innerWidth <= 768 : false,
  );

  useEffect(() => {
    const handleResize = () => setIsMobile(window.innerWidth <= 768);
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const reduceMotion = typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

  // Reset zoom/pan/load state when the active page changes. Adjusted
  // synchronously during render (not in an effect) so the reset is visible
  // on the same paint as the page switch.
  const [prevPageId, setPrevPageId] = useState(page?.page_id);
  if (prevPageId !== page?.page_id) {
    setPrevPageId(page?.page_id);
    setScale(1);
    setOffset({ x: 0, y: 0 });
    setImageLoaded(false);
  }

  // Browser-cached images can complete before the React `onLoad` handler is
  // attached, so the load event never fires and the hotspot layer would stay
  // hidden forever. Back-check `img.complete` after each page switch so the
  // hotspot overlay still mounts for already-cached diagrams.
  useEffect(() => {
    if (imgRef.current?.complete && imgRef.current?.naturalWidth > 0) {
      setImageLoaded(true);
    }
  }, [page?.page_id]);

  // `.schematic-image-bounds` is sized purely by CSS `aspect-ratio` (no
  // ResizeObserver-driven px stage). We still need its rendered box for the
  // pan-clamp math (legacy used `schematicImageRef.current.offsetHeight`),
  // so a lightweight ResizeObserver tracks it without owning layout.
  const measureBounds = useCallback(() => {
    const bounds = imageBoundsRef.current;
    if (!bounds) return;
    const nextBoundsSize = { width: bounds.offsetWidth, height: bounds.offsetHeight };
    setBoundsSize((current) => (
      current?.width === nextBoundsSize.width && current?.height === nextBoundsSize.height
        ? current
        : nextBoundsSize
    ));
    const containerSize = containerRef.current
      ? { width: containerRef.current.clientWidth, height: containerRef.current.clientHeight }
      : null;
    setOffset((current) => clampOffset(current, scale, containerSize, nextBoundsSize));
  }, [scale]);

  useEffect(() => {
    measureBounds();
    const bounds = imageBoundsRef.current;
    if (!bounds) return undefined;

    if (typeof ResizeObserver !== 'undefined') {
      const observer = new ResizeObserver(measureBounds);
      observer.observe(bounds);
      return () => observer.disconnect();
    }

    window.addEventListener('resize', measureBounds);
    return () => window.removeEventListener('resize', measureBounds);
  }, [measureBounds, page?.page_id, imageLoaded]);

  const applyScale = useCallback((nextScale, nextOffset) => {
    const container = containerRef.current;
    const size = container ? { width: container.clientWidth, height: container.clientHeight } : null;
    const clampedScale = clampScale(nextScale);
    setScale(clampedScale);
    setOffset(clampOffset(nextOffset ?? offset, clampedScale, size, boundsSize));
  }, [offset, boundsSize]);

  const zoomAtPoint = useCallback((nextScale, clientX, clientY, baseScale = scale, baseOffset = offset) => {
    const container = containerRef.current;
    if (!container) return;
    const rect = container.getBoundingClientRect();
    const point = { x: clientX - rect.left - rect.width / 2, y: clientY - rect.top - rect.height / 2 };
    const clampedScale = clampScale(nextScale);
    const ratio = clampedScale / baseScale;
    applyScale(clampedScale, {
      x: point.x - ((point.x - baseOffset.x) * ratio),
      y: point.y - ((point.y - baseOffset.y) * ratio),
    });
  }, [applyScale, offset, scale]);

  // A navigator selection targets a stable occurrence on the active page.
  // Use the existing center-origin transform and pan limits: no second camera.
  useLayoutEffect(() => {
    if (!focusedHotspotId || !focusRequest || !imageLoaded || !boundsSize) return;
    const occurrence = page?.hotspot_dataset?.occurrences?.find(
      (spot) => String(spot.hotspot_id) === String(focusedHotspotId),
    );
    const x = Number(occurrence?.coordinates?.x_pct);
    const y = Number(occurrence?.coordinates?.y_pct);
    const viewport = containerRef.current;
    const bounds = imageBoundsRef.current;
    if (!occurrence || !Number.isFinite(x) || !Number.isFinite(y)
      || x < 0 || x > 100 || y < 0 || y > 100 || !viewport || !bounds) return;

    const rect = viewport.getBoundingClientRect();
    const imageRect = bounds.getBoundingClientRect();
    if (!rect.width || !rect.height || !imageRect.width || !imageRect.height) return;
    // Current hotspot position is measured in screen space; subtract the
    // current camera offset before applying the target scale ratio.
    const hotspotX = imageRect.left + (x / 100) * imageRect.width - rect.left - rect.width / 2;
    const hotspotY = imageRect.top + (y / 100) * imageRect.height - rect.top - rect.height / 2;
    const targetScale = clampScale(Math.max(scale, 1.65));
    const ratio = targetScale / scale;
    const targetOffset = {
      x: -(hotspotX - offset.x) * ratio,
      y: (rect.height * 0.38 - rect.height / 2) - (hotspotY - offset.y) * ratio,
    };
    setFocusTransition(true);
    setScale(targetScale);
    setOffset(clampOffset(targetOffset, targetScale, {
      width: rect.width, height: rect.height,
    }, boundsSize));
  // Intentionally only run for explicit selection/page/media readiness, not
  // scale/offset changes caused by this effect or a manual pan.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusedHotspotId, focusRequest, imageLoaded, page?.page_id, boundsSize]);

  // Only zoom on ctrl/cmd+wheel (the standard "trackpad pinch sends
  // ctrl+wheel" convention, matching map-style UX). Plain wheel scroll must
  // fall through to normal page scrolling instead of hijacking it — this
  // container previously zoomed on any wheel delta >= 4, which is virtually
  // every mouse-wheel tick, trapping the page under the diagram.
  const handleWheel = useCallback((event) => {
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    setFocusTransition(false);
    const delta = -event.deltaY * 0.0015;
    zoomAtPoint(scale + delta * scale, event.clientX, event.clientY);
  }, [scale, zoomAtPoint]);

  const handlePointerDown = useCallback((event) => {
    setFocusTransition(false);
    if (event.pointerType === 'mouse' && scale <= 1) return;
    // Deliberately do NOT call setPointerCapture here for the single-pointer
    // case. Explicit pointer capture retargets the subsequent compatibility
    // `click` event to the capturing element (this container) instead of the
    // originally hit-tested target, which silently swallowed hotspot clicks
    // whenever the diagram was zoomed (scale > 1) — a plain tap/click never
    // got the chance to become a real drag, but capturing eagerly on
    // pointerdown still stole its click. Gesture intent starts as `pending`
    // for every 1-pointer press (mouse or touch) and is only promoted to a
    // real `pan` — with capture acquired at that point — once movement
    // crosses the drag threshold in handlePointerMove. A press that never
    // crosses the threshold ends as a normal, uncaptured click on whatever
    // element was actually pressed (e.g. a hotspot).
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pointers.current.size === 1) {
      gesture.current = {
        type: 'pending',
        startOffset: offset,
        startPoint: { x: event.clientX, y: event.clientY },
      };
      setIsGesturing(true);
    } else if (pointers.current.size === 2) {
      // Pinch always involves two active pointers, never a click target, so
      // capturing immediately here is safe.
      pointers.current.forEach((_point, pointerId) => {
        containerRef.current?.setPointerCapture?.(pointerId);
      });
      const [a, b] = Array.from(pointers.current.values());
      gesture.current = {
        type: 'pinch',
        startDistance: Math.hypot(a.x - b.x, a.y - b.y),
        startScale: scale,
        startOffset: offset,
        startMidpoint: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
      };
    }
  }, [scale, offset]);

  const handlePointerMove = useCallback((event) => {
    if (!pointers.current.has(event.pointerId)) return;
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });

    // Promote a still-`pending` single-pointer press into a real pan once it
    // has moved far enough to be an intentional drag rather than a tap/click.
    // Pointer capture is acquired only now, at the moment we've committed to
    // handling this as a drag — never on pointerdown — so it can't intercept
    // a plain click's compatibility event.
    if (gesture.current?.type === 'pending' && pointers.current.size === 1) {
      const dx = event.clientX - gesture.current.startPoint.x;
      const dy = event.clientY - gesture.current.startPoint.y;
      if (Math.hypot(dx, dy) <= 8) return;
      if (scale <= 1) return; // nothing to pan at scale<=1; stay pending for tap/double-tap
      containerRef.current?.setPointerCapture?.(event.pointerId);
      gesture.current = {
        type: 'pan',
        startOffset: gesture.current.startOffset,
        startPoint: gesture.current.startPoint,
      };
    }

    if (pointers.current.size === 2) {
      suppressHotspotUntil.current = Date.now() + 250;
      const [a, b] = Array.from(pointers.current.values());
      const distance = Math.hypot(a.x - b.x, a.y - b.y);
      if (!gesture.current || gesture.current.type !== 'pinch') return;
      const midpoint = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      const nextScale = gesture.current.startScale * (distance / gesture.current.startDistance);
      const containerRect = containerRef.current?.getBoundingClientRect();
      if (!containerRect) return;
      const center = { x: containerRect.left + containerRect.width / 2, y: containerRect.top + containerRect.height / 2 };
      const startPoint = {
        x: gesture.current.startMidpoint.x - center.x,
        y: gesture.current.startMidpoint.y - center.y,
      };
      const currentPoint = { x: midpoint.x - center.x, y: midpoint.y - center.y };
      const clampedScale = clampScale(nextScale);
      const ratio = clampedScale / gesture.current.startScale;
      applyScale(clampedScale, {
        x: currentPoint.x - ((startPoint.x - gesture.current.startOffset.x) * ratio),
        y: currentPoint.y - ((startPoint.y - gesture.current.startOffset.y) * ratio),
      });
      return;
    }

    if (gesture.current?.type === 'pan') {
      const dx = event.clientX - gesture.current.startPoint.x;
      const dy = event.clientY - gesture.current.startPoint.y;
      if (Math.hypot(dx, dy) > 8) suppressHotspotUntil.current = Date.now() + 250;
      applyScale(scale, { x: gesture.current.startOffset.x + dx, y: gesture.current.startOffset.y + dy });
    }
  }, [scale, applyScale]);

  const handlePointerUp = useCallback((event) => {
    const endedGesture = gesture.current;
    pointers.current.delete(event.pointerId);
    if (pointers.current.size === 0) {
      gesture.current = null;
      setIsGesturing(false);
      if (event.pointerType !== 'mouse' && endedGesture?.startPoint) {
        const drift = Math.hypot(
          event.clientX - endedGesture.startPoint.x,
          event.clientY - endedGesture.startPoint.y,
        );
        const now = Date.now();
        const previous = lastTap.current;
        if (drift < 12 && now - previous.time < 320 && Math.hypot(event.clientX - previous.x, event.clientY - previous.y) < 28) {
          zoomAtPoint(scale > 1 ? 1 : 2, event.clientX, event.clientY);
          lastTap.current = { time: 0, x: 0, y: 0 };
        } else if (drift < 12) {
          lastTap.current = { time: now, x: event.clientX, y: event.clientY };
        }
      }
    } else if (pointers.current.size === 1 && scale > 1) {
      const [point] = Array.from(pointers.current.values());
      gesture.current = { type: 'pan', startOffset: offset, startPoint: point };
    }
  }, [offset, scale, zoomAtPoint]);

  const zoomIn = () => { setFocusTransition(false); applyScale(scale + 0.5); };
  const zoomOut = () => { setFocusTransition(false); applyScale(scale - 0.5); };
  const resetZoom = () => { setFocusTransition(false); applyScale(1, { x: 0, y: 0 }); };

  if (!page) {
    return (
      <div className="dtb-schematic-diagram dtb-schematic-diagram--missing" role="status">
        <AlertTriangle size={28} aria-hidden="true" />
        <p>This page isn't available.</p>
      </div>
    );
  }

  if (!page.url) {
    return (
      <div className="dtb-schematic-diagram dtb-schematic-diagram--missing" role="status">
        <ImageOff size={28} aria-hidden="true" />
        <p>This page's diagram image isn't available yet.</p>
      </div>
    );
  }

  const hotspotDataset = page.hotspot_dataset;

  return (
    <div className="dtb-schematic-diagram-wrap" ref={wrapRef}>
      <div
        ref={containerRef}
        className="dtb-schematic-diagram"
        id={`dtb-schematic-panel-${page.page_id}`}
        role="tabpanel"
        aria-labelledby={`dtb-schematic-tab-${page.page_id}`}
        onWheel={handleWheel}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerUp}
        onDoubleClick={(event) => zoomAtPoint(scale > 1 ? 1 : 2, event.clientX, event.clientY)}
        style={{ touchAction: scale > 1 ? 'none' : 'pan-y', cursor: scale > 1 ? (isGesturing ? 'grabbing' : 'grab') : 'default' }}
      >
        {/* Transform wrapper — legacy `.schematic-image-wrapper`. Zoom/pan is a
            single `transform: scale(...) translate(...)` here, center-origin,
            with a CSS transition (suppressed during active gestures so drags
            track the pointer 1:1). The aspect-ratio is NOT set on this div —
            it lives on the inner bounds div so hotspot % coordinates always
            reference that box, never this full-width wrapper. */}
        <div
          className={`schematic-image-wrapper${!imageLoaded ? ' schematic-image-wrapper--loading' : ''}`}
          style={{
            position: 'relative',
            transform: `scale(${scale}) translate(${scale ? offset.x / scale : 0}px, ${scale ? offset.y / scale : 0}px)`,
            transformOrigin: 'center center',
            transition: reduceMotion || isGesturing ? 'none' : focusTransition ? 'transform 350ms cubic-bezier(0.22, 1, 0.36, 1)' : 'transform 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94)',
            userSelect: 'none',
            WebkitUserSelect: 'none',
            pointerEvents: 'auto',
            willChange: scale > 1 || isGesturing ? 'transform' : 'auto',
          }}
        >
          {/* Diagram image bounds — legacy `.schematic-image-bounds`. Sized by
              CSS `aspect-ratio` from the page's known natural dimensions so it
              is EXACTLY the rendered image's pixel area; every hotspot
              top/left percentage is relative to this div. */}
          <div
            ref={imageBoundsRef}
            className="schematic-image-bounds"
            style={{
              position: 'relative',
              aspectRatio: page.width && page.height ? `${page.width} / ${page.height}` : undefined,
            }}
          >
            <DiagramImage
              page={page}
              onLoad={() => {
                setImageLoaded(true);
                measureBounds();
              }}
              imgRef={imgRef}
            />
            {imageLoaded && (
              <HotspotLayer
                hotspotDataset={hotspotDataset}
                parts={parts}
                activePartRef={activePartRef}
                activeHotspotId={selectedHotspotId}
                onSelectPart={(partRef, anchor, hotspotId) => {
                  if (Date.now() >= suppressHotspotUntil.current) {
                    setAnchorRect(anchor);
                    onSelectPart(partRef, anchor, hotspotId);
                  }
                }}
              />
            )}
          </div>
        </div>
      </div>

      {hotspotDataset && hotspotDataset.available === false && (
        <p className="dtb-schematic-diagram__note" role="status">
          Interactive part links are temporarily unavailable for this diagram.
        </p>
      )}

      <ZoomControls
        scale={scale}
        minScale={MIN_SCALE}
        maxScale={MAX_SCALE}
        onZoomIn={zoomIn}
        onZoomOut={zoomOut}
        onReset={resetZoom}
      />

      {partsTrigger}

      {activePart && (
        <SchematicPartDialog
          key={activePart.part_ref}
          part={activePart}
          schematicContext={{
            ...(schematicContext || {}),
            pageId: page?.page_id || '',
            pageNumber: page?.page_number || '',
            pageLabel: page?.label || '',
          }}
          anchorRect={selectedFromList ? null : anchorRect}
          wrapRef={wrapRef}
          isMobile={isMobile}
          onClose={onCloseActivePart}
        />
      )}
    </div>
  );
}

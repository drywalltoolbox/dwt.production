/**
 * frontend/src/components/schematics-v2/SchematicPartDialog.jsx
 *
 * Positioning wrapper ported from the legacy `Schematics.jsx` desktop
 * "detached modal" + mobile overlay behavior:
 *   - Desktop: a `.part-modal.part-modal-detached` div, `position: absolute`
 *     with inline `top`/`left` px computed relative to the diagram wrapper
 *     (the legacy `.schematic-container` equivalent here is the
 *     `.dtb-schematic-diagram-wrap` element, passed in via `wrapRef`),
 *     placed below the clicked hotspot and flipped above / clamped to the
 *     wrapper bounds when it would otherwise overflow.
 *   - Mobile (<=768px): a fixed, viewport-centered `.mobile-part-modal-overlay`
 *     with a `.mobile-modal-backdrop`, matching the legacy mobile behavior
 *     (CSS in mobile-schematic.css only shows these under max-width:768px).
 *
 * The dialog is an interaction boundary above the diagram/hotspot layer.
 * Pointer events must not leak into the diagram's zoom/pan handlers while a
 * user is interacting with the card controls.
 */
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import SchematicHotspotCard from './SchematicHotspotCard';

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const OFFSET = 12; // gap between hotspot and modal
const PADDING = 8; // minimum clearance from wrapper edges
const MODAL_WIDTH_FALLBACK = 280;
const MODAL_HEIGHT_FALLBACK = 320;

/**
 * Ported from the legacy `calculateAndSetModalPosition` in Schematics.jsx:
 * prefers placing the modal below the hotspot, flips above when it would
 * overflow the bottom edge, and clamps both axes to the wrapper bounds.
 */
function calculatePosition(hotspotRect, wrapRect, modalSize) {
  if (!hotspotRect || !wrapRect) return null;

  const modalWidth = modalSize?.width || MODAL_WIDTH_FALLBACK;
  const modalHeight = modalSize?.height || MODAL_HEIGHT_FALLBACK;

  const hotspotCenterX = (hotspotRect.left + hotspotRect.width / 2) - wrapRect.left;
  const hotspotBottom = hotspotRect.bottom - wrapRect.top;
  const hotspotTop = hotspotRect.top - wrapRect.top;

  let top = hotspotBottom + OFFSET;
  let left = hotspotCenterX - modalWidth / 2;

  left = Math.max(PADDING, Math.min(left, wrapRect.width - modalWidth - PADDING));

  if (top + modalHeight > wrapRect.height - PADDING) {
    top = hotspotTop - modalHeight - OFFSET;
  }

  top = Math.max(PADDING, Math.min(top, wrapRect.height - modalHeight - PADDING));

  return { top, left };
}

function stopDialogPointerPropagation(event) {
  event.stopPropagation();
}

export default function SchematicPartDialog({ part, schematicContext, anchorRect, wrapRef, isMobile, onClose }) {
  const cardRef = useRef(null);
  const mobileDialogRef = useRef(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => { onCloseRef.current = onClose; }, [onClose]);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const hasPart = Boolean(part);

  useLayoutEffect(() => {
    if (isMobile || !anchorRect || !wrapRef?.current) return;
    const wrapRect = wrapRef.current.getBoundingClientRect();
    const modalSize = cardRef.current
      ? { width: cardRef.current.offsetWidth, height: cardRef.current.offsetHeight }
      : null;
    const next = calculatePosition(anchorRect, wrapRect, modalSize);
    if (next) setPosition(next);
  }, [anchorRect, isMobile, wrapRef, part]);

  useLayoutEffect(() => {
    if (isMobile) return;
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isMobile, onClose]);

  useEffect(() => {
    if (!isMobile || !hasPart) return undefined;

    const opener = document.activeElement;
    const focusFrame = window.requestAnimationFrame(() => {
      mobileDialogRef.current?.focus({ preventScroll: true });
    });
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        onCloseRef.current?.();
        return;
      }
      if (event.key !== 'Tab' || !mobileDialogRef.current) return;
      const dialog = mobileDialogRef.current;
      const focusable = Array.from(dialog.querySelectorAll(FOCUSABLE_SELECTOR))
        .filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));
      if (!focusable.length) {
        event.preventDefault();
        dialog.focus({ preventScroll: true });
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!dialog.contains(document.activeElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus({ preventScroll: true });
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus({ preventScroll: true });
      }
    };
    const activeDialog = mobileDialogRef.current;
    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', onKeyDown, true);
      if (opener instanceof HTMLElement && opener.isConnected
        && activeDialog?.contains(document.activeElement)) {
        opener.focus({ preventScroll: true });
      }
    };
  }, [isMobile, hasPart, part?.part_ref]);

  if (!part) return null;

  const dialogLabel = part.title ? `${part.title} details` : 'Part details';

  if (isMobile) {
    return (
      <>
        <div className="mobile-modal-backdrop" onClick={onClose} />
        <div
          ref={mobileDialogRef}
          tabIndex={-1}
          className="mobile-part-modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-label={dialogLabel}
          onClick={stopDialogPointerPropagation}
          onPointerDown={stopDialogPointerPropagation}
          onPointerUp={stopDialogPointerPropagation}
          style={{ zIndex: 1002, pointerEvents: 'auto' }}
        >
          <SchematicHotspotCard part={part} schematicContext={schematicContext} onClose={onClose} />
        </div>
      </>
    );
  }

  return (
    <div
      ref={cardRef}
      className={`part-modal part-modal-detached${anchorRect ? '' : ' part-modal--centered'}`}
      role="dialog"
      aria-label={dialogLabel}
      onClick={stopDialogPointerPropagation}
      onPointerDown={stopDialogPointerPropagation}
      onPointerUp={stopDialogPointerPropagation}
      style={{
        position: 'absolute',
        ...(anchorRect ? { top: `${position.top}px`, left: `${position.left}px` } : {}),
        zIndex: 1002,
        pointerEvents: 'auto',
      }}
    >
      <SchematicHotspotCard part={part} schematicContext={schematicContext} onClose={onClose} />
    </div>
  );
}

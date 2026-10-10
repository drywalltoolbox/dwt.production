import { useEffect, useRef } from 'react';
import { useReducedMotion } from 'framer-motion';
import MotionBackdrop from '../motion/MotionBackdrop.jsx';
import MotionDrawer from '../motion/MotionDrawer.jsx';
import MotionPresence from '../motion/MotionPresence.jsx';
import { dtbDistance } from '../../motion/dtbMotion.js';

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// A navigation drawer settles without scaling a viewport-sized raster surface.
const navigationDrawerVariants = {
  hidden: { opacity: 0, y: -dtbDistance.small },
  visible: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -dtbDistance.small },
};

export default function StorefrontMobileDrawer({ isOpen, onClose, labelledBy, children }) {
  const closeRef = useRef(null);
  const panelRef = useRef(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;
  const reduceMotion = useReducedMotion();

  useEffect(() => {
    if (!isOpen) return undefined;

    const previouslyFocused = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      closeRef.current?.focus({ preventScroll: true });
    });

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onCloseRef.current?.();
        return;
      }

      if (event.key !== 'Tab' || !panelRef.current) return;

      const focusable = Array.from(panelRef.current.querySelectorAll(FOCUSABLE_SELECTOR))
        .filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));

      if (!focusable.length) {
        event.preventDefault();
        closeRef.current?.focus({ preventScroll: true });
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (!panelRef.current.contains(document.activeElement)) {
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

    document.addEventListener('keydown', onKeyDown);

    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;

      // Do not steal focus if navigation opened another surface while closing.
      if (panelRef.current?.contains(document.activeElement)
        && previouslyFocused instanceof HTMLElement
        && previouslyFocused.isConnected) {
        previouslyFocused.focus({ preventScroll: true });
      }
    };
  }, [isOpen]);

  const handlePanelClick = (event) => {
    const row = event.target.closest('.storefront-mobile-drawer__row');
    if (!row) return;

    // The title remains the navigation target. The chevron keeps its native
    // button handler, while the rest of the row proxies to that same control.
    if (
      event.target.closest('.storefront-mobile-drawer__row-label')
      || event.target.closest('.storefront-mobile-drawer__row-toggle')
    ) {
      return;
    }

    const toggle = row.querySelector('.storefront-mobile-drawer__row-toggle');
    if (toggle instanceof HTMLButtonElement && !toggle.disabled) {
      toggle.click();
    }
  };

  return (
    <div className="storefront-mobile-drawer" data-open={isOpen ? 'true' : 'false'}>
      <MotionPresence mode="sync" initial={false}>
        {isOpen ? (
          <>
            <MotionBackdrop
              className="storefront-mobile-drawer__backdrop"
              onClick={onClose}
              reduceMotion={reduceMotion}
            />
            <MotionDrawer
              ref={panelRef}
              variants={navigationDrawerVariants}
              className="storefront-mobile-drawer__panel"
              reduceMotion={reduceMotion}
              role="dialog"
              aria-modal="true"
              aria-labelledby={labelledBy}
              aria-label={labelledBy ? undefined : 'Mobile navigation'}
              onClick={handlePanelClick}
            >
              <button
                ref={closeRef}
                type="button"
                onClick={onClose}
                className="sr-only"
              >
                Close
              </button>
              {children}
            </MotionDrawer>
          </>
        ) : null}
      </MotionPresence>
    </div>
  );
}

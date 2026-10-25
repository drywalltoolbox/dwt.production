import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { m as Motion, useReducedMotion } from 'framer-motion';
import MotionBackdrop from '../motion/MotionBackdrop.jsx';
import MotionDialog from '../motion/MotionDialog.jsx';
import MotionDrawer from '../motion/MotionDrawer.jsx';
import MotionPresence from '../motion/MotionPresence.jsx';
import {
  productModalBackdropTransition,
  productModalDesktopVariants,
  productModalDesktopCardVariants,
  productModalMobileVariants,
  reducedProductModalCardVariants,
  productModalTransition,
  reducedTransition,
} from '../../motion/dtbMotion.js';
import '../../styles/product-quick-view-desktop.css';

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function useIsMobileModal() {
  const [isMobile, setIsMobile] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(max-width: 768px)').matches;
  });

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;
    const mediaQuery = window.matchMedia('(max-width: 768px)');
    const onChange = () => setIsMobile(mediaQuery.matches);
    onChange();
    mediaQuery.addEventListener?.('change', onChange);
    return () => mediaQuery.removeEventListener?.('change', onChange);
  }, []);

  return isMobile;
}

export default function ProductModal({ isOpen, product, onClose, children }) {
  const scrollRef = useRef(null);
  const openerRef = useRef(null);
  const scrollHideTimerRef = useRef(null);
  const bodyUnlockTimerRef = useRef(null);
  const bodyLockStateRef = useRef(null);
  const [isScrollActive, setIsScrollActive] = useState(false);
  const reduceMotion = useReducedMotion();
  const isMobile = useIsMobileModal();

  useEffect(() => {
    if (isOpen) {
      openerRef.current = document.activeElement;
    } else {
      const id = setTimeout(() => {
        if (openerRef.current instanceof HTMLElement && openerRef.current.isConnected) {
          openerRef.current.focus({ preventScroll: true });
        }
        openerRef.current = null;
      }, reduceMotion ? 80 : 280);
      return () => clearTimeout(id);
    }
  }, [isOpen, reduceMotion]);

  useEffect(() => {
    if (typeof document === 'undefined') return;

    const body = document.body;

    const releaseBodyLock = () => {
      const state = bodyLockStateRef.current;
      if (!state) return;
      body.style.overflow = state.overflow;
      body.style.paddingRight = state.paddingRight;
      body.classList.remove('dtb-product-modal-open');
      bodyLockStateRef.current = null;
    };

    if (bodyUnlockTimerRef.current) {
      window.clearTimeout(bodyUnlockTimerRef.current);
      bodyUnlockTimerRef.current = null;
    }

    if (isOpen) {
      if (!bodyLockStateRef.current) {
        const scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
        const computedPaddingRight = Number.parseFloat(window.getComputedStyle(body).paddingRight) || 0;

        bodyLockStateRef.current = {
          overflow: body.style.overflow,
          paddingRight: body.style.paddingRight,
        };

        body.style.overflow = 'hidden';
        if (scrollbarWidth > 0) {
          body.style.paddingRight = `${computedPaddingRight + scrollbarWidth}px`;
        }
        body.classList.add('dtb-product-modal-open');
      }
      return;
    }

    if (bodyLockStateRef.current) {
      // Keep document geometry locked until the opacity-only exit completes.
      // Releasing overflow immediately reintroduces the desktop scrollbar while
      // the modal is still visible, shifting the entire storefront underneath it.
      bodyUnlockTimerRef.current = window.setTimeout(
        releaseBodyLock,
        reduceMotion ? 20 : 190,
      );
    }
  }, [isMobile, isOpen, reduceMotion]);

  useEffect(() => () => {
    if (bodyUnlockTimerRef.current) {
      window.clearTimeout(bodyUnlockTimerRef.current);
    }
    const state = bodyLockStateRef.current;
    if (!state || typeof document === 'undefined') return;
    document.body.style.overflow = state.overflow;
    document.body.style.paddingRight = state.paddingRight;
    document.body.classList.remove('dtb-product-modal-open');
    bodyLockStateRef.current = null;
  }, []);

  useEffect(() => {
    if (isOpen && scrollRef.current) {
      requestAnimationFrame(() => {
        scrollRef.current?.focus({ preventScroll: true });
      });
    }
  }, [isOpen]);

  useEffect(() => {
    if (isOpen && scrollRef.current) {
      scrollRef.current.scrollTop = 0;
    }
  }, [product?.id, isOpen]);

  useEffect(() => {
    if (!isOpen) {
      if (scrollHideTimerRef.current) {
        clearTimeout(scrollHideTimerRef.current);
        scrollHideTimerRef.current = null;
      }
      const resetId = setTimeout(() => {
        setIsScrollActive(false);
      }, 0);
      return () => clearTimeout(resetId);
    }
  }, [isOpen]);

  useEffect(() => {
    return () => {
      if (scrollHideTimerRef.current) {
        clearTimeout(scrollHideTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    if (!isOpen) return undefined;
    const handler = (event) => {
      // The product gallery can open its own body-portaled full-screen image
      // dialog. The parent's focus trap must never intercept that dialog's
      // keyboard handling or steal its focus on Tab.
      const nestedDialog = event.target instanceof Element
        ? event.target.closest('[role="dialog"][aria-modal="true"]')
        : null;
      if (nestedDialog && nestedDialog !== scrollRef.current
        && !scrollRef.current?.contains(nestedDialog)) return;
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
        return;
      }
      if (event.key !== 'Tab' || !scrollRef.current) return;

      const dialog = scrollRef.current;
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
    // Capture on the active modal, before underlying drawer/search listeners.
    document.addEventListener('keydown', handler, true);
    return () => document.removeEventListener('keydown', handler, true);
  }, [isOpen, onClose]);

  if (typeof document === 'undefined') return null;

  const transition = reduceMotion ? reducedTransition : productModalTransition;
  const variants = isMobile ? productModalMobileVariants : productModalDesktopVariants;
  const PanelComponent = isMobile ? MotionDrawer : MotionDialog;

  return createPortal(
    <MotionPresence mode="sync" initial>
      {isOpen && product && (
        <>
          <MotionBackdrop
            className="fixed inset-0 bg-slate-950/55 product-modal-backdrop"
            style={{ zIndex: 10001 }}
            reduceMotion={reduceMotion}
            transition={productModalBackdropTransition}
            onClick={onClose}
          />

          <PanelComponent
            key="product-modal-panel"
            ref={scrollRef}
            className={`product-modal-scroll-shell fixed left-0 right-0 bottom-0 overflow-y-auto overscroll-contain outline-none${isScrollActive ? ' product-modal-scroll-shell--active' : ''}`}
            style={{ zIndex: 10002 }}
            role="dialog"
            aria-modal="true"
            aria-label={product?.name || 'Product detail'}
            tabIndex={-1}
            reduceMotion={reduceMotion}
            transition={transition}
            variants={variants}
            onScroll={() => {
              setIsScrollActive(true);
              if (scrollHideTimerRef.current) clearTimeout(scrollHideTimerRef.current);
              scrollHideTimerRef.current = setTimeout(() => {
                setIsScrollActive(false);
              }, 900);
            }}
          >
            <div
              className="product-modal-scroll-inner flex items-end md:items-center justify-center min-h-full px-0 py-0 md:px-4 md:py-6 lg:px-6"
              onClick={onClose}
            >
              <Motion.div
                className="product-modal-card-shell dtb-product-page-shell w-full max-w-6xl"
                initial={isMobile ? undefined : 'hidden'}
                animate={isMobile ? undefined : 'visible'}
                exit={isMobile ? undefined : 'exit'}
                variants={
                  isMobile
                    ? undefined
                    : (reduceMotion ? reducedProductModalCardVariants : productModalDesktopCardVariants)
                }
                onClick={(e) => e.stopPropagation()}
              >
                <div className="product-modal-content">
                  {children}
                </div>
              </Motion.div>
            </div>
          </PanelComponent>

        </>
      )}
    </MotionPresence>,
    document.body
  );
}

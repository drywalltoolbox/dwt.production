import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown } from 'lucide-react';
import getQuantityMenuPosition from '../../utils/quantityMenuPosition.js';

const QUANTITY_OPTIONS = Array.from({ length: 10 }, (_, index) => index + 1);

export default function ProductQuantityDropdown({ quantity, onQuantityChange, disabled = false }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;

    const updatePosition = () => {
      setPosition(getQuantityMenuPosition(triggerRef.current));
    };
    const isWithinSelector = (target) => (
      triggerRef.current?.contains(target) || menuRef.current?.contains(target)
    );
    const handlePointerDown = (event) => {
      if (!isWithinSelector(event.target)) setOpen(false);
    };
    const handleFocusIn = (event) => {
      if (!isWithinSelector(event.target)) setOpen(false);
    };
    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      setOpen(false);
      window.requestAnimationFrame(() => triggerRef.current?.focus());
    };

    updatePosition();
    const focusFrame = window.requestAnimationFrame(() => {
      menuRef.current?.querySelector('[aria-pressed="true"]')?.focus();
    });
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('focusin', handleFocusIn);
    document.addEventListener('keydown', handleKeyDown);
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    window.visualViewport?.addEventListener('resize', updatePosition);
    window.visualViewport?.addEventListener('scroll', updatePosition);

    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('focusin', handleFocusIn);
      document.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
      window.visualViewport?.removeEventListener('resize', updatePosition);
      window.visualViewport?.removeEventListener('scroll', updatePosition);
    };
  }, [open]);

  const toggle = (event) => {
    event.stopPropagation();
    if (disabled) return;
    if (open) {
      setOpen(false);
      return;
    }
    setPosition(getQuantityMenuPosition(triggerRef.current));
    setOpen(true);
  };

  const selectQuantity = (value) => {
    onQuantityChange?.(value);
    setOpen(false);
    window.requestAnimationFrame(() => triggerRef.current?.focus());
  };

  return (
    <div className="dtb-pdp-mobile-quantity">
      <button
        ref={triggerRef}
        type="button"
        className="dtb-pdp-mobile-quantity__trigger"
        onClick={toggle}
        onPointerDown={(event) => event.stopPropagation()}
        aria-label={`Quantity: ${quantity}`}
        aria-haspopup="dialog"
        aria-expanded={open}
        disabled={disabled}
      >
        <span>{quantity}</span>
        <ChevronDown size={16} strokeWidth={2.1} aria-hidden="true" />
      </button>
      {open && position && createPortal(
        <div
          ref={menuRef}
          className="dtb-quantity-menu dtb-pdp-mobile-quantity__menu"
          role="dialog"
          aria-label="Choose quantity"
          style={position}
          onClick={(event) => event.stopPropagation()}
        >
          {QUANTITY_OPTIONS.map((value) => (
            <button
              key={value}
              type="button"
              className={`dtb-quantity-menu__option${quantity === value ? ' dtb-quantity-menu__option--selected' : ''}`}
              aria-pressed={quantity === value}
              onKeyDown={(event) => {
                if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const options = Array.from(menuRef.current?.querySelectorAll('.dtb-quantity-menu__option') || []);
                const currentIndex = options.indexOf(event.currentTarget);
                const nextIndex = event.key === 'Home'
                  ? 0
                  : event.key === 'End'
                    ? options.length - 1
                    : (currentIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
                options[nextIndex]?.focus();
              }}
              onClick={() => selectQuantity(value)}
            >
              <span>{value}</span>
              {quantity === value ? <Check size={15} strokeWidth={2.5} aria-hidden="true" /> : null}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
}

import { useEffect, useRef } from 'react';
import { ArrowRight, X } from 'lucide-react';
import dtbLogo from '../../assets/brand/dtb-logo-white.svg';
import './HomepageSignupCTA.css';

export default function HomepageSignupCTA({ offer, onClose, onSignup }) {
  const dialogRef = useRef(null);
  const closeRef = useRef(null);

  useEffect(() => {
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    closeRef.current?.focus();

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
      }
      if (event.key !== 'Tab' || !dialogRef.current) return;

      const controls = [...dialogRef.current.querySelectorAll('button, a[href]')]
        .filter((element) => !element.hasAttribute('disabled'));
      if (!controls.length) return;
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
      if (previousFocus instanceof HTMLElement) previousFocus.focus();
    };
  }, [onClose]);

  const discount = 10;
  const terms = offer?.terms || {};
  const minimumSpend = Number(terms.minimum_amount) > 0
    ? new Intl.NumberFormat(undefined, { style: 'currency', currency: offer?.currency || 'USD' }).format(Number(terms.minimum_amount))
    : '';
  const termsParts = [
    'New accounts only. One use on your first order.',
    terms.exclude_sale_items ? 'Excludes sale items.' : '',
    terms.individual_use ? 'Cannot be combined with other coupons.' : '',
    minimumSpend ? `Minimum spend ${minimumSpend}.` : '',
    Number(terms.expiry_days) > 0 ? `Code expires ${Number(terms.expiry_days)} days after signup.` : '',
  ].filter(Boolean);

  return (
    <div className="homepage-signup-cta">
      <button
        type="button"
        className="homepage-signup-cta__backdrop"
        onClick={onClose}
          aria-label="Close welcome message"
      />
      <section
        ref={dialogRef}
        className="homepage-signup-cta__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="homepage-signup-cta-title"
        aria-describedby="homepage-signup-cta-description"
      >
        <div className="homepage-signup-cta__accent" aria-hidden="true" />
        <button
          ref={closeRef}
          type="button"
          className="homepage-signup-cta__close"
          onClick={onClose}
        aria-label="Close welcome message"
        >
          <X size={21} strokeWidth={2.2} />
        </button>

        <div className="homepage-signup-cta__content">
          <img
            className="homepage-signup-cta__logo"
            src={dtbLogo}
            alt="Drywall Toolbox"
            width="230"
            height="55"
          />
          <p className="homepage-signup-cta__eyebrow"><span /> Welcome to Drywall Toolbox <span /></p>
          <h2 id="homepage-signup-cta-title" className="homepage-signup-cta__headline">
            Get <span>{discount}% off</span> your first order.
          </h2>
          <p id="homepage-signup-cta-description" className="homepage-signup-cta__body">
            Create your free account. Your personal coupon code will be included in your new-account email.
          </p>
          <button type="button" className="homepage-signup-cta__button" onClick={onSignup}>
            Create account &amp; save {discount}% <ArrowRight size={20} strokeWidth={2.2} aria-hidden="true" />
          </button>
          <button type="button" className="homepage-signup-cta__dismiss" onClick={onClose}>
            No thanks, I’ll keep browsing
          </button>
          <p className="homepage-signup-cta__note">{termsParts.join(' ')}</p>
        </div>
      </section>
    </div>
  );
}

import { useEffect, useId, useRef, useState } from 'react';
import useCheckoutReadiness from '../../hooks/useCheckoutReadiness.js';
import '../../styles/product-buy-now.css';

function readinessMessage(readiness) {
  if (readiness.state === 'ready') {
    return 'Secure payment methods are presented on the checkout page.';
  }
  return 'Available payment methods are confirmed on the secure checkout page.';
}

export default function ProductBuyNow({
  onBuyNow,
  status = 'idle',
  disabled = false,
  suspended = false,
  disabledReason = '',
}) {
  const statusId = useId();
  const clickLockedRef = useRef(false);
  const observedPendingRef = useRef(false);
  const readiness = useCheckoutReadiness();
  const [interactionError, setInteractionError] = useState('');

  const pending = status === 'pending';
  const confirmed = status === 'confirmed';

  useEffect(() => {
    if (pending || confirmed) {
      observedPendingRef.current = true;
      return;
    }
    if (observedPendingRef.current) {
      clickLockedRef.current = false;
      observedPendingRef.current = false;
    }
  }, [pending, confirmed]);

  useEffect(() => {
    if (!disabled) return;
    clickLockedRef.current = false;
  }, [disabled]);

  const busy = pending || confirmed;
  const blocked = disabled || busy;
  const statusMessage = interactionError
    || (disabled && disabledReason)
    || (pending
      ? 'Preparing secure checkout…'
      : confirmed
        ? 'Redirecting to checkout…'
        : readinessMessage(readiness));
  const statusVisible = Boolean(
    interactionError
    || (disabled && disabledReason),
  );
  const buttonLabel = pending
    ? 'Preparing Checkout…'
    : confirmed
      ? 'Checkout Ready'
      : 'Checkout Now';

  const handleBuyNow = () => {
    if (blocked || clickLockedRef.current || typeof onBuyNow !== 'function') return;

    clickLockedRef.current = true;
    setInteractionError('');

    try {
      const result = onBuyNow();
      if (result && typeof result.catch === 'function') {
        result.catch((error) => {
          clickLockedRef.current = false;
          setInteractionError(error?.message || 'Checkout could not be prepared. Please try again.');
        });
      }
    } catch (error) {
      clickLockedRef.current = false;
      setInteractionError(error?.message || 'Checkout could not be prepared. Please try again.');
    }
  };

  return (
    <section
      className="dtb-product-buy-now"
      data-readiness={readiness.state}
      aria-label="Buy now"
    >
      <button
        type="button"
        className="dtb-product-buy-now__button"
        data-state={pending ? 'pending' : confirmed ? 'confirmed' : 'idle'}
        data-suspended={suspended ? 'true' : undefined}
        onClick={handleBuyNow}
        disabled={blocked}
        aria-busy={busy}
        aria-describedby={statusId}
      >
        <span className="dtb-product-buy-now__content" aria-live="polite">{buttonLabel}</span>
        <span className="dtb-product-buy-now__spinner" aria-hidden="true" />
        <span className="dtb-product-buy-now__success" aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <circle className="dtb-product-buy-now__success-ring" cx="12" cy="12" r="10" fill="none" />
            <path className="dtb-product-buy-now__success-check" fill="none" d="m5.5 12.5 4.2 4.2 8.8-9.4" />
          </svg>
        </span>
      </button>


      <p
        id={statusId}
        className={`dtb-product-buy-now__status${statusVisible ? ' is-visible' : ''}${interactionError ? ' is-error' : ''}`}
        role={interactionError ? 'alert' : 'status'}
        aria-live={interactionError ? 'assertive' : 'polite'}
      >
        {statusMessage}
      </p>
    </section>
  );
}

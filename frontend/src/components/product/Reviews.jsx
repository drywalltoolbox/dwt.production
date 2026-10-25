import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { apiClient } from '../../api/client.js';
import ProductRating from './ProductRating.jsx';

const PAGE_SIZE = 10;

function formatDate(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.valueOf()) ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
}

export default function Reviews({ productId, productName = '', reviewFormOpen = false, onCloseReviewForm = () => {} }) {
  const dialogRef = useRef(null);
  const closeButtonRef = useRef(null);
  const [summary, setSummary] = useState({ average_rating: 0, rating_count: 0 });
  const [reviews, setReviews] = useState([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadedEndpoint, setLoadedEndpoint] = useState(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const [rating, setRating] = useState(5);
  const [content, setContent] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submissionMessage, setSubmissionMessage] = useState('');
  const endpoint = Number.isInteger(Number(productId)) && Number(productId) > 0 ? `/wp-json/dtb/v1/products/${Number(productId)}/reviews` : null;
  const loading = Boolean(endpoint) && loadedEndpoint !== endpoint;
  const hasCurrentReviews = Boolean(endpoint) && loadedEndpoint === endpoint;
  const visibleSummary = hasCurrentReviews ? summary : { average_rating: 0, rating_count: 0 };
  const visibleReviews = hasCurrentReviews ? reviews : [];
  const visibleHasMore = hasCurrentReviews && hasMore;
  const visibleError = endpoint
    ? (loadedEndpoint === endpoint ? error : '')
    : 'Reviews are unavailable for this product.';


  useEffect(() => {
    if (!reviewFormOpen || typeof document === 'undefined') return undefined;
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => closeButtonRef.current?.focus({ preventScroll: true }));

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onCloseReviewForm();
      } else if (event.key === 'Tab' && dialog) {
        const focusable = [...dialog.querySelectorAll('button:not([disabled]), textarea:not([disabled])')]
          .filter((element) => element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!first || !last) {
          event.preventDefault();
          dialog.focus();
        } else if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus({ preventScroll: true });
      }
    };
  }, [reviewFormOpen, onCloseReviewForm]);

  const fetchPage = useCallback(async (requestedPage = 1, append = false) => {
    if (!endpoint) return;
    if (append) setLoadingMore(true);
    setError('');
    try {
      const data = await apiClient(`${endpoint}?page=${requestedPage}&per_page=${PAGE_SIZE}`);
      setSummary(data?.summary || { average_rating: 0, rating_count: 0 });
      setReviews((current) => append ? [...current, ...(data?.reviews || [])] : (data?.reviews || []));
      setPage(requestedPage);
      setHasMore(Boolean(data?.has_more));
    } catch (requestError) {
      setError(requestError?.message || 'Unable to load reviews right now.');
    } finally {
      if (append) setLoadingMore(false);
    }
  }, [endpoint]);

  useEffect(() => {
    if (!endpoint) return undefined;
    let cancelled = false;
    apiClient(`${endpoint}?page=1&per_page=${PAGE_SIZE}`)
      .then((data) => {
        if (cancelled) return;
        setSummary(data?.summary || { average_rating: 0, rating_count: 0 });
        setReviews(data?.reviews || []);
        setPage(1);
        setHasMore(Boolean(data?.has_more));
        setError('');
      })
      .catch((requestError) => {
        if (cancelled) return;
        setSummary({ average_rating: 0, rating_count: 0 });
        setReviews([]);
        setHasMore(false);
        setError(requestError?.message || 'Unable to load reviews right now.');
      })
      .finally(() => {
        if (!cancelled) setLoadedEndpoint(endpoint);
      });
    return () => { cancelled = true; };
  }, [endpoint]);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!endpoint || !content.trim()) return;
    setSubmitting(true); setSubmissionMessage(''); setError('');
    try {
      await apiClient(endpoint, { method: 'POST', body: JSON.stringify({ rating, content: content.trim() }) });
      setContent(''); setRating(5);
      setSubmissionMessage('Thanks — your review was submitted for moderation.');
      await fetchPage(1);
    } catch (requestError) {
      setError(requestError?.message || 'Unable to submit your review.');
    } finally { setSubmitting(false); }
  }

  return <>
    {hasCurrentReviews && visibleReviews.length > 0 && (
      <section className="dtb-pdp-reviews" id="product-reviews" tabIndex={-1} aria-labelledby="product-reviews-title">
        <h2 id="product-reviews-title">Customer reviews</h2>
        <div className="dtb-reviews">
          <div className="dtb-reviews__summary"><ProductRating rating={visibleSummary.average_rating} ratingCount={visibleSummary.rating_count} /></div>
          <div className="dtb-reviews__list">
            {visibleReviews.map((review) => <article key={review.id} className="dtb-reviews__item"><div className="dtb-reviews__item-header"><div className="dtb-reviews__item-meta"><span className="dtb-reviews__item-author">{review.author}</span><ProductRating rating={review.rating} ratingCount={1} compact />{review.verified_purchase && <span className="dtb-reviews__verified">Verified purchase</span>}</div><time className="dtb-reviews__item-date" dateTime={review.date}>{formatDate(review.date)}</time></div><p className="dtb-reviews__item-text">{review.content}</p></article>)}
          </div>
          {visibleHasMore && <button type="button" className="dtb-reviews__more" onClick={() => fetchPage(page + 1, true)} disabled={loadingMore}>{loadingMore ? 'Loading…' : 'Load more reviews'}</button>}
        </div>
      </section>
    )}

    {reviewFormOpen && typeof document !== 'undefined' && createPortal(
      <div className="dtb-review-modal-backdrop" onMouseDown={(event) => {
        if (event.target === event.currentTarget) onCloseReviewForm();
      }}>
        <section
          ref={dialogRef}
          className="dtb-review-dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dtb-review-dialog-title"
          aria-describedby="dtb-review-dialog-description"
          tabIndex={-1}
        >
          <header className="dtb-review-dialog__header">
            <div>
              <span className="dtb-review-dialog__eyebrow">Product feedback</span>
              <h2 id="dtb-review-dialog-title">Write a review</h2>
              {productName && <p className="dtb-review-dialog__product">{productName}</p>}
            </div>
            <button ref={closeButtonRef} type="button" className="dtb-review-dialog__close" onClick={onCloseReviewForm} aria-label="Close review form">×</button>
          </header>
          <p id="dtb-review-dialog-description" className="dtb-reviews__form-note">Your feedback helps other pros choose the right tool. Submitted reviews are moderated before publishing.</p>
          <form onSubmit={handleSubmit} className="dtb-review-dialog__form">
            <fieldset className="dtb-reviews__field">
              <legend className="dtb-reviews__label">Your rating</legend>
              <div className="dtb-reviews__star-picker" role="group" aria-label="Choose a rating">
                {[1, 2, 3, 4, 5].map((value) => (
                  <button type="button" key={value} onClick={() => setRating(value)} className={'dtb-reviews__star-btn' + (value <= rating ? ' is-active' : '')} aria-label={value + ' star' + (value === 1 ? '' : 's')} aria-pressed={value === rating}>★</button>
                ))}
              </div>
            </fieldset>
            <div className="dtb-reviews__field">
              <label className="dtb-reviews__label" htmlFor={'dtb-review-content-' + productId}>Your review</label>
              <textarea id={'dtb-review-content-' + productId} className="dtb-reviews__textarea" rows={5} maxLength={3000} value={content} onChange={(event) => setContent(event.target.value)} placeholder="How has this product worked for you?" required />
              <span className="dtb-review-dialog__counter">{content.length}/3000</span>
            </div>
            {(error || submissionMessage) && <p className={error ? 'dtb-reviews__error' : 'dtb-reviews__notice'} role={error ? 'alert' : 'status'}>{error || submissionMessage}</p>}
            {!endpoint && <p className="dtb-reviews__error" role="alert">Reviews are unavailable for this product.</p>}
            <footer className="dtb-review-dialog__actions">
              <button type="button" className="dtb-review-dialog__cancel" onClick={onCloseReviewForm}>Cancel</button>
              <button type="submit" className="dtb-reviews__submit" disabled={submitting || !endpoint}>{submitting ? 'Submitting…' : 'Submit review'}</button>
            </footer>
            <p className="dtb-review-dialog__signin">You must be signed in to submit a review.</p>
          </form>
        </section>
      </div>,
      document.body
    )}
  </>;
}

import './pagination.css';
import { ChevronLeft, ChevronRight } from 'lucide-react';

/**
 * Stable, accessible catalog pagination.
 *
 * The component owns pagination presentation only; callers own URL state and
 * product data. Page tokens are windowed so large catalogs never render an
 * unbounded row of controls.
 */
export default function Pagination({
  currentPage,
  totalPages,
  onPageChange,
  totalItems = null,
  startItem = null,
  endItem = null,
  itemLabel = 'products',
  className = '',
}) {
  const hasItemCount = Number.isFinite(totalItems)
    && Number.isFinite(startItem)
    && Number.isFinite(endItem)
    && totalItems > 0;
  const hasMultiplePages = totalPages > 1;

  if (!hasMultiplePages && !hasItemCount) return null;

  const buildPageTokens = () => {
    const pages = new Set([1, totalPages]);
    const wing = 2;

    for (let page = currentPage - wing; page <= currentPage + wing; page += 1) {
      if (page >= 1 && page <= totalPages) pages.add(page);
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const tokens = [];

    sorted.forEach((page, index) => {
      if (index > 0 && page - sorted[index - 1] > 1) tokens.push('…');
      tokens.push(page);
    });

    return tokens;
  };

  const tokens = hasMultiplePages ? buildPageTokens() : [];

  const changePage = (nextPage) => {
    if (nextPage < 1 || nextPage > totalPages || nextPage === currentPage) return;
    onPageChange(nextPage);
  };

  return (
    <section className={`pgn-11 ${className}`.trim()} aria-label="Catalog pagination">
      <div className="pgn-11__stage">
        {hasItemCount && (
          <p className="pgn-11__count" aria-live="polite">
            Showing <b>{startItem.toLocaleString()}–{endItem.toLocaleString()}</b> of{' '}
            <b>{totalItems.toLocaleString()}</b> {itemLabel}
          </p>
        )}

        {hasMultiplePages && (
          <nav className="pgn-11__nav" aria-label="Product pagination">
            <button
              className="pgn-11__edge pgn-11__edge--previous"
              type="button"
              disabled={currentPage === 1}
              onClick={() => changePage(currentPage - 1)}
              aria-label="Previous page"
            >
              <ChevronLeft size={16} strokeWidth={2} aria-hidden="true" />
              <span>Previous</span>
            </button>

            <ol className="pgn-11__list">
              {tokens.map((token, index) => (
                token === '…' ? (
                  <li key={`gap-${index}`} aria-hidden="true" className="pgn-11__gap">…</li>
                ) : (
                  <li key={token}>
                    <button
                      className="pgn-11__num"
                      type="button"
                      aria-current={token === currentPage ? 'page' : undefined}
                      aria-label={`Page ${token}`}
                      onClick={() => changePage(token)}
                    >
                      {token}
                    </button>
                  </li>
                )
              ))}
            </ol>

            <button
              className="pgn-11__edge pgn-11__edge--next"
              type="button"
              disabled={currentPage === totalPages}
              onClick={() => changePage(currentPage + 1)}
              aria-label="Next page"
            >
              <span>Next</span>
              <ChevronRight size={16} strokeWidth={2} aria-hidden="true" />
            </button>
          </nav>
        )}
      </div>
    </section>
  );
}

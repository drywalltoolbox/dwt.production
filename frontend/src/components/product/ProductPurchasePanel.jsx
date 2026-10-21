import { Minus, Plus } from 'lucide-react';
import AddToCartButton from '../ui/AddToCartButton.jsx';

export default function ProductPurchasePanel({
  quantity,
  onDecrease,
  onIncrease,
  onQuantityChange,
  onAddToCart,
  canAddToCart,
  isOutOfStock,
  needsVariation,
  hasCompleteSelection,
  addToCartState = 'idle',
  expressCheckout = null,
  setSummary = null,
  onViewIncludes,
}) {
  const handleInputChange = (e) => {
    const val = parseInt(e.target.value, 10);
    if (Number.isFinite(val) && val >= 1 && val <= 99) {
      onQuantityChange?.(val);
    }
  };

  const purchaseBusy = addToCartState === 'adding' || addToCartState === 'added';
  const addToCartLabel = isOutOfStock
    ? 'Out of Stock'
    : needsVariation && !hasCompleteSelection
      ? 'Select Options'
      : 'Add to Cart';
  const includedItemCount = Number.isInteger(setSummary?.itemCount) && setSummary.itemCount > 0
    ? setSummary.itemCount
    : 0;
  const hasSetSummary = Boolean(setSummary && typeof onViewIncludes === 'function');

  return (
    <div
      className="product-detail-purchase-panel dtb-pdp-purchase-panel"
      aria-busy={purchaseBusy}
    >
      {hasSetSummary ? (
        <div className="dtb-pdp-set-summary">
          <div className="dtb-pdp-set-summary__copy">
            <strong>Complete Professional Set</strong>
            {includedItemCount > 0 ? (
              <span>
                {includedItemCount} included {includedItemCount === 1 ? 'item' : 'items'}
              </span>
            ) : null}
          </div>
          <button
            type="button"
            className="dtb-pdp-set-summary__action"
            onClick={onViewIncludes}
          >
            View what&apos;s included
            <span aria-hidden="true">→</span>
          </button>
        </div>
      ) : null}

      <span className="dtb-pdp-purchase-panel__quantity-label">Quantity</span>
      <div className="dtb-pdp-purchase-row">
        <div className="dtb-pdp-qty-root" role="group" aria-label="Quantity">
          <button
            type="button"
            onClick={onDecrease}
            disabled={purchaseBusy || quantity <= 1}
            className="dtb-pdp-qty-btn"
            aria-label="Decrease quantity"
          >
            <Minus size={15} strokeWidth={2.4} />
          </button>
          <input
            type="number"
            className="dtb-pdp-qty-input"
            value={quantity}
            min={1}
            max={99}
            onChange={handleInputChange}
            disabled={purchaseBusy}
            aria-label="Quantity"
          />
          <button
            type="button"
            onClick={onIncrease}
            disabled={purchaseBusy || quantity >= 99}
            className="dtb-pdp-qty-btn"
            aria-label="Increase quantity"
          >
            <Plus size={15} strokeWidth={2.4} />
          </button>
        </div>

        <AddToCartButton
          onClick={onAddToCart}
          disabled={!canAddToCart || purchaseBusy}
          className="dtb-pdp-add-to-cart"
          size="wide"
          label={addToCartLabel}
          state={addToCartState}
          feedbackMode="controlled"
        />
      </div>

      {expressCheckout}
    </div>
  );
}

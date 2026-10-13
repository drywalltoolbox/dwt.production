import React from 'react';

export default function ProductPurchaseActions({ quantity, onQuantityChange, onAdd, onBuy, onWishlist, wishlisted, canPurchase, addLabel = 'Add to cart', buyLabel = 'Buy now' }) {
  const change = (delta) => onQuantityChange((current) => Math.max(1, current + delta));
  return <div className="product-purchase">
    <div className="product-purchase__row">
      <div className="product-purchase__quantity" aria-label="Quantity">
        <button type="button" onClick={() => change(-1)} disabled={quantity <= 1} aria-label="Decrease quantity">−</button>
        <output aria-live="polite" aria-label={`Quantity ${quantity}`}>{quantity}</output>
        <button type="button" onClick={() => change(1)} aria-label="Increase quantity">+</button>
      </div>
      <button type="button" className="product-purchase__add" onClick={onAdd} disabled={!canPurchase || !onAdd}>{canPurchase ? addLabel : 'Unavailable'}</button>
      {onWishlist && <button type="button" className={`product-purchase__wishlist ${wishlisted ? 'is-active' : ''}`} onClick={onWishlist} aria-label={wishlisted ? 'Remove from wishlist' : 'Add to wishlist'} aria-pressed={wishlisted}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.1-8.8 10.2-8.8 10.2S3.2 12.9 3.2 8.8A4.7 4.7 0 0 1 12 6.1a4.7 4.7 0 0 1 8.8 2.7Z" /></svg></button>}
    </div>
    {onBuy && <button type="button" className="product-purchase__buy" onClick={onBuy} disabled={!canPurchase}>{buyLabel}</button>}
  </div>;
}

import React from 'react';

export default function ProductDetails({ items = [] }) {
  return <div className="product-details">
    {items.map((item) => <details className="product-details__item" key={item.id || item.title}>
      <summary>{item.title}</summary><div className="product-details__content">{typeof item.content === 'string' ? <p>{item.content}</p> : item.content}</div>
    </details>)}
  </div>;
}

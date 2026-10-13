import React, { useState } from 'react';

export default function ProductGallery({ images = [], productName = 'Product' }) {
  const [activeIndex, setActiveIndex] = useState(0);
  const [failed, setFailed] = useState({});
  const active = images[activeIndex];
  return <section className="product-gallery" aria-label={`${productName} images`}>
    <div className="product-gallery__main">
      {active && !failed[active.src] ? <img src={active.src} alt={active.alt || productName} width={active.width} height={active.height} fetchPriority="high" onError={() => setFailed((value) => ({ ...value, [active.src]: true }))} />
        : <div className="product-gallery__empty" role="img" aria-label={active?.alt || `${productName} image unavailable`}>Image unavailable</div>}
    </div>
    {images.length > 1 && <div className="product-gallery__thumbnails" role="group" aria-label="Choose product image">
      {images.map((image, index) => <button key={`${image.src}-${index}`} type="button" className={`product-gallery__thumbnail ${index === activeIndex ? 'is-active' : ''}`} onClick={() => setActiveIndex(index)} aria-label={`Show image ${index + 1}: ${image.alt || productName}`} aria-pressed={index === activeIndex}>
        {failed[image.src] ? <span aria-hidden="true">Image</span> : <img src={image.src} alt="" width={image.width} height={image.height} loading="lazy" onError={() => setFailed((value) => ({ ...value, [image.src]: true }))} />}
      </button>)}
    </div>}
  </section>;
}

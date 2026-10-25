import { Link } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

/** Full-page PDP information only; product, variation and cart state stay upstream. */
export default function ProductInformationAccordion({ description, features, specs, partsUrl, specificationsRequest = 0 }) {
  const [openKeys, setOpenKeys] = useState([]);
  const [renderedKeys, setRenderedKeys] = useState([]);
  const [dismissedSpecsRequest, setDismissedSpecsRequest] = useState(0);
  const specificationsButtonRef = useRef(null);
  const requestedSpecsOpen = specificationsRequest > 0 && dismissedSpecsRequest !== specificationsRequest;
  const isOpen = (key) => openKeys.includes(key) || (key === 'specs' && requestedSpecsOpen);
  // The parent request reaches this component after React commits its open
  // state. Scroll the matching Specifications control, not a global selector,
  // so full PDP and Quick View always move their own scroll container.
  useEffect(() => {
    if (!requestedSpecsOpen || typeof window === 'undefined') return undefined;

    const frame = window.requestAnimationFrame(() => {
      const button = specificationsButtonRef.current;
      if (!button) return;

      button.focus({ preventScroll: true });
      const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
      button.scrollIntoView({
        behavior: prefersReducedMotion ? 'auto' : 'smooth',
        block: 'center',
        inline: 'nearest',
      });
    });

    return () => window.cancelAnimationFrame(frame);
  }, [requestedSpecsOpen, specificationsRequest]);
  const toggle = (key) => {
    if (isOpen(key)) {
      setOpenKeys((current) => current.filter((item) => item !== key));
      if (key === 'specs' && requestedSpecsOpen) setDismissedSpecsRequest(specificationsRequest);
      return;
    }

    setRenderedKeys((current) => current.includes(key) ? current : [...current, key]);
    setOpenKeys((current) => current.includes(key) ? current : [...current, key]);
  };

  const sections = [
    { key: 'description', title: 'Description', content: description },
    { key: 'features', title: 'Features', content: features || <p>Product features are not separately listed.</p> },
    { key: 'specs', title: 'Specifications', content: specs || <p>No additional specifications are listed.</p> },
    { key: 'parts', title: 'Parts & Schematics', content: (
      <p>Use the model diagram and part number to identify a replacement, then confirm fit before ordering. <Link to={partsUrl}>Browse parts and schematics</Link>.</p>
    ) },
    { key: 'shipping', title: 'Shipping & Returns', content: (
      <p>Shipping charges are calculated at checkout. <Link to="/shipping-policy">Shipping policy</Link> · <Link to="/return-policy">Returns policy</Link></p>
    ) },
  ];

  return (
    <section className="dtb-pdp-info-accordion" aria-label="Product information">
      {sections.map(({ key, title, content }) => (
        <div className="dtb-pdp-info-accordion__item" key={key}>
          <h2 className="dtb-pdp-info-accordion__heading">
            <button ref={key === "specs" ? specificationsButtonRef : undefined} type="button" aria-expanded={isOpen(key)} aria-controls={`dtb-pdp-detail-${key}`} data-pdp-section={key} onClick={() => toggle(key)}>
              <span>{title}</span><ChevronDown size={17} aria-hidden="true" className={isOpen(key) ? 'is-open' : ''} />
            </button>
          </h2>
          <div
            id={`dtb-pdp-detail-${key}`}
            className="dtb-pdp-info-accordion__panel-motion"
            data-open={isOpen(key)}
            aria-hidden={!isOpen(key)}
            inert={!isOpen(key)}
          >
            <div className="dtb-pdp-info-accordion__panel-clip">
              {(renderedKeys.includes(key) || (key === 'specs' && specificationsRequest > 0)) && (
                <div className="dtb-pdp-info-accordion__panel">{content}</div>
              )}
            </div>
          </div>
        </div>
      ))}
    </section>
  );
}

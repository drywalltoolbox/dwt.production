import { Link } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';
import { ArrowRight, ChevronDown } from 'lucide-react';

/** Full-page PDP information only; product, variation and cart state stay upstream. */
export default function ProductInformationAccordion({ description, features, specs, partsUrl, specificationsRequest = 0 }) {
  const [openKeys, setOpenKeys] = useState([]);
  const [renderedKeys, setRenderedKeys] = useState([]);
  const [dismissedSpecsRequest, setDismissedSpecsRequest] = useState(0);
  const specificationsButtonRef = useRef(null);
  const requestedSpecsOpen = specificationsRequest > 0 && dismissedSpecsRequest !== specificationsRequest;
  const isOpen = (key) => openKeys.includes(key) || (key === 'specs' && requestedSpecsOpen);
  // The requested panel can be taller than a mobile viewport. Align its heading
  // just below the visible sticky navigation, rather than centering the heading
  // (which leaves most of the included items below the fold).
  useEffect(() => {
    if (!requestedSpecsOpen || typeof window === 'undefined') return undefined;

    let secondFrame;
    const frame = window.requestAnimationFrame(() => {
      secondFrame = window.requestAnimationFrame(() => {
        const button = specificationsButtonRef.current;
        if (!button) return;

        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        const behavior = reducedMotion ? 'auto' : 'smooth';
        const scrollShell = button.closest('.product-modal-scroll-shell');
        button.focus({ preventScroll: true });

        if (scrollShell) {
          const shellRect = scrollShell.getBoundingClientRect();
          const buttonRect = button.getBoundingClientRect();
          scrollShell.scrollTo({
            top: scrollShell.scrollTop + buttonRect.top - shellRect.top - 12,
            behavior,
          });
          return;
        }

        // Account for the actual on-screen header, including the mobile
        // shipping bar, logo row, and search row; CSS token heights alone
        // don't describe this compound sticky header.
        let headerBottom = 0;
        // Sticky positioning may belong to an outer header wrapper rather
        // than the semantic <header> itself. Inspect each banner's ancestors
        // so the destination lands below the entire visible mobile app shell.
        document.querySelectorAll('header, [role="banner"]').forEach((header) => {
          for (let element = header; element && element !== document.body; element = element.parentElement) {
            const position = window.getComputedStyle(element).position;
            if (position !== 'sticky' && position !== 'fixed') continue;
            const rect = element.getBoundingClientRect();
            if (rect.top <= 2 && rect.bottom > 0 && rect.bottom < window.innerHeight) {
              headerBottom = Math.max(headerBottom, rect.bottom);
            }
          }
        });
        const heading = button.closest('.dtb-pdp-info-accordion__heading') || button;
        window.scrollTo({
          top: window.scrollY + heading.getBoundingClientRect().top - headerBottom - 12,
          behavior,
        });
      });
    });

    return () => {
      window.cancelAnimationFrame(frame);
      if (secondFrame) window.cancelAnimationFrame(secondFrame);
    };
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
      <div className="dtb-pdp-parts-guide">
        <p className="dtb-pdp-parts-guide__copy">
          <strong>Choose your tool model in our interactive schematic, find the part number, then confirm fit before ordering.</strong>
        </p>
        <Link to={partsUrl} className="dtb-pdp-parts-guide__link">
          Explore schematics &amp; parts
          <ArrowRight size={16} strokeWidth={2.1} aria-hidden="true" />
        </Link>
      </div>
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

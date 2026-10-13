import { Link } from 'react-router-dom';
import { useState } from 'react';
import { ChevronDown } from 'lucide-react';

/** Presentation-only native details. Never duplicates variant or cart state. */
export default function ProductInformationAccordion({ description, specs, reviews, partsUrl, sku, brand }) {
  const [openKeys, setOpenKeys] = useState([]);
  const toggle = (key) => setOpenKeys((current) => current.includes(key) ? current.filter((item) => item !== key) : [...current, key]);
  const sections = [
    { key: 'description', title: 'Description', content: description },
    { key: 'additional', title: 'Additional Information', content: (sku || brand) ? <dl className="dtb-pdp-info-accordion__facts">{sku && <><dt>SKU</dt><dd>{sku}</dd></>}{brand && <><dt>Brand</dt><dd>{brand}</dd></>}</dl> : null },
    { key: 'specs', title: 'Specifications', content: specs },
    { key: 'parts', title: 'Parts & Repair', content: <p>Check compatibility before ordering replacement parts. <Link to={partsUrl}>Browse parts and schematics</Link></p> },
    { key: 'schematics', title: 'Schematics', content: <p><Link to={partsUrl}>Explore compatible schematics and parts</Link></p> },
    { key: 'shipping', title: 'Shipping / Returns', content: <p>Shipping charges are calculated at checkout. <Link to="/shipping-policy">Shipping policy</Link> · <Link to="/returns">Returns policy</Link></p> },
    { key: 'reviews', title: 'Customer Reviews', content: reviews },
  ].filter((section) => section.content);
  return (
    <section className="dtb-pdp-info-accordion" aria-label="Product information">
      {sections.map(({ key, title, content }) => (
        <div className="dtb-pdp-info-accordion__item" key={key}>
          <h2 className="dtb-pdp-info-accordion__heading">
            <button type="button" aria-expanded={openKeys.includes(key)} aria-controls={`dtb-pdp-detail-${key}`} onClick={() => toggle(key)}>
              <span>{title}</span><ChevronDown size={17} aria-hidden="true" className={openKeys.includes(key) ? 'is-open' : ''} />
            </button>
          </h2>
          {openKeys.includes(key) && <div id={`dtb-pdp-detail-${key}`} className="dtb-pdp-info-accordion__panel">{content}</div>}
        </div>
      ))}
    </section>
  );
}

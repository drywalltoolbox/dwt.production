import { Link } from 'react-router-dom';
import { useState } from 'react';
import { ChevronDown } from 'lucide-react';

/** Full-page PDP information only; product, variation and cart state stay upstream. */
export default function ProductInformationAccordion({ description, features, specs, partsUrl }) {
  const [openKeys, setOpenKeys] = useState([]);
  const toggle = (key) => setOpenKeys((current) => current.includes(key)
    ? current.filter((item) => item !== key)
    : [...current, key]);

  const sections = [
    { key: 'description', title: 'Description', content: description },
    { key: 'features', title: 'Features', content: features || <p>Product features are not separately listed.</p> },
    { key: 'specs', title: 'Specifications', content: specs || <p>No additional specifications are listed.</p> },
    { key: 'parts', title: 'Parts & Schematics', content: (
      <p>Confirm compatibility before ordering replacement parts. <Link to={partsUrl}>Browse compatible parts and schematics</Link>.</p>
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

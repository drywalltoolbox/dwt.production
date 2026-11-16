import { Link } from 'react-router-dom';
import SEOHead from '../components/shared/SEOHead';
import { CATALOGUES, cataloguePageUrl } from '../data/catalogues.js';
import '../styles/catalogues.css';

export default function Catalogues() {
  return (
    <main className="catalogues-page">
      <SEOHead
        title="Product Catalogues"
        description="Browse the Columbia Tools and TapeTech product catalogues as interactive flipbooks, then shop the tools on Drywall Toolbox."
        canonical="/catalogues"
      />
      <p className="catalogues-page__eyebrow">Catalogues</p>
      <h1>Product catalogues</h1>
      <p className="catalogues-page__intro">
        Browse the full manufacturer catalogues as interactive flipbooks — turn the pages,
        jump to a section, or zoom in on a tool — then shop the range on Drywall Toolbox.
      </p>
      <ul className="catalogues-page__grid">
        {CATALOGUES.map((catalogue) => (
          <li className="catalogue-card" key={catalogue.slug}>
            <img
              src={cataloguePageUrl(catalogue, 1)}
              alt={`${catalogue.title} cover`}
              loading="lazy"
              decoding="async"
            />
            <div className="catalogue-card__body">
              <h2>{catalogue.title}</h2>
              <p>{catalogue.description}</p>
              <p className="catalogue-card__meta">
                {catalogue.pageCount} pages · {catalogue.brand}
              </p>
              <div className="catalogue-card__actions">
                <Link to={`/catalogues/${catalogue.slug}`}>Open flipbook</Link>
                {catalogue.shopLink && <Link to={catalogue.shopLink}>{catalogue.shopLabel}</Link>}
              </div>
            </div>
          </li>
        ))}
      </ul>
    </main>
  );
}

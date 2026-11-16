import { Link, useParams } from 'react-router-dom';
import SEOHead from '../components/shared/SEOHead';
import FlipbookViewer from '../components/catalogues/FlipbookViewer.jsx';
import { getCatalogue } from '../data/catalogues.js';
import '../styles/catalogues.css';

export default function CatalogueViewer() {
  const { slug } = useParams();
  const catalogue = getCatalogue(slug);

  if (!catalogue) {
    return (
      <main className="catalogue-viewer__not-found">
        <SEOHead
          noindex
          title="Catalogue not found"
          description="The catalogue you asked for is not available. Browse the available product catalogues."
          canonical="/catalogues"
        />
        <h1>Catalogue not found</h1>
        <p>
          We do not have a catalogue called “{slug}”. Browse the catalogues we do have,
          or shop the full range instead.
        </p>
        <p>
          <Link to="/catalogues">Browse all catalogues</Link>
          {' · '}
          <Link to="/products">Shop products</Link>
        </p>
      </main>
    );
  }

  return (
    <main className="catalogues-page">
      <SEOHead
        title={catalogue.title}
        description={catalogue.description}
        canonical={`/catalogues/${catalogue.slug}`}
      />
      <p className="catalogues-page__eyebrow">
        <Link to="/catalogues">Catalogues</Link>
        {' / '}
        {catalogue.brand}
      </p>
      <h1>{catalogue.title}</h1>
      <p className="catalogues-page__intro">
        {catalogue.pageCount} pages. Turn the pages with a drag, swipe, tap, or the arrow keys.
        {catalogue.shopLink && (
          <>
            {' '}
            <Link to={catalogue.shopLink}>{catalogue.shopLabel}</Link>
          </>
        )}
      </p>
      <FlipbookViewer catalogue={catalogue} />
    </main>
  );
}

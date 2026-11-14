import { useLocation } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
import './products.css';
import './products-selector.css';
import '../styles/tool-selector.css';
import '../styles/selector-cards.css';

function ProductsHero() {
  return (
    <InteriorPageHero
      id="dtb-products-hero-title"
      title="All Products"
      description="Professional drywall tools, parts and supplies."
      breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'All Products' }]}
    />
  );
}

export default function Products(props) {
  const location = useLocation();
  const isAllProductsRoute = location.pathname === '/products';
  const isSearchResultsPage = isAllProductsRoute && Boolean(new URLSearchParams(location.search).get('search')?.trim());

  return (
    <div className="dtb-products-page">
      {isAllProductsRoute && !isSearchResultsPage ? <ProductsHero /> : null}
      <ProductsCatalogPlatform {...props} />
    </div>
  );
}

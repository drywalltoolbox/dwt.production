import { ArrowRight, ShoppingCart } from 'lucide-react';
import { Link } from 'react-router-dom';
import { getBrandLogo } from '../../utils/brandAssets.js';
import ProductRating from '../product/ProductRating.jsx';
import ToolSetComparison from './ToolSetComparison.jsx';
import { buildCategoryPageUrl } from '../../utils/catalogFacets.js';
import '../../styles/tool-sets-kits-experience.css';

const IMAGE_ROOT = 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/categories/thumbnails';
const WORKFLOWS = [
  { title: 'Hand finishing', description: 'Tell our team what you finish by hand. We will help identify a suitable setup while the catalog has no dedicated hand-tool range.', to: '/contact?inquiry=product_guidance&topic=hand-finishing', action: 'Ask for setup help' },
  { title: 'Semi-auto upgrade', description: 'Start with banjo tapers and semi-automatic tools while keeping familiar finishing methods.', category: 'semi-automatic-tapers-banjos', action: 'Shop semi-auto tapers' },
  { title: 'Flat-box setup', description: 'Compare flat boxes first, then check the required handle and loading equipment.', category: 'flat-boxes', action: 'Shop flat boxes' },
  { title: 'Full automatic system', description: 'Compare listed taping, loading, flat, and corner tools in the sets below.', to: '#dtb-set-comparison-title', action: 'Compare sets' },
];

function productImage(product) {
  return product?.image || product?.image_url || product?.images?.[0]?.src || `${IMAGE_ROOT}/automatic-taping-tool-sets.webp`;
}

function productPrice(product) {
  const amount = Number(String(product?.price ?? '').replace(/[^0-9.]/g, ''));
  return Number.isFinite(amount) && amount > 0 ? amount : null;
}

export default function ToolSetsKitsExperience({ category, breadcrumbs = [], products = [], brands = [], brand = '', onAddToCart, onOpenProduct }) {
  const featured = products.slice(0, 4);
  const trustedBrands = brands
    .map((brand) => ({ name: brand.name || brand.label, src: getBrandLogo(brand.name || brand.label) || brand.logo, to: `/products/brands/${brand.slug}` }))
    .filter((brand) => brand.name && brand.src && brand.to)
    .slice(0, 7);
  return (
    <section className="dtb-toolsets-page" aria-label="Tool Sets and Kits">
      <div className="dtb-toolsets-hero">
        <div className="dtb-toolsets-hero__crumbs">
          {breadcrumbs.map((crumb, index) => (
            <span key={crumb.path || crumb.label}>
              {index ? <b aria-hidden="true">›</b> : null}
              {crumb.path && index < breadcrumbs.length - 1 ? <Link to={crumb.path}>{crumb.label}</Link> : <strong>{crumb.label}</strong>}
            </span>
          ))}
        </div>
        <div className="dtb-toolsets-hero__copy">
          <p>Taping &amp; Finishing Tools</p>
          <h1>{category.label}</h1>
          <div>Choose tools by the work you perform, then compare the exact items listed in each set. Review current price and product details before ordering.</div>
        </div>
      </div>

      <div className="dtb-toolsets-content">
        <header className="dtb-toolsets-section-head">
          <p>Choose a system</p>
          <h2>Start with the work your crew performs.</h2>
          <span>Start with individual tools, an upgrade path, or a complete set.</span>
        </header>
        <div className="dtb-toolsets-system-grid">
          {WORKFLOWS.map(({ title, description, to, category: categorySlug, action }) => {
            const destination = categorySlug ? buildCategoryPageUrl(categorySlug, brand) : to;
            const content = <div className="dtb-toolsets-system__body"><div className="dtb-toolsets-system__copy"><h3>{title}</h3><p>{description}</p><span className="dtb-toolsets-system__destination">{action}</span></div><span className="dtb-toolsets-system__action"><ArrowRight aria-hidden="true" /></span></div>;
            return destination.startsWith('#')
              ? <a className="dtb-toolsets-system" href={destination} key={title}>{content}</a>
              : <Link className="dtb-toolsets-system" to={destination} key={title}>{content}</Link>;
          })}
        </div>

        <ToolSetComparison products={products} />

        <div className="dtb-toolsets-featured-head">
          <div><h2>Featured Tool Sets &amp; Kits</h2><span>Explore available systems selected for professional drywall work.</span></div>
          <a href="#dtb-category-filters">View all Tool Sets &amp; Kits <ArrowRight aria-hidden="true" /></a>
        </div>
        {featured.length > 0 && <div className="dtb-toolsets-featured-grid">
          {featured.map((product) => {
            const price = productPrice(product);
            const productPath = product.slug ? `/products/${product.slug}` : `/product/${product.id}`;
            const canAddDirectly = !product.is_variable && typeof onAddToCart === 'function';
            return <article className="dtb-toolsets-product" key={product.id || product.sku || product.slug}>
              <Link className="dtb-toolsets-product__media" to={productPath} aria-label={`View ${product.name}`}><img src={productImage(product)} alt="" loading="lazy" /></Link>
              <div className="dtb-toolsets-product__body"><Link to={productPath}><h3>{product.name}</h3></Link><ProductRating rating={product.average_rating ?? product.rating} ratingCount={product.rating_count ?? product.reviews} compact /><div className="dtb-toolsets-product__purchase">{price ? <strong>${price.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong> : <span>See details</span>}{canAddDirectly ? <button type="button" onClick={() => onAddToCart(product.cardProduct || product)}><ShoppingCart aria-hidden="true" />Add to cart</button> : <button type="button" onClick={() => onOpenProduct?.(product)}>Options <ArrowRight aria-hidden="true" /></button>}</div></div>
            </article>;
          })}
        </div>}
        {trustedBrands.length > 0 ? <section className="dtb-toolsets-trusted-brands" aria-labelledby="dtb-toolsets-trusted-brands-title">
          <div className="dtb-toolsets-trusted-brands__copy"><p>Trusted brands</p><span id="dtb-toolsets-trusted-brands-title">Build with the industry&apos;s most trusted brands.</span></div>
          <div className="dtb-toolsets-trusted-brands__logos">{trustedBrands.map((brand) => <Link key={brand.to} to={brand.to} className="dtb-toolsets-trusted-brands__logo" aria-label={`Shop ${brand.name}`}><img src={brand.src} alt={brand.name} loading="lazy" decoding="async" /></Link>)}</div>
          <Link className="dtb-toolsets-trusted-brands__all" to="/products/brands">Shop All Brands <ArrowRight aria-hidden="true" /></Link>
        </section> : null}
      </div>
    </section>
  );
}

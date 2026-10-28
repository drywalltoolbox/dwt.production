import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { buildCategoryPageUrl } from '../../utils/catalogFacets.js';
import { getCategoryMerchandising, resolveIntentTarget } from '../../data/categoryMerchandising.js';
import ToolSetHelpLink from './ToolSetHelpLink.jsx';
import '../../styles/category-merchandising.css';
import '../../styles/contractor-shopping.css';

const TOOL_SET_HELP_CATEGORY_SLUGS = new Set([
  'automatic-tool-sets-kits',
  'automatic-tool-sets',
  'semi-automatic-tool-sets-kits',
  'tool-sets-kits',
  'tool-sets-and-kits',
  'toolsets',
]);

function categorySlug(category = {}) {
  return String(category?.slug || category?.key || category?.id || '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-');
}

function IntentCard({ intent, target, brand }) {
  const hasRoutableTarget = Boolean(target?.slug);
  const content = (
    <>
      <span className="dtb-category-intent-card__title">{intent.label}</span>
      <span className="dtb-category-intent-card__description">{intent.description}</span>
      {hasRoutableTarget && (
        <span className="dtb-category-intent-card__action">
          Shop {target.name || target.label || intent.label}
          <ArrowRight size={14} aria-hidden="true" />
        </span>
      )}
    </>
  );

  if (!hasRoutableTarget) {
    return (
      <article className="dtb-category-intent-card dtb-category-intent-card--informational">
        {content}
      </article>
    );
  }

  return (
    <Link className="dtb-category-intent-card" to={buildCategoryPageUrl(target.slug, brand)}>
      {content}
    </Link>
  );
}

export default function CategoryMerchandising({ category, brand = '' }) {
  if (TOOL_SET_HELP_CATEGORY_SLUGS.has(categorySlug(category))) {
    return <ToolSetHelpLink />;
  }

  const config = getCategoryMerchandising(category);
  if (!config) return null;

  const children = Array.isArray(category?.children) ? category.children : [];
  const intents = Array.isArray(config.intents) ? config.intents : [];
  const hasMerchandisingBody = intents.length > 0 || Boolean(config.guide);

  if (!hasMerchandisingBody) return null;

  return (
    <section className="dtb-category-merchandising" aria-labelledby="dtb-category-merchandising-title">
      {intents.length > 0 ? (
        <>
          <div className="dtb-category-merchandising__head">
            <div>
              <p className="dtb-category-merchandising__eyebrow">{config.eyebrow}</p>
              <h2 id="dtb-category-merchandising-title">{config.title}</h2>
              <p>{config.description}</p>
            </div>
          </div>

          <div className="dtb-category-merchandising__grid">
            {intents.map((intent) => (
              <IntentCard
                key={intent.label}
                intent={intent}
                target={resolveIntentTarget(intent, children)}
                brand={brand}
              />
            ))}
          </div>
        </>
      ) : null}

      {config.guide && (
        <div className="dtb-category-buying-guide" aria-labelledby="dtb-category-buying-guide-title">
          <div className="dtb-category-buying-guide__intro">
            <p className="dtb-category-merchandising__eyebrow">Buying guide</p>
            <h3 id="dtb-category-buying-guide-title">{config.guide.title}</h3>
          </div>
          <div className="dtb-category-buying-guide__items">
            {config.guide.items.map((item) => (
              <article key={item.label}>
                <h4>{item.label}</h4>
                <p>{item.detail}</p>
              </article>
            ))}
          </div>
        </div>
      )}
    </section>
  );
}

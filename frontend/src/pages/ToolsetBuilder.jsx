import { useMemo } from 'react';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { dtbSpring } from '../motion/dtbMotion.js';
import { Link, useSearchParams } from 'react-router-dom';
import {
  ArrowRight,
  Box,
  Boxes,
  CheckCircle2,
  Flame,
  Layers3,
  Link2,
  Settings2,
  Tag,
  WandSparkles,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead.jsx';
import Breadcrumb from '../components/shared/Breadcrumb.jsx';
import ToolsetBuilderWorkspace from '../features/toolset-builder/ToolsetBuilderWorkspace.jsx';
import {
  TOOLSET_WORKFLOWS,
  getToolsetWorkflow,
} from '../features/toolset-builder/model.js';
import { getBrandLogo } from '../utils/brandAssets.js';
import toolsetHeroImage from '../assets/media/toolset/toolset-hero-automatic-tapers.webp';
import { resolveCategoryThumbnail } from '../utils/categoryThumbnailImages.js';
import '../styles/toolset-builder.css';

const WORKFLOW_ICONS = {
  full: Boxes,
  finishing: Layers3,
  taping: WandSparkles,
  flatbox: Box,
};

const TOOLSET_BUILDER_BRANDS = [
  { name: 'TapeTech', slug: 'tapetech' },
  { name: 'Columbia Tools', slug: 'columbia-tools' },
  { name: 'Platinum Drywall Tools', slug: 'platinum-drywall-tools' },
  { name: 'Level5', slug: 'level5' },
  { name: 'SurPro', slug: 'surpro' },
  { name: 'Dura-Stilts', slug: 'dura-stilts' },
  { name: 'Asgard', slug: 'asgard' },
  { name: 'Graco', slug: 'graco' },
].map((brand) => ({ ...brand, logo: getBrandLogo(brand.name) }));

const WORKFLOW_MEDIA = {
  full: resolveCategoryThumbnail({ slug: 'automatic-taping-tool-sets' }),
  finishing: resolveCategoryThumbnail({ slug: 'flat-boxes' }),
  taping: resolveCategoryThumbnail({ slug: 'automatic-tapers' }),
  flatbox: resolveCategoryThumbnail({ slug: 'flat-boxes' }),
};

function WorkflowCard({ workflow, onSelect }) {
  const reduceMotion = useReducedMotion();
  const Icon = WORKFLOW_ICONS[workflow.id] || Settings2;
  const media = WORKFLOW_MEDIA[workflow.id] || toolsetHeroImage;

  return (
    <Motion.button
      type="button"
      className="dtb-toolset-workflow-card"
      onClick={() => onSelect(workflow.id)}
      whileHover={reduceMotion ? undefined : { y: -2 }}
      whileTap={reduceMotion ? undefined : { scale: 0.992 }}
      transition={dtbSpring.responsive}
    >
      <span className="dtb-toolset-workflow-card__media" aria-hidden="true">
        <img src={media} alt="" loading="lazy" decoding="async" />
        <span className="dtb-toolset-workflow-card__icon">
          <Icon size={24} />
        </span>
      </span>
      <span className="dtb-toolset-workflow-card__copy">
        <span className="dtb-toolset-workflow-card__label">{workflow.label}</span>
        <span className="dtb-toolset-workflow-card__description">{workflow.description}</span>
        <span className="dtb-toolset-workflow-card__meta">{workflow.capabilities.length} tool categories</span>
      </span>
      <span className="dtb-toolset-workflow-card__arrow" aria-hidden="true">
        <ArrowRight size={20} />
      </span>
    </Motion.button>
  );
}

function BuilderLanding({ onSelectWorkflow }) {
  return (
    <>
      <section className="dtb-toolset-hero" aria-labelledby="toolset-builder-title">
        <img
          className="dtb-toolset-hero__media"
          src={toolsetHeroImage}
          alt=""
          aria-hidden="true"
          fetchPriority="high"
          decoding="async"
        />
        <div className="dtb-toolset-hero__scrim" aria-hidden="true" />
        <div className="dtb-container dtb-container--wide">
          <div className="dtb-toolset-hero__breadcrumb">
            <Breadcrumb items={[{ label: 'Home', path: '/' }, { label: 'Toolset Builder' }]} />
          </div>

          <div className="dtb-toolset-hero__grid">
            <div className="dtb-toolset-hero__copy">
              <h1 id="toolset-builder-title">
                <span>Toolset</span> <em>Builder</em>
              </h1>
              <p>
                Configure the exact setup for the work you do, with compatible
                tools from the brands professionals trust.
              </p>
              <div className="dtb-toolset-hero__actions">
                <button type="button" className="dtb-toolset-hero__primary" onClick={() => onSelectWorkflow('full')}>
                  Start Building <ArrowRight size={19} aria-hidden="true" />
                </button>
                <a className="dtb-toolset-hero__secondary" href="#toolset-workflow-title">
                  Explore Workflows <ArrowRight size={17} aria-hidden="true" />
                </a>
              </div>
            </div>
          </div>

          <dl className="dtb-toolset-hero__proofs" aria-label="Toolset builder benefits">
            <div>
              <span className="dtb-toolset-hero__proof-icon" aria-hidden="true"><Settings2 size={19} /></span>
              <span>
                <dt>Built for your workflow</dt>
                <dd>Start with a proven setup and make it your own.</dd>
              </span>
            </div>
            <div>
              <span className="dtb-toolset-hero__proof-icon" aria-hidden="true"><Link2 size={19} /></span>
              <span>
                <dt>Mix top brands</dt>
                <dd>Use the tools you trust across the major brands.</dd>
              </span>
            </div>
            <div>
              <span className="dtb-toolset-hero__proof-icon" aria-hidden="true"><CheckCircle2 size={19} /></span>
              <span>
                <dt>See it all together</dt>
                <dd>View your complete set as you build.</dd>
              </span>
            </div>
          </dl>
        </div>
      </section>

      <section className="dtb-toolset-workflow-section" aria-labelledby="toolset-workflow-title">
        <div className="dtb-container dtb-container--wide">
          <div className="dtb-toolset-workflow-shell">
            <header className="dtb-toolset-section-heading">
              <div className="dtb-toolset-section-heading__eyebrow">
                <Flame size={18} aria-hidden="true" />
                <span>Popular Workflows</span>
              </div>
              <div className="dtb-toolset-section-heading__row">
                <div>
                  <h2 id="toolset-workflow-title">Start with a proven setup</h2>
                  <p>Choose a workflow, then customize every tool and configuration.</p>
                </div>
                <a href="#toolset-workflow-grid" className="dtb-toolset-section-heading__action">
                  View All <ArrowRight size={17} aria-hidden="true" />
                </a>
              </div>
            </header>

            <div id="toolset-workflow-grid" className="dtb-toolset-workflow-grid">
              {TOOLSET_WORKFLOWS.map((workflow) => (
                <WorkflowCard
                  key={workflow.id}
                  workflow={workflow}
                  onSelect={onSelectWorkflow}
                />
              ))}
            </div>
            <div className="dtb-toolset-workflow-dots" aria-hidden="true">
              <span className="is-active" />
              <span />
              <span />
            </div>
          </div>
        </div>
      </section>

      <section className="dtb-toolset-brand-section" aria-labelledby="toolset-brand-title">
        <div className="dtb-container dtb-container--wide">
          <header className="dtb-toolset-brand-section__heading">
            <div>
              <div className="dtb-toolset-brand-section__eyebrow">
                <Tag size={18} aria-hidden="true" />
                <span id="toolset-brand-title">Shop by Brand Compatibility</span>
              </div>
              <p>Build with the major drywall-tool brands you already use.</p>
            </div>
            <Link to="/products/brands" className="dtb-toolset-brand-section__action">
              View All Brands <ArrowRight size={17} aria-hidden="true" />
            </Link>
          </header>

          <div className="dtb-toolset-brand-grid" aria-label="Compatible major brands">
            {TOOLSET_BUILDER_BRANDS.map((brand) => (
              <Link key={brand.slug} to={`/products/brands/${brand.slug}`} aria-label={`Shop ${brand.name}`}>
                {brand.logo ? (
                  <img src={brand.logo} alt={brand.name} width="150" height="46" loading="lazy" decoding="async" />
                ) : (
                  <span>{brand.name}</span>
                )}
              </Link>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}

export default function ToolsetBuilder() {
  const [searchParams, setSearchParams] = useSearchParams();
  const workflowId = searchParams.get('workflow') || '';
  const workflow = useMemo(() => getToolsetWorkflow(workflowId), [workflowId]);

  const selectWorkflow = (nextWorkflowId) => {
    const next = new URLSearchParams(searchParams);
    next.set('workflow', nextWorkflowId);
    setSearchParams(next, { replace: false });
  };

  const clearWorkflow = () => {
    const next = new URLSearchParams(searchParams);
    next.delete('workflow');
    setSearchParams(next, { replace: false });
  };

  return (
    <div className="dtb-page dtb-toolset-page">
      <SEOHead
        title="Toolset Builder"
        description="Build a custom professional drywall tool set from live catalog products, exact variations, and workflow-focused tool categories."
        canonical="/toolset-builder"
        noindex
      />

      {workflow ? (
        <section className="dtb-section dtb-toolset-builder-section">
          <div className="dtb-container dtb-container--full">
            <ToolsetBuilderWorkspace
              key={workflow.id}
              workflow={workflow}
              onChangeWorkflow={clearWorkflow}
            />
          </div>
        </section>
      ) : (
        <BuilderLanding onSelectWorkflow={selectWorkflow} />
      )}
    </div>
  );
}

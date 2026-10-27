import BackButton from '../../components/shared/BackButton.jsx';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowRight,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  PackageOpen,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  X,
} from 'lucide-react';
import Breadcrumb from '../../components/shared/Breadcrumb.jsx';
import ToolsetBuilderProductCard from './ToolsetBuilderProductCard.jsx';
import useToolsetBuilderCatalog from './useToolsetBuilderCatalog.js';
import {
  flattenToolsetSelections,
  getCapabilitySelectionCount,
  getWorkflowCompletion,
  isCapabilityComplete,
} from './model.js';

function formatCurrency(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(number);
}

function selectionLabel(item) {
  return [item?.name, item?.variationLabel].filter(Boolean).join(' · ');
}

function capabilityInstruction(capability) {
  const minimum = Number(capability?.minimum || 0);
  const maximum = Number(capability?.maximum || minimum);
  const label = String(capability?.label || 'tool').toLowerCase();

  if (minimum === maximum && minimum === 1) {
    const singular = label.endsWith('s') ? label.slice(0, -1) : label;
    return `Choose one ${singular} to get started.`;
  }
  if (minimum === maximum) {
    return `Choose ${minimum} ${label} to continue.`;
  }
  return `Choose ${minimum}–${maximum} ${label} to continue.`;
}

function BrandFilterMenu({ brands, value, onChange }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;

    const closeOnPointerOutside = (event) => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('pointerdown', closeOnPointerOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnPointerOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  const chooseBrand = (nextBrand) => {
    onChange(nextBrand);
    setOpen(false);
  };

  return (
    <div ref={rootRef} className="dtb-toolset-brand-filter">
      <button
        type="button"
        className="dtb-toolset-brand-filter__trigger"
        aria-expanded={open}
        aria-controls="toolset-brand-filter-menu"
        onClick={() => setOpen((current) => !current)}
      >
        <SlidersHorizontal size={17} aria-hidden="true" />
        <span>{value || 'All brands'}</span>
        <ChevronDown size={16} aria-hidden="true" />
      </button>

      {open ? (
        <div id="toolset-brand-filter-menu" className="dtb-toolset-brand-filter__menu" role="menu" aria-label="Filter by brand">
          <button type="button" role="menuitemradio" aria-checked={!value} className={!value ? 'is-selected' : ''} onClick={() => chooseBrand('')}>
            <span>All brands</span>
            {!value ? <Check size={16} aria-hidden="true" /> : null}
          </button>
          {brands.map((brandName) => (
            <button type="button" key={brandName} role="menuitemradio" aria-checked={value === brandName} className={value === brandName ? 'is-selected' : ''} onClick={() => chooseBrand(brandName)}>
              <span>{brandName}</span>
              {value === brandName ? <Check size={16} aria-hidden="true" /> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function BuilderProgress({ workflow, selections, currentIndex, onSelectStep }) {
  const completion = getWorkflowCompletion(workflow, selections);

  return (
    <nav className="dtb-toolset-progress" aria-label="Toolset builder progress">
      <ol className="dtb-toolset-progress__steps">
        {workflow.capabilities.map((capability, index) => {
          const complete = isCapabilityComplete(capability, selections);
          const current = index === currentIndex;
          const requirement = Number(capability.minimum || 0) > 0
            ? `Choose ${capability.minimum}`
            : 'Optional';

          return (
            <li key={capability.id}>
              <button
                type="button"
                className={'dtb-toolset-progress__step' + (complete ? ' is-complete' : '') + (current ? ' is-current' : '')}
                onClick={() => onSelectStep(index)}
                aria-current={current ? 'step' : undefined}
              >
                <span className="dtb-toolset-progress__number">
                  {complete ? <Check size={14} aria-hidden="true" /> : index + 1}
                </span>
                <span className="dtb-toolset-progress__step-copy">
                  <strong>{capability.label}</strong>
                  <small className="dtb-toolset-progress__step-requirement">{requirement}</small>
                  <small className="dtb-toolset-progress__step-state">{complete ? 'Selected' : current ? 'In progress' : 'Required'}</small>
                </span>
                <ChevronRight size={16} aria-hidden="true" />
              </button>
            </li>
          );
        })}
      </ol>
      <div className="dtb-toolset-progress__meter">
        <div className="dtb-toolset-progress__bar" aria-hidden="true">
          <span style={{ width: completion.percent + '%' }} />
        </div>
        <div className="dtb-toolset-progress__summary">
          <span>{completion.completed} / {completion.total} configured</span>
        </div>
      </div>
    </nav>
  );
}

function ToolsetSummary({
  workflow,
  selections,
  completion,
  onRemove,
  onReview,
}) {
  const items = flattenToolsetSelections(workflow, selections);
  const estimatedSubtotal = items.reduce((sum, item) => (
    Number.isFinite(Number(item.price)) ? sum + Number(item.price) : sum
  ), 0);

  return (
    <aside className="dtb-toolset-summary" aria-labelledby="toolset-summary-title">
      <div className="dtb-toolset-summary__head">
        <div>
          <h2 id="toolset-summary-title">Your Tool Set</h2>
        </div>
        <span>{completion.completed}/{completion.total}</span>
      </div>

      <div className="dtb-toolset-summary__selection-list">
        {items.length === 0 ? (
          <p className="dtb-toolset-summary__empty">Choose your first tool to begin the set.</p>
        ) : items.map((item) => (
          <article className="dtb-toolset-summary__item" key={item.capabilityId + ':' + item.key}>
            <span className="dtb-toolset-summary__media" aria-hidden="true">
              {item.image ? <img src={item.image} alt="" loading="lazy" decoding="async" /> : <PackageOpen size={19} />}
            </span>
            <div className="dtb-toolset-summary__identity">
              <span className="dtb-toolset-summary__category">{item.capabilityLabel}</span>
              <strong className="dtb-toolset-summary__name">{selectionLabel(item)}</strong>
              <div className="dtb-toolset-summary__meta">
                {item.sku ? <small>SKU {item.sku}</small> : <span />}
                <span className="dtb-toolset-summary__price">{formatCurrency(item.price)}</span>
              </div>
            </div>
            <button
              type="button"
              className="dtb-toolset-summary__remove"
              onClick={() => onRemove(item.capabilityId, item.key)}
              aria-label={'Remove ' + selectionLabel(item)}
            >
              <X size={15} aria-hidden="true" />
            </button>
          </article>
        ))}
      </div>

      <div className="dtb-toolset-summary__totals">
        <div>
          <span>Estimated subtotal</span>
          <strong>{formatCurrency(estimatedSubtotal)}</strong>
        </div>
        <p>Price and availability are confirmed before purchase.</p>
      </div>

      <button
        type="button"
        className="dtb-toolset-primary-action"
        disabled={!completion.isComplete}
        onClick={onReview}
      >
        Review set
        <ArrowRight size={18} aria-hidden="true" />
      </button>
    </aside>
  );
}

function ReviewSet({ workflow, selections, onBackToBuilder }) {
  const items = flattenToolsetSelections(workflow, selections);
  const estimatedSubtotal = items.reduce((sum, item) => (
    Number.isFinite(Number(item.price)) ? sum + Number(item.price) : sum
  ), 0);

  return (
    <section className="dtb-toolset-review" aria-labelledby="toolset-review-title">
      <div className="dtb-toolset-review__heading">
        <BackButton onClick={onBackToBuilder} label="Continue editing" />
        <h1 id="toolset-review-title">{workflow.label}</h1>
        <p>Review your selected products and configurations.</p>
      </div>

      <div className="dtb-toolset-review__grid">
        <div className="dtb-toolset-review__items">
          {items.map((item) => (
            <article className="dtb-toolset-review__item" key={item.capabilityId + ':' + item.key}>
              <span className="dtb-toolset-review__media">
                {item.image ? <img src={item.image} alt="" loading="lazy" decoding="async" /> : <PackageOpen size={26} aria-hidden="true" />}
              </span>
              <div>
                <span>{item.capabilityLabel}</span>
                <h2>{selectionLabel(item)}</h2>
                <p>{[item.brand, item.sku ? 'SKU ' + item.sku : ''].filter(Boolean).join(' · ')}</p>
              </div>
              <strong>{formatCurrency(item.price)}</strong>
            </article>
          ))}
        </div>

        <aside className="dtb-toolset-review__checkout">
          <ShieldCheck size={28} aria-hidden="true" />
          <h2>Ready for validation</h2>
          <p>We’ll confirm your complete set before it is added to cart.</p>
          <div className="dtb-toolset-review__subtotal">
            <span>Estimated subtotal</span>
            <strong>{formatCurrency(estimatedSubtotal)}</strong>
          </div>
          <button type="button" className="dtb-toolset-primary-action" disabled>
            Add set to cart
          </button>
        </aside>
      </div>
    </section>
  );
}

export default function ToolsetBuilderWorkspace({ workflow, onChangeWorkflow }) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [selections, setSelections] = useState({});
  const [brand, setBrand] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reviewing, setReviewing] = useState(false);

  const capability = workflow.capabilities[currentIndex];
  const currentSelections = Array.isArray(selections[capability.id]) ? selections[capability.id] : [];
  const completion = getWorkflowCompletion(workflow, selections);

  const {
    items,
    pagination,
    loading,
    error,
    retry,
  } = useToolsetBuilderCatalog({
    toolFamily: capability.toolFamily,
    displayCategory: capability.displayCategory || '',
    brand,
    search,
    page,
    enabled: !reviewing,
  });

  const availableBrands = useMemo(() => {
    const labels = new Set();
    items.forEach((product) => {
      const label = product?.brand?.label || product?.brandLabel || '';
      if (label) labels.add(label);
    });
    if (brand) labels.add(brand);
    return Array.from(labels).sort((a, b) => a.localeCompare(b));
  }, [brand, items]);

  const setStep = (index) => {
    if (index < 0 || index >= workflow.capabilities.length) return;
    setCurrentIndex(index);
    setBrand('');
    setSearch('');
    setSearchInput('');
    setPage(1);
  };

  const addSelection = (item) => {
    setSelections((current) => {
      const existing = Array.isArray(current[capability.id]) ? current[capability.id] : [];
      if (existing.some((selected) => selected.key === item.key)) return current;
      if (existing.length >= capability.maximum) return current;
      return { ...current, [capability.id]: [...existing, item] };
    });
  };

  const removeSelection = (capabilityId, key) => {
    setSelections((current) => {
      const existing = Array.isArray(current[capabilityId]) ? current[capabilityId] : [];
      const next = existing.filter((item) => item.key !== key);
      if (next.length === existing.length) return current;
      return { ...current, [capabilityId]: next };
    });
  };

  const submitSearch = (event) => {
    event.preventDefault();
    setSearch(searchInput.trim());
    setPage(1);
  };

  if (reviewing) {
    return (
      <ReviewSet
        workflow={workflow}
        selections={selections}
        onBackToBuilder={() => setReviewing(false)}
      />
    );
  }

  const completeCurrentCapability = isCapabilityComplete(capability, selections);
  const selectedCount = getCapabilitySelectionCount(selections, capability.id);
  const canAddMore = selectedCount < capability.maximum;
  const selectedItems = flattenToolsetSelections(workflow, selections);
  const estimatedSubtotal = selectedItems.reduce((sum, item) => (
    Number.isFinite(Number(item.price)) ? sum + Number(item.price) : sum
  ), 0);

  const advanceStep = () => {
    if (!completeCurrentCapability) return;
    if (currentIndex >= workflow.capabilities.length - 1) {
      setReviewing(true);
      return;
    }
    setStep(currentIndex + 1);
  };

  return (
    <div className="dtb-toolset-workspace">
      <header className="dtb-toolset-workspace__heading">
        <Breadcrumb
          items={[
            { label: 'Toolset Builder', onClick: onChangeWorkflow },
            { label: workflow.label },
          ]}
        />

        <div className="dtb-toolset-workspace__hero">
          <div className="dtb-toolset-workspace__hero-content">
            <div className="dtb-toolset-workspace__eyebrow-row">
              <span className="dtb-toolset-workspace__eyebrow">Toolset Builder</span>
              <span className="dtb-toolset-workspace__eyebrow-rule" aria-hidden="true" />
            </div>
            <h1>{workflow.label}</h1>
            <p>Customize your setup. Configure each required tool before review.</p>
          </div>

          <div className="dtb-toolset-workspace__hero-art" aria-hidden="true">
            <span className="dtb-toolset-workspace__slash dtb-toolset-workspace__slash--one" />
            <span className="dtb-toolset-workspace__slash dtb-toolset-workspace__slash--two" />
            <span className="dtb-toolset-workspace__slash dtb-toolset-workspace__slash--three" />
            <div className="dtb-toolset-workspace__statement">
              <span>Choose Your Tools</span>
              <span>Configure Your Set</span>
              <span>Build Your System</span>
              <span className="dtb-toolset-workspace__statement-rule" />
            </div>
          </div>
        </div>
      </header>

      <div className="dtb-toolset-workspace__layout">
        <BuilderProgress
          workflow={workflow}
          selections={selections}
          currentIndex={currentIndex}
          onSelectStep={setStep}
        />

        <section className="dtb-toolset-catalog" aria-labelledby="toolset-capability-title">
          <header className="dtb-toolset-catalog__head">
            <div className="dtb-toolset-catalog__title-row">
              <div>
                <h2 id="toolset-capability-title">Select {capability.label}</h2>
                <p>{capabilityInstruction(capability)}</p>
              </div>
              <span className="dtb-toolset-catalog__requirement">
                {capability.minimum === capability.maximum
                  ? 'Choose ' + capability.minimum
                  : 'Choose ' + capability.minimum + '–' + capability.maximum}
              </span>
            </div>
          </header>

          <div className="dtb-toolset-catalog__controls">
            <form className="dtb-toolset-search" onSubmit={submitSearch} role="search">
              <Search size={18} aria-hidden="true" />
              <label className="sr-only" htmlFor="toolset-product-search">Search products</label>
              <input
                id="toolset-product-search"
                type="search"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder={'Search ' + capability.label.toLowerCase()}
              />
              <button type="submit">Search</button>
            </form>

            <BrandFilterMenu
              brands={availableBrands}
              value={brand}
              onChange={(nextBrand) => {
                setBrand(nextBrand);
                setPage(1);
              }}
            />
          </div>

          {!canAddMore ? (
            <div className="dtb-toolset-catalog__status">
              <strong>Selection limit reached</strong>
            </div>
          ) : null}

          {loading ? (
            <div className="dtb-toolset-product-grid" aria-busy="true" aria-label="Loading products">
              {Array.from({ length: 6 }, (_, index) => (
                <div className="dtb-toolset-product-skeleton" key={index} aria-hidden="true">
                  <span />
                  <span />
                  <span />
                </div>
              ))}
            </div>
          ) : error ? (
            <div className="dtb-toolset-catalog__message" role="alert">
              <h2>Could not load this tool category</h2>
              <p>{error.message}</p>
              <button type="button" onClick={retry}>Try again</button>
            </div>
          ) : items.length === 0 ? (
            <div className="dtb-toolset-catalog__message">
              <PackageOpen size={30} aria-hidden="true" />
              <h2>No matching products</h2>
              <p>Try another search or clear the brand filter.</p>
              {(brand || search) ? (
                <button
                  type="button"
                  onClick={() => {
                    setBrand('');
                    setSearch('');
                    setSearchInput('');
                    setPage(1);
                  }}
                >
                  Clear filters
                </button>
              ) : null}
            </div>
          ) : (
            <div className="dtb-toolset-product-grid">
              {items.map((product) => (
                <ToolsetBuilderProductCard
                  key={product.id}
                  product={product}
                  selectedItems={currentSelections}
                  maximum={capability.maximum}
                  onSelect={addSelection}
                  onRemove={(key) => removeSelection(capability.id, key)}
                />
              ))}
            </div>
          )}

          {pagination.totalPages > 1 ? (
            <nav className="dtb-toolset-pagination" aria-label="Product results pages">
              <button
                type="button"
                disabled={pagination.page <= 1 || loading}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                <ChevronLeft size={17} aria-hidden="true" />
                Previous
              </button>
              <span>Page {pagination.page} of {pagination.totalPages}</span>
              <button
                type="button"
                disabled={pagination.page >= pagination.totalPages || loading}
                onClick={() => setPage((current) => Math.min(pagination.totalPages, current + 1))}
              >
                Next
                <ChevronRight size={17} aria-hidden="true" />
              </button>
            </nav>
          ) : null}

          <div className="dtb-toolset-step-actions">
            <BackButton disabled={currentIndex === 0} onClick={() => setStep(currentIndex - 1)} label="Previous category" />
            <button
              type="button"
              className="dtb-toolset-primary-action"
              disabled={!completeCurrentCapability}
              onClick={advanceStep}
            >
              {currentIndex >= workflow.capabilities.length - 1 ? 'Review set' : 'Next category'}
              <ArrowRight size={17} aria-hidden="true" />
            </button>
          </div>
        </section>

        <ToolsetSummary
          workflow={workflow}
          selections={selections}
          completion={completion}
          onRemove={removeSelection}
          onReview={() => setReviewing(true)}
        />
      </div>

      <div className="dtb-toolset-mobile-bar" aria-label="Toolset build summary">
        <div className="dtb-toolset-mobile-bar__progress">
          <span>{completion.completed} of {completion.total} configured</span>
          <div aria-hidden="true"><span style={{ width: completion.percent + '%' }} /></div>
        </div>
        <div className="dtb-toolset-mobile-bar__subtotal">
          <span>Set Subtotal</span>
          <strong>{formatCurrency(estimatedSubtotal)}</strong>
        </div>
        <button
          type="button"
          className="dtb-toolset-mobile-bar__continue"
          disabled={!completeCurrentCapability}
          onClick={advanceStep}
        >
          <span>{currentIndex >= workflow.capabilities.length - 1 ? 'Review Set' : 'Continue to Next Step'}</span>
          <ArrowRight size={18} aria-hidden="true" />
        </button>
      </div>
    </div>
  );
}

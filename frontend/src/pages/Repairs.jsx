import { useState, useRef, useCallback, useMemo, useEffect } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { m as Motion } from 'framer-motion';
import { PackageCheck } from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import Dropdown from '../components/ui/Dropdown';
import { useCatalogFacets } from '../hooks/useCatalogFacets.js';
import { canonicalBrandLabel } from '../utils/catalogUrlState.js';
import { SCHEMATIC_DEFINITIONS } from '../data/schematicMappings';
import {
  normalizeRepairCategory,
  getOfficialRepairBrands,
  getOfficialRepairBrandsForCategory,
  getOfficialRepairModelsForBrandCategory,
} from '../data/repairCatalogMap.js';
import {
  REPAIR_SERVICE_TOOL_CATEGORIES,
  REPAIR_TOOL_FAMILIES,
  getRepairPackageById,
  getRepairPackagesForToolFamily,
  getRepairToolFamilyFromCategory,
} from '../data/repairPackages.js';
import { uploadRepairMedia } from '../api/repairs.js';
import veeqoService from '../services/veeqo';
import TransactionSuccessSheet from '../components/confirmation/TransactionSuccessSheet.jsx';
import ResponsivePicture from '../components/media/ResponsivePicture.jsx';
import { RESPONSIVE_HERO_MEDIA } from '../assets/media/responsiveHeroMedia.js';
import '../styles/repairs-workflow.css';
import '../styles/repair-step-nav.css';

/* ─────────────────────────────────────────────────────────────────────────────
   Brands & tools sourced from schematicMappings — the single source of truth
   for every tool we carry schematics / repair support for.
   ───────────────────────────────────────────────────────────────────────── */
const SUPPORTED_BRANDS = Object.keys(SCHEMATIC_DEFINITIONS).sort((a, b) => a.localeCompare(b)); // alphabetical

function normalizeCategoryForBrand(category, _brand) {
  return normalizeRepairCategory(category);
}

/**
 * Returns the tool model objects for a specific brand + category combination.
 * Each object has { value, label } where value is the full display string
 * stored in form state and label is the same (title + MPN when available).
 */
function getModelsForBrandCategory(brand, category) {
  const entries = SCHEMATIC_DEFINITIONS[brand];
  if (!entries || !category) return [];
  return entries
    .filter((t) => normalizeCategoryForBrand(t.category, brand) === category)
    .map((t) => {
      const label = t.mpn ? `${t.title} — ${t.mpn}` : t.title;
      return { value: label, label };
    })
    .sort((a, b) => a.label.localeCompare(b.label));
}

function brandHasRepairModelsForCategory(brand, category) {
  if (!brand || !category) return true;
  return (
    getOfficialRepairModelsForBrandCategory(brand, category).length > 0 ||
    getModelsForBrandCategory(brand, category).length > 0
  );
}

function getLiveBrandsFromFacets(facets) {
  if (!Array.isArray(facets?.brands)) return [];
  return facets.brands
    .map((b) => canonicalBrandLabel(b?.label || b?.name || b?.key || b?.slug || ''))
    .filter(Boolean)
    .filter((v, i, a) => a.indexOf(v) === i)
    .sort((a, b) => a.localeCompare(b));
}

/**
 * Builds a human-readable tool description for the success screen.
 */
function getToolDisplayName({ toolBrand, toolModel, toolCategory }) {
  return [toolBrand, toolModel || toolCategory].filter(Boolean).join(' — ');
}

const BLANK_FORM = {
  fullName: '', email: '', phone: '', company: '',
  toolBrand: '', toolCategory: '', toolModel: '', serialNumber: '', toolAge: '',
  // serviceType: human-readable label displayed in Step 5 Review (e.g. "Standard Repair ($85–$195)")
  // pricingTierId/packageId: machine-readable package id for logic, validation, and backend intake.
  // Both set together when the user picks a tier card in Step 3.
  serviceType: '', pricingTierId: '', packageId: '', priority: '', issueStart: '', issueDescription: '',
  approvalMode: 'quote_required',
  preapprovalLimit: '',
  warrantyRequested: 'no',
  purchaseDate: '',
  oldPartsReturn: 'discard',
  inboundShippingMethod: 'customer_pays_carrier',
  returnShippingPreference: 'standard',
  contactPreference: 'email',
  // Shipping fields (Step 4)
  address: '', city: '', state: '', zip: '', country: 'US',
  shippingRateId: '', shippingRateName: '', shippingRatePrice: null,
};

const STEPS = [
  { id: 1, label: 'Contact Info',    short: 'Contact'  },
  { id: 2, label: 'Tool Details',    short: 'Tool'     },
  { id: 3, label: 'Service Request', short: 'Service'  },
  { id: 4, label: 'Shipping',        short: 'Shipping' },
  { id: 5, label: 'Review & Submit', short: 'Review'   },
];

/* ─────────────────────────────────────────────────────────────────────────────
   Service cards shown above the form
   ───────────────────────────────────────────────────────────────────────── */
// ─── Authoritative repair pricing — sourced from DTB_Strategy_Overview.md ────
// Display rules:
//   • "Best Value" tag on the recommended tier per category
//   • Auto Taper anchor line displayed when toolCategory is an auto taper
//   • Member discounts (10% / 15%) shown only to authenticated members in Step 5

export const REPAIR_PRICING = {
  autoTaper: {
    label: 'Auto Taper',
    tiers: [
      { id: 'qt',  name: 'Quick Fix',         desc: 'Minor issues, adjustments, and basic wear-part service',         badge: null },
      { id: 'sr',  name: 'Standard Rebuild',  desc: 'Most common full rebuild path for worn-out tapers',             badge: null },
      { id: 'po',  name: 'Premium Overhaul',  desc: 'Comprehensive teardown and high-mileage restoration',           badge: null },
      { id: 'ftu', name: 'Factory Tune-Up',   desc: 'Preventive maintenance, cleaning, lubrication, and calibration', badge: null },
    ],
  },
  flatBoxes: {
    label: 'Flat & Angle Boxes',
    tiers: [
      { id: 'fr',  name: 'Refresh',           desc: 'Cleaning, adjustment, and performance check',                    badge: null },
      { id: 'rb',  name: 'Rebuild',           desc: 'Full disassembly with worn-part replacement as needed',          badge: null },
      { id: 'pp',  name: 'Multi-Tool Service', desc: 'Coordinated service for multiple boxes in one request',         badge: null },
      { id: 'tub', name: 'Tune-Up',           desc: 'Preventive cleaning and lubrication service',                    badge: null },
    ],
  },
  mudPumps: {
    label: 'Mud Pumps',
    tiers: [
      { id: 'ss',  name: 'Seal & Screen',     desc: 'Targeted service for common flow and seal issues',              badge: null },
      { id: 'fr2', name: 'Full Rebuild',      desc: 'Complete pump teardown and component restoration',              badge: null },
      { id: 'ph',  name: 'Pump + Hose',       desc: 'Full pump service including hose-system inspection',            badge: null },
      { id: 'ps',  name: 'Preventive',        desc: 'Preventive maintenance to reduce in-field downtime',            badge: null },
    ],
  },
  handles: {
    label: 'Handles & Accessories',
    tiers: [
      { id: 'hr',  name: 'Handle Rebuild',    desc: 'Standard handle service and hardware refresh',                  badge: null },
      { id: 'gn',  name: 'Gooseneck',         desc: 'Gooseneck service and functional adjustment',                   badge: null },
      { id: 'cf',  name: 'Corner Flusher',    desc: 'Corner flusher full-service restoration',                       badge: null },
      { id: 'ns',  name: 'Nail Spotter',      desc: 'Nail spotter inspection and service',                           badge: null },
      { id: 'bu',  name: 'Accessory Group Service', desc: 'Single request covering multiple accessories',             badge: null },
    ],
  },
  diagnostic: {
    label: 'Diagnostic',
    tiers: [
      { id: 'dx', name: 'Diagnostic Inspection',
        desc: 'Full inspection and written quote before any repair work begins.', badge: null },
    ],
  },
};

// ─── Full pricing tab data — sourced from research_repairs.md ────────────────
export const PRICING_TAB_DATA = [
  {
    id: 'autoTaper',
    label: 'Auto Tapers',
    shortLabel: 'Auto Tapers',
    anchor: { newPrice: '$1,899', rebuildPrice: '$299', savePct: '84%' },
    note: 'Rebuild vs. new taper: save up to 84% with a Standard Rebuild',
    tiers: [
      {
        id: 'qt', name: 'Quick Fix', price: '$75', badge: null,
        target: 'Ideal for minor symptoms and straightforward fixes',
        features: ['Blade + cable replacement', 'Lubrication service', 'Minor adjustments'],
      },
      {
        id: 'sr', name: 'Standard Rebuild', price: '$299', badge: 'Best Value',
        target: 'Most common service for worn-out tapers',
        features: ['Wheels, bushings & liners', 'Plunger cup, cable & blade', 'Needle + calibration', 'Full wear-kit replacement'],
      },
      {
        id: 'po', name: 'Premium Overhaul', price: '$499', badge: null,
        target: 'Ideal for high-volume professionals and fleet operators',
        features: ['Everything in Standard Rebuild', 'Chain/sprocket inspection', 'Tube flip option', 'Priority turnaround', '90-day workmanship warranty'],
      },
      {
        id: 'ftu', name: 'Factory Tune-Up', price: '$179', badge: null,
        target: 'Preventive care for maintenance-focused contractors',
        features: ['Deep cleaning & inspection', '11+ wear-part replacements', 'Performance report', 'Lubrication & calibration'],
      },
    ],
  },
  {
    id: 'flatBoxes',
    label: 'Flat & Angle Boxes',
    shortLabel: 'Flat Boxes',
    anchor: null,
    note: 'Bundle: Rebuild 3 boxes, get the 4th at 50% off',
    tiers: [
      {
        id: 'bwr', name: 'Blade & Wheel Refresh', price: '$49', badge: null,
        target: 'Quick performance boost',
        features: ['New blades + wheels', 'Basic adjustment & test', 'Clean & lubricate'],
      },
      {
        id: 'fbr', name: 'Full Box Rebuild', price: '$89', badge: 'Best Value',
        target: 'Standard repair for streaking or drag issues',
        features: ['Blades, wheels & skids', 'Springs, seals & O-rings', 'Full calibration', 'Comprehensive teardown'],
      },
      {
        id: 'pbp', name: 'Pro Box Package', price: '$149', badge: '3-Box Bundle',
        target: 'Best value for multiple boxes',
        features: ['Full rebuild on 3 boxes', 'Free handle inspection', '60-day workmanship warranty', 'Best rate per unit'],
      },
      {
        id: 'abtu', name: 'Annual Box Tune-Up', price: '$59/box', badge: null,
        target: 'Preventive maintenance plan',
        features: ['Preventive cleaning', 'Wear inspection', 'Minor adjustments', 'Per-box service'],
      },
    ],
  },
  {
    id: 'mudPumps',
    label: 'Mud Pumps',
    shortLabel: 'Mud Pumps',
    anchor: null,
    note: 'Comprehensive fluid system servicing for all major pump brands',
    tiers: [
      {
        id: 'sss', name: 'Seal & Screen Service', price: '$59', badge: null,
        target: 'Recommended for minor flow issues',
        features: ['Gaskets & u-cups', 'Screens & valve discs', 'Pressure test'],
      },
      {
        id: 'fpr', name: 'Full Pump Rebuild', price: '$119', badge: 'Best Value',
        target: 'Recommended for weak pressure or leakage',
        features: ['Complete disassembly', 'All wear parts replaced', 'Housing inspection', 'Flow calibration'],
      },
      {
        id: 'php', name: 'Pump + Hose Package', price: '$159', badge: null,
        target: 'Comprehensive fluid system service',
        features: ['Full pump rebuild', 'Hose inspection/repair', 'Fittings check', 'End-to-end fluid test'],
      },
      {
        id: 'ppt', name: 'Preventive Pump Tune', price: '$79', badge: null,
        target: 'Ideal for high-volume users preventing downtime',
        features: ['Cleaning & inspection', 'Seal inspection', 'Screen replacement', 'Lubrication'],
      },
    ],
  },
  {
    id: 'handles',
    label: 'Handles & Accessories',
    shortLabel: 'Accessories',
    anchor: null,
    note: 'Bundle: Handle + Gooseneck + Corner Flusher = $99 (save $38)',
    tiers: [
      {
        id: 'hr2', name: 'Handle Rebuild', price: '$49', badge: null,
        target: 'Any handle length',
        features: ['Brake adjusters', 'Springs & washers', 'Couplers', 'Any standard length'],
      },
      {
        id: 'gns', name: 'Gooseneck Service', price: '$39', badge: null,
        target: 'Corner tool stability',
        features: ['Pivot bushings', 'Tension adjustment', 'Lubrication'],
      },
      {
        id: 'cfr', name: 'Corner Flusher Rebuild', price: '$69', badge: null,
        target: 'Corner finishing quality',
        features: ['Blades & springs', 'Pivot point service', 'Calibration'],
      },
      {
        id: 'nss', name: 'Nail Spotter Service', price: '$45', badge: null,
        target: 'Consistent spotting results',
        features: ['Plunger service', 'Seal replacement', 'Trigger mechanism'],
      },
      {
        id: 'ab2', name: 'Accessory Bundle', price: '$99', badge: 'Bundle Savings',
        target: 'Complete accessory overhaul',
        features: ['Handle + Gooseneck + Corner Flusher', 'Save $38 vs individual', 'All three rebuilt together'],
      },
    ],
  },
  {
    id: 'shipping',
    label: 'Shipping & Logistics',
    shortLabel: 'Shipping',
    anchor: null,
    note: 'Transparent pricing — no hidden fees or surprise surcharges',
    tiers: [
      {
        id: 'srs', name: 'Standard Return Shipping', price: 'Actual cost', badge: null,
        target: 'Available to all customers',
        features: ['Customer pays actual carrier cost', 'Transparent; no markup', 'USPS, FedEx, or UPS'],
      },
      {
        id: 'er', name: 'Expedited Return (2-Day)', price: '+$25 flat', badge: 'Popular',
        target: 'Ideal for urgent jobs and tight deadlines',
        features: ['2-day air return', '+$25 flat fee', 'Track every step'],
      },
      {
        id: 'iu', name: 'Insurance Upgrade', price: '+$15', badge: null,
        target: 'Recommended for tools valued over $500',
        features: ['Full declared value coverage'],
      },
      {
        id: 'pk', name: 'Packaging Kit', price: '$12 credit', badge: null,
        target: 'Ideal for first-time shippers',
        features: ['Protective packaging supplied', '$12 credited toward repair'],
      },
      {
        id: 'ldp', name: 'Local Pickup / Drop-Off', price: 'FREE', badge: 'Free',
        target: 'Available to local customers',
        features: ['Free local pickup or drop-off', 'No carrier charge'],
      },
    ],
  },
];

const REPAIR_PRICING_DISCLOSURE = 'Prices shown are starting estimates, not final repair quotes. Final pricing is confirmed after your tool is received, checked in, and thoroughly inspected. Additional parts, labor, damage, missing components, or service needs may change the final quote. No additional work begins without your approval.';

const REPAIR_CARD_PRICE_NOTE = 'Starting estimate. Final quote confirmed after inspection.';

const WARRANTY_REQUEST_OPTIONS = [
  { value: 'no', label: 'Standard paid repair service' },
  { value: 'yes', label: 'Manufacturer warranty evaluation requested' },
  { value: 'not_sure', label: 'Eligibility review requested' },
];

function getWarrantyRequestLabel(value) {
  return WARRANTY_REQUEST_OPTIONS.find((option) => option.value === value)?.label || 'Standard paid repair service';
}

const MAINTENANCE_SCHEDULE = [
  { level: 'High-Volume Pro', usage: '6+ rolls (500 ft) / day', interval: 'Every 6 months', badge: 'Heavy' },
  { level: 'Standard Pro', usage: '4–10 rolls / week', interval: 'Annually', badge: 'Regular' },
  { level: 'Occasional User', usage: '<4 rolls / week', interval: 'Every 18–24 months', badge: 'Light' },
];


/* ─────────────────────────────────────────────────────────────────────────────
   Reusable labelled field wrapper
   ───────────────────────────────────────────────────────────────────────── */
function Field({ label, required, optional, children, hint }) {
  return (
    <div className="form-group" style={{ marginBottom: '14px' }}>
      <label className="machined-label" style={{ color: 'var(--primary-600)', marginBottom: 4, display: 'inline-flex', alignItems: 'baseline', gap: '6px' }}>
        <span>
          {label}{required && <span style={{ color: '#ef4444', marginLeft: 3 }}>*</span>}
        </span>
        {optional && (
          <span style={{ fontSize: '0.7rem', color: 'rgba(15,23,42,0.5)', lineHeight: 1 }}>
            Optional
          </span>
        )}
      </label>
      {hint && (
        <p style={{
          fontSize: '0.7rem',
          color: 'rgba(15,23,42,0.45)',
          margin: '0 0 4px 0',
        }}>
          {hint}
        </p>
      )}
      {children}
    </div>
  );
}

function RepairWorkflowSection({ steps }) {
  return (
    <section className="repairs-workflow-section" aria-labelledby="repairs-workflow-title">
      <div className="repairs-workflow-shell">
        <div className="repairs-workflow-intro">
          <p className="repairs-workflow-kicker">Repair workflow</p>
          <h2 id="repairs-workflow-title">How Repairs Work</h2>
        </div>

        <div className="repairs-workflow-track">
          {steps.map((step, index) => (
            <Motion.article
              key={step.title}
              className="repairs-workflow-card"
              initial={{ opacity: 0, y: 18 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true, margin: '-80px' }}
              transition={{ duration: 0.28, delay: index * 0.035, ease: 'easeOut' }}
            >
              <div className="repairs-workflow-card__top">
                <span className="repairs-workflow-card__number" aria-hidden="true">
                  {index + 1}
                </span>
              </div>
              <h3>{step.title}</h3>
              <p>{step.description}</p>
            </Motion.article>
          ))}
        </div>

        <Link to="/repairs/start" className="repairs-workflow-cta">
          Start a repair
          <PackageCheck size={17} aria-hidden="true" />
        </Link>
      </div>
    </section>
  );
}

export default function Repairs() {
  const serviceRoutes = [
    {
      to: '/repairs/start',
      title: 'Begin a Repair',
      description: 'Start the package-driven repair intake for one tool, with photos, shipping, warranty, and approval rules.',
      action: 'Start repair',
    },
    {
      to: '/repairs/packages',
      title: 'Compare Packages',
      description: 'Review standard rebuilds, tune-ups, and diagnostic quote-first service paths.',
      action: 'View packages',
    },
    {
      to: '/repairs/track',
      title: 'Track a Repair',
      description: 'Look up an existing repair by repair number and token, then review current status and next steps.',
      action: 'Track repair',
    },
  ];

  const processSteps = [
    {
      title: 'Tell us about your tool',
      description: 'Describe the issue and choose a repair, tune-up, or warranty evaluation.',
    },
    {
      title: 'Ship it or drop it off',
      description: 'Send your tool in or bring it by, whichever is easiest for you.',
    },
    {
      title: 'Approve your quote',
      description: 'We inspect the tool and send a quote. No work starts until you approve it.',
    },
    {
      title: 'Get it back and running',
      description: 'Track your repair in real time and get your tool back ready to work.',
    },
  ];

  return (
    <div style={{ minHeight: '100vh' }} className="page-wrapper">
      <SEOHead
        title="Drywall Tool Repair Services"
        description="Package-driven drywall tool repair services for TapeTech, Columbia, Asgard, Graco, and other professional finishing tools."
        canonical="/repairs"
      />

      <section style={{
        padding: 'clamp(70px, 10vw, 110px) clamp(1.5rem, 5vw, 3rem) clamp(44px, 7vw, 72px)',
        background: '#04172f',
        color: 'white',
      }}>
        <ResponsivePicture
          className="repair-workflow-hero__media"
          imageClassName="repair-workflow-hero__image"
          media={RESPONSIVE_HERO_MEDIA.repairs}
          decorative
          fetchPriority="high"
        />
        <div className="repair-workflow-hero__scrim" aria-hidden="true" />
        <div style={{ maxWidth: '1180px', margin: '0 auto' }}>
          <div style={{ maxWidth: '1120px' }}>
            <p className="dtb-title-eyebrow" style={{
              margin: '0 0 14px',
              color: 'rgba(219,234,254,0.86)',
              textTransform: 'uppercase',
              fontSize: '0.75rem',
              fontWeight: 900,
              letterSpacing: '0.12em',
            }}>
              Tool Repair & Maintenance
            </p>
            <h1 style={{
              margin: '0 0 18px',
              fontSize: 'clamp(2.4rem, 6vw, 4.9rem)',
              lineHeight: 0.96,
              fontWeight: 950,
              letterSpacing: '0',
            }}>
              KEEP YOUR TOOLS<br />
              <span style={{ color: '#93c5fd' }}>RUNNING STRONG.</span>
            </h1>
            <p style={{
              margin: '0 0 28px',
              color: 'rgba(255,255,255,0.78)',
              fontSize: 'clamp(1rem, 2vw, 1.18rem)',
              lineHeight: 1.65,
              maxWidth: '820px',
            }}>
              Professional drywall tool repair service for all tools. Submit a repair request, receive a professional
              evaluation, approve recommended work, and track progress every step of the way.
            </p>
            <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
              <Link to="/repairs/start" className="alloy-button" style={{
                textDecoration: 'none',
                background: '#fff',
                color: '#2255ee',
              }}>
                Start a repair
              </Link>
              <Link to="/repairs/packages" className="alloy-button" style={{
                textDecoration: 'none',
                background: 'rgba(255,255,255,0.08)',
                border: '1px solid rgba(255,255,255,0.32)',
              }}>
                View packages
              </Link>
            </div>
          </div>
        </div>
      </section>

      <section style={{ padding: 'clamp(2rem, 5vw, 4rem) clamp(1.5rem, 5vw, 3rem)', background: 'white' }}>
        <div style={{
          maxWidth: '1180px',
          margin: '0 auto',
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 260px), 1fr))',
          gap: '18px',
        }}>
          {serviceRoutes.map((route) => (
            <Link key={route.to} to={route.to} style={{ textDecoration: 'none', color: 'inherit' }}>
              <article style={{
                height: '100%',
                padding: '24px',
                border: '1px solid var(--machined-border)',
                borderRadius: '8px',
                background: '#fff',
                display: 'flex',
                flexDirection: 'column',
              }}>
                <h2 style={{ margin: '0 0 10px', color: '#0f172a', fontSize: '1.15rem', fontWeight: 900 }}>
                  {route.title}
                </h2>
                <p style={{ margin: '0 0 18px', color: 'rgba(15,23,42,0.62)', fontSize: '0.88rem', lineHeight: 1.55, flex: 1 }}>
                  {route.description}
                </p>
                <span style={{ color: 'var(--primary-600)', fontSize: '0.76rem', fontWeight: 900, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                  {route.action}
                </span>
              </article>
            </Link>
          ))}
        </div>
      </section>

      <RepairWorkflowSection steps={processSteps} />
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Step progress bar
   ───────────────────────────────────────────────────────────────────────── */
function ProgressBar({ step, total, onStepSelect }) {
  const pct = ((step - 1) / (total - 1)) * 100;

  return (
    <nav aria-label="Repair inquiry progress" className="repair-step-nav">
      <ol className="repair-step-nav__steps">
        {STEPS.map((s, index) => {
          const done = s.id < step;
          const active = s.id === step;
          const stateClass = done ? ' is-done' : active ? ' is-active' : '';

          return (
            <li key={s.id} className={`repair-step-nav__step${stateClass}`}>
              <button
                type="button"
                className="repair-step-nav__content"
                onClick={() => onStepSelect?.(s.id)}
                aria-current={active ? 'step' : undefined}
                aria-label={`Step ${s.id}: ${s.short}${done ? ', completed' : active ? ', current' : ''}`}
              >
                <span className="repair-step-nav__circle" aria-hidden="true">
                  {done
                    ? <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    : s.id}
                </span>
                <span className="repair-step-nav__info" aria-hidden="true">
                  <span className="repair-step-nav__label">Step {s.id}</span>
                  <span className="repair-step-nav__title">{s.short}</span>
                </span>
              </button>
              {index < STEPS.length - 1 && (
                <span className="repair-step-nav__connector" aria-hidden="true" />
              )}
            </li>
          );
        })}
      </ol>
      <div className="repair-step-nav__summary">
        <span className="repair-step-nav__summary-text">
          Step {step} of {total} — {STEPS[step - 1]?.short}
        </span>
        <span
          className="repair-step-nav__progress-track"
          role="progressbar"
          aria-label="Repair inquiry completion"
          aria-valuemin="1"
          aria-valuemax={total}
          aria-valuenow={step}
        >
          <span className="repair-step-nav__progress-bar" style={{ '--repair-progress': `${pct}%` }} />
        </span>
      </div>
    </nav>
  );
}

function SelectedPackageSummary({ pkg, formData, step }) {
  if (!pkg) return null;

  return (
    <div style={{
      display: 'flex',
      flexWrap: 'wrap',
      justifyContent: 'space-between',
      gap: '16px',
      alignItems: 'center',
      margin: '0 0 24px',
      padding: '14px 16px',
      border: '1.5px solid rgba(34,85,238,0.24)',
      borderRadius: '12px',
      background: 'linear-gradient(135deg, rgba(239,246,255,0.95), rgba(255,255,255,0.98))',
    }}>
      <div style={{ display: 'flex', gap: '12px', minWidth: 0 }}>
        <span style={{
          width: '34px',
          height: '34px',
          borderRadius: '10px',
          background: 'var(--primary-600)',
          color: 'white',
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0,
        }}>
          <PackageCheck size={18} aria-hidden="true" />
        </span>
        <div style={{ minWidth: 0 }}>
          <p style={{
            margin: '0 0 3px',
            color: 'var(--primary-600)',
            fontSize: '0.68rem',
            fontWeight: 900,
            letterSpacing: '0.08em',
            textTransform: 'uppercase',
          }}>
            Selected repair package
          </p>
          <h3 style={{
            margin: 0,
            color: '#0f172a',
            fontSize: 'clamp(0.95rem, 2vw, 1.08rem)',
            fontWeight: 850,
            lineHeight: 1.2,
          }}>
            {pkg.name}
          </h3>
          <p style={{ margin: '5px 0 0', color: 'rgba(15,23,42,0.58)', fontSize: '0.78rem', lineHeight: 1.45 }}>
            {pkg.priceLabel}
          </p>
        </div>
      </div>
      <Link
        to="/repairs/packages"
        state={{ repairFormResume: { formData, step } }}
        style={{
          color: 'var(--primary-600)',
          fontSize: '0.75rem',
          fontWeight: 850,
          textDecoration: 'none',
          whiteSpace: 'nowrap',
        }}
      >
        Change package
      </Link>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Review row helper
   ───────────────────────────────────────────────────────────────────────── */
function ReviewRow({ label, value }) {
  if (!value) return null;
  return (
    <div style={{
      display: 'flex',
      gap: '12px',
      alignItems: 'flex-start',
      padding: '10px 0',
      borderBottom: '1px solid rgba(15,23,42,0.06)',
    }}>
      <span style={{
        minWidth: '130px',
        fontSize: '0.72rem',
        fontWeight: 700,
        textTransform: 'uppercase',
        letterSpacing: '0.08em',
        color: 'rgba(15,23,42,0.45)',
        paddingTop: '2px',
      }}>
        {label}
      </span>
      <span style={{ fontSize: '0.875rem', color: 'black', lineHeight: 1.5, wordBreak: 'break-word' }}>
        {value}
      </span>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Single photo thumbnail with remove button
   ───────────────────────────────────────────────────────────────────────── */
function PhotoThumb({ photo, onRemove }) {
  return (
    <div style={{ position: 'relative', flexShrink: 0 }}>
      <img
        src={photo.preview}
        alt={photo.name}
        style={{
          width: '40px', height: '40px',
          objectFit: 'cover',
          borderRadius: '8px',
          border: '1px solid rgba(15,23,42,0.12)',
          display: 'block',
        }}
      />
      <button
        type="button"
        onClick={onRemove}
        aria-label={`Remove ${photo.name}`}
        style={{
          position: 'absolute',
          top: '-5px', right: '-5px',
          width: '16px', height: '16px',
          borderRadius: '50%',
          border: 'none',
          background: 'rgba(0,0,0,0.65)',
          color: 'white',
          cursor: 'pointer',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          padding: 0,
        }}
      >
        <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Photo uploader — compact icon trigger only; thumbnails rendered by parent
   ───────────────────────────────────────────────────────────────────────── */
const MAX_PHOTOS = 6;
const MAX_FILE_MB = 10;

function PhotoUploader({ photos, onChange }) {
  const fileInputRef = useRef(null);
  const cameraInputRef = useRef(null);

  const addFiles = useCallback((files) => {
    const MAX_BYTES = MAX_FILE_MB * 1024 * 1024;
    const incoming = Array.from(files).filter((f) => {
      if (!f.type.startsWith('image/')) return false;
      if (f.size > MAX_BYTES) {
        alert(`"${f.name}" exceeds the ${MAX_FILE_MB} MB limit and was not added.`);
        return false;
      }
      return true;
    });
    const remaining = MAX_PHOTOS - photos.length;
    if (remaining <= 0) return;
    const toAdd = incoming.slice(0, remaining).map((file) => ({
      id: crypto.randomUUID(),
      file,
      preview: URL.createObjectURL(file),
      name: file.name,
      size: file.size,
    }));
    onChange([...photos, ...toAdd]);
  }, [photos, onChange]);

  const full = photos.length >= MAX_PHOTOS;

  return (
    <>
      {/* Hidden inputs */}
      <input
        ref={fileInputRef}
        type="file"
        accept="image/*"
        multiple
        style={{ display: 'none' }}
        onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
      />
      <input
        ref={cameraInputRef}
        type="file"
        accept="image/*"
        capture="environment"
        style={{ display: 'none' }}
        onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
      />

      {/* Compact camera icon trigger */}
      {!full && (
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          title={`Attach photos (${photos.length}/${MAX_PHOTOS})`}
          aria-label="Attach photos"
          style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            width: '30px', height: '30px',
            borderRadius: '6px',
            border: photos.length > 0 ? '1.5px solid var(--primary-600)' : '1.5px solid rgba(15,23,42,0.2)',
            background: photos.length > 0 ? 'rgba(34,85,238,0.08)' : 'rgba(255,255,255,0.85)',
            color: photos.length > 0 ? 'var(--primary-600)' : 'rgba(15,23,42,0.45)',
            cursor: 'pointer',
            transition: 'border-color 0.15s, background 0.15s, color 0.15s',
            flexShrink: 0,
            backdropFilter: 'blur(2px)',
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.borderColor = 'var(--primary-600)';
            e.currentTarget.style.color = 'var(--primary-600)';
            e.currentTarget.style.background = 'rgba(34,85,238,0.08)';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.borderColor = photos.length > 0 ? 'var(--primary-600)' : 'rgba(15,23,42,0.2)';
            e.currentTarget.style.color = photos.length > 0 ? 'var(--primary-600)' : 'rgba(15,23,42,0.45)';
            e.currentTarget.style.background = photos.length > 0 ? 'rgba(34,85,238,0.08)' : 'rgba(255,255,255,0.85)';
          }}
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
        </button>
      )}
    </>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Multi-tab pricing component — shows all repair categories with tier cards
   ───────────────────────────────────────────────────────────────────────── */
const tabPanelVariants = {
  enter: { opacity: 1, y: 4 },
  center: { opacity: 1, y: 0, transition: { duration: 0.18, ease: [0.22, 1, 0.36, 1] } },
};

function PricingTabs() {
  const [activeTab, setActiveTab] = useState(0);
  const tab = PRICING_TAB_DATA[activeTab];

  return (
    <div>
      {/* Tab navigation */}
      <div style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', marginBottom: '28px' }}>
        <div style={{
          display: 'flex',
          gap: '4px',
          minWidth: 'max-content',
          padding: '4px',
          background: 'rgba(15,23,42,0.04)',
          borderRadius: '10px',
          border: '1px solid var(--machined-border)',
        }}>
          {PRICING_TAB_DATA.map((t, i) => {
            const active = i === activeTab;
            return (
              <button
                key={t.id}
                type="button"
                onClick={() => setActiveTab(i)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '6px',
                  padding: 'clamp(8px, 1.5vw, 10px) clamp(12px, 2.5vw, 18px)',
                  borderRadius: '7px',
                  border: 'none',
                  cursor: 'pointer',
                  fontWeight: active ? 700 : 500,
                  fontSize: 'clamp(0.75rem, 1.8vw, 0.85rem)',
                  whiteSpace: 'nowrap',
                  transition: 'background 0.18s, color 0.18s, box-shadow 0.18s',
                  background: active ? 'white' : 'transparent',
                  color: active ? 'var(--primary-600)' : 'rgba(15,23,42,0.55)',
                  boxShadow: active ? '0 1px 6px rgba(15,23,42,0.1)' : 'none',
                  letterSpacing: active ? '0.01em' : 'normal',
                }}
              >
                <span className="tab-label-full" style={{ display: 'block' }}>{t.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Note bar */}
      {tab.note && (
        <div style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          padding: '10px 16px',
          background: 'rgba(34,85,238,0.05)',
          border: '1px solid rgba(34,85,238,0.15)',
          borderRadius: '12px',
          marginBottom: '20px',
        }}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary-600)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0 }}>
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span style={{ fontSize: '0.8rem', color: 'var(--primary-600)', fontWeight: 600 }}>{tab.note}</span>
        </div>
      )}

      {/* Tab content with animation */}
      <Motion.div
        key={tab.id}
        variants={tabPanelVariants}
        initial="enter"
        animate="center"
      >
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(clamp(220px, 28vw, 280px), 1fr))',
            gap: '16px',
          }}>
            {tab.tiers.map((tier) => {
              const isBestValue = tier.badge === 'Best Value';
              const isFree = tier.price === 'FREE';
              return (
                <div
                  key={tier.id}
                  style={{
                    background: isBestValue ? 'linear-gradient(160deg, #eff6ff 0%, #dbeafe 100%)' : 'white',
                    border: isBestValue ? '2px solid var(--primary-600)' : '1px solid var(--machined-border)',
                    borderRadius: '10px',
                    padding: 'clamp(16px, 3vw, 22px)',
                    position: 'relative',
                    transition: 'box-shadow 0.2s, transform 0.2s',
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.boxShadow = isBestValue
                      ? '0 8px 32px rgba(34,85,238,0.2)'
                      : '0 6px 24px rgba(15,23,42,0.08)';
                    e.currentTarget.style.transform = 'translateY(-2px)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.boxShadow = 'none';
                    e.currentTarget.style.transform = 'translateY(0)';
                  }}
                >
                  {/* Badge */}
                  {tier.badge && (
                    <div style={{
                      position: 'absolute',
                      top: '-11px',
                      right: '14px',
                      background: isBestValue ? 'var(--primary-600)'
                        : tier.badge === 'Bundle Savings' || tier.badge === '3-Box Bundle' ? '#f59e0b'
                        : tier.badge === 'Free' ? '#16a34a'
                        : tier.badge === 'Popular' ? '#8b5cf6'
                        : '#64748b',
                      color: 'white',
                      borderRadius: '999px',
                      padding: '3px 11px',
                      fontSize: '0.62rem',
                      fontWeight: 800,
                      letterSpacing: '0.06em',
                      textTransform: 'uppercase',
                      boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
                    }}>
                      {tier.badge}
                    </div>
                  )}

                  {/* Name + Price */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '10px' }}>
                    <h4 style={{
                      margin: 0,
                      fontSize: 'clamp(0.875rem, 1.8vw, 0.95rem)',
                      fontWeight: 800,
                      color: isBestValue ? 'var(--primary-600)' : '#0f172a',
                      lineHeight: 1.3,
                      flex: 1,
                      paddingRight: '8px',
                    }}>
                      {tier.name}
                    </h4>
                    <span style={{
                      fontSize: 'clamp(1rem, 2.2vw, 1.2rem)',
                      fontWeight: 900,
                      color: isFree ? '#16a34a' : isBestValue ? 'var(--primary-600)' : '#0f172a',
                      whiteSpace: 'nowrap',
                      flexShrink: 0,
                    }}>
                      {tier.price}
                    </span>
                  </div>

                  {!isFree && (
                    <p style={{
                      margin: '-4px 0 10px',
                      color: 'rgba(15,23,42,0.48)',
                      fontSize: '0.68rem',
                      fontWeight: 700,
                      lineHeight: 1.35,
                    }}>
                      {REPAIR_CARD_PRICE_NOTE}
                    </p>
                  )}

                  {/* Target */}
                  <p style={{
                    margin: '0 0 12px 0',
                    fontSize: '0.75rem',
                    color: 'rgba(15,23,42,0.5)',
                    lineHeight: 1.4,
                    fontStyle: 'italic',
                  }}>
                    {tier.target}
                  </p>

                  {/* Feature list */}
                  <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: '6px' }}>
                    {tier.features.map((feat) => (
                      <li key={feat} style={{
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: '7px',
                        fontSize: '0.78rem',
                        color: 'rgba(15,23,42,0.7)',
                        lineHeight: 1.45,
                      }}>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke={isBestValue ? 'var(--primary-600)' : '#16a34a'} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, marginTop: '2px' }}>
                          <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        {feat}
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </div>
      </Motion.div>

      {/* Disclaimer */}
      <p style={{
        fontSize: '0.72rem',
        color: 'rgba(15,23,42,0.38)',
        marginTop: '20px',
        lineHeight: 1.6,
      }}>
        * {REPAIR_PRICING_DISCLOSURE}
      </p>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Main Repairs page
   ───────────────────────────────────────────────────────────────────────── */
export function RepairStartExperience() {
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const initialPackage = getRepairPackageById(searchParams.get('package'));
  const resumeState = location.state?.repairFormResume;
  const resumeFormData = resumeState?.formData && typeof resumeState.formData === 'object'
    ? resumeState.formData
    : null;
  const initialPackageToolCategory = initialPackage?.toolFamily && initialPackage.toolFamily !== 'diagnostic'
    ? REPAIR_TOOL_FAMILIES[initialPackage.toolFamily]?.label || ''
    : '';
  const [step, setStep] = useState(() => {
    if (!resumeFormData) return 1;
    const resumeStep = Number(resumeState?.step) || 2;
    return Math.max(2, Math.min(resumeStep, 3));
  });
  const [formData, setFormData] = useState(() => ({
    ...BLANK_FORM,
    ...resumeFormData,
    toolCategory: initialPackageToolCategory || resumeFormData?.toolCategory || BLANK_FORM.toolCategory,
    toolModel: initialPackageToolCategory && initialPackageToolCategory !== resumeFormData?.toolCategory
      ? BLANK_FORM.toolModel
      : (resumeFormData?.toolModel || BLANK_FORM.toolModel),
    serviceType: initialPackage?.name || BLANK_FORM.serviceType,
    pricingTierId: initialPackage?.id || BLANK_FORM.pricingTierId,
    packageId: initialPackage?.id || BLANK_FORM.packageId,
    approvalMode: initialPackage?.requiresApproval
      ? 'quote_required'
      : (resumeFormData?.approvalMode || BLANK_FORM.approvalMode),
  }));
  const [photos, setPhotos] = useState([]);
  const [errors, setErrors] = useState({});
  const [submitted, setSubmitted] = useState(false);
  const formRef = useRef(null);

  const selectedToolFamily = useMemo(
    () => formData.toolCategory
      ? getRepairToolFamilyFromCategory(formData.toolCategory)
      : (initialPackage?.toolFamily || 'diagnostic'),
    [formData.toolCategory, initialPackage],
  );

  const selectedRepairPackage = useMemo(
    () => getRepairPackageById(formData.packageId || formData.pricingTierId),
    [formData.packageId, formData.pricingTierId],
  );

  const servicePackageOptions = useMemo(() => {
    return getRepairPackagesForToolFamily(selectedToolFamily);
  }, [selectedToolFamily]);

  useEffect(() => {
    if (!formData.pricingTierId) return;
    if (servicePackageOptions.some((pkg) => pkg.id === formData.pricingTierId)) return;

    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setFormData((prev) => ({ ...prev, serviceType: '', pricingTierId: '', packageId: '' }));
      }
    });

    return () => {
      cancelled = true;
    };
  }, [formData.pricingTierId, servicePackageOptions]);

  // Service type selection helper
  function selectTier(tier) {
    const tierCategory = tier.toolFamily && tier.toolFamily !== 'diagnostic'
      ? REPAIR_TOOL_FAMILIES[tier.toolFamily]?.label || ''
      : '';

    setFormData((prev) => ({
      ...prev,
      toolCategory:   tierCategory || prev.toolCategory,
      toolModel:      tierCategory && tierCategory !== prev.toolCategory ? '' : prev.toolModel,
      serviceType:    tier.name,
      pricingTierId:  tier.id,
      packageId:      tier.id,
      approvalMode:   tier.requiresApproval ? 'quote_required' : prev.approvalMode,
    }));
    setErrors((prev) => { const n = { ...prev }; delete n.serviceType; return n; });
  }

  // Tool selection mode — track whether each level is in freetext (custom) mode.
  // These are separate from formData so the actual string values in toolBrand /
  // toolCategory / toolModel are always the clean submitted values.
  const [brandIsCustom,    setBrandIsCustom]    = useState(false);
  const [categoryIsCustom, setCategoryIsCustom] = useState(false);
  const [modelIsCustom,    setModelIsCustom]    = useState(false);

  // Submission state
  const [submitting, setSubmitting]   = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [orderResult, setOrderResult] = useState(null);

  // Shipping rate state
  const [rates, setRates]           = useState([]);
  const [ratesLoading, setRatesLoading] = useState(false);
  const [ratesError, setRatesError] = useState('');

  // Live catalog facets (official catalog source) for brands/categories.
  const { facets: catalogFacets } = useCatalogFacets({});

  const liveBrands = useMemo(
    () => getLiveBrandsFromFacets(catalogFacets),
    [catalogFacets],
  );

  const officialBrands = useMemo(() => getOfficialRepairBrands(), []);

  const selectedPackageCategory = useMemo(() => {
    if (!selectedRepairPackage?.toolFamily || selectedRepairPackage.toolFamily === 'diagnostic') return '';
    return REPAIR_TOOL_FAMILIES[selectedRepairPackage.toolFamily]?.label || '';
  }, [selectedRepairPackage]);

  const availableBrands = useMemo(() => {
    const baseBrands = selectedPackageCategory
      ? getOfficialRepairBrandsForCategory(selectedPackageCategory)
      : [...officialBrands, ...liveBrands, ...SUPPORTED_BRANDS];

    const merged = new Set(baseBrands);
    if (selectedPackageCategory) {
      [...liveBrands, ...SUPPORTED_BRANDS].forEach((brand) => {
        if (brandHasRepairModelsForCategory(brand, selectedPackageCategory)) {
          merged.add(brand);
        }
      });
    }

    return [...merged].sort((a, b) => a.localeCompare(b));
  }, [officialBrands, liveBrands, selectedPackageCategory]);

  const availableCategories = useMemo(() => {
    if (selectedPackageCategory) return [selectedPackageCategory];

    return REPAIR_SERVICE_TOOL_CATEGORIES.map((category) => category.label);
  }, [selectedPackageCategory]);

  useEffect(() => {
    if (brandIsCustom || !selectedPackageCategory || !formData.toolBrand) return;
    if (availableBrands.includes(formData.toolBrand)) return;

    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setFormData((prev) => ({
          ...prev,
          toolBrand: '',
          toolCategory: selectedPackageCategory,
          toolModel: '',
        }));
      }
    });

    return () => {
      cancelled = true;
    };
  }, [availableBrands, brandIsCustom, formData.toolBrand, selectedPackageCategory]);

  useEffect(() => {
    if (categoryIsCustom || !formData.toolCategory) return;
    if (availableCategories.includes(formData.toolCategory)) return;

    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setFormData((prev) => ({ ...prev, toolCategory: '', toolModel: '' }));
      }
    });

    return () => {
      cancelled = true;
    };
  }, [availableCategories, categoryIsCustom, formData.toolCategory]);

  const fallbackModelOptions = useMemo(
    () => getModelsForBrandCategory(formData.toolBrand, formData.toolCategory),
    [formData.toolBrand, formData.toolCategory],
  );

  const officialModelOptions = useMemo(
    () => getOfficialRepairModelsForBrandCategory(formData.toolBrand, formData.toolCategory),
    [formData.toolBrand, formData.toolCategory],
  );

  const availableModelOptions = useMemo(
    () => (officialModelOptions.length ? officialModelOptions : fallbackModelOptions),
    [officialModelOptions, fallbackModelOptions],
  );

  /* ── field helpers ── */
  const set = (field) => (e) =>
    setFormData((prev) => ({ ...prev, [field]: e.target.value }));

  const clearErr = (field) =>
    setErrors((prev) => { const n = { ...prev }; delete n[field]; return n; });

  /* ── fetch shipping rates for the destination in formData ── */
  const fetchShippingRates = useCallback(async (data) => {
    const { address, city, state: st, zip, country } = data;
    if (!address || !city || !st || !zip) return;

    setRatesLoading(true);
    setRatesError('');
    try {
      const destination = { address, city, state: st, zip, country };
      // Repair service items don't have WC product IDs; pass a placeholder.
      const items = [{
        id: 0,
        sku: 'REPAIR-SVC',
        name: `Repair Service — ${ data.toolBrand } ${ data.toolModel || data.toolCategory }`.trim(),
        quantity: 1,
        price: 0,
        weight: 5, // estimated tool weight in lbs
        category: 'repair service',
      }];
      const result = await veeqoService.getShippingRates(destination, items);
      setRates(result);

      // Pre-select the first rate.
      if (result.length > 0 && !data.shippingRateId) {
        setFormData((prev) => ({
          ...prev,
          shippingRateId:    result[0].id,
          shippingRateName:  result[0].name,
          shippingRatePrice: result[0].price,
        }));
      }
    } catch (err) {
      setRatesError('Could not load shipping options. Please try again.');
      console.error('Shipping rate fetch failed:', err);
    } finally {
      setRatesLoading(false);
    }
  }, []);

  /* ── per-step validation ── */
  function validate(s) {
    const errs = {};
    if (s === 1) {
      if (!formData.fullName.trim())             errs.fullName    = 'Full name is required.';
      if (!formData.email.trim())                errs.email       = 'Email is required.';
      else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email))
                                                 errs.email       = 'Enter a valid email address.';
      if (!formData.phone.trim())                errs.phone       = 'Phone number is required.';
    }
    if (s === 2) {
      if (!formData.toolBrand.trim())
        errs.toolBrand = brandIsCustom ? 'Please enter the brand name.' : 'Please select a brand.';
      if (formData.toolBrand.trim() && !formData.toolCategory.trim())
        errs.toolCategory = (brandIsCustom || categoryIsCustom) ? 'Please enter the tool type / category.' : 'Please select a tool category.';
      // Model required only when navigating fully through the catalog dropdowns
      if (!brandIsCustom && !categoryIsCustom && !modelIsCustom && formData.toolCategory && !formData.toolModel)
        errs.toolModel = 'Please select the tool model.';
    }
    if (s === 3) {
      if (!formData.serviceType)                 errs.serviceType  = 'Please select a service type.';
      if (!formData.priority)                    errs.priority     = 'Please select a priority level.';
      if (!formData.issueDescription.trim())     errs.issueDescription = 'Please describe the issue.';
      if (formData.approvalMode === 'preapprove_limit' && (!formData.preapprovalLimit || Number(formData.preapprovalLimit) <= 0))
                                                 errs.preapprovalLimit = 'Enter a pre-approval limit.';
    }
    if (s === 4) {
      if (!formData.address.trim())              errs.address      = 'Street address is required.';
      if (!formData.city.trim())                 errs.city         = 'City is required.';
      if (!formData.state.trim())                errs.state        = 'State / Province is required.';
      if (!formData.zip.trim())                  errs.zip          = 'ZIP / Postal code is required.';
      if (!formData.shippingRateId)              errs.shippingRateId = 'Please select a shipping option.';
    }
    return errs;
  }

  function next() {
    const errs = validate(step);
    if (Object.keys(errs).length) { setErrors(errs); return; }
    setErrors({});
    const nextStep = step + 1;
    setStep(nextStep);

    // When entering Step 4 (Shipping), auto-fetch rates if address already present.
    if (nextStep === 4) {
      fetchShippingRates(formData);
    }

    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function back() {
    setErrors({});
    setStep((s) => s - 1);
    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function goToStep(targetStep) {
    if (targetStep === step) return;

    // Always allow navigating backwards.
    if (targetStep < step) {
      setErrors({});
      setStep(targetStep);
      formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }

    // Guard forward navigation: each intermediate step must validate.
    for (let s = step; s < targetStep; s += 1) {
      const errs = validate(s);
      if (Object.keys(errs).length) {
        setErrors(errs);
        setStep(s);
        formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
    }

    setErrors({});
    setStep(targetStep);

    // When entering Step 4 (Shipping), auto-fetch rates if address already present.
    if (targetStep === 4) {
      fetchShippingRates(formData);
    }

    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setSubmitError('');
    setSubmitting(true);

    try {
      const result = await veeqoService.submitRepairRequest({
        fullName:           formData.fullName,
        email:              formData.email,
        phone:              formData.phone,
        company:            formData.company,
        toolBrand:          formData.toolBrand,
        toolCategory:       formData.toolCategory,
        toolModel:          formData.toolModel,
        serialNumber:       formData.serialNumber,
        toolAge:            formData.toolAge,
        serviceType:        formData.serviceType,
        pricingTierId:      formData.pricingTierId,
        packageId:          formData.packageId,
        approvalMode:       formData.approvalMode,
        preapprovalLimit:   formData.preapprovalLimit,
        warrantyRequested:  formData.warrantyRequested,
        purchaseDate:       formData.purchaseDate,
        oldPartsReturn:     formData.oldPartsReturn,
        inboundShippingMethod: formData.inboundShippingMethod,
        returnShippingPreference: formData.returnShippingPreference,
        priority:           formData.priority,
        issueStart:         formData.issueStart,
        issueDescription:   formData.issueDescription,
        contactPreference:  formData.contactPreference,
        address:            formData.address,
        city:               formData.city,
        state:              formData.state,
        zip:                formData.zip,
        country:            formData.country,
        shippingRateId:     formData.shippingRateId,
        shippingRateName:   formData.shippingRateName,
        shippingRatePrice:  formData.shippingRatePrice,
      });

      let mediaUploadError = '';
      if (photos.length > 0 && result?.repair_id && result?.public_token) {
        try {
          const mediaFormData = new FormData();
          photos.forEach((photo) => mediaFormData.append('files[]', photo.file));
          await uploadRepairMedia(result.repair_id, mediaFormData, result.public_token);
        } catch (mediaErr) {
          mediaUploadError = mediaErr.message || 'Repair request submitted, but photo upload failed.';
        }
      }

      setOrderResult(mediaUploadError ? { ...result, media_upload_error: mediaUploadError } : result);
      setSubmitted(true);
      formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
      setSubmitError(err.message || 'Submission failed. Please try again or call us directly.');
    } finally {
      setSubmitting(false);
    }
  }

  function resetForm() {
    photos.forEach((p) => URL.revokeObjectURL(p.preview));
    setFormData(BLANK_FORM);
    setPhotos([]);
    setStep(1);
    setErrors({});
    setSubmitted(false);
    setOrderResult(null);
    setSubmitError('');
    setRates([]);
    setBrandIsCustom(false);
    setCategoryIsCustom(false);
    setModelIsCustom(false);
  }

  /* ── shared input style ── */
  const inputCls = 'machined-input text-black';
  const errStyle = { fontSize: '0.78rem', color: '#ef4444', marginTop: '5px' };

  /* ──────────────────────────────────────────────────────────────────────────
     Render
     ────────────────────────────────────────────────────────────────────── */
  return (
    <div style={{ minHeight: '100vh' }} className="page-wrapper">
      <SEOHead
        title="Tool Repair Services"
        description="Professional drywall tool repair services and guides. Find repair solutions for TapeTech, Columbia, Asgard, Graco, and other professional drywall finishing tools."
        canonical="/repairs"
      />

      {/* ── Repair Request Form ──────────────────────────────────────────── */}
      <section style={{
        background: 'var(--alloy-base)',
        borderTop: '1px solid var(--machined-border)',
        borderBottom: '1px solid var(--machined-border)',
        padding: 'clamp(3rem, 6vw, 5rem) clamp(1.5rem, 5vw, 3rem)',
      }}>
        <div style={{ maxWidth: '900px', margin: '0 auto' }}>

          {/* Section heading */}
          {!submitted && (
            <div style={{ textAlign: 'center', marginBottom: 'clamp(2rem, 4vw, 3rem)' }}>
              <div className="dtb-title-eyebrow" style={{
                display: 'inline-block',
                background: 'rgba(34,85,238,0.08)',
                border: '1px solid rgba(34,85,238,0.2)',
                borderRadius: '99px', padding: '5px 16px',
                fontSize: '0.68rem', fontWeight: 700,
                letterSpacing: '0.12em', textTransform: 'uppercase',
                color: 'var(--primary-600)', marginBottom: '14px',
              }}>
                Repair Service Request
              </div>
              <h2 style={{
                fontSize: 'clamp(1.75rem, 4vw, 2.5rem)',
                fontWeight: 900, color: '#0f172a',
                margin: '0 0 12px 0', letterSpacing: '-0.025em',
              }}>
                Submit a Repair Inquiry
              </h2>
              <p style={{ fontSize: 'clamp(0.875rem, 2vw, 1rem)', color: 'rgba(15,23,42,0.55)', margin: 0, lineHeight: 1.6 }}>
                Fill out the form below. We will send your quote and estimated turnaround
                within 24 hours after your tool is delivered and checked in at our shop.
              </p>
            </div>
          )}

          {/* Form card */}
          <div
            ref={formRef}
            style={submitted ? undefined : {
              background: 'white',
              border: '1px solid var(--machined-border)',
              borderRadius: '16px',
              padding: 'clamp(1.5rem, 4vw, 2.5rem)',
              boxShadow: '0 4px 24px rgba(15,23,42,0.06)',
            }}
          >
            {submitted ? (
              /* ── Success screen — shared confirmation UI also used by
                 order and return confirmations (see TransactionSuccessSheet). ── */
              <TransactionSuccessSheet
                type="repair"
                titleId="repair-success-heading"
                title="Request Submitted!"
                message={(
                  <>
                    Thank you, <strong>{formData.fullName}</strong>. We received your repair inquiry for{' '}
                    <strong>{getToolDisplayName(formData)}</strong>.
                  </>
                )}
                details={[
                  orderResult?.repair_id ? { label: 'Repair', value: `DTB-${orderResult.repair_id}` } : null,
                  orderResult?.wc_order_number ? { label: 'Order', value: `#${orderResult.wc_order_number}` } : null,
                ].filter(Boolean)}
                primaryAction={{ label: 'Submit Another Request', onClick: resetForm }}
                secondaryAction={{ label: 'Browse Parts & Schematics', to: '/parts' }}
              >
                <p style={{ fontSize: '0.9rem', color: '#64748b', margin: '0 0 8px', lineHeight: 1.6 }}>
                  Our service team will contact you at <strong>{formData.email}</strong> with your quote and
                  estimated turnaround within 24 hours after your tool is delivered and checked in at our shop.
                </p>
                {orderResult?.public_token && (
                  <p style={{ fontSize: '0.9rem', color: '#64748b', margin: '0 0 8px', lineHeight: 1.6 }}>
                    Track this request anytime at{' '}
                    <Link
                      to={`/repairs/status/${orderResult.repair_id}?token=${encodeURIComponent(orderResult.public_token)}`}
                      style={{ color: '#2255ee', fontWeight: 700 }}
                    >
                      your repair status page
                    </Link>
                    .
                  </p>
                )}
                {orderResult?.media_upload_error && (
                  <p style={{ fontSize: '0.82rem', color: '#b45309', margin: '0 0 8px', lineHeight: 1.5 }}>
                    {orderResult.media_upload_error} You can add photos from the repair status page after submission.
                  </p>
                )}
                <div className="dtb-success-sheet__checklist">
                  <h4 className="dtb-success-sheet__checklist-title">What Happens Next</h4>
                  <ol className="dtb-success-sheet__checklist-list">
                    {[
                      { step: '1', text: 'Our team reviews your request and confirms the next steps and repair-center shipping address.' },
                      { step: '2', text: 'Pack each tool securely in a sturdy box. Include a printed copy of this request if possible.' },
                      { step: '3', text: formData.inboundShippingMethod === 'local_pickup_dropoff' ? 'Use the free local pickup or drop-off arrangement confirmed by our team.' : 'Arrange and pay for shipment of each tool to us. Use a carrier and service that meet your needs, and keep your tracking number.' },
                      { step: '4', text: 'We diagnose your tool and send you a quote. No work begins until you approve pricing.' },
                      { step: '5', text: 'Repaired tool ships back to you within 1–3 weeks depending on parts availability.' },
                    ].map((item) => (
                      <li key={item.step}>
                        <span className="dtb-success-sheet__checklist-badge">{item.step}</span>
                        <span className="dtb-success-sheet__checklist-text">{item.text}</span>
                      </li>
                    ))}
                  </ol>
                </div>
              </TransactionSuccessSheet>
            ) : (
              <form onSubmit={handleSubmit} noValidate>
                <ProgressBar step={step} total={STEPS.length} onStepSelect={goToStep} />
                <SelectedPackageSummary pkg={selectedRepairPackage} formData={formData} step={step} />

                {/* ── STEP 1: Contact Info ── */}
                {step === 1 && (
                  <div>
                    <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: 'black', margin: '0 0 6px 0' }}>
                      Contact Information
                    </h3>
                    <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.5)', margin: '0 0 28px 0' }}>
                      Let us know how to reach you regarding your repair request.
                    </p>

                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                      gap: '0 20px',
                    }}>
                      <Field label="Full Name" required>
                        <input
                          type="text" className={inputCls}
                          placeholder="Jane Smith"
                          value={formData.fullName}
                          onChange={set('fullName')}
                          onFocus={() => clearErr('fullName')}
                          autoComplete="name"
                        />
                        {errors.fullName && <p style={errStyle}>{errors.fullName}</p>}
                      </Field>

                      <Field label="Company / Business" optional>
                        <input
                          type="text" className={inputCls}
                          placeholder="Acme Drywall Co."
                          value={formData.company}
                          onChange={set('company')}
                          autoComplete="organization"
                        />
                      </Field>
                    </div>

                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                      gap: '0 20px',
                    }}>
                      <Field label="Email Address" required>
                        <input
                          type="email" className={inputCls}
                          placeholder="you@example.com"
                          value={formData.email}
                          onChange={set('email')}
                          onFocus={() => clearErr('email')}
                          autoComplete="email"
                        />
                        {errors.email && <p style={errStyle}>{errors.email}</p>}
                      </Field>

                      <Field label="Phone Number" required>
                        <input
                          type="tel" className={inputCls}
                          placeholder="(555) 000-1234"
                          value={formData.phone}
                          onChange={set('phone')}
                          onFocus={() => clearErr('phone')}
                          autoComplete="tel"
                        />
                        {errors.phone && <p style={errStyle}>{errors.phone}</p>}
                      </Field>
                    </div>
                  </div>
                )}

                {/* ── STEP 2: Tool Details ── */}
                {step === 2 && (
                  <div>
                    <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: 'black', margin: '0 0 6px 0' }}>
                      Tool Details
                    </h3>
                    <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.5)', margin: '0 0 28px 0' }}>
                      Identify the tool that needs service so we can prepare the right technician and parts.
                    </p>

                    {/* ── Brand ── */}
                    <Field label="Brand" required>
                      {brandIsCustom ? (
                        <>
                          <input
                            type="text"
                            className={inputCls}
                            placeholder="e.g. Renegade, Ames, Custom Brand…"
                            value={formData.toolBrand}
                            onChange={(e) => { set('toolBrand')(e); clearErr('toolBrand'); }}
                            autoFocus
                          />
                          <button
                            type="button"
                            onClick={() => {
                              setBrandIsCustom(false);
                              setCategoryIsCustom(false);
                              setModelIsCustom(false);
                              setFormData((prev) => ({ ...prev, toolBrand: '', toolCategory: '', toolModel: '' }));
                              clearErr('toolBrand'); clearErr('toolCategory'); clearErr('toolModel');
                            }}
                            style={{ background: 'none', border: 'none', padding: '4px 0 0', cursor: 'pointer', fontSize: '0.78rem', color: 'var(--primary-600)', textDecoration: 'underline' }}
                          >
                            ← Back to brand list
                          </button>
                        </>
                      ) : (
                        <Dropdown
                          value={formData.toolBrand}
                          placeholder="Select a brand..."
                          options={[
                            ...availableBrands.map((b) => ({ value: b, label: b })),
                            ...(!selectedPackageCategory ? [{ value: '__other__', label: 'Other / Not Listed' }] : []),
                          ]}
                          fullWidth
                          onChange={(val) => {
                            if (val === '__other__') {
                              setBrandIsCustom(true);
                              setCategoryIsCustom(false);
                              setModelIsCustom(false);
                              setFormData((prev) => ({ ...prev, toolBrand: '', toolCategory: '', toolModel: '' }));
                            } else {
                              setBrandIsCustom(false);
                              setCategoryIsCustom(false);
                              setModelIsCustom(false);
                              setFormData((prev) => ({
                                ...prev,
                                toolBrand: val,
                                toolCategory: selectedPackageCategory || '',
                                toolModel: '',
                              }));
                            }
                            clearErr('toolBrand'); clearErr('toolCategory'); clearErr('toolModel');
                          }}
                        />
                      )}
                      {errors.toolBrand && <p style={errStyle}>{errors.toolBrand}</p>}
                    </Field>

                    {/* ── Category + Model — catalog path (known brand) ── */}
                    {!brandIsCustom && formData.toolBrand && (
                      <>
                        {/* Category */}
                        <Field label="Tool Category" required>
                          {categoryIsCustom ? (
                            <>
                              <input
                                type="text"
                                className={inputCls}
                                placeholder="e.g. Flat Box, Auto Taper, Pump…"
                                value={formData.toolCategory}
                                onChange={(e) => { set('toolCategory')(e); clearErr('toolCategory'); }}
                                autoFocus
                              />
                              <button
                                type="button"
                                onClick={() => {
                                  setCategoryIsCustom(false);
                                  setModelIsCustom(false);
                                  setFormData((prev) => ({ ...prev, toolCategory: '', toolModel: '' }));
                                  clearErr('toolCategory'); clearErr('toolModel');
                                }}
                                style={{ background: 'none', border: 'none', padding: '4px 0 0', cursor: 'pointer', fontSize: '0.78rem', color: 'var(--primary-600)', textDecoration: 'underline' }}
                              >
                                ← Back to category list
                              </button>
                            </>
                          ) : (
                            <Dropdown
                              value={formData.toolCategory}
                              placeholder="Select a tool category..."
                              options={[
                                ...availableCategories.map((cat) => ({ value: cat, label: cat })),
                                { value: '__other__', label: 'Other / Not Listed' },
                              ]}
                              fullWidth
                              onChange={(val) => {
                                if (val === '__other__') {
                                  setCategoryIsCustom(true);
                                  setModelIsCustom(false);
                                  setFormData((prev) => ({ ...prev, toolCategory: '', toolModel: '' }));
                                } else {
                                  setCategoryIsCustom(false);
                                  setModelIsCustom(false);
                                  setFormData((prev) => ({ ...prev, toolCategory: val, toolModel: '' }));
                                }
                                clearErr('toolCategory'); clearErr('toolModel');
                              }}
                            />
                          )}
                          {errors.toolCategory && <p style={errStyle}>{errors.toolCategory}</p>}
                        </Field>

                        {/* Model — catalog dropdown when category is known */}
                        {!categoryIsCustom && formData.toolCategory && (
                          <Field label="Tool Model" required>
                            {modelIsCustom ? (
                              <>
                                <input
                                  type="text"
                                  className={inputCls}
                                  placeholder="e.g. model name, number, or description"
                                  value={formData.toolModel}
                                  onChange={(e) => { set('toolModel')(e); clearErr('toolModel'); }}
                                  autoFocus
                                />
                                <button
                                  type="button"
                                  onClick={() => {
                                    setModelIsCustom(false);
                                    setFormData((prev) => ({ ...prev, toolModel: '' }));
                                    clearErr('toolModel');
                                  }}
                                  style={{ background: 'none', border: 'none', padding: '4px 0 0', cursor: 'pointer', fontSize: '0.78rem', color: 'var(--primary-600)', textDecoration: 'underline' }}
                                >
                                  ← Back to model list
                                </button>
                              </>
                            ) : (
                              <Dropdown
                                value={formData.toolModel}
                                placeholder="Select the specific model..."
                                options={[
                                  ...availableModelOptions.map((model) => ({ value: model.value, label: model.label })),
                                  { value: '__other__', label: 'Other / Not Listed' },
                                ]}
                                fullWidth
                                onChange={(val) => {
                                  if (val === '__other__') {
                                    setModelIsCustom(true);
                                    setFormData((prev) => ({ ...prev, toolModel: '' }));
                                  } else {
                                    setModelIsCustom(false);
                                    setFormData((prev) => ({ ...prev, toolModel: val }));
                                  }
                                  clearErr('toolModel');
                                }}
                              />
                            )}
                            {errors.toolModel && <p style={errStyle}>{errors.toolModel}</p>}
                          </Field>
                        )}

                        {/* Model freetext when category is custom — shown inline below category input */}
                        {categoryIsCustom && (
                          <Field label="Tool Model / Name" optional>
                            <input
                              type="text"
                              className={inputCls}
                              placeholder="e.g. ProBox 10-inch, Model #XB-200"
                              value={formData.toolModel}
                              onChange={(e) => { set('toolModel')(e); clearErr('toolModel'); }}
                            />
                          </Field>
                        )}
                      </>
                    )}

                    {/* ── Category + Model — freetext path (custom brand) ── */}
                    {brandIsCustom && (
                      <div style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                        gap: '0 20px',
                      }}>
                        <Field label="Tool Type / Category" required hint="e.g. Flat Box, Taper, Pump…">
                          <input
                            type="text" className={inputCls}
                            placeholder="e.g. Finishing Box"
                            value={formData.toolCategory}
                            onChange={(e) => { set('toolCategory')(e); clearErr('toolCategory'); }}
                          />
                          {errors.toolCategory && <p style={errStyle}>{errors.toolCategory}</p>}
                        </Field>
                        <Field label="Tool Model / Name" hint="Model number, size, or description">
                          <input
                            type="text" className={inputCls}
                            placeholder="e.g. ProBox 10-inch"
                            value={formData.toolModel}
                            onChange={(e) => { set('toolModel')(e); clearErr('toolModel'); }}
                          />
                        </Field>
                      </div>
                    )}

                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                      gap: '0 20px',
                    }}>
                      <Field label="Serial Number" optional>
                        <input
                          type="text" className={inputCls}
                          placeholder="e.g. COL-2024-XXXXX"
                          value={formData.serialNumber}
                          onChange={set('serialNumber')}
                        />
                      </Field>

                      <Field label="Approximate Tool Age">
                        <Dropdown
                          value={formData.toolAge}
                          placeholder="Unknown / Not sure"
                          options={[
                            { value: 'Under 1 year', label: 'Under 1 year' },
                            { value: '1–3 years', label: '1–3 years' },
                            { value: '3–5 years', label: '3–5 years' },
                            { value: '5–10 years', label: '5–10 years' },
                            { value: '10+ years', label: '10+ years' },
                          ]}
                          fullWidth
                          onChange={(value) => setFormData((prev) => ({ ...prev, toolAge: value }))}
                        />
                      </Field>
                    </div>
                  </div>
                )}

                {/* ── STEP 3: Service Request ── */}
                {step === 3 && (
                  <div>
                    <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: 'black', margin: '0 0 6px 0' }}>
                      Service Request Details
                    </h3>
                    <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.5)', margin: '0 0 28px 0' }}>
                      Tell us what kind of service you need and describe the issue in as much detail as possible.
                    </p>

                    {/* Service Tier Cards */}
                    <div style={{ marginBottom: '24px' }}>
                      <label className="machined-label" style={{ color: 'var(--primary-600)', marginBottom: 10, display: 'block' }}>
                        Service Type <span style={{ color: '#ef4444', marginLeft: 3 }}>*</span>
                      </label>
                      <div style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))',
                        gap: '12px',
                      }}>
                        {servicePackageOptions.map((tier) => {
                          const selected = formData.pricingTierId === tier.id;
                          return (
                            <div
                              key={tier.id}
                              onClick={() => selectTier(tier)}
                              role="button"
                              tabIndex={0}
                              onKeyDown={(e) => e.key === 'Enter' && selectTier(tier)}
                              style={{
                                border: `2px solid ${selected ? 'var(--primary-600)' : 'rgba(15,23,42,0.1)'}`,
                                borderRadius: '14px',
                                padding: '16px',
                                cursor: 'pointer',
                                position: 'relative',
                                background: selected ? '#eff6ff' : 'white',
                                transition: 'border-color 0.2s, background 0.2s',
                              }}
                            >
                              {tier.badge && (
                                <div style={{
                                  position: 'absolute', top: -10, right: 12,
                                  background: tier.badge === 'Best Value' ? '#16a34a' : '#f59e0b',
                                  color: 'white', borderRadius: '999px',
                                  padding: '2px 10px', fontSize: '0.65rem', fontWeight: 700,
                                }}>
                                  {tier.badge}
                                </div>
                              )}
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                                <span style={{ fontWeight: 700, color: '#0f172a', fontSize: '0.9rem' }}>{tier.name}</span>
                                <span style={{ fontSize: '0.72rem', fontWeight: 800, color: 'var(--primary-600)', marginLeft: '10px', whiteSpace: 'nowrap' }}>
                                  {tier.priceLabel}
                                </span>
                              </div>
                              <p style={{ margin: '5px 0 0', color: 'rgba(15,23,42,0.45)', fontSize: '0.68rem', fontWeight: 700, lineHeight: 1.35 }}>
                                {REPAIR_CARD_PRICE_NOTE}
                              </p>
                              <p style={{ margin: '6px 0 0', fontSize: '0.78rem', color: 'rgba(15,23,42,0.55)' }}>
                                {tier.recommendedFor?.[0] || tier.commonSymptoms?.[0] || 'Technician-guided service path'}
                              </p>
                              {Array.isArray(tier.includes) && tier.includes.length > 0 && (
                                <ul style={{ margin: '10px 0 0', paddingLeft: '16px', color: 'rgba(15,23,42,0.6)', fontSize: '0.72rem', lineHeight: 1.45 }}>
                                  {tier.includes.slice(0, 3).map((item) => (
                                    <li key={item}>{item}</li>
                                  ))}
                                </ul>
                              )}
                            </div>
                          );
                        })}
                      </div>
                      {errors.serviceType && <p style={errStyle}>{errors.serviceType}</p>}
                    </div>

                    <div style={{
                      marginBottom: '24px',
                      padding: '14px 16px',
                      background: 'rgba(245,158,11,0.07)',
                      border: '1px solid rgba(245,158,11,0.24)',
                      borderRadius: '12px',
                    }}>
                      <p style={{ margin: '0 0 5px', color: '#92400e', fontSize: '0.78rem', fontWeight: 800 }}>
                        Repair pricing is estimate-based until inspection is complete.
                      </p>
                      <p style={{ margin: 0, color: 'rgba(15,23,42,0.66)', fontSize: '0.76rem', lineHeight: 1.55 }}>
                        {REPAIR_PRICING_DISCLOSURE}
                      </p>
                    </div>

                    <Field label="Approval Preference" required hint="Choose how we should handle work that needs parts or labor beyond the selected package.">
                      <div style={{ display: 'grid', gap: '10px', marginBottom: '10px' }}>
                        {[
                          { value: 'quote_required', label: 'Send me a quote before any repair.', help: 'No bench work begins until you approve the estimate.' },
                          { value: 'package_only', label: 'Pre-approve this package price only.', help: 'Package work can begin; added parts still require approval.' },
                          { value: 'preapprove_limit', label: 'Pre-approve added work up to a limit.', help: 'Useful when you want faster turnaround under a fixed ceiling.' },
                        ].map((option) => {
                          const active = formData.approvalMode === option.value;
                          return (
                            <button
                              key={option.value}
                              type="button"
                              onClick={() => setFormData((prev) => ({ ...prev, approvalMode: option.value }))}
                              style={{
                                textAlign: 'left',
                                border: active ? '2px solid var(--primary-600)' : '1.5px solid rgba(15,23,42,0.12)',
                                borderRadius: '12px',
                                padding: '12px 14px',
                                background: active ? 'rgba(34,85,238,0.05)' : 'white',
                                cursor: 'pointer',
                              }}
                            >
                              <span style={{ display: 'block', color: '#0f172a', fontSize: '0.84rem', fontWeight: 800 }}>
                                {option.label}
                              </span>
                              <span style={{ display: 'block', color: 'rgba(15,23,42,0.52)', fontSize: '0.74rem', marginTop: '3px', lineHeight: 1.45 }}>
                                {option.help}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                      {formData.approvalMode === 'preapprove_limit' && (
                        <>
                          <input
                            type="number"
                            min="0"
                            step="25"
                            className={inputCls}
                            placeholder="Approval limit in dollars"
                            value={formData.preapprovalLimit}
                            onChange={(e) => { set('preapprovalLimit')(e); clearErr('preapprovalLimit'); }}
                          />
                          {errors.preapprovalLimit && <p style={errStyle}>{errors.preapprovalLimit}</p>}
                        </>
                      )}
                    </Field>

                    <Field label="Warranty / Coverage Review" hint="Select whether this repair should be handled as standard paid service or reviewed for possible coverage.">
                      <Dropdown
                        value={formData.warrantyRequested}
                        placeholder="Standard paid repair service"
                        options={WARRANTY_REQUEST_OPTIONS}
                        fullWidth
                        onChange={(value) => setFormData((prev) => ({ ...prev, warrantyRequested: value }))}
                      />
                    </Field>

                    {(formData.warrantyRequested === 'yes' || formData.warrantyRequested === 'not_sure') && (
                      <Field label="Purchase Date" optional>
                        <input
                          type="date"
                          className={inputCls}
                          value={formData.purchaseDate}
                          onChange={set('purchaseDate')}
                        />
                      </Field>
                    )}

                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                      gap: '0 20px',
                    }}>
                      <Field label="Priority Level" required>
                        <Dropdown
                          value={formData.priority}
                          placeholder="Select priority..."
                          options={[
                            { value: 'Standard (5–7 business days)', label: 'Standard — 5–7 business days' },
                            { value: 'Expedited (2–3 business days)', label: 'Expedited — 2–3 business days' },
                            { value: 'Emergency (same/next day)', label: 'Emergency — same / next day' },
                          ]}
                          fullWidth
                          onChange={(value) => {
                            setFormData((prev) => ({ ...prev, priority: value }));
                            clearErr('priority');
                          }}
                        />
                        {errors.priority && <p style={errStyle}>{errors.priority}</p>}
                      </Field>
                    </div>

                    <Field label="When Did the Issue Start?">
                      <Dropdown
                        value={formData.issueStart}
                        placeholder="Not sure / N/A"
                        options={[
                          { value: 'Today', label: 'Today' },
                          { value: 'This week', label: 'This week' },
                          { value: 'This month', label: 'This month' },
                          { value: '1–3 months ago', label: '1–3 months ago' },
                          { value: 'More than 3 months ago', label: 'More than 3 months ago' },
                        ]}
                        fullWidth
                        onChange={(value) => setFormData((prev) => ({ ...prev, issueStart: value }))}
                      />
                    </Field>

                    <Field label="Preferred Contact Method" hint="How should we reach you when your repair is ready?">
                      <div style={{ display: 'inline-flex', gap: '6px', flexWrap: 'wrap' }}>
                        {[
                          {
                            val: 'email',
                            label: 'Email',
                            icon: (
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                              </svg>
                            ),
                          },
                          {
                            val: 'phone',
                            label: 'Phone',
                            icon: (
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.59 1.18h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 5.56 5.56l.92-.92a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21 16.92z"/>
                              </svg>
                            ),
                          },
                          {
                            val: 'either',
                            label: 'Either',
                            icon: (
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <polyline points="16 3 21 3 21 8"/>
                                <line x1="4" y1="20" x2="21" y2="3"/>
                                <polyline points="21 16 21 21 16 21"/>
                                <line x1="15" y1="15" x2="21" y2="21"/>
                              </svg>
                            ),
                          },
                        ].map((opt) => {
                          const active = formData.contactPreference === opt.val;
                          return (
                            <button
                              key={opt.val}
                              type="button"
                              onClick={() => setFormData((prev) => ({ ...prev, contactPreference: opt.val }))}
                              aria-pressed={active}
                              style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '5px',
                                padding: '6px 13px',
                                borderRadius: '99px',
                                border: active
                                  ? '1.5px solid var(--primary-600)'
                                  : '1.5px solid rgba(15,23,42,0.14)',
                                background: active ? 'var(--primary-600)' : 'white',
                                color: active ? 'white' : 'rgba(15,23,42,0.6)',
                                cursor: 'pointer',
                                fontSize: '0.78rem',
                                fontWeight: 600,
                                letterSpacing: '0.02em',
                                transition: 'border-color 0.15s, background 0.15s, color 0.15s',
                                outline: 'none',
                                WebkitTapHighlightColor: 'transparent',
                                lineHeight: 1,
                              }}
                            >
                              {opt.icon}
                              {opt.label}
                            </button>
                          );
                        })}
                      </div>
                    </Field>

                    <Field label="Details & Notes" required hint="Describe symptoms, sounds, leaks, damage, and prior repair history. Tip: photograph your tool before shipping — it speeds up diagnosis.">
                      <div style={{ position: 'relative' }}>
                        <textarea
                          rows="6"
                          className="machined-textarea text-black"
                          placeholder="e.g. The pump is losing pressure mid-use, valve seal leaking at base connection. Previously repaired 6 months ago…"
                          value={formData.issueDescription}
                          onChange={(e) => { set('issueDescription')(e); clearErr('issueDescription'); }}
                          style={{ resize: 'vertical', minHeight: '130px', paddingBottom: '44px' }}
                        />
                        {/* Photo icon anchored to bottom-left of textarea */}
                        <div style={{
                          position: 'absolute',
                          bottom: '10px',
                          left: '10px',
                          display: 'flex',
                          alignItems: 'center',
                          gap: '6px',
                          pointerEvents: 'none',
                        }}>
                          <div style={{ pointerEvents: 'auto' }}>
                            <PhotoUploader photos={photos} onChange={setPhotos} />
                          </div>
                          {photos.length > 0 && (
                            <span style={{ fontSize: '0.68rem', color: 'rgba(15,23,42,0.5)', fontWeight: 600 }}>
                              {photos.length}/{MAX_PHOTOS} photo{photos.length !== 1 ? 's' : ''}
                            </span>
                          )}
                        </div>
                      </div>
                      {/* Thumbnails below the textarea */}
                      {photos.length > 0 && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '8px' }}>
                          {photos.map((p) => (
                            <PhotoThumb key={p.id} photo={p} onRemove={() => {
                              const next = photos.filter((x) => x.id !== p.id);
                              URL.revokeObjectURL(p.preview);
                              setPhotos(next);
                            }} />
                          ))}
                        </div>
                      )}
                      {errors.issueDescription && <p style={errStyle}>{errors.issueDescription}</p>}
                    </Field>
                  </div>
                )}

                {/* ── STEP 4: Shipping ── */}
                {step === 4 && (
                  <div>
                    <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: 'black', margin: '0 0 6px 0' }}>
                      Shipping & Return Delivery
                    </h3>
                    <div role="note" style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: '12px', padding: '14px 16px', marginBottom: '20px' }}>
                      <p style={{ color: '#1e3a8a', fontWeight: 700, margin: '0 0 4px' }}>Getting your tool to our repair center</p>
                      <p style={{ color: '#334155', fontSize: '0.875rem', lineHeight: 1.55, margin: 0 }}>
                        Choose free local pickup/drop-off, or arrange and pay a carrier to ship your tool. We do not provide or pay for inbound carrier labels.
                      </p>
                    </div>
                    <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.65)', margin: '0 0 20px 0' }}>
                      Enter the address where we should return your tool. Return delivery is quoted separately.
                    </p>

                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                      gap: '0 20px',
                    }}>
                      <Field label="How will the tool get to our repair center?">
                        <Dropdown
                          value={formData.inboundShippingMethod}
                          options={[
                            { value: 'customer_pays_carrier', label: 'I will ship it and pay the carrier' },
                            { value: 'local_pickup_dropoff', label: 'Free local pickup / drop-off' },
                          ]}
                          fullWidth
                          onChange={(value) => setFormData((prev) => ({ ...prev, inboundShippingMethod: value }))}
                        />
                      </Field>

                      <Field label="Return Preference">
                        <Dropdown
                          value={formData.returnShippingPreference}
                          options={[
                            { value: 'standard', label: 'Standard return shipping' },
                            { value: 'expedited', label: 'Expedited return if available' },
                            { value: 'hold_for_pickup', label: 'Hold for pickup' },
                          ]}
                          fullWidth
                          onChange={(value) => setFormData((prev) => ({ ...prev, returnShippingPreference: value }))}
                        />
                      </Field>
                    </div>

                    <Field label="Old Parts">
                      <Dropdown
                        value={formData.oldPartsReturn}
                        options={[
                          { value: 'discard', label: 'Recycle or discard replaced parts' },
                          { value: 'return', label: 'Return replaced parts with my tool' },
                        ]}
                        fullWidth
                        onChange={(value) => setFormData((prev) => ({ ...prev, oldPartsReturn: value }))}
                      />
                    </Field>

                    {/* Packaging Tips */}
                    <div style={{
                      background: 'rgba(34,85,238,0.04)',
                      border: '1px solid rgba(34,85,238,0.2)',
                      borderRadius: '12px',
                      padding: 'clamp(12px, 2vw, 16px) clamp(14px, 2.5vw, 20px)',
                      marginBottom: '24px',
                      display: 'flex',
                      gap: '12px',
                      alignItems: 'flex-start',
                    }}>
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-600)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, marginTop: '1px' }}>
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                      </svg>
                      <div>
                        <p style={{ margin: '0 0 8px 0', fontSize: '0.825rem', fontWeight: 700, color: 'var(--primary-600)' }}>
                          Packaging Guidance
                        </p>
                        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: '5px' }}>
                          {[
                            'Use the smallest sturdy box available; wrap tool in bubble wrap or paper.',
                            'Include a printed copy of this repair form or a written description of the issue.',
                            'Photograph your tool before sealing the box — keep the photos for your records.',
                            'Accepted carriers: USPS, FedEx, UPS. Keep your outbound tracking number.',
                          ].map((tip, i) => (
                            <li key={i} style={{ display: 'flex', gap: '7px', fontSize: '0.8rem', color: 'rgba(15,23,42,0.65)', lineHeight: 1.5 }}>
                              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--primary-600)" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, marginTop: '3px' }}>
                                <polyline points="20 6 9 17 4 12"/>
                              </svg>
                              {tip}
                            </li>
                          ))}
                        </ul>
                      </div>
                    </div>

                    {/* Address fields */}
                    <Field label="Street Address" required>
                      <input
                        type="text" className={inputCls}
                        placeholder="123 Main St"
                        value={formData.address}
                        onChange={(e) => { set('address')(e); clearErr('address'); }}
                        autoComplete="street-address"
                      />
                      {errors.address && <p style={errStyle}>{errors.address}</p>}
                    </Field>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0 20px' }}>
                      <Field label="City" required>
                        <input
                          type="text" className={inputCls}
                          placeholder="Sacramento"
                          value={formData.city}
                          onChange={(e) => { set('city')(e); clearErr('city'); }}
                          autoComplete="address-level2"
                        />
                        {errors.city && <p style={errStyle}>{errors.city}</p>}
                      </Field>

                      <Field label="State / Province" required>
                        <input
                          type="text" className={inputCls}
                          placeholder="CA"
                          value={formData.state}
                          onChange={(e) => { set('state')(e); clearErr('state'); }}
                          autoComplete="address-level1"
                        />
                        {errors.state && <p style={errStyle}>{errors.state}</p>}
                      </Field>
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0 20px' }}>
                      <Field label="ZIP / Postal Code" required>
                        <input
                          type="text" className={inputCls}
                          placeholder="95814"
                          value={formData.zip}
                          onChange={(e) => { set('zip')(e); clearErr('zip'); }}
                          autoComplete="postal-code"
                        />
                        {errors.zip && <p style={errStyle}>{errors.zip}</p>}
                      </Field>

                      <Field label="Country">
                        <Dropdown
                          value={formData.country}
                          options={[
                            { value: 'US', label: 'United States' },
                            { value: 'CA', label: 'Canada' },
                            { value: 'MX', label: 'Mexico' },
                            { value: 'GB', label: 'United Kingdom' },
                            { value: 'AU', label: 'Australia' },
                            { value: 'OTHER', label: 'Other' },
                          ]}
                          fullWidth
                          onChange={(value) => {
                            setFormData((prev) => ({ ...prev, country: value, shippingRateId: '', shippingRateName: '', shippingRatePrice: null }));
                            setRates([]);
                          }}
                        />
                      </Field>
                    </div>

                    {/* Refresh rates button */}
                    <div style={{ marginBottom: '24px' }}>
                      <button
                        type="button"
                        onClick={() => fetchShippingRates(formData)}
                        disabled={ratesLoading || !formData.address || !formData.city || !formData.state || !formData.zip}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          padding: '8px 16px',
                          borderRadius: '10px',
                          border: '1px solid var(--primary-600)',
                          background: 'transparent',
                          color: 'var(--primary-600)',
                          cursor: (!formData.address || !formData.city || !formData.state || !formData.zip) ? 'not-allowed' : 'pointer',
                          fontSize: '0.8rem', fontWeight: 700,
                          opacity: (!formData.address || !formData.city || !formData.state || !formData.zip) ? 0.45 : 1,
                          transition: 'opacity 0.15s',
                        }}
                      >
                        {ratesLoading ? (
                          <>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ animation: 'spin 1s linear infinite' }}>
                              <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                            </svg>
                            Calculating…
                          </>
                        ) : (
                          <>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                              <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.08-4.42"/>
                            </svg>
                            {rates.length > 0 ? 'Refresh Rates' : 'Get Shipping Rates'}
                          </>
                        )}
                      </button>
                    </div>

                    {/* Rate options */}
                    {ratesError && (
                      <p style={{ fontSize: '0.82rem', color: '#ef4444', marginBottom: '16px' }}>{ratesError}</p>
                    )}
                    {rates.length > 0 && (
                      <div style={{ marginBottom: '8px' }}>
                        <p style={{ fontSize: '0.8rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'rgba(15,23,42,0.45)', marginBottom: '10px' }}>
                          Select Shipping Option
                        </p>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                          {rates.map((rate) => {
                            const active = formData.shippingRateId === rate.id;
                            return (
                              <label
                                key={rate.id}
                                style={{
                                  display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                  padding: '12px 16px',
                                  borderRadius: '12px',
                                  border: active ? '2px solid var(--primary-600)' : '1.5px solid rgba(15,23,42,0.14)',
                                  background: active ? 'rgba(34,85,238,0.04)' : 'white',
                                  cursor: 'pointer',
                                  transition: 'border-color 0.15s, background 0.15s',
                                }}
                              >
                                <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                  <input
                                    type="radio"
                                    name="shippingRate"
                                    value={rate.id}
                                    checked={active}
                                    onChange={() => {
                                      setFormData((prev) => ({
                                        ...prev,
                                        shippingRateId:    rate.id,
                                        shippingRateName:  rate.name,
                                        shippingRatePrice: rate.price,
                                      }));
                                      clearErr('shippingRateId');
                                    }}
                                    style={{ accentColor: 'var(--primary-600)', width: '16px', height: '16px', flexShrink: 0 }}
                                  />
                                  <span style={{ fontSize: '0.875rem', fontWeight: active ? 700 : 500, color: 'black' }}>
                                    {rate.name}
                                  </span>
                                </div>
                                <span style={{
                                  fontSize: '0.875rem', fontWeight: 700,
                                  color: rate.price === 0 ? '#16a34a' : 'black',
                                  whiteSpace: 'nowrap', marginLeft: '12px',
                                }}>
                                  {rate.price === 0 ? 'FREE' : `$${rate.price.toFixed(2)}`}
                                </span>
                              </label>
                            );
                          })}
                        </div>
                        {errors.shippingRateId && <p style={errStyle}>{errors.shippingRateId}</p>}
                      </div>
                    )}
                    {rates.length === 0 && !ratesLoading && !ratesError && (
                      <p style={{ fontSize: '0.82rem', color: 'rgba(15,23,42,0.45)', marginTop: '4px' }}>
                        Enter your address above then click <strong>Get Shipping Rates</strong> to see available options.
                      </p>
                    )}
                  </div>
                )}

                {/* ── STEP 5: Review & Submit ── */}
                {step === 5 && (
                  <div>
                    <h3 style={{ fontSize: '1.1rem', fontWeight: 700, color: 'black', margin: '0 0 6px 0' }}>
                      Review Your Request
                    </h3>
                    <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.5)', margin: '0 0 28px 0' }}>
                      Please review the details below before submitting. Use the Back button to make any changes.
                    </p>

                    {/* Contact section */}
                    <div style={{
                      background: 'var(--alloy-base)',
                      border: '1px solid var(--machined-border)',
                      borderRadius: '12px',
                      padding: '16px 20px',
                      marginBottom: '16px',
                    }}>
                      <div style={{
                        fontSize: '0.7rem', fontWeight: 800,
                        textTransform: 'uppercase', letterSpacing: '0.1em',
                        color: 'var(--primary-600)', marginBottom: '8px',
                      }}>
                        Contact Information
                      </div>
                      <ReviewRow label="Name"        value={formData.fullName} />
                      <ReviewRow label="Email"       value={formData.email} />
                      <ReviewRow label="Phone"       value={formData.phone} />
                      <ReviewRow label="Company"     value={formData.company} />
                      <ReviewRow label="Contact Via" value={formData.contactPreference === 'email' ? 'Email' : formData.contactPreference === 'phone' ? 'Phone' : 'Either'} />
                    </div>

                    {/* Tool section */}
                    <div style={{
                      background: 'var(--alloy-base)',
                      border: '1px solid var(--machined-border)',
                      borderRadius: '12px',
                      padding: '16px 20px',
                      marginBottom: '16px',
                    }}>
                      <div style={{
                        fontSize: '0.7rem', fontWeight: 800,
                        textTransform: 'uppercase', letterSpacing: '0.1em',
                        color: 'var(--primary-600)', marginBottom: '8px',
                      }}>
                        Tool Details
                      </div>
                      <ReviewRow label="Brand"       value={formData.toolBrand} />
                      <ReviewRow label="Category"    value={formData.toolCategory} />
                      <ReviewRow label="Model"       value={formData.toolModel} />
                      <ReviewRow label="Serial #"    value={formData.serialNumber} />
                      <ReviewRow label="Tool Age"    value={formData.toolAge} />
                    </div>

                    {/* Service section */}
                    <div style={{
                      background: 'var(--alloy-base)',
                      border: '1px solid var(--machined-border)',
                      borderRadius: '12px',
                      padding: '16px 20px',
                      marginBottom: '24px',
                    }}>
                      <div style={{
                        fontSize: '0.7rem', fontWeight: 800,
                        textTransform: 'uppercase', letterSpacing: '0.1em',
                        color: 'var(--primary-600)', marginBottom: '8px',
                      }}>
                        Service Request
                      </div>
                      <ReviewRow label="Service Type"   value={formData.serviceType} />
                      {formData.packageId && (
                        <ReviewRow label="Package ID" value={formData.packageId} />
                      )}
                      <ReviewRow label="Approval"       value={
                        formData.approvalMode === 'preapprove_limit'
                          ? `Pre-approve up to $${formData.preapprovalLimit}`
                          : formData.approvalMode === 'package_only'
                            ? 'Package price only'
                            : 'Quote required before repair'
                      } />
                      <ReviewRow label="Warranty / Coverage" value={getWarrantyRequestLabel(formData.warrantyRequested)} />
                      {(formData.warrantyRequested === 'yes' || formData.warrantyRequested === 'not_sure') && (
                        <ReviewRow label="Purchase Date" value={formData.purchaseDate} />
                      )}
                      <ReviewRow label="Priority"       value={formData.priority} />
                      <ReviewRow label="Issue Start"    value={formData.issueStart} />
                      <ReviewRow label="Details & Notes" value={formData.issueDescription} />
                      {photos.length > 0 && (
                        <div style={{
                          display: 'flex',
                          gap: '12px',
                          alignItems: 'flex-start',
                          padding: '10px 0',
                          borderBottom: '1px solid rgba(15,23,42,0.06)',
                        }}>
                          <span style={{
                            minWidth: '130px',
                            fontSize: '0.72rem',
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.08em',
                            color: 'rgba(15,23,42,0.45)',
                            paddingTop: '2px',
                          }}>
                            Photos
                          </span>
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                            {photos.map((p) => (
                              <img
                                key={p.id}
                                src={p.preview}
                                alt={p.name}
                                style={{
                                  width: '60px', height: '60px',
                                  objectFit: 'cover',
                                  borderRadius: '8px',
                                  border: '1px solid rgba(15,23,42,0.12)',
                                }}
                              />
                            ))}
                            <span style={{ fontSize: '0.78rem', color: 'rgba(15,23,42,0.5)', alignSelf: 'center' }}>
                              {photos.length} photo{photos.length !== 1 ? 's' : ''} attached
                            </span>
                          </div>
                        </div>
                      )}
                    </div>

                    {/* Shipping section */}
                    <div style={{
                      background: 'var(--alloy-base)',
                      border: '1px solid var(--machined-border)',
                      borderRadius: '12px',
                      padding: '16px 20px',
                      marginBottom: '24px',
                    }}>
                      <div style={{
                        fontSize: '0.7rem', fontWeight: 800,
                        textTransform: 'uppercase', letterSpacing: '0.1em',
                        color: 'var(--primary-600)', marginBottom: '8px',
                      }}>
                        Shipping & Return Delivery
                      </div>
                      <ReviewRow label="Address"    value={formData.address} />
                      <ReviewRow label="City"       value={formData.city} />
                      <ReviewRow label="State"      value={formData.state} />
                      <ReviewRow label="ZIP"        value={formData.zip} />
                      <ReviewRow label="Country"    value={formData.country} />
                      <ReviewRow label="Get tool to us" value={formData.inboundShippingMethod === 'local_pickup_dropoff' ? 'Free local pickup / drop-off' : 'Customer arranges and pays carrier directly'} />
                      <ReviewRow label="Return"     value={formData.returnShippingPreference === 'expedited' ? 'Expedited if available' : formData.returnShippingPreference === 'hold_for_pickup' ? 'Hold for pickup' : 'Standard return shipping'} />
                      <ReviewRow label="Old Parts"  value={formData.oldPartsReturn === 'return' ? 'Return replaced parts' : 'Recycle or discard'} />
                      <ReviewRow
                        label="Shipping Option"
                        value={formData.shippingRateName
                          ? `${formData.shippingRateName} — ${formData.shippingRatePrice === 0 ? 'FREE' : `$${Number(formData.shippingRatePrice).toFixed(2)}`}`
                          : ''}
                      />
                    </div>

                    {/* Disclaimer */}
                    <p style={{
                      fontSize: '0.78rem', color: 'rgba(15,23,42,0.45)',
                      lineHeight: 1.6, margin: '0 0 8px 0',
                    }}>
                      By submitting this request you agree that our service team may contact you via your
                      preferred method to discuss repair options, pricing, and scheduling. No charges are
                      incurred until you approve a quote.
                    </p>

                    {/* Submit error */}
                    {submitError && (
                      <div style={{
                        background: '#fef2f2',
                        border: '1px solid #fca5a5',
                        borderRadius: '12px',
                        padding: '12px 16px',
                        marginTop: '12px',
                        fontSize: '0.875rem',
                        color: '#dc2626',
                      }}>
                        {submitError}
                      </div>
                    )}
                  </div>
                )}

                {/* ── Navigation buttons ── */}
                <div style={{
                  display: 'flex',
                  justifyContent: step === 1 ? 'flex-end' : 'space-between',
                  alignItems: 'center',
                  marginTop: '28px',
                  gap: '12px',
                  flexWrap: 'wrap',
                }}>
                  {step > 1 && (
                    <button
                      type="button"
                      onClick={back}
                      style={{
                        display: 'flex', alignItems: 'center', gap: '6px',
                        background: 'transparent',
                        border: '1px solid var(--machined-border)',
                        borderRadius: '10px',
                        padding: '12px 20px',
                        fontSize: '0.825rem', fontWeight: 700,
                        color: 'rgba(15,23,42,0.6)',
                        cursor: 'pointer',
                        transition: 'border-color 0.2s, color 0.2s',
                        letterSpacing: '0.04em',
                      }}
                      onMouseEnter={(e) => {
                        e.currentTarget.style.borderColor = 'rgba(15,23,42,0.35)';
                        e.currentTarget.style.color = 'black';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.borderColor = 'var(--machined-border)';
                        e.currentTarget.style.color = 'rgba(15,23,42,0.6)';
                      }}
                    >
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M19 12H5M12 5l-7 7 7 7"/>
                      </svg>
                      Back
                    </button>
                  )}

                  {step < 5 ? (
                    <button
                      type="button"
                      className="alloy-button"
                      onClick={next}
                      style={{ display: 'flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}
                    >
                      Continue
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                      </svg>
                    </button>
                  ) : (
                    <button
                      type="submit"
                      className="alloy-button"
                      disabled={submitting}
                      style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: submitting ? 'not-allowed' : 'pointer', opacity: submitting ? 0.7 : 1 }}
                    >
                      {submitting ? (
                        <>
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" style={{ animation: 'spin 1s linear infinite' }}>
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                          </svg>
                          Submitting…
                        </>
                      ) : (
                        <>
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                          </svg>
                          Submit Repair Request
                        </>
                      )}
                    </button>
                  )}
                </div>
              </form>
            )}
          </div>
        </div>
      </section>

      {/* ── Quick Links ──────────────────────────────────────────────────── */}
      <section style={{
        padding: 'clamp(3rem, 6vw, 4rem) clamp(1.5rem, 5vw, 3rem)',
        background: 'white',
        borderTop: '1px solid var(--machined-border)',
      }}>
        <div style={{ maxWidth: '1400px', margin: '0 auto' }}>
          <div style={{ textAlign: 'center', marginBottom: 'clamp(1.5rem, 3vw, 2rem)' }}>
            <h2 style={{
              fontSize: 'clamp(1.5rem, 3vw, 2rem)',
              fontWeight: 900,
              color: '#0f172a',
              margin: '0 0 8px 0',
              letterSpacing: '-0.02em',
            }}>
              Related Resources
            </h2>
            <p style={{ fontSize: '0.9rem', color: 'rgba(15,23,42,0.5)', margin: 0 }}>
              Everything you need to keep your tools in top shape
            </p>
          </div>
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 240px), 1fr))',
            gap: '16px',
          }}>
            {[
              {
                to: '/parts',
                title: 'Schematics',
                description: 'Browse interactive part diagrams and order replacement parts for all major brands.',
              },
              {
                to: '/products',
                title: 'Shop Parts',
                description: 'Find replacement parts, repair supplies, and professional drywall tool essentials.',
              },
              {
                to: '/contact',
                title: 'Talk to an Expert',
                description: 'Our industry veterans are ready to help - no bots, no runaround, only expert real support.',
              },
            ].map((ql) => (
              <Link key={ql.to} to={ql.to} style={{ textDecoration: 'none' }}>
                <div
                  style={{
                    background: 'white',
                    border: '1px solid var(--machined-border)',
                    borderRadius: '12px',
                    padding: '24px',
                    cursor: 'pointer',
                    transition: 'box-shadow 0.22s, border-color 0.22s, transform 0.22s',
                    height: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.boxShadow = '0 8px 32px rgba(34,85,238,0.1)';
                    e.currentTarget.style.borderColor = 'rgba(34,85,238,0.3)';
                    e.currentTarget.style.transform = 'translateY(-2px)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.boxShadow = 'none';
                    e.currentTarget.style.borderColor = 'var(--machined-border)';
                    e.currentTarget.style.transform = 'translateY(0)';
                  }}
                >
                  <h3 style={{ fontSize: '1rem', fontWeight: 800, color: '#0f172a', margin: '0 0 8px 0' }}>{ql.title}</h3>
                  <p style={{ fontSize: '0.825rem', color: 'rgba(15,23,42,0.6)', margin: '0 0 16px 0', lineHeight: 1.5, flex: 1 }}>{ql.description}</p>
                  <div style={{
                    display: 'flex', alignItems: 'center', gap: '4px',
                    color: 'var(--primary-600)', fontSize: '0.75rem',
                    fontWeight: 800, textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                  }}>
                    Learn more
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}

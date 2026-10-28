/**
 * frontend/src/pages/ShippingPolicy.jsx
 *
 * Shipping Policy page — /shipping-policy
 *
 * Covers: free shipping threshold, carriers & service levels, processing
 * times, international shipping, order modifications, packaging, and FAQs.
 * Static informational page — no auth required.
 */

import { Link } from 'react-router-dom';
import {
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  Clock3,
  FileText,
  Globe2,
  LifeBuoy,
  PackageCheck,
  RotateCcw,
  ShieldCheck,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import '../styles/store-policies.css';

/* ─── Quick facts shown in the hero panel ────────────────────────────────── */
const QUICK_FACTS = [
  { Icon: Clock3, label: 'Order processing', text: 'Fulfillment timing depends on stock, package handling, and the selected shipping method.' },
  { Icon: PackageCheck, label: 'Free shipping $50+', text: 'Qualifying domestic orders may receive free standard shipping when available at checkout.' },
  { Icon: CheckCircle2, label: 'Shipment tracking', text: 'Tracking details are provided when a tracked shipment is dispatched.' },
];

// Information only. Carrier services and checkout charges are authoritative
// solely when returned by the native WooCommerce Checkout Block / Store API.
const SHIPPING_RATES = [
  { service: 'Standard shipping', carrier: 'Assigned at fulfillment', transit: 'Varies by address and package', price: 'Shown at checkout; free when eligible', highlight: true },
  { service: 'Expedited service', carrier: 'Only when supported', transit: 'Depends on confirmed availability', price: 'Shown only when available', highlight: false },
  { service: 'Oversized or multi-package', carrier: 'Selected for safe handling', transit: 'May require special arrangements', price: 'Confirmed for the shipment', highlight: false },
  { service: 'International shipping', carrier: 'Destination-dependent', transit: 'Varies by customs and service', price: 'Only where shipping is supported', highlight: false },
];

const PROCESSING_POINTS = [
  'Processing and dispatch times depend on inventory availability, packaging requirements, and order verification.',
  'Shipping estimates are not guaranteed delivery dates unless expressly confirmed for your order.',
  'Tracking information is provided when the shipment is dispatched and a carrier tracking reference is available.',
  'Backordered or multiple-package orders may require separate shipments; our team will provide updates when applicable.',
];

const INTERNATIONAL_POINTS = [
  'International destinations and available methods depend on the shipping options offered at checkout.',
  'Import duties, customs charges and local taxes may apply and will be disclosed where the applicable service supports it.',
  'Free domestic standard-shipping offers are not a promise of free international or freight shipping.',
  'Contact support before ordering if your destination requires special delivery arrangements.',
];

const SIGNATURE_RULES = [
  { threshold: 'High-value orders', rule: 'Signature confirmation may be required depending on order value, carrier and destination.' },
  { threshold: 'Oversized shipments', rule: 'Delivery arrangements and receiving requirements are confirmed when applicable.' },
];

const SHIPPING_FAQS = [
  {
    q: 'How do I track my shipment?',
    a: 'When tracking is available, it is provided after dispatch. You can also check your Account Dashboard under Order History or visit drywalltoolbox.com/order-tracking.',
  },
  {
    q: 'Can I modify or cancel my order after it is placed?',
    a: 'Contact support promptly. Cancellation or modification depends on whether processing, allocation or shipment has already begun.',
  },
  {
    q: 'What if an item is backordered?',
    a: 'We will provide fulfillment options and updates when an item cannot ship as expected.',
  },
  {
    q: 'Do you offer Saturday delivery?',
    a: 'Saturday or other special delivery services are not guaranteed. Contact support to confirm availability before ordering.',
  },
  {
    q: 'Are there signature requirements for high-value shipments?',
    a: 'Some shipments require a signature based on the carrier, destination or insured value. We will communicate applicable requirements.',
  },
  {
    q: 'How are large or heavy tools packaged?',
    a: 'Packing is determined by the actual item and its handling needs. Long tools and complete toolsets may require multiple cartons or specialized delivery.',
  },
  {
    q: 'How does freight delivery work?',
    a: 'If freight is necessary, delivery access, receiving arrangements and additional services must be confirmed before dispatch.',
  },
];

const CTA_LINKS = [
  { Icon: RotateCcw, title: 'Start a Return', text: 'Initiate a return or exchange request', to: '/returns', action: 'Start a return' },
  { Icon: LifeBuoy, title: 'Contact Support', text: 'Questions about your shipment? We’re here.', to: '/contact', action: 'Get help' },
  { Icon: FileText, title: 'Help Center', text: 'Shipping, warranty, repairs & more', to: '/help', action: 'Get help' },
  { Icon: ShieldCheck, title: 'Return Policy', text: 'Returns, warranty, cancellations & payment', to: '/return-policy', action: 'View policy' },
];

function PolicyPill({ children }) {
  return <span className="store-policy-pill">{children}</span>;
}

function QuickFactCard({ Icon, label, text }) {
  return (
    <article className="store-policy-fact">
      <span className="store-policy-fact__icon"><Icon size={20} /></span>
      <div>
        <strong>{label}</strong>
        <p>{text}</p>
      </div>
    </article>
  );
}

function PolicyCTACard({ Icon, title, text, to, action }) {
  return (
    <Link to={to} className="store-policies-card">
      <span className="store-policies-card__icon"><Icon size={22} /></span>
      <span className="store-policies-card__copy">
        <strong>{title}</strong>
        <span>{text}</span>
      </span>
      <span className="store-policies-card__action">
        {action} <ArrowRight size={15} />
      </span>
    </Link>
  );
}

export default function ShippingPolicy() {
  return (
    <div className="store-policy-page">
      <SEOHead
        title="Shipping Policy"
        description="Drywall Toolbox shipping information: qualifying standard shipping, checkout shipping options, delivery handling, and tracking."
        canonical="/shipping-policy"
      />

      <section className="store-policy-hero">
        <div className="store-policy-hero__copy">
          <PolicyPill>Shipping information</PolicyPill>
          <h1>Shipping Policy</h1>
          <p>
            Everything you need to know about how we pack, ship, and track your
            Drywall Toolbox orders — from processing times to delivery.
          </p>
        </div>

        <aside className="store-policy-hero__panel" aria-label="Shipping policy highlights">
          {QUICK_FACTS.map((fact) => <QuickFactCard key={fact.label} {...fact} />)}
        </aside>
      </section>

      <main className="store-policy-content">
        <section className="store-policy-section">
          <div className="store-policy-section__header">
            <PolicyPill>Service levels</PolicyPill>
            <h2>Service Levels & Carriers</h2>
            <p>
              Shipping costs and available methods are determined at checkout. The final carrier
              is selected during fulfillment based on the physical shipment.
            </p>
          </div>

          <div className="store-policy-table store-policy-table--rates">
            <div className="store-policy-table__row store-policy-table__row--head store-policy-table--rates">
              <span>Service</span>
              <span>Carrier</span>
              <span>Transit time</span>
              <span>Cost</span>
            </div>
            {SHIPPING_RATES.map((row) => (
              <div key={row.service} className="store-policy-table__row store-policy-table--rates">
                <div className="store-policy-table__cell store-policy-table__cell--label">{row.service}</div>
                <div className="store-policy-table__cell">{row.carrier}</div>
                <div className="store-policy-table__cell">{row.transit}</div>
                <div className={`store-policy-table__cell${row.highlight ? ' store-policy-table__cell--highlight' : ''}`}>
                  {row.price}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="store-policy-grid">
          <article className="store-policy-card">
            <Clock3 size={22} />
            <h2>Order Processing</h2>
            <ul className="store-policy-check-list">
              {PROCESSING_POINTS.map((text) => (
                <li key={text}>
                  <CheckCircle2 size={15} style={{ color: '#16a34a' }} />
                  <span>{text}</span>
                </li>
              ))}
            </ul>
          </article>

          <article className="store-policy-card">
            <Globe2 size={22} />
            <h2>International & Canada</h2>
            <ul className="store-policy-check-list">
              {INTERNATIONAL_POINTS.map((text) => (
                <li key={text}>
                  <CheckCircle2 size={15} style={{ color: '#7c3aed' }} />
                  <span>{text}</span>
                </li>
              ))}
            </ul>
          </article>
        </section>

        <section className="store-policy-section">
          <div className="store-policy-section__header">
            <PolicyPill>High-value orders</PolicyPill>
            <h2>Signature Requirements</h2>
            <p>To protect your order, signature confirmation may be required for high-value shipments.</p>
          </div>

          <div className="store-policy-table">
            {SIGNATURE_RULES.map((rule) => (
              <div key={rule.threshold} className="store-policy-table__row store-policy-table--rules">
                <div className="store-policy-table__cell--label">{rule.threshold}</div>
                <div className="store-policy-table__cell">{rule.rule}</div>
              </div>
            ))}
          </div>
          <p style={{ margin: '14px 0 0', color: '#64748b', fontSize: '0.82rem', lineHeight: 1.6 }}>
            We recommend using UPS My Choice or FedEx Delivery Manager to manage delivery windows and redirect
            packages as needed. Shipments must go to a valid physical address — no PO Boxes.
          </p>
        </section>

        <section className="store-policy-section">
          <div className="store-policy-section__header">
            <PolicyPill>Good to know</PolicyPill>
            <h2>Common Shipping Questions</h2>
          </div>
          <div className="store-policy-faq-list">
            {SHIPPING_FAQS.map(({ q, a }) => (
              <div key={q} className="store-policy-faq">
                <div className="store-policy-faq__q">{q}</div>
                <div className="store-policy-faq__a">{a}</div>
              </div>
            ))}
          </div>
        </section>

        <div className="store-policy-alert">
          <AlertCircle size={18} />
          <p>
            Packaging and carrier protections depend on the shipment and its service requirements. If your order arrives
            damaged or contains the wrong item, photograph the packaging and contents immediately and{' '}
            <Link to="/contact" style={{ color: 'inherit', textDecoration: 'underline' }}>
              contact us within 72 hours
            </Link>
            . We will file the carrier claim and dispatch a replacement — you will not be asked to
            return a damaged item.
          </p>
        </div>

        <section className="store-policies-grid" aria-label="Related shipping links">
          {CTA_LINKS.map((link) => <PolicyCTACard key={link.title} {...link} />)}
        </section>
      </main>
    </div>
  );
}

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
  { Icon: Clock3, label: 'Same-day processing', text: 'In-stock orders placed before 12:00 PM CST ship the same day.' },
  { Icon: PackageCheck, label: 'Free shipping $50+', text: 'Standard ground shipping is free on qualifying orders.' },
  { Icon: CheckCircle2, label: 'Tracked every step', text: 'A tracking number is emailed the moment your order ships.' },
];

/* ─── Shipping rate table data ───────────────────────────────────────────── */
const SHIPPING_RATES = [
  { service: 'Standard Ground', carrier: 'UPS / FedEx', transit: '3–7 business days', price: 'Free on orders $50+', highlight: true },
  { service: 'USPS Priority Mail', carrier: 'USPS', transit: '2–4 business days', price: 'Calculated at checkout', highlight: false },
  { service: 'Expedited 2-Day', carrier: 'UPS / FedEx', transit: '2 business days', price: 'Calculated at checkout', highlight: false },
  { service: 'Overnight / Next Day Air', carrier: 'UPS / FedEx', transit: '1 business day', price: 'Calculated at checkout', highlight: false },
  { service: 'Saturday Delivery', carrier: 'UPS / FedEx', transit: 'Saturday arrival', price: 'Calculated at checkout', highlight: false },
  { service: 'LTL Freight', carrier: 'Regional freight carriers', transit: 'Coordinated with customer', price: 'Calculated at checkout', highlight: false },
  { service: 'Canada / International', carrier: 'FedEx / Canada Post', transit: '2–21 days (by method)', price: 'Calculated at checkout', highlight: false },
];

const PROCESSING_POINTS = [
  'In-stock orders placed before 12:00 PM CST on business days ship the same day.',
  'Orders after 12:00 PM CST or on weekends/holidays ship the next business day.',
  'You\'ll receive a shipment confirmation email with tracking once your order is picked up by the carrier.',
  'Backordered items ship separately at no additional charge — they never delay in-stock items.',
];

const INTERNATIONAL_POINTS = [
  'Canada: Canada Post (14–21 days), FedEx Air – Duties & Taxes Included (2–3 days, all-in pricing), or FedEx Air – Duties & Taxes Excluded (2–3 days).',
  'Worldwide: FedEx International Priority (2–3 days) or FedEx International Economy (3–5 days). All orders invoiced in USD.',
  'Free shipping threshold does not apply to Alaska, Hawaii, Canada, or international orders.',
  'Buyer is responsible for all import duties, taxes, and brokerage fees outside the US. Refusing delivery and failing to pay duties makes the buyer liable for both the original and return freight costs.',
];

const SIGNATURE_RULES = [
  { threshold: 'Orders over $300', rule: 'Indirect signature required at apartment complexes.' },
  { threshold: 'Orders over $500', rule: 'Indirect signature required at all residential deliveries.' },
  { threshold: 'Orders over $1,000', rule: 'Direct signature required at time of delivery.' },
];

/* ─── FAQ items specific to shipping ────────────────────────────────────── */
const SHIPPING_FAQS = [
  {
    q: 'How do I track my shipment?',
    a: 'A tracking number is emailed automatically the moment your order ships. You can also find live tracking and your full order history in your Account Dashboard under Order History, or visit drywalltoolbox.com/order-tracking.',
  },
  {
    q: 'Can I modify or cancel my order after it\'s placed?',
    a: 'You may cancel any order before it has been fulfilled and shipped. Once a shipment has left our facility, the standard return process applies. Direct-ship (drop-shipped) orders and special-order items cannot be cancelled once placed with the manufacturer.',
  },
  {
    q: 'What if an item is backordered?',
    a: 'We will notify you by email with an estimated restock date and offer to hold, substitute, or refund the line item. Backordered parts never delay in-stock items — they ship separately at no extra shipping charge.',
  },
  {
    q: 'Do you offer Saturday delivery?',
    a: 'Yes — Saturday Delivery is available at checkout for select ZIP codes. Order by 12:00 PM CST Friday. Contact us to confirm availability for your area.',
  },
  {
    q: 'Are there signature requirements for high-value shipments?',
    a: 'Yes. Orders over $300 require indirect signature at apartment complexes; orders over $500 require indirect signature at all residential addresses; orders over $1,000 require a direct signature at the time of delivery. We recommend using UPS My Choice or FedEx Delivery Manager to manage delivery windows.',
  },
  {
    q: 'How are large or heavy tools packaged?',
    a: 'Larger tools and complete sets are double-boxed with industrial foam inserts and corner protection. Items that are oversized or too heavy for standard parcel carriers ship via LTL (Less than Truckload) freight — clearly marked on the product page.',
  },
  {
    q: 'How does LTL freight delivery work?',
    a: 'LTL shipments require a physical address accessible to a full-size truck and trailer. Someone must be present to receive, inspect, and sign the Bill of Lading. Inspect the shipment BEFORE signing — note any damage on the BOL. Lift gate service is available for an additional fee if you don\'t have a loading dock.',
  },
];

const CTA_LINKS = [
  { Icon: RotateCcw, title: 'Start a Return', text: 'Initiate a return or exchange request', to: '/returns', action: 'Start a return' },
  { Icon: LifeBuoy, title: 'Contact Support', text: 'Questions about your shipment? We’re here.', to: '/contact', action: 'Get help' },
  { Icon: FileText, title: 'Full FAQ', text: 'Shipping, warranty, repairs & more', to: '/faq', action: 'Read FAQ' },
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
        description="Drywall Toolbox shipping policy: free shipping on orders $50 or more, same-day processing by 12 PM CST, UPS, FedEx &amp; USPS service levels, international shipping, and tracking information."
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
              Carrier is selected automatically based on package size, weight, and destination.
              UPS, FedEx, USPS, and LTL freight carriers are used.
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
            All outbound shipments are insured and photographed before sealing. If your order arrives
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

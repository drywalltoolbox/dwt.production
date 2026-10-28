/**
 * frontend/src/pages/StorePolicies.jsx
 *
 * Store policies landing page.
 */

import { Link } from 'react-router-dom';
import {
  ArrowRight,
  CircleHelp,
  CreditCard,
  FileText,
  LifeBuoy,
  RotateCcw,
  ShieldCheck,
  Truck,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import '../styles/store-policies.css';

const POLICY_CARDS = [
  {
    Icon: RotateCcw,
    title: 'Return Policy',
    text: '45-day returns, no restocking fee on unused approved returns, Return ID steps, return shipping, exclusions, and refund timing.',
    to: '/return-policy',
    action: 'View return policy',
  },
  {
    Icon: Truck,
    title: 'Shipping Policy',
    text: 'Processing times, carriers, free-shipping threshold, freight delivery, damage reporting, and tracking.',
    to: '/shipping-policy',
    action: 'View shipping policy',
  },
  {
    Icon: FileText,
    title: 'Return Portal',
    text: 'Start a return request, submit order details, and receive your Return ID and instructions by email.',
    to: '/returns',
    action: 'Start a return',
  },
  {
    Icon: ShieldCheck,
    title: 'Warranty Support',
    text: 'Manufacturer warranty help, defect routing, repair support, and product issue guidance.',
    to: '/contact',
    action: 'Contact support',
  },
  {
    Icon: CreditCard,
    title: 'Payments & Orders',
    text: 'Order changes, cancellations before fulfillment, accepted payment methods, and checkout support.',
    to: '/help',
    action: 'Visit Help Center',
  },
  {
    Icon: LifeBuoy,
    title: 'Customer Support',
    text: 'Questions about an order, delivery, return, repair, or product fitment go through our support team.',
    to: '/contact',
    action: 'Get help',
  },
  {
    Icon: FileText,
    title: 'Terms of Service',
    text: 'Customer terms for ordering, payments, shipping, returns, warranties, repair services, and website use.',
    to: '/terms-of-service/',
    action: 'Read terms',
  },
  {
    Icon: ShieldCheck,
    title: 'Privacy Policy',
    text: 'How we collect, use, protect, and share information across shopping, checkout, accounts, repairs, and support.',
    to: '/privacy-policy/',
    action: 'Read privacy policy',
  },
];

function PolicyPill({ children }) {
  return <span className="store-policy-pill">{children}</span>;
}

function PolicyCard({ Icon, title, text, to, action }) {
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

export default function StorePolicies() {
  return (
    <div className="store-policy-page">
      <SEOHead
        title="Store Policies"
        description="Drywall Toolbox store policies for returns, shipping, warranty support, order changes, payments, and customer support."
        canonical="/policies"
      />

      <section className="store-policy-hero store-policy-hero--hub">
        <div className="store-policy-hero__copy">
          <PolicyPill>Customer support center</PolicyPill>
          <h1>Store Policies</h1>
          <p>
            Find the policy or support path you need without digging through a long
            legal document. Returns, shipping, warranty help, order changes, and
            support are organized below.
          </p>
        </div>

        <aside className="store-policy-hero__panel store-policy-hero__panel--compact" aria-label="Policy summary">
          <div className="store-policies-summary">
            <CircleHelp size={22} />
            <strong>Need a fast answer?</strong>
            <p>
              Start with the return or shipping policy, or contact us if your issue
              involves an active order.
            </p>
          </div>
        </aside>
      </section>

      <main className="store-policy-content store-policies-content">
        <section className="store-policy-section store-policy-section--intro">
          <div>
            <PolicyPill>Popular policies</PolicyPill>
            <h2>Choose what you need</h2>
          </div>
          <p>
            Each policy page is written for customers first: short, direct, and
            focused on what to do next.
          </p>
        </section>

        <section className="store-policies-grid" aria-label="Store policy links">
          {POLICY_CARDS.map((card) => <PolicyCard key={card.title} {...card} />)}
        </section>

        <section className="store-policy-footer-cta">
          <LifeBuoy size={24} />
          <div>
            <h2>Still not sure where to go?</h2>
            <p>
              Send us your order number and a short description. We will route it
              to the right support workflow.
            </p>
          </div>
          <Link to="/contact" className="store-policy-button store-policy-button--primary">
            Contact Support <ArrowRight size={16} />
          </Link>
        </section>
      </main>
    </div>
  );
}

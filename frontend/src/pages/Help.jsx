import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { ArrowRight, Box, ChevronRight, Headphones, Mail, MessageCircle, Package, Phone, Search, Settings, ShieldCheck, Star, Truck, UserRound, Wrench } from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import Accordion from '../components/ui/Accordion';
import ToolSetBuyingGuide from '../components/catalog/ToolSetBuyingGuide.jsx';
import { dtbTransition, reducedTransition } from '../motion/dtbMotion.js';
import helpBlueprint from '../assets/media/help-center-blueprint.svg';
import '../styles/help.css';

const FAQ_CATEGORIES = [
  {
    id: 'tool-sets',
    label: 'Tool Sets & Kits',
    questions: [
      { q: 'How do I choose between semi-automatic and automatic sets?', a: 'Start with the work your crew performs and the tools you want in the setup. Compare the listed set contents and product details for each option; exact configurations vary by product.' },
      { q: 'How can I confirm what a set includes?', a: 'Use the product listing’s included-items details as the source for what comes with that set. Check the full list before ordering, and ask us if a specific tool, case, handle, or adapter is not clear.' },
      { q: 'What sizes and working ranges should I compare?', a: 'Review the listed box sizes, corner-finisher sizes, and whether included handles are fixed or extendable. Confirm the range on the product details for the specific set.' },
      { q: 'How should I compare price and availability?', a: 'Use the current catalog listing for price and stock status. Any set savings should be based on the price shown for that product; do not assume an additional bundle discount.' },
      { q: 'Where can I check warranty and compatibility details?', a: 'Review the product details for the set and its included tools. If warranty coverage, a required adapter, or a tool’s fit with your existing equipment is unclear, contact us before ordering.' },
      { q: 'Can someone help me pick a setup for my crew?', a: 'Yes. Contact us with the kind of work you do, the tools you already own, and what you want the set to help your crew accomplish. We can help you compare the listed options.' },
    ],
  },
  {
    id: 'shipping-orders',
    label: 'Shipping & Orders',
    questions: [
      { q: 'Where do you ship?', a: 'We ship to all 50 US states and Canada. International orders outside North America are handled on a case-by-case basis — contact us before placing an international order to confirm eligibility and get a shipping quote.' },
      { q: 'How long does order processing take?', a: 'In-stock tools and parts are typically picked, packed, and handed to the carrier within 1 business day of order placement. Orders placed before 12:00 PM CT on business days are prioritized for same-day processing.' },
      { q: 'What shipping carriers and service levels do you offer?', a: 'We ship via UPS, FedEx, and USPS. Available service levels at checkout include Standard Ground, Expedited 2-Day, Overnight, and Saturday Delivery where available.' },
      { q: 'Do you offer free shipping?', a: 'Orders over $75 qualify for free Standard Ground shipping to the contiguous 48 states. Alaska, Hawaii, Canada, oversized items, and freight shipments may be excluded.' },
      { q: 'How do I track my shipment?', a: 'A tracking number is emailed when your order ships. You can also find order history and tracking in your Account Dashboard.' },
      { q: 'Can I modify or cancel my order after it is placed?', a: 'Order modifications and cancellations can be made within 2 hours of placement if the order has not been picked. Contact us immediately with your order number.' },
    ],
  },
  {
    id: 'warranty-returns',
    label: 'Warranty & Returns',
    questions: [
      { q: 'What is your standard return policy for tools and parts?', a: 'New, unused products in original, unaltered packaging may be returned within 45 days of invoice date for a refund to the original payment method. Items must be in resalable condition.' },
      { q: 'Which items are non-returnable?', a: 'Electrical components, special-order parts, custom-configured items, final-sale products, and products that have been installed, modified, or used in the field are non-returnable.' },
      { q: 'How do I initiate a return?', a: 'Log in to your Account Dashboard and select the order you would like to return, then choose Request Return. Returns sent without a Return ID may be refused or delayed.' },
      { q: 'What if my order arrives damaged or incorrect?', a: 'Photograph the outer packaging and product contents immediately, then contact us within 72 hours of delivery. We will review the issue and coordinate the replacement, claim, or correction.' },
      { q: 'Do new tools carry a manufacturer warranty?', a: 'All new tools sold by Drywall Toolbox are covered by the applicable manufacturer warranty. Warranty claims are processed through us so you do not need to contact the manufacturer separately.' },
      { q: 'Do replacement parts carry a warranty?', a: 'OEM replacement parts carry a 90-day defect warranty from delivery. Wear items such as blades, seals, o-rings, and springs are consumables and are excluded from defect coverage unless clearly defective on arrival.' },
    ],
  },
  {
    id: 'repair-services',
    label: 'Repair Services',
    questions: [
      { q: 'How do I submit a repair request?', a: 'Go to the Repair Services page and complete the request form. You can describe your tool and issue, upload photos, and choose a service path.' },
      { q: 'Is there a diagnostic or bench fee?', a: 'Diagnostic fees generally cover inspection and a written quote. If you approve the work, eligible diagnostic fees may be credited toward the repair cost.' },
      { q: 'Who pays to ship a tool for repair?', a: 'If you ship the tool, you arrange and pay the carrier directly; we do not provide or pay for inbound shipping labels. Free local pickup/drop-off is also available. Return shipping is quoted separately.' },
      { q: 'What happens if I decline the repair after the quote?', a: 'You pay the diagnostic fee and return shipping, if applicable. No additional parts or labor are charged unless you approve the work.' },
      { q: 'How long does a typical repair take?', a: 'Most standard rebuilds complete within 5–7 business days after quote approval. Heavy-damage repairs and parts delays can extend turnaround.' },
      { q: 'What brands do you repair?', a: 'We service major drywall finishing tool brands including TapeTech, Columbia, Asgard, Graco, Level 5, Platinum, SurPro, and Dura-Stilts. Contact us if your brand is not listed.' },
      { q: 'What warranty comes with a repair?', a: 'Repair warranties vary by service tier. Warranty coverage applies to the repaired issue and does not cover new damage, misuse, or normal wear.' },
    ],
  },
  {
    id: 'tool-care',
    label: 'Tool Care & Maintenance',
    questions: [
      { q: 'How often should I service my automatic taper?', a: 'High-volume professionals should consider service every 6 months. Standard professional use is usually annual. Occasional users can often wait 18–24 months.' },
      { q: 'What does a standard service include?', a: 'A standard service includes disassembly, cleaning, inspection, lubrication, worn-part review, calibration, and a functional test before return.' },
      { q: 'Can I do basic maintenance myself?', a: 'Daily cleaning, taping head flushing, cable lubrication, and gate wipe-down can be done in the field. Deep drive-mechanism work should be handled professionally.' },
      { q: 'What are the most common failure points on auto tapers?', a: 'Common issues include cable wear, cracked blades, drive wheel slippage, gooseneck pivot wear, and compound buildup in moving assemblies.' },
      { q: 'How should I store my tools off-season?', a: 'Drain all compound, flush with warm water, dry thoroughly, apply light tool oil to metal moving parts, and store in a dry temperature-stable environment.' },
    ],
  },
  {
    id: 'account',
    label: 'Account',
    questions: [
      { q: 'Do I need an account to place an order?', a: 'No — guest checkout is available. Creating an account gives you order history, tracking, saved addresses, saved products, and repair request access.' },
      { q: 'How do I view my past orders?', a: 'Log in and go to your Account Dashboard. Orders, invoices, repair requests, and tracking details are listed there when available.' },
      { q: 'Can I save addresses for future checkout?', a: 'Yes. Registered customers can manage saved addresses from the Account Dashboard.' },
      { q: 'Can I manage repair requests in my account?', a: 'Yes. Repair requests and status updates are available from the Account Dashboard for logged-in customers.' },
    ],
  },
];

const ICONS = { 'tool-sets': Box, 'shipping-orders': Truck, 'warranty-returns': ShieldCheck, 'repair-services': Wrench, 'tool-care': Settings, account: UserRound };
const DESCRIPTIONS = {
  'shipping-orders': 'Track orders, shipping times, and delivery info',
  'repair-services': 'Request a repair, status, and process details',
  'warranty-returns': 'Returns, warranty coverage, and eligibility',
  'tool-care': 'Keep your tools working their best.',
  account: 'Manage your orders, addresses, and account settings.',
  'tool-sets': 'Find the right setup for your crew.',
};
const SHORTCUTS = [
  { title: 'Track a repair', text: 'Check your repair status', to: '/repairs/track', Icon: Truck },
  { title: 'Shipping timelines', text: 'When will my order arrive?', to: '/shipping-policy', Icon: Package },
  { title: 'Request a return', text: 'Start a return or exchange', to: '/returns', Icon: ArrowRight },
  { title: 'Find replacement parts', text: 'Parts, diagrams and more', to: '/schematics', Icon: Settings },
];

export default function Help() {
  const reduceMotion = useReducedMotion();
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedCategory = searchParams.get('category');
  const activeCategory = FAQ_CATEGORIES.some((category) => category.id === requestedCategory) ? requestedCategory : 'account';
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const activeData = FAQ_CATEGORIES.find((category) => category.id === activeCategory);
  const ActiveIcon = ICONS[activeCategory];
  const results = search ? FAQ_CATEGORIES.flatMap((category) => category.questions.filter(({ q, a }) => `${q} ${a} ${category.label}`.toLowerCase().includes(search.toLowerCase())).map((item) => ({ ...item, category: category.label }))) : [];
  const chooseCategory = (id) => {
    const next = new URLSearchParams(searchParams);
    next.set('category', id);
    setSearchParams(next, { replace: true });
    setSearch('');
    setQuery('');
    document.getElementById('help-topics')?.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
  };
  const submitSearch = (event) => { event.preventDefault(); setSearch(query.trim()); document.getElementById('help-topics')?.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' }); };
  return (
    <main className="help-page">
      <SEOHead title="Help Center — Drywall Toolbox" description="Answers about tool sets, shipping, orders, repair services, warranties, maintenance, parts, and accounts." canonical="/help" />
      <section className="help-hero">
        <div className="help-hero__blueprint" aria-hidden="true"><img src={helpBlueprint} alt="" /></div>
        <div className="help-container help-hero__inner">
          <div className="help-hero__intro"><span className="help-eyebrow">Help Center</span><h1>How can we <span>help?</span></h1><p>Find answers about repair services, shipping, warranties, account management, and product support.</p></div>
          <form className="help-search" onSubmit={submitSearch} role="search"><Search size={21} aria-hidden="true" /><label className="help-sr-only" htmlFor="help-search">Search help articles</label><input id="help-search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search help articles, orders, repairs, policies..." /><button type="submit">Search</button></form>
          <div className="help-hero__topics">{['shipping-orders', 'repair-services', 'warranty-returns'].map((id) => { const category = FAQ_CATEGORIES.find((item) => item.id === id); const Icon = ICONS[id]; return <button className="help-hero-card" key={id} type="button" onClick={() => chooseCategory(id)}><span className="help-hero-card__icon"><Icon size={28} /></span><span><strong>{category.label}</strong><small>{DESCRIPTIONS[id]}</small></span><ChevronRight className="help-chevron" size={20} /></button>; })}</div>
        </div>
      </section>
      <section className="help-popular" aria-label="Popular topics"><div className="help-container help-popular__inner"><div className="help-popular__title"><Star size={30} /><span><strong>Popular topics</strong><small>Quick answers to our most common questions.</small></span></div><div className="help-popular__links">{SHORTCUTS.map(({ title, text, to, Icon }) => <Link className="help-shortcut" to={to} key={title}><span className="help-shortcut__icon"><Icon size={23} /></span><span><strong>{title}</strong><small>{text}</small></span><ChevronRight className="help-chevron" size={18} /></Link>)}</div></div></section>
      <section id="help-topics" className="help-content help-container">
        <nav className="help-sidebar" aria-label="Help topics"><h2>Help Topics</h2><div className="help-sidebar__items">{FAQ_CATEGORIES.map((category) => { const Icon = ICONS[category.id]; return <button key={category.id} type="button" className={category.id === activeCategory && !search ? 'is-active' : ''} aria-current={category.id === activeCategory && !search ? 'page' : undefined} onClick={() => chooseCategory(category.id)}><Icon size={21} /><span>{category.label}</span><ChevronRight size={18} className="help-sidebar__arrow" /></button>; })}</div></nav>
        <div className="help-answers"><header className="help-answers__header"><span className="help-answers__icon">{search ? <Search size={30} /> : <ActiveIcon size={30} />}</span><div><h2>{search ? 'Search results' : activeData.label}</h2><p>{search ? `${results.length} ${results.length === 1 ? 'result' : 'results'} for “${search}”` : `${activeData.questions.length} questions`}</p></div><p className="help-answers__description">{search ? 'Search across all help topics.' : DESCRIPTIONS[activeCategory]}</p></header>
          <Motion.div key={search ? `search-${search}` : activeCategory} initial={{ opacity: reduceMotion ? 1 : 0, y: reduceMotion ? 0 : 8 }} animate={{ opacity: 1, y: 0 }} transition={reduceMotion ? reducedTransition : dtbTransition.standard}>
            {search ? (results.length ? <Accordion className="help-faq" variant="cards" items={results.map((item, index) => ({ id: `search-${index}`, question: `${item.category}: ${item.q}`, answer: item.a }))} /> : <div className="help-empty"><p>No answers found. Try a different search or choose a topic.</p><button type="button" onClick={() => { setSearch(''); setQuery(''); }}>Browse help topics</button></div>) : activeCategory === 'tool-sets' ? <ToolSetBuyingGuide questions={activeData.questions} /> : <Accordion className="help-faq" variant="cards" items={activeData.questions.map((item, index) => ({ id: `${activeCategory}-${index}`, question: item.q, answer: item.a }))} />}
          </Motion.div>
        </div>
        <aside className="help-support"><span className="help-support__icon"><Headphones size={29} /></span><h2>Still need help?</h2><p>Our team is here to help with orders, repairs, parts, and more.</p><Link className="help-support__button" to="/contact"><MessageCircle size={20} /> Contact Support</Link><div className="help-support__contact"><Phone size={19} /><span><a href="tel:+16129781007">612-978-1007</a><small>Mon–Fri 8AM–5PM EST</small></span></div><div className="help-support__contact"><Mail size={19} /><span><a href="mailto:dtb@drywalltoolbox.com">dtb@drywalltoolbox.com</a><small>We typically respond within one business day.</small></span></div></aside>
      </section>
    </main>
  );
}

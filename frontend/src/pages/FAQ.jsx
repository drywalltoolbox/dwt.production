import { useState, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { m as Motion } from 'framer-motion';
import SEOHead from '../components/shared/SEOHead';
import NavbarTabs from '../components/ui/NavbarTabs';
import Accordion from '../components/ui/Accordion';
import ToolSetBuyingGuide from '../components/catalog/ToolSetBuyingGuide.jsx';
import { dtbTransition } from '../motion/dtbMotion.js';

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

export default function FAQ() {
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedCategory = searchParams.get('category');
  const activeCategory = FAQ_CATEGORIES.some((category) => category.id === requestedCategory)
    ? requestedCategory
    : 'shipping-orders';
  const [isMobile, setIsMobile] = useState(() => (typeof window !== 'undefined' ? window.innerWidth < 768 : false));

  const setActiveCategory = (categoryId) => {
    const nextParams = new URLSearchParams(searchParams);
    nextParams.set('category', categoryId);
    setSearchParams(nextParams, { replace: true });
  };

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 767px)');
    const handler = (e) => setIsMobile(e.matches);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, []);

  const activeData = FAQ_CATEGORIES.find((c) => c.id === activeCategory);
  const accordionItems = (activeData?.questions || []).map((q, i) => ({ id: `${activeCategory}-${i}`, question: q.q, answer: q.a }));

  return (
    <div style={{ minHeight: '100vh' }} className="page-wrapper">
      <SEOHead
        title="FAQ — Drywall Tool Repair & Maintenance"
        description="Answers about tool sets, shipping, orders, repair services, warranties, maintenance, parts, and accounts."
        canonical="/faq"
      />

      <section style={{ background: 'linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2255ee 100%)', padding: 'clamp(3.5rem, 8vw, 6rem) clamp(1.5rem, 5vw, 3rem) clamp(3rem, 6vw, 5rem)', position: 'relative', overflow: 'hidden' }}>
        <div style={{ position: 'absolute', inset: 0, backgroundImage: 'linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px)', backgroundSize: '40px 40px', pointerEvents: 'none' }} />
        <div style={{ maxWidth: '720px', margin: '0 auto', textAlign: 'center', position: 'relative' }}>
          <div className="dtb-title-eyebrow" style={{ display: 'inline-block', background: 'rgba(255,255,255,0.1)', border: '1px solid rgba(255,255,255,0.2)', borderRadius: '99px', padding: '5px 16px', fontSize: '0.68rem', fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'rgba(255,255,255,0.85)', marginBottom: '18px' }}>
            Help Center
          </div>
          <h1 style={{ fontSize: 'clamp(2rem, 5vw, 3.25rem)', fontWeight: 900, color: 'white', margin: '0 0 16px 0', letterSpacing: '-0.03em', lineHeight: 1.1 }}>
            Frequently Asked<br /><span style={{ color: '#93c5fd' }}>Questions</span>
          </h1>
          <p style={{ color: 'rgba(255,255,255,0.65)', fontSize: 'clamp(0.95rem, 2vw, 1.1rem)', margin: '0 auto', lineHeight: 1.6, maxWidth: '520px' }}>
            Answers about tool sets, repair services, warranties, tool maintenance, parts, accounts, orders, and shipping.
          </p>
        </div>
      </section>

      <section style={{ padding: 'clamp(2rem, 5vw, 5rem) clamp(1rem, 4vw, 3rem)', background: 'white' }}>
        <div style={{ maxWidth: '1100px', margin: '0 auto' }}>
          {isMobile ? (
            <>
              <NavbarTabs
                tabs={FAQ_CATEGORIES.map((cat) => ({ id: cat.id, label: cat.label }))}
                activeIndex={FAQ_CATEGORIES.findIndex((c) => c.id === activeCategory)}
                onChange={(idx) => setActiveCategory(FAQ_CATEGORIES[idx].id)}
                style={{ marginBottom: '24px' }}
              />
              <div style={{ marginBottom: '16px' }}>
                <h2 style={{ fontSize: 'clamp(1.15rem, 4vw, 1.4rem)', fontWeight: 900, color: '#0f172a', margin: '0 0 2px 0', letterSpacing: '-0.02em' }}>{activeData?.label}</h2>
                <p style={{ fontSize: '0.75rem', color: 'rgba(15,23,42,0.45)', margin: 0 }}>{activeCategory === 'tool-sets' ? 'A practical guide to comparing tool sets' : `${activeData?.questions.length} questions`}</p>
              </div>
              <Motion.div key={activeCategory} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={dtbTransition.standard}>
                {activeCategory === 'tool-sets'
                  ? <ToolSetBuyingGuide questions={activeData?.questions || []} />
                  : <Accordion items={accordionItems} isMobile />}
              </Motion.div>
            </>
          ) : (
            <div style={{ display: 'flex', gap: 'clamp(2rem, 5vw, 4rem)', alignItems: 'flex-start' }}>
              <nav style={{ flexShrink: 0, width: '210px', position: 'sticky', top: '100px', display: 'flex', flexDirection: 'column', gap: '4px' }} aria-label="FAQ categories">
                <p style={{ fontSize: '0.62rem', fontWeight: 800, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'rgba(15,23,42,0.4)', margin: '0 0 10px 0' }}>Categories</p>
                {FAQ_CATEGORIES.map((cat) => {
                  const active = cat.id === activeCategory;
                  return <button key={cat.id} type="button" onClick={() => setActiveCategory(cat.id)} style={{ background: active ? 'rgba(34,85,238,0.08)' : 'none', border: 'none', borderLeft: active ? '3px solid var(--primary-600)' : '3px solid transparent', borderRadius: '0 6px 6px 0', padding: '9px 12px', textAlign: 'left', cursor: 'pointer', fontSize: '0.875rem', fontWeight: active ? 700 : 500, color: active ? 'var(--primary-700)' : 'rgba(15,23,42,0.6)', transition: 'all 0.15s', lineHeight: 1.4 }}>{cat.label}</button>;
                })}
              </nav>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ marginBottom: '28px' }}>
                  <h2 style={{ fontSize: 'clamp(1.25rem, 3vw, 1.6rem)', fontWeight: 900, color: '#0f172a', margin: '0 0 4px 0', letterSpacing: '-0.02em' }}>{activeData?.label}</h2>
                  <p style={{ fontSize: '0.8rem', color: 'rgba(15,23,42,0.45)', margin: 0 }}>{activeCategory === 'tool-sets' ? 'A practical guide to comparing tool sets' : `${activeData?.questions.length} questions`}</p>
                </div>
                <Motion.div key={activeCategory} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={dtbTransition.standard}>
                  {activeCategory === 'tool-sets'
                    ? <ToolSetBuyingGuide questions={activeData?.questions || []} />
                    : <Accordion items={accordionItems} />}
                </Motion.div>
              </div>
            </div>
          )}
        </div>
      </section>

      <section style={{ padding: 'clamp(3rem, 6vw, 4rem) clamp(1.5rem, 5vw, 3rem)', background: 'white', borderTop: '1px solid var(--machined-border)' }}>
        <div style={{ maxWidth: '640px', margin: '0 auto', textAlign: 'center' }}>
          <h2 style={{ fontSize: 'clamp(1.5rem, 3vw, 2rem)', fontWeight: 900, color: '#0f172a', margin: '0 0 12px 0', letterSpacing: '-0.02em' }}>Still have questions?</h2>
          <p style={{ color: 'rgba(15,23,42,0.55)', fontSize: 'clamp(0.875rem, 2vw, 1rem)', margin: '0 0 32px 0', lineHeight: 1.6 }}>Our team is ready to help. Reach out directly or submit a repair request and we will be in touch within one business day.</p>
          <div style={{ display: 'flex', gap: '12px', justifyContent: 'center', flexWrap: 'wrap' }}>
            <Link to="/contact" style={{ display: 'inline-block', background: 'var(--primary-600)', color: 'white', fontWeight: 800, fontSize: '0.875rem', letterSpacing: '0.04em', textTransform: 'uppercase', padding: '12px 28px', borderRadius: '6px', textDecoration: 'none' }}>Contact Us</Link>
            <Link to="/repairs" style={{ display: 'inline-block', background: 'white', color: 'var(--primary-700)', fontWeight: 800, fontSize: '0.875rem', letterSpacing: '0.04em', textTransform: 'uppercase', padding: '12px 28px', borderRadius: '6px', border: '1.5px solid rgba(34,85,238,0.3)', textDecoration: 'none' }}>Repair Services</Link>
          </div>
        </div>
      </section>
    </div>
  );
}

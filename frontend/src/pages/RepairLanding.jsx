import { Link } from 'react-router-dom';
import SEOHead from '../components/shared/SEOHead';
import RepairHero from '../components/RepairHero';
import { getOfficialRepairBrands } from '../data/repairCatalogMap.js';
import { getBrandLogo } from '../utils/brandAssets.js';
import repairHeroArtwork from '../assets/media/repairs/repair-hero.webp';
import repairServicePackageArt from '../assets/media/repairs/repair-service-package.webp';
import { HOME_HERO_CAMPAIGNS } from '../components/home/homeHeroCampaigns.js';
import '../styles/repair-landing.css';
import '../styles/repair-merchandising.css';
import '../styles/repair-landing-responsive.css';
import '../styles/repair-landing-desktop.css';
import '../styles/repair-landing-mobile-hero.css';
import '../styles/repair-guarantees.css';
import '../styles/repair-hero-desktop-promises.css';
import '../styles/repair-service-cards-mobile.css';
import '../styles/repair-hero-shared.css';

const PROCESS_STEPS = [
  { title: 'Identify the tool', description: 'Tell us the brand, tool family, model, symptoms, and service goal. Photos can be added during intake.' },
  { title: 'Get your tool to us', description: 'Choose free local pickup/drop-off or arrange and pay a carrier to ship each tool to our repair center. Return delivery is quoted separately.' },
  { title: 'Inspect and approve', description: 'We inspect the tool and follow the approval rules you select. Additional quote-first work waits for your approval.' },
  { title: 'Repair and service', description: 'Approved repair, rebuild, calibration, and service work is completed against the inspected scope.' },
  { title: 'Return to you', description: 'Repair status remains available through return shipping so the same request follows the tool back to you.' },
];

const SHIPPING_PREP_STEPS = [
  { title: 'Clean excess compound', description: 'Remove loose or heavy compound buildup so the tool can be handled and inspected safely.' },
  { title: 'Secure loose components', description: 'Remove or secure detachable pieces and accessories that could shift or separate in transit.' },
  { title: 'Protect the tool', description: 'Wrap vulnerable surfaces and moving assemblies so they are protected from direct impact.' },
  { title: 'Use a rigid box', description: 'Choose a sturdy shipping carton with enough room for protective packing on every side.' },
  { title: 'Pack against movement', description: 'Fill open space so the tool cannot slide, strike the carton, or move freely during shipping.' },
];

const FAQ_GROUPS = [
  { title: 'Choosing Service', items: [
    { question: 'What if I do not know which repair package I need?', answer: 'Choose Diagnose and Quote. We will inspect the tool and provide an estimate before quote-first repair work begins.' },
    { question: 'Can I request a warranty or coverage review?', answer: 'Yes. Repair intake supports paid service, manufacturer warranty evaluation requests, and eligibility-review requests.' },
  ] },
  { title: 'Inspection & Approval', items: [
    { question: 'Can photos or symptoms provide a final diagnosis?', answer: 'No. Photos and symptoms help document the problem during intake, but final repair scope is determined after the tool is physically inspected.' },
    { question: 'Will additional work be completed without my approval?', answer: 'No. Quote-first work requires approval, and eligible repairs can use the pre-approval limit selected during intake.' },
    { question: 'What happens if the repair does not make practical sense?', answer: 'Inspection findings are presented before additional quote-first work is authorized, so you can decide how to proceed instead of automatically adding repair work.' },
  ] },
  { title: 'Shipping', items: [
    { question: 'Who pays to ship the tool to you?', answer: 'If you ship the tool, you arrange and pay the carrier directly; we do not provide or pay for inbound shipping labels. Free local pickup/drop-off is also available. Return delivery is quoted separately during intake.' },
    { question: 'How should I prepare the tool for shipping?', answer: 'Clean excess compound, secure loose parts, protect vulnerable surfaces, use a rigid carton, and pack the tool so it cannot move freely in transit.' },
  ] },
  { title: 'Service Priority', items: [
    { question: 'Can I request faster service?', answer: 'Repair intake exposes the service-priority options currently supported for the request. Availability and timing are confirmed through the repair workflow rather than promised on this page.' },
  ] },
  { title: 'Repair & Parts', items: [
    { question: 'Can I upload photos of the problem?', answer: 'Yes. Add photos during intake to document leaks, damage, wear, or other symptoms before you send the tool.' },
    { question: 'Can replaced parts be returned with my tool?', answer: 'Yes. Intake includes an old-parts preference so you can request that replaced parts be returned instead of recycled or discarded.' },
  ] },
  { title: 'Tracking', items: [
    { question: 'Can I track an existing repair?', answer: 'Yes. Use Track Repair with your repair number and token to view current status and next steps.' },
  ] },
];

const REPAIRS_CAMPAIGN = HOME_HERO_CAMPAIGNS.find(({ id }) => id === 'repairs');

const PROMOTED_REPAIR_BRAND_EXCLUSIONS = new Set(['SurPro', 'Dura-Stilts']);
const PROMOTED_REPAIR_BRAND_ADDITIONS = ['Level 5'];

function faqGroupId(title) {
  return `repair-faq-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
}

export default function RepairLanding() {
  const featuredBrands = [...new Set([
    ...getOfficialRepairBrands().filter((brand) => !PROMOTED_REPAIR_BRAND_EXCLUSIONS.has(brand)),
    ...PROMOTED_REPAIR_BRAND_ADDITIONS,
  ])]
    .map((brand) => ({ brand, logo: getBrandLogo(brand) }))
    .filter(({ logo }) => Boolean(logo))
    .slice(0, 8);

  return (
    <div className="repair-landing page-wrapper">
      <SEOHead title="Expert Repair Services | Drywall Toolbox" description={REPAIRS_CAMPAIGN.description} canonical="/repairs" />
      <RepairHero campaign={REPAIRS_CAMPAIGN} image={repairHeroArtwork} />

      <section className="repair-service-details" aria-labelledby="repair-service-paths-title"><div className="repair-section-shell">
        <div className="repair-section-heading repair-section-heading--center"><p className="repair-eyebrow">How Should We Start?</p><h2 id="repair-service-paths-title">Choose the path that matches what you know today.</h2><p>Both options continue into the same structured repair intake, shipping, approval, and tracking workflow.</p></div>
        <div className="repair-service-details__grid">
          <article className="repair-service-panel repair-service-panel--package"><img className="repair-service-panel__package-art" src={repairServicePackageArt} alt="" aria-hidden="true" decoding="async" /><p className="repair-eyebrow">I Know the Service I Need</p><h2>Choose a repair package.</h2><p>Pick the service that fits your tool and the work it needs.</p><ul><li>Choose a tool-family package or tune-up</li><li>Set shipping and approval preferences during intake</li></ul><Link to="/repairs/packages">Compare repair packages <span aria-hidden="true">→</span></Link></article>
          <article className="repair-service-panel repair-service-panel--diagnostic"><p className="repair-eyebrow">I Need the Tool Diagnosed</p><h2>Start with physical inspection.</h2><p>Not sure what is wrong? Send it in for inspection and an estimate before added work begins.</p><ul><li>Add photos and symptoms to help document the issue</li><li>Review inspection findings before quote-first work begins</li></ul><Link className="repair-button repair-button--primary" to="/repairs/start?package=diagnose_and_quote">Start Diagnostic <span aria-hidden="true">→</span></Link></article>
        </div>
      </div></section>

      <section className="repair-process" aria-labelledby="repair-process-title"><div className="repair-section-shell"><div className="repair-section-heading repair-section-heading--center"><p className="repair-eyebrow">Repair Workflow</p><h2 id="repair-process-title">Know what happens before you send the tool.</h2><p>One request follows the tool from intake and shipping through inspection, approval, repair, and return.</p></div><ol className="repair-process__grid">{PROCESS_STEPS.map((step, index) => (<li className="repair-process__step" key={step.title}><span className="repair-process__number" aria-hidden="true">{String(index + 1).padStart(2, '0')}</span><div><h3>{step.title}</h3><p>{step.description}</p></div></li>))}</ol></div></section>

      <section className="repair-shipping-prep" aria-labelledby="repair-shipping-prep-title"><div className="repair-section-shell"><div className="repair-shipping-prep__intro"><div className="repair-section-heading"><p className="repair-eyebrow">Before You Ship</p><h2 id="repair-shipping-prep-title">Protect the tool before it leaves your hands.</h2><p>Good preparation reduces transit damage and helps the tool arrive ready for intake and inspection.</p></div></div><ol className="repair-shipping-prep__grid">{SHIPPING_PREP_STEPS.map((step, index) => (<li key={step.title}><span aria-hidden="true">{String(index + 1).padStart(2, '0')}</span><div><h3>{step.title}</h3><p>{step.description}</p></div></li>))}</ol></div></section>

      {featuredBrands.length > 0 && (<section className="repair-brands" aria-labelledby="repair-brands-title"><div className="repair-section-shell repair-brands__inner"><div><p className="repair-eyebrow">Supported Brands</p><h2 id="repair-brands-title">We service taping and finishing tools from all major brands.</h2><p className="repair-brands__copy">Don’t see yours? We may still be able to help.</p></div><div className="repair-brands__list" aria-label="Supported repair brands">{featuredBrands.map(({ brand, logo }) => (<span key={brand} title={brand}><img src={logo} alt={brand} loading="lazy" decoding="async" style={{ width: '150px', maxWidth: '100%', height: '44px', objectFit: 'contain' }} /></span>))}</div></div></section>)}

      <section className="repair-resources" aria-labelledby="repair-resources-title"><div className="repair-section-shell repair-resources__grid"><div className="repair-resources__copy"><p className="repair-eyebrow">Repair It Yourself?</p><h2 id="repair-resources-title">Know the part you need?</h2><p>Use tool schematics to identify replacement parts for straightforward repairs. For inspection, calibration, or rebuild work, start a professional repair instead.</p></div><div className="repair-resources__actions"><Link to="/schematics">Find It in a Schematic <span aria-hidden="true">→</span></Link><Link to="/parts">Shop Repair Parts <span aria-hidden="true">→</span></Link><Link to="/repairs/start">Start Professional Service <span aria-hidden="true">→</span></Link></div></div></section>

      <section className="repair-faq" aria-labelledby="repair-faq-title"><div className="repair-section-shell repair-faq__layout"><div className="repair-section-heading"><p className="repair-eyebrow">Repair FAQ</p><h2 id="repair-faq-title">What to know before service starts.</h2><p>Service selection, physical inspection, approval, shipping, service priority, parts, and repair tracking.</p></div><div className="repair-faq__groups">{FAQ_GROUPS.map((group) => { const groupId = faqGroupId(group.title); return (<section className="repair-faq__group" key={group.title} aria-labelledby={groupId}><h3 id={groupId}>{group.title}</h3><div className="repair-faq__list">{group.items.map((item) => (<details key={item.question}><summary>{item.question}</summary><p>{item.answer}</p></details>))}</div></section>); })}</div></div></section>

      <section className="repair-closing-cta" aria-labelledby="repair-closing-title"><div className="repair-section-shell repair-closing-cta__inner"><div><p className="repair-eyebrow">Ready to Start?</p><h2 id="repair-closing-title">Send the right information with the tool from day one.</h2></div><div className="repair-closing-cta__actions"><Link className="repair-button repair-button--primary" to="/repairs/start">Start a Repair <span aria-hidden="true">→</span></Link><Link to="/repairs/packages">View Repair Packages <span aria-hidden="true">→</span></Link><Link to="/repairs/track">Track Existing Repair <span aria-hidden="true">→</span></Link></div></div></section>
    </div>
  );
}

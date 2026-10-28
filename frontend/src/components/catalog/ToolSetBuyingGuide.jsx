import { ArrowRight, CircleHelp, ClipboardList, Ruler, Tags } from 'lucide-react';
import { Link } from 'react-router-dom';
import '../../styles/tool-set-buying-guide.css';

const BUYING_CHECKS = [
  {
    title: 'Confirm the included tools',
    description: 'Use the product’s included-items list to verify tapers, pumps, boxes, corner tools, handles, cases, and adapters. Count an item as included only when the listing says so.',
    Icon: ClipboardList,
  },
  {
    title: 'Check working range and fit',
    description: 'Compare box and corner-finisher sizes, handle lengths, and compatibility details against the work and equipment your crew already has.',
    Icon: Ruler,
  },
  {
    title: 'Review current purchase details',
    description: 'Check the product page for current price, availability, warranty information, and any stated set savings before ordering.',
    Icon: Tags,
  },
];

export default function ToolSetBuyingGuide({ questions = [] }) {
  return (
    <div className="dtb-tool-set-guide" aria-label="Tool sets and kits buying guide">
      <section className="dtb-tool-set-guide__workflows" aria-labelledby="dtb-tool-set-guide-workflows-title">
        <div className="dtb-tool-set-guide__section-heading">
          <span className="dtb-tool-set-guide__step">01</span>
          <div>
            <h3 id="dtb-tool-set-guide-workflows-title">Start with your workflow</h3>
            <p>Compare the set type that matches how your crew tapes and finishes.</p>
          </div>
        </div>
        <div className="dtb-tool-set-guide__workflow-grid">
          <Link className="dtb-tool-set-guide__workflow" to="/category/semi-automatic-tool-sets-kits">
            <span className="dtb-tool-set-guide__workflow-type">Semi-automatic</span>
            <h4>Semi-Automatic Tool Sets &amp; Kits</h4>
            <p>Review the listed semi-automatic configurations and check each set’s contents and fit for your work.</p>
            <span className="dtb-tool-set-guide__link-label">Browse semi-automatic sets <ArrowRight size={16} aria-hidden="true" /></span>
          </Link>
          <Link className="dtb-tool-set-guide__workflow" to="/category/automatic-tool-sets-kits">
            <span className="dtb-tool-set-guide__workflow-type">Automatic</span>
            <h4>Automatic Tool Sets &amp; Kits</h4>
            <p>Compare the listed taping, loading, flat-finishing, and corner-finishing equipment in each system.</p>
            <span className="dtb-tool-set-guide__link-label">Browse automatic sets <ArrowRight size={16} aria-hidden="true" /></span>
          </Link>
        </div>
      </section>

      <section className="dtb-tool-set-guide__checks" aria-labelledby="dtb-tool-set-guide-checks-title">
        <div className="dtb-tool-set-guide__section-heading">
          <span className="dtb-tool-set-guide__step">02</span>
          <div>
            <h3 id="dtb-tool-set-guide-checks-title">Before you choose a set</h3>
            <p>Use the product listing to verify the details that affect your setup.</p>
          </div>
        </div>
        <div className="dtb-tool-set-guide__check-grid">
          {BUYING_CHECKS.map(({ title, description, Icon }) => (
            <article className="dtb-tool-set-guide__check" key={title}>
              <span className="dtb-tool-set-guide__check-icon"><Icon size={18} aria-hidden="true" /></span>
              <div>
                <h4>{title}</h4>
                <p>{description}</p>
              </div>
            </article>
          ))}
        </div>
      </section>

      {questions.length > 0 ? (
        <section className="dtb-tool-set-guide__questions" aria-labelledby="dtb-tool-set-guide-questions-title">
          <div className="dtb-tool-set-guide__section-heading">
            <span className="dtb-tool-set-guide__step">03</span>
            <div>
              <h3 id="dtb-tool-set-guide-questions-title">Common buying questions</h3>
              <p>Quick answers to help you review the options with confidence.</p>
            </div>
          </div>
          <div className="dtb-tool-set-guide__question-grid">
            {questions.map(({ q, a }) => (
              <article className="dtb-tool-set-guide__question" key={q}>
                <h4><CircleHelp size={15} aria-hidden="true" />{q}</h4>
                <p>{a}</p>
              </article>
            ))}
          </div>
        </section>
      ) : null}

      <aside className="dtb-tool-set-guide__contact">
        <div>
          <h3>Want a hand comparing options?</h3>
          <p>Tell us what your crew does and what tools you already own.</p>
        </div>
        <Link to="/contact?inquiry=product_guidance&topic=tool-sets">
          Talk with our team <ArrowRight size={16} aria-hidden="true" />
        </Link>
      </aside>
    </div>
  );
}

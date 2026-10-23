import { useEffect, useId, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import SEOHead from '../../components/shared/SEOHead.jsx';
import '../../styles/store-policies.css';
import './legal-document.css';

const anchor = (heading, index) => `legal-section-${index + 1}-${heading.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`;

export default function LegalDocument({ title, description, canonical, eyebrow = 'Legal', introduction, lastUpdated, sections }) {
  const { hash } = useLocation();
  const selectId = useId();
  const [active, setActive] = useState('');
  const entries = sections.map((section, index) => ({ ...section, id: anchor(section.heading, index) }));

  useEffect(() => {
    if (!hash) return;
    let id;
    try {
      id = decodeURIComponent(hash.slice(1));
    } catch {
      return;
    }
    const section = document.getElementById(id);
    if (!section) return;
    section.scrollIntoView({ behavior: 'auto', block: 'start' });
  }, [hash]);

  const goTo = (id) => {
    const section = document.getElementById(id);
    if (!section) return;
    window.history.replaceState(window.history.state, '', `#${encodeURIComponent(id)}`);
    section.scrollIntoView({
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
      block: 'start',
    });
    setActive(id);
  };
  const hashId = hash.slice(1);
  const activeId = active || (entries.some(({ id }) => id === hashId) ? hashId : '');

  return (
    <div className="store-policy-page dtb-legal-page">
      <SEOHead title={title} description={description} canonical={canonical} />
      <header className="store-policy-hero dtb-legal-hero">
        <div className="store-policy-hero__copy">
          <span className="store-policy-pill">{eyebrow}</span>
          <h1>{title}</h1>
          <p>{introduction}</p>
          {lastUpdated && <p className="dtb-legal-dates">Last revised: {lastUpdated}</p>}
        </div>
      </header>
      <div className="dtb-legal-layout">
        <aside className="dtb-legal-toc" aria-label={`${title} contents`}>
          <h2>On this page</h2>
          <nav className="dtb-legal-toc__links" aria-label="Document sections">
            {entries.map(({ heading, id }) => (
              <a key={id} href={`#${id}`} aria-current={activeId === id ? 'location' : undefined} onClick={() => setActive(id)}>
                {heading}
              </a>
            ))}
          </nav>
          <label className="dtb-legal-toc__select-label" htmlFor={selectId}>Jump to section</label>
          <select id={selectId} className="dtb-legal-toc__select" value={activeId} onChange={(event) => goTo(event.target.value)}>
            <option value="">Select a section</option>
            {entries.map(({ heading, id }) => <option key={id} value={id}>{heading}</option>)}
          </select>
        </aside>
        <main className="store-policy-content dtb-legal-document" id="main-content">
          <article aria-label={title}>
            {entries.map(({ heading, paragraphs = [], bullets = [], links = [], id }) => (
              <section className="dtb-legal-document__section" id={id} key={id} aria-labelledby={`${id}-heading`}>
                <h2 id={`${id}-heading`}>{heading}</h2>
                {paragraphs.map((paragraph, index) => <p key={index}>{paragraph}</p>)}
                {bullets.length > 0 && <ul>{bullets.map((bullet, index) => <li key={index}>{bullet}</li>)}</ul>}
                {links.length > 0 && (
                  <ul className="dtb-legal-document__resources" aria-label="Related resources">
                    {links.map(({ label, to, href }) => (
                      <li key={to || href}>
                        {to ? <Link to={to}>{label}</Link> : <a href={href} target="_blank" rel="noopener noreferrer">{label} (opens in a new tab)</a>}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            ))}
          </article>
          <nav className="dtb-legal-document__links" aria-label="Related legal information">
            <Link to="/privacy-policy/">Privacy Policy</Link>
            <Link to="/terms-of-service/">Terms of Service</Link>
            <Link to="/policies">Store policies</Link>
            <Link to="/contact">Contact Drywall Toolbox</Link>
          </nav>
        </main>
      </div>
    </div>
  );
}

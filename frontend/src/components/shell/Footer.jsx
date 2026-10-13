import { Facebook, Instagram } from 'lucide-react';
import { Link } from 'react-router-dom';

import { DTB_LOGO_WHITE_URL } from '../../utils/dtbLogoAssets.js';
import '../../styles/storefront-footer-template.css';
import { useEditableComponent } from '../../designer/useEditableComponent.js';

const FOOTER_GROUPS = [
  {
    title: 'Shop',
    links: [
      { label: 'All Products', to: '/products' },
      { label: 'Brands', to: '/products/brands' },
      { label: 'Parts', to: '/parts' },
      { label: 'New Arrivals', to: '/products?sort=newest' },
    ],
  },
  {
    title: 'Tools & Services',
    links: [
      { label: 'Repair Services', to: '/repairs' },
      { label: 'Repair Packages', to: '/repairs/packages' },
      { label: 'Schematics', to: '/schematics' },
      { label: 'Calculators', to: '/calculators' },
    ],
  },
  {
    title: 'Support',
    links: [
      { label: 'Contact Us', to: '/contact' },
      { label: 'Frequently Asked Questions', to: '/faq' },
      { label: 'Shipping', to: '/shipping-policy' },
      { label: 'Returns', to: '/returns' },
    ],
  },
  {
    title: 'Account',
    links: [
      { label: 'Sign In', to: '/login' },
      { label: 'Create Account', to: '/register' },
      { label: 'My Account', to: '/dashboard' },
      { label: 'Store Policies', to: '/policies' },
    ],
  },
];

function XLogo() {
  return (
    <svg width="17" height="17" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">
      <path d="M36.6526 3.80782H43.3995L28.6594 20.6548L46 43.5798H32.4225L21.7881 29.6759L9.61989 43.5798H2.86886L18.6349 25.56L2 3.80782H15.9222L25.5348 16.5165L36.6526 3.80782ZM34.2846 39.5414H38.0232L13.8908 7.63408H9.87892L34.2846 39.5414Z" fill="currentColor" />
    </svg>
  );
}

const SOCIAL_LINKS = [
  { label: 'Instagram', href: 'https://www.instagram.com/drywalltoolbox', Icon: Instagram },
  { label: 'Facebook', href: 'https://www.facebook.com/Drywalltoolbox/', Icon: Facebook },
  { label: 'X', href: 'https://twitter.com', Icon: XLogo },
];

function FooterLinkGroup({ title, links }) {
  return (
    <section aria-labelledby={`footer-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`}>
      <h2 id={`footer-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`} className="dtb-footer-template__heading">
        {title}
      </h2>
      <ul className="dtb-footer-template__links">
        {links.map(({ label, to }) => (
          <li key={to}>
            <Link className="dtb-footer-template__link" to={to}>{label}</Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

export default function Footer() {
  const { rootProps, getColorVar } = useEditableComponent('global-footer', 'footer-root');

  return (
    <footer {...rootProps} className="site-footer dtb-footer-template" style={{ background: getColorVar('background_color', undefined) }}>
      <div className="dtb-footer-template__inner">
        <div className="dtb-footer-template__grid">
          <section className="dtb-footer-template__brand" aria-label="Drywall Toolbox">
            <Link to="/" aria-label="Drywall Toolbox home">
              <img className="dtb-footer-template__logo" src={DTB_LOGO_WHITE_URL} alt="Drywall Toolbox" width="230" height="70" loading="lazy" decoding="async" />
            </Link>
          <nav className="dtb-footer-template__socials" aria-label="Social media">
            {SOCIAL_LINKS.map(({ label, href, Icon }) => (
              <a
                key={label}
                className="dtb-footer-template__social"
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={label}
              >
                <Icon size={18} strokeWidth={1.9} aria-hidden="true" />
              </a>
            ))}
          </nav>
          </section>

          {FOOTER_GROUPS.map((group) => <FooterLinkGroup key={group.title} {...group} />)}
        </div>

        <div className="dtb-footer-template__bottom">
          <div>
            <p className="dtb-footer-template__copyright">© 2026 Drywall Toolbox. All rights reserved.</p>
            <nav className="dtb-footer-template__legal" aria-label="Legal">
              <Link className="dtb-footer-template__link" to="/privacy-policy/">Privacy</Link>
              <Link className="dtb-footer-template__link" to="/terms-of-service/">Terms</Link>
            </nav>
          </div>


        </div>
      </div>
    </footer>
  );
}

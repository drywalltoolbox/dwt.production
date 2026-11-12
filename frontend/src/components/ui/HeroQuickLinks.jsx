/**
 * ui/HeroQuickLinks.jsx — Light quick-link card row shown beneath the hero
 * image band. A simple fluid grid (not a 3D carousel) so every link is
 * reachable without interaction and never overflows the viewport.
 */

import { Link, useLocation } from 'react-router-dom';
import { Wrench, Package, Settings, Ruler, Calculator } from 'lucide-react';

const QUICK_LINKS = [
  { id: 'repairs', label: 'Repairs', sub: 'Expert service', to: '/repairs', Icon: Wrench },
  { id: 'products', label: 'Products', sub: 'Full catalog', to: '/products', Icon: Package },
  { id: 'parts', label: 'Parts', sub: 'Replacement parts', to: '/parts', Icon: Settings },
  { id: 'schematics', label: 'Schematics', sub: 'Tool diagrams', to: '/schematics', Icon: Ruler },
  { id: 'calculator', label: 'Calculator', sub: 'Estimate materials', to: '/calculators', Icon: Calculator },
];

export default function HeroQuickLinks() {
  const { pathname } = useLocation();

  return (
    <div className="dtb-hero-quicklinks">
      {QUICK_LINKS.map(({ id, label, sub, to, Icon }) => {
        const isActive = pathname === to || pathname.startsWith(`${to}/`);
        return (
          <Link
            key={id}
            to={to}
            className={`dtb-hero-quicklinks__card${isActive ? ' is-active' : ''}`}
          >
            <Icon size={22} strokeWidth={2} aria-hidden="true" />
            <span className="dtb-hero-quicklinks__label">{label}</span>
            <span className="dtb-hero-quicklinks__sub">{sub}</span>
          </Link>
        );
      })}
    </div>
  );
}

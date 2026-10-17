export const HOME_HERO_CAMPAIGNS = [
  {
    id: 'tools',
    label: 'Tools',
    eyebrow: 'Pro Quality. Pro Results.',
    titleLines: ['A New Standard', 'in Drywall.'],
    accentLine: 1,
    description: 'Everything you need for taping and finishing—from professional tools and parts to expert repair service. Built for pros who demand more.',
    primaryAction: { label: 'Shop All', to: '/all-products' },
    secondaryAction: { label: 'Shop by Brand', to: '/products/brands' },
  },
  {
    id: 'parts',
    label: 'Parts',
    eyebrow: 'Parts. Diagrams. Fitment.',
    titleLines: ['Find the part.', 'Keep moving.'],
    accentLine: 1,
    description: 'Find replacement parts by tool and brand, then use schematics to confirm the exact component before you order.',
    primaryAction: { label: 'Shop Parts', to: '/parts' },
    secondaryAction: { label: 'Browse Schematics', to: '/schematics' },
  },
  {
    id: 'repairs',
    label: 'Repairs',
    eyebrow: 'Professional Repair Service',
    titleLines: ['Get it repaired.', 'Get back to work.'],
    accentLine: 1,
    description: 'Professional repair service for drywall taping and finishing equipment, with clear service options and repair tracking.',
    primaryAction: { label: 'Start a Repair', to: '/repairs/start' },
    secondaryAction: { label: 'Repair Services', to: '/repairs' },
  },
];

export const HOME_HERO_CAMPAIGN_COUNT = HOME_HERO_CAMPAIGNS.length;

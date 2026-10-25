export const HOME_HERO_CAMPAIGNS = [
  {
    id: 'tools',
    label: 'Tools',
    eyebrow: 'Pro Quality. Pro Results.',
    titleLines: ['A New.', 'Standard.', 'In Drywall Tools.'],
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
    eyebrow: 'Drywall Tool Repair Specialists',
    titleLines: ['Expert Repairs.', 'Less Downtime.'],
    accentLine: 1,
    description: 'Restore your taping and finishing tools with professional service, straightforward repair options, and status tracking.',
    primaryAction: { label: 'Request a Repair', to: '/repairs/start' },
    secondaryAction: { label: 'Explore Services', to: '/repairs' },
  },
];

export const HOME_HERO_CAMPAIGN_COUNT = HOME_HERO_CAMPAIGNS.length;

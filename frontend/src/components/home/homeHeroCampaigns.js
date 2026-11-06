export const HOME_HERO_CAMPAIGNS = [
  {
    id: 'tools',
    label: 'Tools',
    eyebrow: '',
    titleLines: ['The New', 'Standard In', 'Drywall Tools'],
    accentLine: 1,
    description: 'Professional drywall tools, replacement parts, and repair services—all in one place.',
    primaryAction: { label: 'Shop All Tools', to: '/all-products' },
    secondaryAction: { label: 'Shop by Brand', to: '/products/brands' },
  },
  {
    id: 'parts',
    label: 'Parts',
    eyebrow: '',
    titleLines: ['Right Part.', 'No Guesswork.'],
    accentLine: 1,
    description: 'Browse by brand or use our interactive schematics to find the exact replacement part you need.',
    primaryAction: { label: 'Shop Parts', to: '/parts' },
    secondaryAction: { label: 'View Schematics', to: '/schematics' },
  },
  {
    id: 'repairs',
    label: 'Repairs',
    eyebrow: '',
    titleLines: ['Your Tools', 'Deserve True', 'Expert Service'],
    accentLine: 1,
    description: 'From routine maintenance to complete repairs, our expert technicians handle all your drywall tool service needs.',
    primaryAction: { label: 'Request a Repair', to: '/repairs/start' },
    secondaryAction: { label: 'Explore Services', to: '/repairs/packages' },
  },
];

export const HOME_HERO_CAMPAIGN_COUNT = HOME_HERO_CAMPAIGNS.length;

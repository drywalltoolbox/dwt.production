export const HOME_HERO_CAMPAIGNS = [
  {
    id: 'tools',
    label: 'Tools',
    eyebrow: 'Built for the Trade',
    titleLines: ['The New', 'Standard In', 'Drywall Tools'],
    accentLine: 1,
    description: 'Everything you need for taping and finishing—from professional tools and parts to expert repair service. Built for pros who demand more.',
    primaryAction: { label: 'Shop All', to: '/all-products' },
    secondaryAction: { label: 'Shop by Brand', to: '/products/brands' },
  },
  {
    id: 'parts',
    label: 'Parts',
    eyebrow: 'Parts & Schematics',
    titleLines: ['Genuine Parts.', 'All In One Place.'],
    accentLine: 1,
    description: 'Find the right drywall tool parts with easy brand browsing and interactive schematics.',
    primaryAction: { label: 'Shop Parts', to: '/parts' },
    secondaryAction: { label: 'Browse Schematics', to: '/schematics' },
  },
  {
    id: 'repairs',
    label: 'Repairs',
    eyebrow: 'Expert Repair Services',
    titleLines: ['We Fix the Tools.', 'You Finish the Job.'],
    accentLine: 1,
    description: 'From routine maintenance to complete repairs, our experts keep your drywall tools performing at their best.',
    primaryAction: { label: 'Request a Repair', to: '/repairs/start' },
    secondaryAction: { label: 'Explore Services', to: '/repairs' },
  },
];

export const HOME_HERO_CAMPAIGN_COUNT = HOME_HERO_CAMPAIGNS.length;

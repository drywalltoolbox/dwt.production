export const HOME_HERO_CAMPAIGNS = [
  {
    id: 'tools',
    label: 'Tools',
    eyebrow: 'Built for the Trade',
    titleLines: ['THE NEW', 'STANDARD IN', 'DRYWALL TOOLS'],
    accentLine: 1,
    description: 'Everything you need for taping and finishing—from professional tools and parts to expert repair service. Built for pros who demand more.',
    primaryAction: { label: 'Shop All', to: '/all-products' },
    secondaryAction: { label: 'Shop by Brand', to: '/products/brands' },
  },
  {
    id: 'parts',
    label: 'Parts',
    eyebrow: 'Parts & Schematics',
    titleLines: ['GENUINE PARTS.', 'ALL IN ONE PLACE.'],
    accentLine: 1,
    description: 'Find the exact replacement part for all of your tools, using our seamless brand browsing and interactive schematic experience.',
    primaryAction: { label: 'Shop Parts', to: '/parts' },
    secondaryAction: { label: 'Browse Schematics', to: '/schematics' },
  },
  {
    id: 'repairs',
    label: 'Repairs',
    eyebrow: 'Expert Repair Services',
    titleLines: ['WE FIX THE TOOLS.', 'YOU FINISH THE JOB.'],
    accentLine: 1,
    description: 'From routine maintenance to complete repairs, our experts keep your drywall tools performing at their best.',
    primaryAction: { label: 'Request a Repair', to: '/repairs/start' },
    secondaryAction: { label: 'Explore Services', to: '/repairs/packages' },
  },
];

export const HOME_HERO_CAMPAIGN_COUNT = HOME_HERO_CAMPAIGNS.length;

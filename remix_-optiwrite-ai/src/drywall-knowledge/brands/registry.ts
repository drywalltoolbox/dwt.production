export interface BrandKnowledge {
  id: string;
  canonicalName: string;
  acceptedSpellings: string[];
  officialDomain?: string;
}

export const BRAND_REGISTRY: Record<string, BrandKnowledge> = {
  columbia: { id:'columbia', canonicalName:'Columbia Tools', acceptedSpellings:['Columbia Tools','Columbia Taping Tools','Columbia'], officialDomain:'columbiatools.com' },
  tapetech: { id:'tapetech', canonicalName:'TapeTech', acceptedSpellings:['TapeTech','Tape Tech'], officialDomain:'tapetech.com' },
  level5: { id:'level5', canonicalName:'LEVEL5', acceptedSpellings:['LEVEL5','Level 5','Level5'], officialDomain:'level5tools.com' },
  platinum: { id:'platinum', canonicalName:'Platinum Drywall Tools', acceptedSpellings:['Platinum Drywall Tools','Platinum'], officialDomain:undefined },
  surpro: { id:'surpro', canonicalName:'SurPro', acceptedSpellings:['SurPro','Sur-Pro'], officialDomain:'stilts.com' },
  durastilts: { id:'durastilts', canonicalName:'Dura-Stilts', acceptedSpellings:['Dura-Stilts','Dura Stilts','Dura-Stilts®'], officialDomain:'durastilt.com' },
  dtb: { id:'dtb', canonicalName:'DryWall TOOLBOX', acceptedSpellings:['DryWall TOOLBOX','Drywall Toolbox'], officialDomain:'drywalltoolbox.com' }
};

export function resolveBrandId(value: string | undefined): string | undefined {
  const normalized = String(value || '').trim().toLowerCase();
  if (!normalized) return undefined;
  return Object.values(BRAND_REGISTRY).find(brand =>
    brand.acceptedSpellings.some(name => name.toLowerCase() === normalized)
  )?.id;
}

import type { KnowledgeReference } from './types';

export const KNOWLEDGE_REFERENCES: Record<string, KnowledgeReference> = {
  columbia_products: {
    id: 'columbia_products', title: 'Columbia Tools product families', publisher: 'Columbia Taping Tools',
    url: 'https://www.columbiatools.com/', authority: 'official_manufacturer'
  },
  columbia_media_kit: {
    id: 'columbia_media_kit', title: 'Columbia product knowledge and media kit', publisher: 'Columbia Taping Tools',
    url: 'https://www.columbiatools.com/dealer-resources/media-kit/', authority: 'official_manufacturer'
  },
  columbia_tool_sets: {
    id: 'columbia_tool_sets', title: 'Columbia suggested tool sets', publisher: 'Columbia Taping Tools',
    url: 'https://www.columbiatools.com/columbia-tools/suggested-tool-sets/', authority: 'official_manufacturer'
  },
  tapetech_taper: {
    id: 'tapetech_taper', title: 'EasyClean Automatic Taper', publisher: 'TapeTech Tool Company',
    url: 'https://tapetech.com/product/easyclean-automatic-taper/', authority: 'official_manufacturer'
  },
  tapetech_pump: {
    id: 'tapetech_pump', title: 'EasyClean Loading Pump', publisher: 'TapeTech Tool Company',
    url: 'https://tapetech.com/product/easyclean-loading-pump/', authority: 'official_manufacturer'
  },
  tapetech_corner_finisher: {
    id: 'tapetech_corner_finisher', title: 'Corner Finisher', publisher: 'TapeTech Tool Company',
    url: 'https://tapetech.com/product/2-5-corner-finisher/', authority: 'official_manufacturer'
  },
  tapetech_taping_order: {
    id: 'tapetech_taping_order', title: 'Automatic taping workflow guidance', publisher: 'TapeTech Tool Company',
    url: 'https://tapetech.com/faq/when-taping-with-automatic-taping-tools-is-there-a-specific-order-i-should-follow/', authority: 'official_manufacturer'
  },
  level5_parts: {
    id: 'level5_parts', title: 'Parts and Components', publisher: 'LEVEL5 Tools',
    url: 'https://www.level5tools.com/parts-components/', authority: 'official_manufacturer'
  },
  level5_finishing_set: {
    id: 'level5_finishing_set', title: 'Automatic Drywall Finishing Set', publisher: 'LEVEL5 Tools',
    url: 'https://www.level5tools.com/drywall-finishing-set/', authority: 'official_manufacturer'
  },
  level5_taping_set: {
    id: 'level5_taping_set', title: 'Automatic Drywall Taping and Finishing Tool Set', publisher: 'LEVEL5 Tools',
    url: 'https://www.level5tools.com/drywall-taping-tool-set/', authority: 'official_manufacturer'
  },
  dura_about: {
    id: 'dura_about', title: 'Dura-Stilts design principles', publisher: 'Dura-Stilts',
    url: 'https://durastilt.com/about/', authority: 'official_manufacturer'
  },
  dura_adjustable: {
    id: 'dura_adjustable', title: 'DURA III Adjustable Stilts', publisher: 'Dura-Stilts',
    url: 'https://durastilt.com/dura-3-adjustable-stilts/', authority: 'official_manufacturer'
  }
};

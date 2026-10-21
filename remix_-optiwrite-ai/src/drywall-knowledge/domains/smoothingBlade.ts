import type { DrywallDomainKnowledge } from '../types';
export const SMOOTHING_BLADE: DrywallDomainKnowledge = {
  id:'smoothing_blade', family:'flat_finishing', label:'Smoothing / Skimming Blade',
  tradeRole:'Smooths, skims, or feathers applied joint compound across larger wall or ceiling surfaces.',
  workflow:{ stages:['smoothing_skimming'], upstreamDomains:[], downstreamDomains:['drywall_sander'] },
  buyerQuestions:{primary:['What blade width/profile is this?','What blade material/flex is documented?','What handle/adapter system is supported?'],secondary:['Is the blade replaceable?','What grip/frame construction is documented?']},
  evidencePriorities:['blade width','blade material/profile','flexibility','frame/grip','handle compatibility','replaceability'],
  mechanisms:[{id:'blade_profile',label:'Blade profile/flex',generalFunction:'Controls how the blade conforms to and feathers compound across the surface.',evidenceRequiredForPresence:true}],
  configurationDimensions:['blade width','blade profile/flex','handheld vs handled configuration'],
  systemRelationships:['May use a dedicated extension handle or adapter for reach.'],
  compatibilityEvidenceRules:['Do not infer handle/adapter interchangeability across blade systems.'],
  terminology:{preferred:['smoothing blade','skimming blade'],contextualSynonyms:['skim blade'],relatedButDistinct:['finishing box','taping knife'],avoid:['finishing box']},
  editorialGuidance:['Prioritize blade geometry/material and supported handle system over generic smooth-finish claims.'],
  claimsRequiringEvidence:['streak-free finish','reduced sanding','superior feathering','blade durability'],
  commonCatalogErrors:['Treating a smoothing blade as an automatic compound applicator.'],
  searchIntentPatterns:['[brand] smoothing blade','[size] skim blade','drywall skimming blade'],
  referenceIds:['columbia_media_kit']
};

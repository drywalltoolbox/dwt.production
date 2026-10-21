import type { DrywallDomainKnowledge } from '../types';
export const TOOL_SET: DrywallDomainKnowledge = {
  id:'tool_set', family:'supporting_equipment', label:'Tool Set / System',
  tradeRole:'Groups multiple verified tools/components into a purchasable configuration that covers one or more drywall taping or finishing workflow stages.',
  workflow:{ stages:['tape_application','flat_joint_finishing','inside_corner_finishing','compound_loading'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['Exactly what SKUs/components are included in this configuration?','Which workflow stages do those verified contents cover?','What sizes/options distinguish this variation?'],secondary:['Which loading interfaces, handles, cases, or service items are included?','What is explicitly not included when ambiguity is likely?']},
  evidencePriorities:['structured package contents','variation/configuration','major tool domains','workflow coverage derived from included tools','sizes','loading components','handles/interfaces','cases'],
  mechanisms:[],
  configurationDimensions:['included tool mix','tool sizes','automatic vs semi-automatic system','handle configuration','with/without taper/pump/case'],
  systemRelationships:['A set may span taping, loading, flat finishing, and corner finishing; each included tool retains its own domain role.'],
  compatibilityEvidenceRules:['Only structured/authoritative package contents establish inclusion.','Do not infer that all included tools are mutually compatible unless the set evidence or product contracts establish it.'],
  terminology:{preferred:['tool set'],contextualSynonyms:['toolset','kit when manufacturer/catalog identity uses it'],relatedButDistinct:['maintenance kit'],avoid:['complete system','everything you need unless supported']},
  editorialGuidance:['Merchandise the verified configuration and workflow coverage, not generic bundle value.','Do not repeat the full description of each included tool; summarize the role of major components and preserve exact contents.'],
  claimsRequiringEvidence:['complete','starter/professional level suitability','savings/value','everything needed','workflow completeness'],
  commonCatalogErrors:['Inventing package contents from related products.','Treating optional variations as included.','Flattening a multi-domain set into generic kit language.'],
  searchIntentPatterns:['[brand] drywall tool set','automatic taping tool set','drywall finishing tool set'],
  referenceIds:['columbia_tool_sets','level5_finishing_set','level5_taping_set']
};

import type { DrywallDomainKnowledge } from '../types';
export const DRYWALL_STILTS: DrywallDomainKnowledge = {
  id:'drywall_stilts', family:'supporting_equipment', label:'Drywall Stilts',
  tradeRole:'Adjustable or fixed-height articulating leg-extension equipment used by tradespeople to work at elevated wall and ceiling heights.',
  workflow:{ stages:['tape_application','flat_joint_finishing','inside_corner_finishing','smoothing_skimming'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['What height range or fixed height is this model?','What rated capacity and frame material are documented?','What leg, ankle, footplate, strap, and sole systems are documented?'],secondary:['Which wear parts are field-replaceable?','What manufacturer safety/adjustment requirements apply?']},
  evidencePriorities:['height range','rated capacity','frame material','leg support','ankle/spring system','foot/floor plates','straps/buckles','sole/traction system','replaceable parts','manufacturer safety instructions'],
  mechanisms:[{id:'articulation',label:'Ankle/articulation system',generalFunction:'Allows the stilt to articulate with the user’s walking motion.',evidenceRequiredForPresence:true},{id:'height_adjustment',label:'Height adjustment system',generalFunction:'Changes working height on adjustable models and locks the selected position.',evidenceRequiredForPresence:true},{id:'traction',label:'Replaceable sole/traction system',generalFunction:'Provides floor contact and traction on supported designs.',evidenceRequiredForPresence:true}],
  configurationDimensions:['height range/fixed height','frame material','rated capacity','single/double side support','leg-band configuration'],
  systemRelationships:['Supporting access equipment; not mechanically compatible with taping/finishing tools.'],
  compatibilityEvidenceRules:['Do not infer replacement-part interchangeability between stilt brands or generations.'],
  terminology:{preferred:['drywall stilts'],contextualSynonyms:['construction stilts','articulating stilts when manufacturer terminology supports it'],relatedButDistinct:['scaffolding','work platforms'],avoid:['safety claims without manufacturer evidence']},
  editorialGuidance:['Safety, load, adjustment, and warranty statements must stay exact and evidence-bound.','Do not convert manufacturer design claims into universal safety guarantees.'],
  claimsRequiringEvidence:['weight rating','reduced fatigue','improved balance','safety improvement','structural warranty','material strength','industry-standard/superlative claims'],
  commonCatalogErrors:['Mixing height range with user working height.','Inventing weight capacity.','Claiming one brand’s parts fit another.'],
  searchIntentPatterns:['[brand] drywall stilts','[height range] drywall stilts','professional drywall stilts'],
  referenceIds:['dura_about','dura_adjustable']
};

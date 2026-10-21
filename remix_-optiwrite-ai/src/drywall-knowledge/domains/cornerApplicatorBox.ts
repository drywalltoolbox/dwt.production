import type { DrywallDomainKnowledge } from '../types';
export const CORNER_APPLICATOR_BOX: DrywallDomainKnowledge = {
  id:'corner_applicator_box', family:'corner_finishing', label:'Corner Applicator Box',
  tradeRole:'Carries and applies joint compound into internal corners for subsequent finishing with a compatible corner-finishing head.',
  workflow:{ stages:['inside_corner_application'], upstreamDomains:['loading_pump','loading_adapter'], downstreamDomains:['corner_finisher'] },
  buyerQuestions:{primary:['What capacity/size is documented?','How does it meter/apply compound?','Which corner finisher and handle interfaces are supported?'],secondary:['What pressure plate, outlet, wheel, seal, or cleaning features are documented?']},
  evidencePriorities:['capacity','compound outlet/control','head interface','handle compatibility','loading interface','construction/serviceability'],
  mechanisms:[{id:'compound_outlet',label:'Compound outlet',generalFunction:'Transfers compound from the box into the corner/tool interface.',evidenceRequiredForPresence:true},{id:'pressure_system',label:'Pressure system',generalFunction:'Moves compound toward the outlet during application.',evidenceRequiredForPresence:true}],
  configurationDimensions:['capacity/box size','head interface','handle interface'],
  systemRelationships:['Uses a compatible corner-finishing head at the outlet/interface.','May be filled through a loading pump/filler adapter.'],
  compatibilityEvidenceRules:['Exact head, handle, filler, and seal compatibility must be verified.'],
  terminology:{preferred:['corner applicator box'],contextualSynonyms:['corner box','angle box when manufacturer evidence uses it'],relatedButDistinct:['corner finisher','applicator head'],avoid:['corner finisher as a synonym']},
  editorialGuidance:['Keep compound application distinct from the finishing action performed by the attached head.'],
  claimsRequiringEvidence:['capacity advantage','continuous flow','reduced refill frequency','specific head compatibility'],
  commonCatalogErrors:['Describing the box itself as the corner finisher.','Inventing included head or handle.'],
  searchIntentPatterns:['[brand] corner applicator box','drywall corner box','[model] angle box'],
  referenceIds:['level5_finishing_set','tapetech_pump','columbia_media_kit']
};

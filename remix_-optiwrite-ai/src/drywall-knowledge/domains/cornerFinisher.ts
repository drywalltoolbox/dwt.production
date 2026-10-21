import type { DrywallDomainKnowledge } from '../types';
export const CORNER_FINISHER: DrywallDomainKnowledge = {
  id:'corner_finisher', family:'corner_finishing', label:'Corner Finisher / Angle Head',
  tradeRole:'Finishes and feathers both sides of an internal drywall corner with a dedicated finishing head.',
  workflow:{ stages:['inside_corner_finishing','tape_embedding'], upstreamDomains:['corner_roller','corner_applicator_box','compound_tube','applicator_head'], downstreamDomains:[] },
  buyerQuestions:{primary:['What working size/configuration is this?','Is it intended for embedding/wiping, finishing coats, or both as documented?','Which applicator/handle interface is supported?'],secondary:['What spring/tension, wheel/glide, frame, blade, or retainer system is documented?']},
  evidencePriorities:['working size','documented corner operation','spring/tension system','wheel/glide system','frame/blade construction','applicator/handle compatibility'],
  mechanisms:[{id:'spring_tension',label:'Spring/tension system',generalFunction:'Allows the finishing head to conform to the internal angle and may control pressure.',evidenceRequiredForPresence:true},{id:'wings_blades',label:'Finishing wings/blades',generalFunction:'Wipe and feather compound along both sides of the internal corner.',evidenceRequiredForPresence:true},{id:'wheels',label:'Guide wheels',generalFunction:'Guide supported corner-finisher designs along the wall surfaces.',evidenceRequiredForPresence:true}],
  configurationDimensions:['working size','fixed vs adjustable tension','wheel/glide configuration','mounting interface'],
  systemRelationships:['May attach to a corner applicator/compound-delivery tool for finishing coats.','May attach to a dedicated handle for tape embedding/wiping depending on product design.'],
  compatibilityEvidenceRules:['Do not infer applicator, MudRunner/compound-tube, or handle fitment across brands/models.'],
  terminology:{preferred:['corner finisher'],contextualSynonyms:['angle head','angle finisher'],relatedButDistinct:['corner flusher','corner roller','corner applicator'],avoid:['corner applicator as a synonym']},
  editorialGuidance:['State the documented role precisely: embedding/wiping, finishing, or both.','Preserve manufacturer naming when angle head and corner finisher are used differently.'],
  claimsRequiringEvidence:['one-pass perfection','compensates for imperfect corners','spring-pressure adjustability','smoothest action','reduced finishing time'],
  commonCatalogErrors:['Saying the head applies compound when it only finishes it.','Treating corner roller as equivalent.'],
  searchIntentPatterns:['[brand] corner finisher','[size] angle head','drywall corner finisher'],
  referenceIds:['tapetech_corner_finisher','tapetech_taping_order','columbia_media_kit','level5_finishing_set']
};

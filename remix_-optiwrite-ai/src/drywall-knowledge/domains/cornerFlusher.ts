import type { DrywallDomainKnowledge } from '../types';
export const CORNER_FLUSHER: DrywallDomainKnowledge = {
  id:'corner_flusher', family:'corner_finishing', label:'Corner Flusher',
  tradeRole:'Wipes, beds, or finishes internal drywall corners in semi-automatic/direct corner workflows according to the specific flusher design.',
  workflow:{ stages:['tape_embedding','inside_corner_finishing'], upstreamDomains:['compound_tube','applicator_head','corner_roller'], downstreamDomains:[] },
  buyerQuestions:{primary:['What flusher type and working size is this?','What documented stage/application is supported?','Which handle/tube/applicator interface is supported?'],secondary:['What spring, blade, frame, wheel, or adjustment system is documented?']},
  evidencePriorities:['working size','direct/standard/combo configuration','documented application','spring/blade system','interface compatibility'],
  mechanisms:[{id:'flushing_wings',label:'Flushing wings/blades',generalFunction:'Wipe and feather compound/tape along both sides of an internal corner.',evidenceRequiredForPresence:true},{id:'spring_system',label:'Spring system',generalFunction:'Allows supported flusher designs to conform to the angle.',evidenceRequiredForPresence:true}],
  configurationDimensions:['working size','direct/standard/combo design','handle/tube interface'],
  systemRelationships:['May be used after corner rolling or with compound delivered by a tube/applicator system depending on the design.'],
  compatibilityEvidenceRules:['Do not infer compound-tube, applicator, or handle compatibility from size alone.'],
  terminology:{preferred:['corner flusher'],contextualSynonyms:['flusher'],relatedButDistinct:['corner finisher','corner roller'],avoid:['angle head unless manufacturer evidence uses it for that product']},
  editorialGuidance:['Preserve the product-specific flusher type; do not generalize all flushers into automatic corner finishers.'],
  claimsRequiringEvidence:['coat-stage suitability','one-pass finish','spring compensation','direct-use capability'],
  commonCatalogErrors:['Conflating flusher and corner finisher.','Claiming compound application without evidence.'],
  searchIntentPatterns:['[brand] corner flusher','[size] drywall flusher','direct corner flusher'],
  referenceIds:['columbia_media_kit','columbia_tool_sets']
};

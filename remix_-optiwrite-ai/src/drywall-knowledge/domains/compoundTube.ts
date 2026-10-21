import type { DrywallDomainKnowledge } from '../types';
export const COMPOUND_TUBE: DrywallDomainKnowledge = {
  id:'compound_tube', family:'compound_delivery', label:'Compound Tube',
  tradeRole:'Stores and delivers joint compound to compatible applicator or finishing heads during semi-automatic finishing workflows.',
  workflow:{ stages:['compound_delivery','inside_corner_application'], upstreamDomains:['loading_pump','loading_adapter'], downstreamDomains:['applicator_head','corner_flusher','corner_finisher'] },
  buyerQuestions:{primary:['What tube length/capacity/configuration is this?','What head/interface is supported?','How is compound advanced or controlled?'],secondary:['What piston, plunger, valve, grip, or assisted-delivery system is documented?']},
  evidencePriorities:['length','capacity','delivery mechanism','head interface','loading method','handle/control design'],
  mechanisms:[{id:'compound_drive',label:'Compound drive',generalFunction:'Forces compound through the tube toward the attached applicator/finishing head.',evidenceRequiredForPresence:true},{id:'outlet_interface',label:'Head/outlet interface',generalFunction:'Connects the tube to a compatible applicator or finishing head.',evidenceRequiredForPresence:true}],
  configurationDimensions:['tube length','capacity','manual vs assisted delivery','head interface'],
  systemRelationships:['Feeds compatible applicator heads or corner tools.','May be loaded by a pump/adapter or other manufacturer-defined method.'],
  compatibilityEvidenceRules:['Exact head and loading-adapter compatibility must be verified.'],
  terminology:{preferred:['compound tube'],contextualSynonyms:['mud tube'],relatedButDistinct:['loading pump','corner applicator box'],avoid:['pump']},
  editorialGuidance:['Explain delivery method and compatible tool role; do not imply the bare tube performs the finishing operation by itself.'],
  claimsRequiringEvidence:['capacity','gas/power assistance','flow consistency','reach advantage'],
  commonCatalogErrors:['Calling the tube a pump.','Inventing included applicator heads.'],
  searchIntentPatterns:['[brand] compound tube','drywall mud tube','[length] compound tube'],
  referenceIds:['columbia_media_kit','level5_parts','level5_taping_set']
};

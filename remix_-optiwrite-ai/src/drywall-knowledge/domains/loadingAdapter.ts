import type { DrywallDomainKnowledge } from '../types';
export const LOADING_ADAPTER: DrywallDomainKnowledge = {
  id:'loading_adapter', family:'compound_delivery', label:'Loading Adapter / Gooseneck / Filler',
  tradeRole:'Provides the interface between a loading pump and the finishing tool being filled.',
  workflow:{ stages:['compound_loading'], upstreamDomains:['loading_pump'], downstreamDomains:['automatic_taper','finishing_box','corner_applicator_box','nailspotter','compound_tube'] },
  buyerQuestions:{primary:['What adapter type is this?','Which pump and tool family is it documented to connect?'],secondary:['What seal, outlet geometry, attachment, or extended-reach features are documented?']},
  evidencePriorities:['adapter type','pump interface','target tool interface','seal/gasket design','length/geometry','included hardware'],
  mechanisms:[{id:'tool_interface',label:'Tool loading interface',generalFunction:'Mates the pump outlet to the receiving tool’s fill port.',evidenceRequiredForPresence:true}],
  configurationDimensions:['gooseneck vs filler/box-filler type','length/reach','pump interface','tool interface'],
  systemRelationships:['Gooseneck-type adapters are used in taper-loading workflows.','Filler/box-filler adapters are used for compatible finishing boxes and related reservoirs.'],
  compatibilityEvidenceRules:['Exact pump and receiving-tool compatibility always requires product evidence.'],
  terminology:{preferred:['loading adapter'],contextualSynonyms:['gooseneck','filler adapter','box filler when exact type is known'],relatedButDistinct:['loading pump'],avoid:['gooseneck as a synonym for every filler']},
  editorialGuidance:['Name the exact adapter type and documented interface; avoid generic accessory prose.'],
  claimsRequiringEvidence:['universal fit','leak-free seal','tool-free attachment','extended reach'],
  commonCatalogErrors:['Calling a filler adapter a gooseneck.','Claiming universal pump compatibility.'],
  searchIntentPatterns:['[brand] gooseneck adapter','[brand] box filler','drywall pump filler adapter'],
  referenceIds:['tapetech_pump','columbia_media_kit','level5_taping_set']
};

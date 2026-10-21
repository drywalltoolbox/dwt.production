import type { DrywallDomainKnowledge } from '../types';
export const LOADING_PUMP: DrywallDomainKnowledge = {
  id:'loading_pump', family:'compound_delivery', label:'Loading / Compound Pump',
  tradeRole:'Transfers joint compound from a container into compatible automatic or semi-automatic finishing tools through the appropriate loading interface.',
  workflow:{ stages:['compound_loading'], upstreamDomains:[], downstreamDomains:['loading_adapter','automatic_taper','finishing_box','corner_applicator_box','nailspotter','compound_tube'] },
  buyerQuestions:{primary:['Which tool families can this pump load when paired with the correct adapter?','What container/tube length and pump configuration are documented?'],secondary:['What valve, screen, gasket/seal, head, cleaning, or service features are documented?']},
  evidencePriorities:['pump configuration','loading interfaces','tube/foot-valve design','screens/filters','seal/gasket system','container fit','included items','serviceability'],
  mechanisms:[{id:'pump_body',label:'Pump body/plunger',generalFunction:'Moves joint compound from the container toward the outlet.',evidenceRequiredForPresence:true},{id:'foot_valve',label:'Foot valve',generalFunction:'Controls intake of compound into the pump body.',evidenceRequiredForPresence:true},{id:'outlet',label:'Loading outlet',generalFunction:'Connects the pump to a compatible gooseneck or filler adapter.',evidenceRequiredForPresence:true}],
  configurationDimensions:['standard/extended length','manual/powered configuration','outlet/interface configuration','container application'],
  systemRelationships:['A gooseneck-style adapter is commonly used to load automatic tapers.','A filler adapter is commonly used to load finishing boxes and related compound reservoirs, subject to exact product compatibility.'],
  compatibilityEvidenceRules:['Do not infer adapter or tool compatibility from outlet appearance.','Do not claim an adapter is included unless package contents prove it.'],
  terminology:{preferred:['loading pump','compound pump'],contextualSynonyms:['mud pump'],relatedButDistinct:['compound tube','loading adapter'],avoid:['automatic taper pump']},
  editorialGuidance:['Explain what the pump loads and through which documented interfaces before secondary construction details.'],
  claimsRequiringEvidence:['faster loading','reduced waste','container reach advantage','seal improvement','cleaning-time reduction'],
  commonCatalogErrors:['Treating gooseneck and filler adapter as interchangeable.','Calling downstream tools included with the pump.'],
  searchIntentPatterns:['[brand] drywall compound pump','drywall loading pump','[model] mud pump'],
  referenceIds:['tapetech_pump','columbia_media_kit','level5_finishing_set']
};

import type { DrywallDomainKnowledge } from '../types';
export const REPLACEMENT_COMPONENT: DrywallDomainKnowledge = {
  id:'replacement_component', family:'parts_and_service', label:'Replacement Component',
  tradeRole:'Replaces an individual functional component within a documented drywall tool or assembly.',
  workflow:{ stages:['service_repair'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['What component is this?','Which tool/assembly is it documented to fit?','What function/position is documented?'],secondary:['What size, material, orientation, or included hardware is documented?']},
  evidencePriorities:['part identity','compatible tool/assembly','function/position','size/specification','material','part number'],
  mechanisms:[{id:'component_function',label:'Component function',generalFunction:'Performs a specific documented mechanical, sealing, guiding, cutting, rolling, or control function within the parent tool.',evidenceRequiredForPresence:true}],
  configurationDimensions:['part size','orientation/position','target tool/model'],
  systemRelationships:['Belongs to a verified parent tool/assembly relationship.'],
  compatibilityEvidenceRules:['Do not infer compatibility from matching dimensions, names, or appearance alone.'],
  terminology:{preferred:['replacement component'],contextualSynonyms:['replacement part'],relatedButDistinct:['replacement assembly','commodity hardware'],avoid:['universal part without evidence']},
  editorialGuidance:['Use concise technical copy centered on identity, function, and verified fitment.'],
  claimsRequiringEvidence:['OEM-equivalent','improved design','longer life','universal compatibility'],
  commonCatalogErrors:['Inventing function from a vague part name.','Using another brand’s similar part as compatibility proof.'],
  searchIntentPatterns:['[brand] [part name]','[tool model] replacement [part]','[SKU or MPN]'],
  referenceIds:['level5_parts']
};

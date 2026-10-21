import type { DrywallDomainKnowledge } from '../types';
export const REPLACEMENT_ASSEMBLY: DrywallDomainKnowledge = {
  id:'replacement_assembly', family:'parts_and_service', label:'Replacement Assembly',
  tradeRole:'Replaces a multi-component functional assembly within a documented drywall tool or subsystem.',
  workflow:{ stages:['service_repair'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['What assembly is this?','Which exact parent tool/model is it documented to fit?','What function does the assembly perform?'],secondary:['Which major subcomponents or interfaces are documented?','Is it complete or partial?']},
  evidencePriorities:['assembly identity','compatible tool/model','functional role','included subcomponents','interface/position','part number'],
  mechanisms:[{id:'assembly_function',label:'Assembly function',generalFunction:'Represents the documented mechanical function performed by the multi-part assembly.',evidenceRequiredForPresence:true}],
  configurationDimensions:['target tool/model','assembly position/function','complete vs partial assembly'],
  systemRelationships:['Installs within a specific parent tool/assembly according to parts/schematic evidence.'],
  compatibilityEvidenceRules:['Exact fitment requires explicit SKU/MPN/schematic/manufacturer evidence.'],
  terminology:{preferred:['replacement assembly'],contextualSynonyms:['assembly'],relatedButDistinct:['replacement component','maintenance kit'],avoid:['upgrade unless documented']},
  editorialGuidance:['Explain function and exact fitment; omit generic durability copy.'],
  claimsRequiringEvidence:['direct replacement','complete assembly','updated/upgraded design','improved durability'],
  commonCatalogErrors:['Inferring fitment from part-name similarity.','Calling a partial assembly complete.'],
  searchIntentPatterns:['[brand] [assembly name]','[tool model] replacement assembly','[part number]'],
  referenceIds:['level5_parts','columbia_media_kit']
};

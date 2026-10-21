import type { DrywallDomainKnowledge } from '../types';
export const COMMODITY_HARDWARE: DrywallDomainKnowledge = {
  id:'commodity_hardware', family:'parts_and_service', label:'Commodity Hardware / Small Part',
  tradeRole:'Provides a small fastening, retaining, spacing, sealing, spring, or bearing function within a documented tool assembly.',
  workflow:{ stages:['service_repair'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['What exact hardware item/specification is this?','Which parent tool/assembly is it documented to fit?'],secondary:['What material, thread, diameter, length, orientation, or quantity is documented?']},
  evidencePriorities:['part name','part number','exact size/specification','material','compatible assembly/tool','quantity'],
  mechanisms:[],
  configurationDimensions:['size/thread/specification','material','target assembly'],
  systemRelationships:['Used within a verified tool or assembly; generic hardware similarity does not establish interchangeability.'],
  compatibilityEvidenceRules:['Exact fitment must be explicit even when the hardware appears standardized.'],
  terminology:{preferred:['replacement hardware'],contextualSynonyms:['screw','washer','O-ring','spring','pin','bearing when exact item is known'],relatedButDistinct:['replacement component'],avoid:['universal unless explicitly established']},
  editorialGuidance:['Keep copy short and factual; do not inflate ordinary hardware into a feature story.'],
  claimsRequiringEvidence:['stainless/corrosion-resistant','high strength','OEM fit','universal fit'],
  commonCatalogErrors:['Padding a screw/O-ring description.','Assuming dimensions imply compatibility.'],
  searchIntentPatterns:['[brand] [part name]','[part number]','[tool model] [hardware name]'],
  referenceIds:['level5_parts']
};

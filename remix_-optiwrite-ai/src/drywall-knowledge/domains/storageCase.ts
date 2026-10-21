import type { DrywallDomainKnowledge } from '../types';
export const STORAGE_CASE: DrywallDomainKnowledge = {
  id:'storage_case', family:'supporting_equipment', label:'Tool Case / Transport',
  tradeRole:'Protects, organizes, stores, or transports specified drywall finishing tools and components.',
  workflow:{ stages:['service_repair'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['Which tool(s) is the case documented to fit?','What dimensions/material/closure and carrying configuration are documented?'],secondary:['What internal organization, drainage, reinforcement, or strap features are documented?']},
  evidencePriorities:['documented fitment','internal/external dimensions','material','closure','handles/strap','internal organization','drainage/protection features'],
  mechanisms:[{id:'closure',label:'Closure system',generalFunction:'Secures the case during storage/transport.',evidenceRequiredForPresence:true}],
  configurationDimensions:['tool-specific vs multi-tool','length/size','soft/hard case','carry configuration'],
  systemRelationships:['May be bundled with a tool set or sold for a specific tool family.'],
  compatibilityEvidenceRules:['Exact fitment and included inserts/straps require evidence.'],
  terminology:{preferred:['tool case'],contextualSynonyms:['storage case','transport case','tool bag when structurally accurate'],relatedButDistinct:['tool set'],avoid:['protective rating terminology without evidence']},
  editorialGuidance:['Keep protection claims proportional to documented materials and construction.'],
  claimsRequiringEvidence:['waterproof','impact-resistant','airtight','fits all automatic tapers','airline-ready'],
  commonCatalogErrors:['Assuming a nominal length proves tool fitment.','Treating case contents as included tools.'],
  searchIntentPatterns:['[brand] drywall tool case','automatic taper case','[tool family] storage case'],
  referenceIds:['columbia_media_kit']
};

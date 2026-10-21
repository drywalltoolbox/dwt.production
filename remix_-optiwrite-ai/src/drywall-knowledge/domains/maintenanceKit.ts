import type { DrywallDomainKnowledge } from '../types';
export const MAINTENANCE_KIT: DrywallDomainKnowledge = {
  id:'maintenance_kit', family:'supporting_equipment', label:'Maintenance / Repair Kit',
  tradeRole:'Groups verified service parts or maintenance items intended to maintain or repair a specified tool or assembly.',
  workflow:{ stages:['service_repair'], upstreamDomains:[], downstreamDomains:[] },
  buyerQuestions:{primary:['Which tool/model is this kit documented for?','Exactly what parts/items are included?','What maintenance/repair function does the kit address?'],secondary:['Are special tools, procedures, or additional parts documented as required?']},
  evidencePriorities:['compatible tool/model','structured contents','maintenance purpose','part identifiers','instructions/procedure references'],
  mechanisms:[],
  configurationDimensions:['target tool/model','service function','included part set'],
  systemRelationships:['Supports maintenance of a specific parent tool or assembly.'],
  compatibilityEvidenceRules:['Never infer kit compatibility from a shared brand or similar tool name.'],
  terminology:{preferred:['maintenance kit','repair kit'],contextualSynonyms:['service kit'],relatedButDistinct:['tool set','replacement assembly'],avoid:['rebuild kit unless manufacturer/catalog uses it']},
  editorialGuidance:['Lead with exact target tool and maintenance purpose; list verified contents when useful.'],
  claimsRequiringEvidence:['restores like-new performance','complete rebuild','extends tool life','prevents downtime'],
  commonCatalogErrors:['Treating maintenance-kit contents as generic spares.','Claiming fitment across generations without evidence.'],
  searchIntentPatterns:['[brand] [tool] maintenance kit','[model] repair kit','[SKU] service kit'],
  referenceIds:['columbia_media_kit','level5_parts']
};

import type { DrywallDomainKnowledge } from '../types';
export const APPLICATOR_HEAD: DrywallDomainKnowledge = {
  id:'applicator_head', family:'corner_finishing', label:'Applicator Head',
  tradeRole:'Applies joint compound through a dedicated head to a defined corner, bead, or flat application according to the head design.',
  workflow:{ stages:['inside_corner_application','compound_delivery'], upstreamDomains:['compound_tube'], downstreamDomains:['corner_finisher','corner_flusher'] },
  buyerQuestions:{primary:['What application geometry is this head designed for?','What working size/profile is documented?','Which tube/handle interface is supported?'],secondary:['What outlet, shoe, wheel, seal, or adjustment features are documented?']},
  evidencePriorities:['application type','working profile/size','compound outlet','tube/handle interface','wear components'],
  mechanisms:[{id:'outlet_profile',label:'Applicator outlet/profile',generalFunction:'Shapes and directs compound into the intended corner, bead, or surface geometry.',evidenceRequiredForPresence:true}],
  configurationDimensions:['inside/outside/flat application','working profile/size','mounting interface'],
  systemRelationships:['Often receives compound from a compatible compound tube in semi-automatic systems.'],
  compatibilityEvidenceRules:['Do not infer tube or downstream finishing-head compatibility across brands.'],
  terminology:{preferred:['applicator head'],contextualSynonyms:['inside corner applicator','flat applicator when exact type is known'],relatedButDistinct:['corner applicator box','corner finisher'],avoid:['corner finisher']},
  editorialGuidance:['Name the exact application geometry before discussing construction.'],
  claimsRequiringEvidence:['uniform bead/compound distribution','specific bead-system compatibility','tool-free adjustment'],
  commonCatalogErrors:['Using applicator as a generic synonym for every corner tool.'],
  searchIntentPatterns:['[brand] applicator head','drywall corner applicator','[profile] applicator head'],
  referenceIds:['columbia_media_kit','columbia_tool_sets']
};

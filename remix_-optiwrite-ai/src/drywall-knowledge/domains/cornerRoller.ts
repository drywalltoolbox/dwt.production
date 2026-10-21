import type { DrywallDomainKnowledge } from '../types';
export const CORNER_ROLLER: DrywallDomainKnowledge = {
  id:'corner_roller', family:'corner_finishing', label:'Corner Roller',
  tradeRole:'Seats and embeds tape into internal corners after tape and compound have been applied.',
  workflow:{ stages:['tape_embedding'], upstreamDomains:['automatic_taper','semi_automatic_taper'], downstreamDomains:['corner_finisher','corner_flusher'] },
  buyerQuestions:{primary:['What roller/head configuration is this?','Which handle/interface is supported?'],secondary:['What roller geometry/material, frame, pivot, or bearing system is documented?']},
  evidencePriorities:['roller/head design','handle compatibility','frame/pivot system','replaceable wear parts'],
  mechanisms:[{id:'roller_wheels',label:'Corner rollers/wheels',generalFunction:'Press tape into the internal angle and help seat it into the compound.',evidenceRequiredForPresence:true},{id:'pivot',label:'Pivot/articulation',generalFunction:'Allows supported roller heads to track the internal angle during use.',evidenceRequiredForPresence:true}],
  configurationDimensions:['roller/head design','handle interface'],
  systemRelationships:['Commonly follows tape application and precedes corner finishing in automatic-tool workflows.'],
  compatibilityEvidenceRules:['Exact handle/adapter compatibility must be verified.'],
  terminology:{preferred:['corner roller'],contextualSynonyms:['inside corner roller'],relatedButDistinct:['corner finisher','corner flusher'],avoid:['corner finisher']},
  editorialGuidance:['Make the tape-embedding role explicit and avoid claiming final corner finishing unless the product evidence establishes another function.'],
  claimsRequiringEvidence:['reduced effort','better embedding','bearing durability','self-aligning action'],
  commonCatalogErrors:['Saying the roller applies compound.','Calling the roller a corner finisher.'],
  searchIntentPatterns:['[brand] corner roller','drywall inside corner roller'],
  referenceIds:['tapetech_taping_order','columbia_media_kit','level5_finishing_set']
};

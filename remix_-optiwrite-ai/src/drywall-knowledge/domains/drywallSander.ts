import type { DrywallDomainKnowledge } from '../types';
export const DRYWALL_SANDER: DrywallDomainKnowledge = {
  id:'drywall_sander', family:'supporting_equipment', label:'Drywall Sander',
  tradeRole:'Sands dried joint compound and drywall finishing surfaces after compound application and finishing.',
  workflow:{ stages:['sanding'], upstreamDomains:['finishing_box','corner_finisher','smoothing_blade'], downstreamDomains:[] },
  buyerQuestions:{primary:['What sanding head/system is this?','What power source, reach, abrasive interface, and dust-control connection are documented?'],secondary:['What articulation, speed/control, motor, or service features are documented?']},
  evidencePriorities:['power source','head size/profile','abrasive interface','speed/control','reach','dust extraction interface','weight','included accessories'],
  mechanisms:[{id:'sanding_head',label:'Sanding head',generalFunction:'Carries the abrasive across the finished surface.',evidenceRequiredForPresence:true},{id:'dust_interface',label:'Dust extraction interface',generalFunction:'Connects supported sanders to a dust-management system.',evidenceRequiredForPresence:true}],
  configurationDimensions:['powered/manual','head size','reach','speed/control','dust interface'],
  systemRelationships:['Used after compound has dried; may connect to a compatible dust extractor/vacuum when designed for extraction.'],
  compatibilityEvidenceRules:['Do not infer abrasive, vacuum, hose, or power-system compatibility.'],
  terminology:{preferred:['drywall sander'],contextualSynonyms:['drywall sanding system'],relatedButDistinct:['smoothing blade'],avoid:['finishing tool as a substitute for sanding role']},
  editorialGuidance:['Treat dust-control and power claims as evidence-sensitive; do not imply dust-free operation.'],
  claimsRequiringEvidence:['dust-free','reduced airborne dust','surface speed','motor power','weight advantage','finish quality'],
  commonCatalogErrors:['Implying sanding replaces compound finishing.','Inventing vacuum compatibility.'],
  searchIntentPatterns:['[brand] drywall sander','drywall sanding system','[model] drywall sander'],
  referenceIds:['columbia_media_kit']
};

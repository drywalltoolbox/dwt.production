import type { DrywallDomainKnowledge } from '../types';
export const SEMI_AUTOMATIC_TAPER: DrywallDomainKnowledge = {
  id:'semi_automatic_taper', family:'taping', label:'Semi-Automatic Taper',
  tradeRole:'Applies tape and/or compound through a manually driven semi-automatic taping system rather than a full automatic-taper mechanism.',
  workflow:{ stages:['tape_application'], upstreamDomains:[], downstreamDomains:['corner_roller','corner_flusher','corner_finisher'] },
  buyerQuestions:{ primary:['What semi-automatic system/type is this?','How are tape and compound loaded and advanced?'], secondary:['What capacity, cutter, wheel, or control features are documented?','Which related heads/handles are part of the same system?'] },
  evidencePriorities:['tool type','tape/compound handling','capacity','cutter/feed','controls','included components','system compatibility'],
  mechanisms:[{id:'manual_feed',label:'Manual feed/application system',generalFunction:'Uses direct operator movement or control to advance tape/compound.',evidenceRequiredForPresence:true},{id:'cutter',label:'Tape cutting mechanism',generalFunction:'Cuts tape at the end of an application run.',evidenceRequiredForPresence:true}],
  configurationDimensions:['capacity','tool length','tape handling','applicator/cutter design'],
  systemRelationships:['May be paired with corner rollers, flushers, applicator heads, or compound tubes depending on the semi-automatic system.'],
  compatibilityEvidenceRules:['Do not infer accessories or head compatibility from the semi-automatic label alone.'],
  terminology:{preferred:['semi-automatic taper'],contextualSynonyms:['semi-auto taper'],relatedButDistinct:['automatic taper','banjo taper'],avoid:['automatic taper when the mechanism is semi-automatic']},
  editorialGuidance:['Describe the actual semi-automatic operating method supported by evidence rather than comparing generically with automatic tapers.'],
  claimsRequiringEvidence:['speed advantage','ease-of-use advantage','capacity','professional productivity'],
  commonCatalogErrors:['Collapsing semi-automatic and automatic tapers into one domain.','Assuming included corner tools from a set listing.'],
  searchIntentPatterns:['[brand] semi automatic taper','semi automatic drywall taper','[model] taper'],
  referenceIds:['columbia_media_kit','columbia_tool_sets']
};

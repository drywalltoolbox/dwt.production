import type { DrywallDomainKnowledge } from '../types';
export const NAILSPOTTER: DrywallDomainKnowledge = {
  id:'nailspotter', family:'flat_finishing', label:'Nail / Fastener Spotter',
  tradeRole:'Applies joint compound over drywall fastener depressions in a purpose-built spotting operation.',
  workflow:{ stages:['fastener_finishing'], upstreamDomains:['loading_pump','loading_adapter'], downstreamDomains:[] },
  buyerQuestions:{primary:['What fastener-spotting width/configuration is this?','How is compound controlled and applied?','Which handle/loading interface is supported?'],secondary:['What blade/skid/contact system and service parts are documented?']},
  evidencePriorities:['working width','compound capacity/control','blade/contact system','handle compatibility','loading interface','serviceability'],
  mechanisms:[{id:'blade_contact',label:'Blade/contact system',generalFunction:'Meters and finishes compound over fastener depressions as the tool passes.',evidenceRequiredForPresence:true}],
  configurationDimensions:['working width','capacity','handle/interface'],
  systemRelationships:['May be filled from a loading pump through a compatible filler interface.','Uses a compatible handle/control system.'],
  compatibilityEvidenceRules:['Do not infer box-handle or filler compatibility from visual similarity to a finishing box.'],
  terminology:{preferred:['nail spotter','nailspotter'],contextualSynonyms:['fastener spotter'],relatedButDistinct:['finishing box'],avoid:['flat box']},
  editorialGuidance:['Keep the fastener-finishing role explicit; do not broaden the application to flat-joint finishing.'],
  claimsRequiringEvidence:['one-pass coverage','speed/productivity','capacity','compatible fastener types'],
  commonCatalogErrors:['Calling a nailspotter a flat box.','Inventing screw/nail compatibility beyond the documented application.'],
  searchIntentPatterns:['[brand] nail spotter','drywall fastener spotter','[size] nailspotter'],
  referenceIds:['columbia_media_kit','level5_parts','tapetech_pump']
};

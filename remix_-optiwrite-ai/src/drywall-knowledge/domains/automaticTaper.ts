import type { DrywallDomainKnowledge } from '../types';
export const AUTOMATIC_TAPER: DrywallDomainKnowledge = {
  id: 'automatic_taper', family: 'taping', label: 'Automatic Taper',
  tradeRole: 'Applies drywall tape and joint compound together along joints and internal angles using an integrated taping mechanism.',
  workflow: { stages: ['tape_application'], upstreamDomains: ['loading_pump','loading_adapter'], downstreamDomains: ['corner_roller','corner_finisher','finishing_box'] },
  buyerQuestions: { primary: ['What exact taper/model is this?','How is it loaded?','What tape/compound operating system is documented?'], secondary: ['What cutter, drive, control, cleaning, or extension features are documented?','What loading equipment and service parts are compatible?'] },
  evidencePriorities: ['model/configuration','loading interface','tape feed','compound flow','cutting mechanism','drive/control system','capacity','serviceability','included items'],
  mechanisms: [
    { id:'tape_feed', label:'Tape feed', generalFunction:'Advances drywall tape through the tool during application.', evidenceRequiredForPresence:true },
    { id:'compound_flow', label:'Compound flow system', generalFunction:'Meters or delivers joint compound with the tape.', evidenceRequiredForPresence:true },
    { id:'cutter', label:'Tape cutter', generalFunction:'Cuts tape at the end of a run.', evidenceRequiredForPresence:true },
    { id:'drive', label:'Drive/control mechanism', generalFunction:'Transmits operator input to tape and compound functions.', evidenceRequiredForPresence:true }
  ],
  configurationDimensions: ['body/material configuration','capacity','extension/reach configuration','loading interface'],
  systemRelationships: ['Typically loaded by a compatible loading pump through a gooseneck-style taper loading interface.','Corner roller/corner finisher may follow automatic-taper application on internal corners.'],
  compatibilityEvidenceRules: ['Do not infer pump/gooseneck fitment by brand alone.','Do not infer tape size, compound capacity, extension compatibility, or service-kit compatibility.'],
  terminology: { preferred:['automatic taper'], contextualSynonyms:['automatic drywall taper'], relatedButDistinct:['semi-automatic taper','banjo taper'], avoid:['bazooka as primary formal product term'] },
  editorialGuidance: ['Explain the documented operating system before secondary construction details.','For richly documented tapers, separate operation/loading/serviceability rather than creating one long feature list.'],
  claimsRequiringEvidence: ['faster taping','reduced fatigue','jam resistance','capacity advantage','cleaning-time reduction','durability/longevity'],
  commonCatalogErrors: ['Calling every taper automatic.','Inventing capacity or tape size.','Treating a compatible pump as included.'],
  searchIntentPatterns: ['[brand] automatic taper','automatic drywall taper','[model] automatic taper'],
  referenceIds: ['tapetech_taper','tapetech_taping_order','columbia_media_kit','level5_taping_set']
};

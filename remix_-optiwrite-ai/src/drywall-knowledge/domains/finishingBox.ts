import type { DrywallDomainKnowledge } from '../types';
export const FINISHING_BOX: DrywallDomainKnowledge = {
  id:'finishing_box', family:'flat_finishing', label:'Finishing / Flat Box',
  tradeRole:'Applies and feathers joint compound over taped flat and butt joints.',
  workflow:{ stages:['flat_joint_finishing','butt_joint_finishing'], upstreamDomains:['loading_pump','loading_adapter'], downstreamDomains:[] },
  buyerQuestions:{ primary:['What working width and capacity/configuration is this?','What compound/crown control is documented?','Which handle/interface is supported?'], secondary:['What blade, pressure plate, wheel, axle, door/gate, or cleaning system is documented?','What service items are included?'] },
  evidencePriorities:['working width','capacity','application','crown/compound control','blade system','pressure plate','wheel/axle system','construction','handle compatibility','loading interface'],
  mechanisms:[{id:'crown_control',label:'Crown control',generalFunction:'Adjusts the compound profile/crown left through the center of the joint.',evidenceRequiredForPresence:true},{id:'pressure_plate',label:'Pressure plate',generalFunction:'Applies pressure to the compound reservoir/output system during finishing.',evidenceRequiredForPresence:true},{id:'blade_system',label:'Blade system',generalFunction:'Shapes the compound profile and feathers edges.',evidenceRequiredForPresence:true},{id:'wheel_axle',label:'Wheel/axle system',generalFunction:'Guides the box across the wall while maintaining contact.',evidenceRequiredForPresence:true}],
  configurationDimensions:['working width','capacity class','standard/assisted operation','handle interface'],
  systemRelationships:['Operates with a compatible finishing-box handle.','Typically filled by a loading pump through a box-filler/filler-adapter interface.'],
  compatibilityEvidenceRules:['Do not infer handle, filler, blade, wheel, or replacement-part compatibility across brands/models.'],
  terminology:{preferred:['finishing box'],contextualSynonyms:['flat box'],relatedButDistinct:['corner applicator box','nailspotter','smoothing blade'],avoid:['mud box unless manufacturer/product evidence uses it']},
  editorialGuidance:['Lead with size/configuration and finishing role.','Explain verified control or wheel/blade mechanisms only when they materially help selection.'],
  claimsRequiringEvidence:['high capacity','fewer refills','specific coat-stage suitability','smoother finish','faster finishing','blade longevity'],
  commonCatalogErrors:['Assigning a coat stage from width alone.','Inventing handle compatibility.','Treating width as capacity.'],
  searchIntentPatterns:['[brand] finishing box','[size] drywall finishing box','[brand] flat box','[model] finishing box'],
  referenceIds:['columbia_media_kit','level5_finishing_set']
};

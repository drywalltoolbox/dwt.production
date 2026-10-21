import type { DrywallDomainKnowledge } from '../types';
export const HANDLE_SYSTEM: DrywallDomainKnowledge = {
  id:'handle_system', family:'compound_delivery', label:'Handle / Tool Adapter System',
  tradeRole:'Provides operator reach, leverage, control, and the mechanical interface to compatible finishing heads or boxes.',
  workflow:{ stages:['flat_joint_finishing','inside_corner_finishing','tape_embedding'], upstreamDomains:[], downstreamDomains:['finishing_box','corner_finisher','corner_roller','corner_applicator_box','applicator_head'] },
  buyerQuestions:{primary:['What tool family is this handle/adapter intended for?','What length or adjustment range is documented?','What connection/control interface is used?'],secondary:['What brake, grip, pivot, lock, tube, or extension mechanism is documented?']},
  evidencePriorities:['tool interface','length/range','fixed/extendable configuration','control/brake mechanism','adapter/head interface','construction'],
  mechanisms:[{id:'extension_lock',label:'Extension/locking system',generalFunction:'Changes and retains handle length on adjustable models.',evidenceRequiredForPresence:true},{id:'control_interface',label:'Tool control interface',generalFunction:'Transfers operator input to the connected finishing tool.',evidenceRequiredForPresence:true}],
  configurationDimensions:['fixed vs extendable','length/range','box vs corner-tool interface','adapter configuration'],
  systemRelationships:['Connects only to compatible tool heads/boxes; an adapter may be required depending on the tool family.'],
  compatibilityEvidenceRules:['Never infer cross-brand handle compatibility or adapter fitment.'],
  terminology:{preferred:['handle'],contextualSynonyms:['extension handle','box handle','corner tool handle when exact type is known'],relatedButDistinct:['loading adapter'],avoid:['universal handle without evidence']},
  editorialGuidance:['Lead with compatible tool family and reach/control configuration.'],
  claimsRequiringEvidence:['universal fit','reduced fatigue','quick-adjust speed','no-slip lock','ergonomic benefit'],
  commonCatalogErrors:['Treating every long handle as interchangeable.','Omitting a required adapter relationship.'],
  searchIntentPatterns:['[brand] flat box handle','[brand] corner finisher handle','drywall tool extension handle'],
  referenceIds:['columbia_media_kit','level5_finishing_set']
};

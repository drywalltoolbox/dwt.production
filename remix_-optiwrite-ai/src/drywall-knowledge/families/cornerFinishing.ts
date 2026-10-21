import type { DrywallFamilyKnowledge } from '../types';
export const CORNER_FINISHING_FAMILY: DrywallFamilyKnowledge = {
  id: 'corner_finishing', label: 'Corner finishing',
  workflowPrinciples: ['Inside-corner workflows distinguish tape application, tape embedding, compound application, and finishing/wiping.', 'Rollers, applicators, flushers, and corner finishers are related system components but are not interchangeable.'],
  sharedBuyerPriorities: ['corner operation', 'working size', 'head/interface', 'adjustment/tension system', 'handle compatibility', 'compound-delivery relationship'],
  sharedEvidenceRules: ['Do not infer that a tool applies compound simply because it finishes corners.', 'Do not infer cross-brand head/handle fitment.'],
  commonConfusions: ['Corner roller != corner finisher.', 'Corner applicator != corner finisher.', 'Corner flusher terminology varies by manufacturer; preserve exact product identity.']
};

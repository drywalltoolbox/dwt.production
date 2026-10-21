import type { DrywallFamilyKnowledge } from '../types';
export const TAPING_FAMILY: DrywallFamilyKnowledge = {
  id: 'taping', label: 'Taping',
  workflowPrinciples: ['Taping establishes the tape-and-compound layer before later finishing coats.', 'Automatic and semi-automatic systems may reach the same workflow objective through materially different mechanisms.'],
  sharedBuyerPriorities: ['tool type', 'tape/compound handling', 'loading method', 'controls', 'serviceability', 'compatible workflow equipment'],
  sharedEvidenceRules: ['Do not infer tape capacity, compound capacity, cutter design, drive system, or loading interface from the word taper alone.'],
  commonConfusions: ['Do not describe tapers as finishing boxes.', 'Do not treat an automatic taper and a banjo/semi-automatic taper as mechanically equivalent.']
};

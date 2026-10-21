import type { DrywallFamilyKnowledge } from '../types';
export const FLAT_FINISHING_FAMILY: DrywallFamilyKnowledge = {
  id: 'flat_finishing', label: 'Flat-joint finishing',
  workflowPrinciples: ['Flat and butt joints are finished by applying and feathering compound after taping.', 'Working width, compound control, contact/feathering systems, and handle interfaces are common selection dimensions.'],
  sharedBuyerPriorities: ['application', 'working width', 'compound control', 'contact/feathering mechanism', 'handle/interface', 'serviceability'],
  sharedEvidenceRules: ['Do not assign a specific coat stage solely from tool width.', 'Do not infer that a wider tool is inherently faster or better.'],
  commonConfusions: ['Nailspotters are fastener-finishing tools, not flat boxes.', 'Smoothing blades spread/smooth compound but are not automatic finishing boxes.']
};

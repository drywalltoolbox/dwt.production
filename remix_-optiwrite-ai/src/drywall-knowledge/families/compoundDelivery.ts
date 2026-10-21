import type { DrywallFamilyKnowledge } from '../types';
export const COMPOUND_DELIVERY_FAMILY: DrywallFamilyKnowledge = {
  id: 'compound_delivery', label: 'Compound delivery and control',
  workflowPrinciples: ['Loading pumps move compound into finishing tools through the correct loading interface.', 'Compound tubes supply compound during semi-automatic applicator/flusher workflows.', 'Handles and adapters transmit operator control and connect compatible heads/tools.'],
  sharedBuyerPriorities: ['interface type', 'tool compatibility', 'capacity or reach when documented', 'control method', 'serviceability'],
  sharedEvidenceRules: ['Generic system relationships do not prove exact SKU compatibility.', 'Do not collapse goosenecks, filler adapters, handles, and head adapters into a single accessory class in copy.'],
  commonConfusions: ['A loading pump is not itself a finishing head.', 'A compound tube is not a loading pump.', 'A handle adapter does not establish universal compatibility.']
};

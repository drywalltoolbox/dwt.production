import type { DrywallFamilyKnowledge } from '../types';
export const PARTS_FAMILY: DrywallFamilyKnowledge = {
  id: 'parts_and_service', label: 'Parts and service',
  workflowPrinciples: ['Parts restore or maintain a parent tool/assembly; exact fitment is a product-evidence question.', 'Description depth follows functional complexity and evidence, not physical size or price.'],
  sharedBuyerPriorities: ['exact part identity', 'replacement function', 'compatible tool/assembly', 'size/material when documented', 'assembly position when documented'],
  sharedEvidenceRules: ['Never infer fitment from title similarity alone.', 'Never turn a generic hardware specification into a universal compatibility claim.'],
  commonConfusions: ['A replacement assembly is not the same content class as one of its component parts.', 'Do not advertise routine hardware as a performance upgrade without evidence.']
};

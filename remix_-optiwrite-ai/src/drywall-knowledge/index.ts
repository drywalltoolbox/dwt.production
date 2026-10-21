export * from './types';
export * from './sources';
export * from './domainRegistry';
export * from './familyRegistry';
export * from './classifyDomain';
export * from './compileContext';
export * from './integration';
export { buildCatalogEditorKnowledge } from './integration';
export * from './brands/registry';

import { DOMAIN_REGISTRY } from './domainRegistry';
import { FAMILY_REGISTRY } from './familyRegistry';
import { KNOWLEDGE_REFERENCES } from './sources';

export function validateDrywallKnowledgeBase(): string[] {
  const issues: string[] = [];
  for (const domain of Object.values(DOMAIN_REGISTRY)) {
    if (!FAMILY_REGISTRY[domain.family]) issues.push(`${domain.id}: missing family ${domain.family}`);
    if (!domain.tradeRole.trim()) issues.push(`${domain.id}: missing trade role`);
    if (!domain.buyerQuestions.primary.length) issues.push(`${domain.id}: no primary buyer questions`);
    if (!domain.evidencePriorities.length) issues.push(`${domain.id}: no evidence priorities`);
    for (const ref of domain.referenceIds) if (!KNOWLEDGE_REFERENCES[ref]) issues.push(`${domain.id}: unknown reference ${ref}`);
  }
  return issues;
}

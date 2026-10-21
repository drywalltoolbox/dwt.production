import { BRAND_REGISTRY } from './brands/registry';
import { BRAND_TERMINOLOGY_RULES } from './brands/terminology';
import { CLAIM_POLICY } from './core/claimPolicy';
import { EDITORIAL_STANDARDS } from './core/editorialStandards';
import { EVIDENCE_POLICY } from './core/evidencePolicy';
import { SEARCH_INTENT_RULES } from './core/searchIntent';
import { GLOBAL_TERMINOLOGY_RULES } from './core/terminology';
import { WORKFLOW_GUARDRAILS } from './core/tradeWorkflow';
import { DOMAIN_REGISTRY } from './domainRegistry';
import { FAMILY_REGISTRY } from './familyRegistry';
import type { CompiledDrywallContext, DrywallContextRequest } from './types';

function bullet(lines: readonly string[]): string { return lines.map(line => `- ${line}`).join('\n'); }

export function buildDrywallDomainContext(request: DrywallContextRequest): CompiledDrywallContext {
  const domain = DOMAIN_REGISTRY[request.domainId];
  if (!domain) throw new Error(`Unknown drywall domain: ${request.domainId}`);
  const family = FAMILY_REGISTRY[domain.family];
  if (!family) throw new Error(`Missing family knowledge for ${domain.family}`);

  const authority = [...EVIDENCE_POLICY];
  const editorial = [...EDITORIAL_STANDARDS];
  const workflow = [
    ...family.workflowPrinciples,
    ...family.sharedBuyerPriorities.map(value => `Shared buyer priority: ${value}`),
    ...family.commonConfusions.map(value => `Family distinction: ${value}`),
    ...WORKFLOW_GUARDRAILS,
    `Trade role: ${domain.tradeRole}`,
    `Workflow stages: ${domain.workflow.stages.join(', ') || 'not assigned'}`,
    `Typical upstream domains: ${domain.workflow.upstreamDomains.join(', ') || 'none'}`,
    `Typical downstream domains: ${domain.workflow.downstreamDomains.join(', ') || 'none'}`,
    ...domain.systemRelationships.map(value => `System relationship: ${value}`)
  ];
  const domainLines = [
    `Primary buyer questions: ${domain.buyerQuestions.primary.join(' | ')}`,
    `Secondary buyer questions: ${domain.buyerQuestions.secondary.join(' | ')}`,
    `Evidence priorities: ${domain.evidencePriorities.join(', ')}`,
    `Configuration dimensions: ${domain.configurationDimensions.join(', ')}`,
    ...domain.mechanisms.map(item => `Recognize ${item.label} only if present in evidence; general function: ${item.generalFunction}`),
    ...domain.editorialGuidance.map(value => `Editorial guidance: ${value}`),
    ...domain.commonCatalogErrors.map(value => `Avoid catalog error: ${value}`)
  ];
  const terminology = [
    ...GLOBAL_TERMINOLOGY_RULES,
    `Preferred domain terms: ${domain.terminology.preferred.join(', ')}`,
    `Contextual synonyms: ${domain.terminology.contextualSynonyms.join(', ') || 'none'}`,
    `Related but distinct: ${domain.terminology.relatedButDistinct.join(', ') || 'none'}`,
    `Avoid: ${domain.terminology.avoid.join(', ') || 'none'}`
  ];
  const evidence = [
    ...family.sharedEvidenceRules,
    ...domain.compatibilityEvidenceRules,
    ...domain.claimsRequiringEvidence.map(value => `Requires explicit evidence: ${value}`),
    `Claim policy: ${Object.entries(CLAIM_POLICY).map(([key,value]) => `${key}=${value}`).join(' ')}`
  ];
  if (request.evidenceRichness === 'sparse') evidence.push('Evidence is sparse: remain precise and concise; do not compensate with generic domain prose.');
  if (request.hasPackageContents) evidence.push('Package contents are present: describe only verified included items and keep bundle identity separate from individual tool roles.');
  if (request.hasCompatibility) evidence.push('Compatibility evidence is present: use only the exact documented relationships; never broaden them by analogy.');
  if (request.hasVariations) evidence.push('Variation context is present: explain configuration differences without turning variation children into independent product authorities.');
  if (request.featureSystems?.length) evidence.push(`Named feature systems present in evidence: ${request.featureSystems.join(', ')}. Give dedicated treatment only when multiple supported facts justify it.`);

  const search: string[] = [...SEARCH_INTENT_RULES, ...domain.searchIntentPatterns.map(value => `Domain pattern: ${value}`)];
  const brand: string[] = [...BRAND_TERMINOLOGY_RULES];
  const brandEntry = request.brandId ? BRAND_REGISTRY[request.brandId] : undefined;
  if (brandEntry) brand.push(`Canonical brand spelling: ${brandEntry.canonicalName}`);

  const sections = { authority, editorial, workflow, domain:domainLines, terminology, evidence, search, brand };
  const text = [
    'DRYWALL TOOLBOX DOMAIN KNOWLEDGE',
    `DOMAIN: ${domain.label} (${domain.id})`,
    `FAMILY: ${family.label}`,
    '', 'AUTHORITY', bullet(authority),
    '', 'EDITORIAL STANDARD', bullet(editorial),
    '', 'WORKFLOW AND SYSTEM CONTEXT', bullet(workflow),
    '', 'DOMAIN INTELLIGENCE', bullet(domainLines),
    '', 'TERMINOLOGY', bullet(terminology),
    '', 'EVIDENCE AND CLAIM DISCIPLINE', bullet(evidence),
    '', 'SEARCH INTENT', bullet(search),
    '', 'BRAND TERMINOLOGY', bullet(brand)
  ].join('\n').trim();

  return { domainId:domain.id, familyId:domain.family, sections, text };
}

#!/usr/bin/env node
import assert from 'node:assert/strict';
import { loadRegistry, resolveTask, riskIndex } from './lib/routing.mjs';

const registry = loadRegistry();
assert.equal(registry.version, 2);
assert.deepEqual(registry.riskOrder, ['low', 'medium', 'high', 'critical']);

const frontend = resolveTask({ intent: 'implement', domain: 'frontend', flags: ['ui', 'responsive'], risk: 'low' }, registry);
assert.equal(frontend.role.id, 'frontend-engineer');
assert.equal(frontend.subjectRole.id, 'frontend-engineer');
assert(frontend.skills.some((item) => item.id === 'dtb-design-system'));
assert(frontend.skills.some((item) => item.id === 'dtb-responsive-ui-engineering'));
assert(!frontend.skills.some((item) => item.id === 'dtb-ui-design-critique'));
assert(!frontend.context.includes('.agents/README.md'));

const critique = resolveTask({ intent: 'review', domain: 'frontend', flags: ['ui-critique'], risk: 'medium' }, registry);
assert(critique.skills.some((item) => item.id === 'dtb-engineering-review'));
assert(critique.skills.some((item) => item.id === 'dtb-ui-design-critique'));

const pdpImplementation = resolveTask({ intent: 'redesign', domain: 'pdp', risk: 'low' }, registry);
assert.equal(pdpImplementation.role.id, 'frontend-engineer');
assert.equal(pdpImplementation.subjectRole.id, 'pdp-conversion-specialist');
assert(pdpImplementation.context.includes('.agents/roles/pdp-conversion-specialist.md'));
assert(pdpImplementation.skills.some((item) => item.id === 'dtb-design-system'));
assert(pdpImplementation.skills.some((item) => item.id === 'dtb-responsive-ui-engineering'));
assert(pdpImplementation.skills.some((item) => item.id === 'dtb-ux-flow-engineering'));

const integration = resolveTask({ intent: 'implement', domain: 'integrations', flags: [], risk: 'low' }, registry);
assert.equal(integration.role.id, 'integration-engineer');
assert.equal(integration.effectiveRisk, 'high');
assert(integration.skills.some((item) => item.id === 'dtb-integration-engineering'));
assert(integration.reviewers.some((item) => item.id === 'integration-reviewer'));
assert(integration.reviewers.some((item) => item.id === 'test-verifier'));

const persistence = resolveTask({ intent: 'fix', domain: 'platform', flags: ['persistence'], risk: 'low' }, registry);
assert(!persistence.reviewers.some((item) => item.id === 'architect'));
const persistenceContract = resolveTask({ intent: 'change', domain: 'platform', flags: ['persistence-contract'], risk: 'low' }, registry);
assert(persistenceContract.reviewers.some((item) => item.id === 'architect'));
assert.equal(persistenceContract.effectiveRisk, 'critical');

const queueIdentity = resolveTask({ intent: 'change', domain: 'orders', flags: ['queue-identity'], risk: 'low' }, registry);
assert(queueIdentity.reviewers.some((item) => item.id === 'architect'));
assert(queueIdentity.reviewers.some((item) => item.id === 'integration-reviewer'));

const criticalCatalog = resolveTask({ intent: 'change', domain: 'catalog', flags: ['catalog-identifiers'], risk: 'critical' }, registry);
assert.equal(criticalCatalog.effectiveRisk, 'critical');
assert(!criticalCatalog.reviewers.some((item) => item.id === 'security-reviewer'));

const payment = resolveTask({ intent: 'fix', domain: 'checkout', flags: ['payment'], risk: 'low' }, registry);
assert.equal(payment.effectiveRisk, 'critical');
assert(payment.reviewers.some((item) => item.id === 'security-reviewer'));
assert(payment.reviewers.some((item) => item.id === 'integration-reviewer'));
assert(payment.reviewers.some((item) => item.id === 'test-verifier'));

const contextMaintenance = resolveTask({ intent: 'context-maintenance', domain: 'ai-governance', risk: 'low' }, registry);
assert.equal(contextMaintenance.role.id, 'ai-governance-engineer');
assert.equal(contextMaintenance.role.mode, 'write');
assert.equal(contextMaintenance.effectiveRisk, 'medium');
assert(contextMaintenance.skills.some((item) => item.id === 'dtb-ai-context-engineering'));
assert(contextMaintenance.skills.some((item) => item.id === 'dtb-ai-workspace-governance'));
assert(contextMaintenance.reviewers.some((item) => item.id === 'code-reviewer'));
assert(contextMaintenance.reviewers.some((item) => item.id === 'test-verifier'));

const marketResearch = resolveTask({ intent: 'research', domain: 'market-research', risk: 'low' }, registry);
assert.equal(marketResearch.role.id, 'market-intelligence-analyst');
assert.equal(marketResearch.role.mode, 'read');
assert.throws(() => resolveTask({ intent: 'implement', domain: 'market-research', risk: 'low' }, registry), /does not define an implementation role/);

const marketingPlan = resolveTask({ intent: 'marketing-plan', domain: 'marketing', risk: 'low' }, registry);
assert.equal(marketingPlan.role.id, 'marketing-specialist');
assert.equal(marketingPlan.role.mode, 'read');
assert(marketingPlan.skills.some((item) => item.id === 'dtb-marketing-live-intelligence'));

const marketingReview = resolveTask({ intent: 'marketing-review', domain: 'marketing', risk: 'low' }, registry);
assert.equal(marketingReview.role.id, 'marketing-specialist');
assert.equal(marketingReview.workflow.id, 'review');

const productImagery = resolveTask({ intent: 'image-production', domain: 'product-imagery', risk: 'low' }, registry);
assert.equal(productImagery.role.id, 'catalog-data-engineer');
assert.equal(productImagery.subjectRole.id, 'product-image-art-director');
assert(productImagery.skills.some((item) => item.id === 'dtb-product-image-engineering'));

for (const [flag, rule] of Object.entries(registry.flags)) {
  const domain = flag === 'catalog-identifiers' ? 'catalog' : flag === 'payment' || flag === 'checkout-contract' || flag === 'refund-contract' ? 'checkout' : flag.includes('provider') || flag === 'queue' || flag === 'queue-identity' || flag === 'webhook' ? 'integrations' : flag === 'ai-governance' || flag === 'external-skill' ? 'ai-governance' : 'platform';
  const intent = domain === 'ai-governance' ? 'context-maintenance' : 'change';
  const resolved = resolveTask({ intent, domain, flags: [flag], risk: 'low' }, registry);
  if (rule.minimumRisk) assert(riskIndex(registry, resolved.effectiveRisk) >= riskIndex(registry, rule.minimumRisk), `${flag} risk did not escalate`);
  assert.equal(new Set(resolved.reviewers.map((item) => item.id)).size, resolved.reviewers.length, `${flag} duplicated reviewers`);
  assert(!resolved.reviewers.some((item) => item.id === resolved.role.id), `${flag} execution role reviews itself`);
  assert(resolved.reviewers.every((item) => item.mode === 'read'), `${flag} resolved a write reviewer`);
}

for (const risk of registry.riskOrder) {
  const resolved = resolveTask({ intent: 'implement', domain: 'frontend', risk }, registry);
  assert(riskIndex(registry, resolved.effectiveRisk) >= riskIndex(registry, risk));
}

assert.throws(() => resolveTask({ intent: 'unknown', domain: 'frontend' }, registry), /unknown intent/);
assert.throws(() => resolveTask({ intent: 'implement', domain: 'unknown' }, registry), /unknown domain/);
assert.throws(() => resolveTask({ intent: 'implement', domain: 'frontend', flags: ['unknown'] }, registry), /unknown flag/);

console.log('DTB AI routing tests passed.');

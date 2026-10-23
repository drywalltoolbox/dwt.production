/*
 * Prevent customer legal routes from reverting to the accounting integration
 * notice. This is a source-level contract check, not legal advice or a
 * substitute for runtime, provider, and attorney verification.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../src');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const privacy = read('pages/legal/PrivacyPolicy.jsx');
const terms = read('pages/legal/TermsOfService.jsx');
const shell = read('pages/legal/LegalDocument.jsx');
const routes = read('routing/routeModules.js');
const app = read('App.jsx');
const policies = read('pages/StorePolicies.jsx');

function verifyDocument(name, source, sectionsMinimum, requiredTopics) {
  assert.match(source, /<LegalDocument\b/, `${name}: expected shared document renderer`);
  assert.match(source, /lastUpdated="[^"]+"/, `${name}: missing revision date`);
  const headings = source.match(/heading: '[^']+'/g) || [];
  assert.ok(headings.length >= sectionsMinimum, `${name}: too few customer-facing sections`);
  assert.doesNotMatch(source, /eyebrow="QuickBooks Online Integration"/, `${name}: operator notice leaked into customer page`);
  for (const topic of requiredTopics) {
    assert.ok(source.toLowerCase().includes(topic), `${name}: missing ${topic}`);
  }
}
verifyDocument('Privacy Policy', privacy, 16, ['cookies', 'payment', 'repair', 'returns', 'privacy rights', 'retention', 'shipping', 'contact']);
verifyDocument('Terms of Service', terms, 19, ['orders', 'payment', 'refund', 'return', 'warrant', 'repair', 'limitation', 'account']);

for (const route of ['privacy-policy', 'terms-of-service']) {
  assert.ok(routes.includes(`/${route}`), `Missing route loader: ${route}`);
  assert.ok(app.includes(`path="/${route}"`), `Missing React route: ${route}`);
}
assert.match(shell, /aria-label="Related resources"/, 'External/internal policy links must be accessible');
assert.match(shell, /Last revised:/, 'Revision date must be visible');
assert.doesNotMatch(policies, /Terms of Service & EULA|QuickBooks accounting integration/, 'Policy hub should use customer copy');
console.log('Customer privacy and terms source contract: OK');

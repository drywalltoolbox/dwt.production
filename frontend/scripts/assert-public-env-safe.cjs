'use strict';

const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const dotenv = require('dotenv');

const frontendRoot = path.resolve(__dirname, '..');
const mode = process.argv[2] || 'pre';
const envFilenames = ['.env', '.env.development', '.env.staging', '.env.production', '.env.test'];
const forbiddenKeys = new Set([
  'REACT_APP_WC_AUTH_USER',
  'REACT_APP_WC_AUTH_PASS',
  'REACT_APP_WOOCOMMERCE_CONSUMER_KEY',
  'REACT_APP_WOOCOMMERCE_CONSUMER_SECRET',
  'REACT_APP_VEEQO_API_KEY',
  'REACT_APP_VEEQO_WEBHOOK_SECRET',
  'REACT_APP_QBO_CLIENT_SECRET',
  'REACT_APP_JWT_SECRET',
]);
const forbiddenArtifactPatterns = [
  ['WooCommerce consumer key', /\bck_[A-Za-z0-9]{24,}\b/],
  ['WooCommerce consumer secret', /\bcs_[A-Za-z0-9]{24,}\b/],
  ['payment processor secret key', /\bsk_(?:live|test)_[A-Za-z0-9]{16,}\b/],
  ['Private key material', /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/],
];

// Source maps are large and rarely public; scanning them by default is the
// single biggest cost in this script. Opt in explicitly when needed, e.g.
// after enabling public source maps or investigating a suspected leak.
const SCAN_SOURCEMAPS = process.env.SCAN_SOURCEMAPS === '1';
const textExtensions = new Set(['.js', '.css', '.html', '.json', '.txt']);
if (SCAN_SOURCEMAPS) textExtensions.add('.map');

// Skip absurdly large files rather than reading them fully into memory;
// flag them for manual review instead. Vendor bundles can be huge and add
// little value to a substring scan anyway.
const MAX_SCAN_BYTES = 10 * 1024 * 1024; // 10MB

// Cap concurrent file reads so we don't try to open thousands of file
// descriptors at once on very large builds.
const CONCURRENCY = 16;

function readEnvFile(filename) {
  const filepath = path.join(frontendRoot, filename);
  if (!fs.existsSync(filepath)) return {};
  return dotenv.parse(fs.readFileSync(filepath));
}

function readCandidateEnvValues() {
  const values = { ...process.env };
  for (const filename of envFilenames) Object.assign(values, readEnvFile(filename));
  return values;
}

function configuredSecrets(values) {
  return [...forbiddenKeys]
    .map((key) => [key, String(values[key] || '').trim()])
    .filter(([, value]) => value.length >= 4);
}

function fail(message, findings) {
  console.error(message);
  for (const finding of findings) console.error(`  - ${finding}`);
  process.exit(1);
}

async function collectFiles(directory) {
  const results = [];
  const entries = await fsp.readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const filepath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      results.push(...(await collectFiles(filepath)));
      continue;
    }
    if (!textExtensions.has(path.extname(entry.name).toLowerCase())) continue;
    results.push(filepath);
  }
  return results;
}

async function runWithConcurrency(items, limit, worker) {
  let cursor = 0;
  async function next() {
    while (cursor < items.length) {
      const index = cursor++;
      await worker(items[index]);
    }
  }
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, next));
}

async function scanFile(filepath, outputRoot, secrets, findings) {
  const relativePath = path.relative(outputRoot, filepath);
  const { size } = await fsp.stat(filepath);

  if (size > MAX_SCAN_BYTES) {
    findings.add(`SKIPPED — file exceeds ${MAX_SCAN_BYTES / (1024 * 1024)}MB, review manually: ${relativePath}`);
    return;
  }

  const content = await fsp.readFile(filepath, 'utf8');

  for (const [key, value] of secrets) {
    if (content.includes(value)) findings.add(`${key} in ${relativePath}`);
  }
  for (const [label, pattern] of forbiddenArtifactPatterns) {
    if (pattern.test(content)) findings.add(`${label} in ${relativePath}`);
  }
}

async function runPre(secrets) {
  if (secrets.length > 0) {
    fail(
      'Refusing frontend build: server credentials are present in browser build configuration.',
      secrets.map(([key]) => key),
    );
  }
}

async function runPost(secrets) {
  const appEnv = String(
    process.env.APP_ENV || process.env.REACT_APP_APP_ENV || process.env.REACT_APP_ENV || 'production',
  ).toLowerCase();
  if (!['production', 'staging'].includes(appEnv)) {
    fail(`Post-build safety validation only accepts APP_ENV=production or staging; received ${appEnv}.`, []);
  }
  const outputRoot = path.resolve(frontendRoot, '..', appEnv === 'staging' ? 'dist-staging' : 'dist');

  if (!fs.existsSync(outputRoot)) fail(`Frontend output directory not found: ${outputRoot}`, []);

  // If there are no configured secret values, we still need to run the
  // artifact-pattern scan (hardcoded keys aren't tied to env vars), so we
  // can't skip scanning entirely — but we can skip it fast when the build
  // output is empty.
  const files = await collectFiles(outputRoot);
  if (files.length === 0) return;

  const findings = new Set();
  await runWithConcurrency(files, CONCURRENCY, (filepath) =>
    scanFile(filepath, outputRoot, secrets, findings),
  );

  if (findings.size > 0) {
    fail('Refusing frontend artifact: credential material was embedded in generated browser assets.', [...findings]);
  }
}

async function main() {
  const values = readCandidateEnvValues();
  const secrets = configuredSecrets(values);

  if (mode === 'pre') {
    await runPre(secrets);
  } else if (mode === 'post') {
    await runPost(secrets);
  } else {
    fail(`Unknown safety-check mode: ${mode}`, []);
  }
}

main().catch((err) => {
  console.error('Safety check crashed unexpectedly:');
  console.error(err);
  process.exit(1);
});

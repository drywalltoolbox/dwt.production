'use strict';

const fs = require('node:fs');
const path = require('node:path');
const dotenv = require('dotenv');

const root = path.resolve(__dirname, '..');
const expectedKeys = new Set([
  'REACT_APP_WP_BASE_URL',
  'REACT_APP_API_BASE_URL',
  'REACT_APP_STORE_API_BASE',
  'REACT_APP_SITE_URL',
  'REACT_APP_ENV',
  'REACT_APP_SEARCH_INDEXING',
  'REACT_APP_REWARDS_ENABLED',
  'REACT_APP_CATALOG_SNAPSHOTS_ENABLED',
  'REACT_APP_DTB_CATALOG_PLATFORM',
]);

function readEnv(filename) {
  const filepath = path.join(root, filename);
  if (!fs.existsSync(filepath)) throw new Error(`Required frontend environment file is missing: ${filename}`);
  return dotenv.parse(fs.readFileSync(filepath));
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function assertOrigin(value, key, filename) {
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${filename}: ${key} must be an absolute HTTPS URL.`);
  }
  assert(url.protocol === 'https:', `${filename}: ${key} must use HTTPS.`);
  assert(!url.username && !url.password, `${filename}: ${key} must not contain credentials.`);
  assert(url.hostname === 'drywalltoolbox.com', `${filename}: ${key} must use the canonical storefront origin.`);
  assert(!url.search && !url.hash, `${filename}: ${key} must not contain a query or fragment.`);
  return url;
}

const environments = [
  ['.env.example', 'production'],
  ['.env.production', 'production'],
  ['.env.staging', 'staging'],
];

let referenceKeys;
for (const [filename, environment] of environments) {
  const values = readEnv(filename);
  const keys = new Set(Object.keys(values));
  const unexpected = [...keys].filter((key) => !expectedKeys.has(key));
  const missing = [...expectedKeys].filter((key) => !keys.has(key));
  assert(unexpected.length === 0, `${filename}: unsupported frontend environment keys: ${unexpected.join(', ')}`);
  assert(missing.length === 0, `${filename}: missing required frontend environment keys: ${missing.join(', ')}`);
  assert([...keys].every((key) => key.startsWith('REACT_APP_')), `${filename}: only explicitly public REACT_APP_* keys are allowed.`);

  if (!referenceKeys) referenceKeys = keys;
  else assert([...keys].every((key) => referenceKeys.has(key)), `${filename}: frontend env key set differs from .env.example.`);

  const apiOrigin = assertOrigin(values.REACT_APP_API_BASE_URL, 'REACT_APP_API_BASE_URL', filename);
  const wpOrigin = assertOrigin(values.REACT_APP_WP_BASE_URL, 'REACT_APP_WP_BASE_URL', filename);
  const siteUrl = assertOrigin(values.REACT_APP_SITE_URL, 'REACT_APP_SITE_URL', filename);
  assert(apiOrigin.origin === wpOrigin.origin, `${filename}: WordPress and API origins must agree.`);
  assert(values.REACT_APP_STORE_API_BASE === '/wp-json/wc/store/v1', `${filename}: Store API must use the same-origin WooCommerce Store API path.`);
  assert(values.REACT_APP_ENV === environment, `${filename}: REACT_APP_ENV must be ${environment}.`);
  assert(siteUrl.pathname.replace(/\/$/, '') === (environment === 'staging' ? '/staging' : ''), `${filename}: site URL does not match its deployment mount.`);
  assert(values.REACT_APP_SEARCH_INDEXING === (environment === 'production' ? '1' : '0'), `${filename}: search indexing must be enabled only for production.`);

  for (const key of ['REACT_APP_REWARDS_ENABLED', 'REACT_APP_CATALOG_SNAPSHOTS_ENABLED', 'REACT_APP_DTB_CATALOG_PLATFORM']) {
    assert(values[key] === '0' || values[key] === '1', `${filename}: ${key} must be explicitly set to 0 or 1.`);
  }
}

console.log('Frontend public environment contract verified for example, production, and staging.');

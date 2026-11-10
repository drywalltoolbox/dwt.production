'use strict';

const fs = require('fs');
const path = require('path');

const frontendRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(frontendRoot, '..');
const manifestPath = path.join(frontendRoot, 'public', 'site.webmanifest');
const indexPath = path.join(frontendRoot, 'index.html');
const storefrontHeaderPath = path.join(frontendRoot, 'src', 'components', 'storefront', 'StorefrontHeader.jsx');
const storefrontShellPath = path.join(frontendRoot, 'src', 'styles', 'storefront-shell.css');
const machinedDesignPath = path.join(frontendRoot, 'src', 'styles', 'machined-design.css');
const htaccessPath = path.join(frontendRoot, '.htaccess');

function fail(message) {
  console.error(message);
  process.exit(1);
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
if (manifest.start_url !== '/') {
  fail('PWA start_url must be "/" so installed mobile launches use the canonical root route.');
}

if (manifest.scope !== '/') {
  fail('PWA scope must be "/" so installed mobile launches do not inherit relative deploy paths.');
}

if (manifest.id !== '/') {
  fail('PWA id must be "/" to keep the installed app identity aligned with the canonical origin root.');
}

if (manifest.display !== 'standalone' || JSON.stringify(manifest.display_override) !== JSON.stringify(['standalone'])) {
  fail('PWA display mode must activate only as a true standalone installed app.');
}

const indexHtml = fs.readFileSync(indexPath, 'utf8');
if (!indexHtml.includes("searchParams.delete('source')")) {
  fail('frontend/index.html must strip legacy source=pwa launches before app boot.');
}

if (!indexHtml.includes("data-dtb-display-mode', standalone ? 'standalone' : 'browser'")) {
  fail('frontend/index.html must derive app mode from the actual standalone display signal.');
}

if (!indexHtml.includes("data-dtb-app-mounted")) {
  fail('frontend/index.html must use the explicit app-mounted marker for blank-page recovery.');
}

if (indexHtml.includes('dtb_frontend_debug') || indexHtml.includes('dtb_frontend_debug_authorized') || indexHtml.includes('dtb-boot-debug-panel')) {
  fail('frontend/index.html must not retain temporary frontend boot diagnostics.');
}

const storefrontHeader = fs.readFileSync(storefrontHeaderPath, 'utf8');
if (!storefrontHeader.includes('const { height } = header.getBoundingClientRect()') || storefrontHeader.includes('const { bottom } = header.getBoundingClientRect()')) {
  fail('Storefront header spacing must use bounded element height, never its viewport-relative bottom coordinate.');
}

const storefrontShell = fs.readFileSync(storefrontShellPath, 'utf8');
if (
  !storefrontShell.includes('.site-header-mount') ||
  !/main\.main-content\s*\{[^}]*padding-top:\s*var\(--header-height/.test(storefrontShell)
) {
  fail('Fixed-header spacing must be owned by main content with a collapsed header mount wrapper.');
}

const machinedDesign = fs.readFileSync(machinedDesignPath, 'utf8');
if (machinedDesign.includes('[style*="linear-gradient(to right"]')) {
  fail('Trusted-brand edge fades must not be overridden with a mismatched page color.');
}

const htaccess = fs.readFileSync(htaccessPath, 'utf8');
const canonicalRootHtaccess = fs.readFileSync(path.join(repositoryRoot, 'drywalltoolbox', '.htaccess'), 'utf8');
if (htaccess !== canonicalRootHtaccess) {
  fail('frontend/.htaccess must match drywalltoolbox/.htaccess before production builds.');
}
if (!/<FilesMatch "\^site\\\.webmanifest\$">[\s\S]*?Cache-Control "no-cache, no-store, must-revalidate"/.test(htaccess)) {
  fail('drywalltoolbox/.htaccess must prevent stale browser caching of site.webmanifest.');
}

process.stdout.write('Frontend launch hardening verified.\n');

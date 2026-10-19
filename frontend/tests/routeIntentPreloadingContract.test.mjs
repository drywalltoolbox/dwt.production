import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('route intent warming respects pointer dwell, keyboard focus and cleanup', async () => {
  const source = await read('src/components/routing/RouteIntentPreloader.jsx');
  assert.match(source, /HOVER_DWELL_MS = 110/);
  assert.match(source, /addEventListener\('pointerover'/);
  assert.match(source, /addEventListener\('pointerout'/);
  assert.match(source, /addEventListener\('pointerdown'/);
  assert.match(source, /addEventListener\('focusin'/);
  assert.match(source, /event\.button !== 0/);
  assert.match(source, /connection\?\.saveData/);
  assert.match(source, /clearTimeout\(timer\)/);
  assert.match(source, /startsWith\('\/checkout\/'\)/);
});

test('speculative chunks are bounded and navigation bypasses the queue', async () => {
  const source = await read('src/routing/routeModules.js');
  assert.match(source, /MAX_SPECULATIVE_IMPORTS = 2/);
  assert.match(source, /speculativeRequests = new Map\(\)/);
  assert.match(source, /activeSpeculativeImports < MAX_SPECULATIVE_IMPORTS/);
  assert.match(source, /modulePromises\.has\(key\)/);
  assert.match(source, /connection\?\.saveData/);
  assert.match(source, /key === 'checkout' \|\| key === 'checkoutReturn'/);
  assert.match(source, /return lazy\(\(\) => withChunkReload\(key\)\)/);
  assert.match(source, /speculativeRequests\.delete\(task\.key\)/);
});

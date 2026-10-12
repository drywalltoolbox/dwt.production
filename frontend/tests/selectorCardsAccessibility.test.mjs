import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const repoRoot = new URL('../../', import.meta.url);

test('shared selector cards keep native button semantics without mislabeling a button grid as a list', async () => {
  const source = await readFile(new URL('frontend/src/components/selectors/SelectorCards.jsx', repoRoot), 'utf8');

  assert.match(source, /<button\s+type="button"/);
  assert.doesNotMatch(source, /role="listitem"/);
  assert.doesNotMatch(source, /role=\{role\}/);
  assert.match(source, /export function SelectorGrid/);
});

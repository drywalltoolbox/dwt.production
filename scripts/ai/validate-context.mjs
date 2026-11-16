#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, loadRegistry, resolveTask, validateTaskManifest } from './lib/routing.mjs';

const failures = [];
const warnings = [];
const exists = (p) => fs.existsSync(path.join(ROOT, p));
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');
const size = (p) => fs.statSync(path.join(ROOT, p)).size;
const fail = (message) => failures.push(message);
const warn = (message) => warnings.push(message);

function walk(dir) {
  const abs = path.join(ROOT, dir);
  if (!fs.existsSync(abs)) return [];
  const out = [];
  for (const entry of fs.readdirSync(abs, { withFileTypes: true })) {
    const rel = path.posix.join(dir.replaceAll('\\', '/'), entry.name);
    if (entry.isDirectory()) out.push(...walk(rel));
    else out.push(rel);
  }
  return out;
}

function frontmatter(text) {
  const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/);
  return match ? match[1] : '';
}

function frontmatterValue(text, key) {
  const fm = frontmatter(text);
  const match = fm.match(new RegExp(`^${key}:\\s*([^\\n]+)$`, 'm'));
  return match ? match[1].trim().replace(/^['"]|['"]$/g, '') : null;
}

function skillMetadataScalar(text, key, file) {
  const fm = frontmatter(text);
  const entries = fm.split(/\r?\n/).filter((line) => new RegExp(`^${key}\\s*:`).test(line));
  if (entries.length !== 1) {
    fail(`${file}: expected exactly one ${key} frontmatter field`);
    return null;
  }
  const match = entries[0].match(new RegExp(`^${key}\\s*:\\s*(.*)$`));
  if (!match) {
    fail(`${file}: malformed ${key} frontmatter field`);
    return null;
  }
  let value = match[1].trim();
  if (!value || value.startsWith('|') || value.startsWith('>')) {
    fail(`${file}: ${key} must be a nonempty single-line scalar`);
    return null;
  }
  if (value.startsWith('"')) {
    try {
      const parsed = JSON.parse(value);
      if (typeof parsed !== 'string') throw new Error('expected string');
      value = parsed;
    } catch {
      fail(`${file}: ${key} has malformed double-quoted scalar`);
      return null;
    }
  } else if (value.startsWith("'")) {
    if (!value.endsWith("'")) {
      fail(`${file}: ${key} has malformed single-quoted scalar`);
      return null;
    }
    const inner = value.slice(1, -1);
    if (inner.replace(/''/g, '').includes("'")) {
      fail(`${file}: ${key} has malformed single-quoted scalar`);
      return null;
    }
    value = inner.replace(/''/g, "'");
  }
  return value;
}

const required = [
  'AGENTS.md', '.agents/README.md', '.agents/registry.json',
  '.agents/roles/explorer.md', '.agents/roles/architect.md', '.agents/roles/frontend-engineer.md',
  '.agents/roles/wordpress-backend-engineer.md', '.agents/roles/commerce-checkout-engineer.md',
  '.agents/roles/catalog-data-engineer.md', '.agents/roles/integration-engineer.md',
  '.agents/roles/ai-governance-engineer.md', '.agents/roles/code-reviewer.md',
  '.agents/roles/security-reviewer.md', '.agents/roles/integration-reviewer.md',
  '.agents/roles/test-verifier.md', '.agents/workflows/implementation.md',
  '.agents/skills/dtb-integration-engineering/SKILL.md', 'scripts/ai/lib/routing.mjs',
  'scripts/ai/resolve-task.mjs', 'scripts/ai/create-task.mjs', 'scripts/ai/validate-task.mjs',
  'scripts/ai/test-routing.mjs', 'docs/work/README.md'
];
for (const file of required) if (!exists(file)) fail(`missing required AI governance file: ${file}`);

const canonicalFiles = [
  '.agents/registry.json', ...walk('.agents/context'), ...walk('.agents/roles'), ...walk('.agents/skills'), ...walk('.agents/workflows')
].filter((p) => /\.(md|yml|yaml|json)$/.test(p));

for (const file of canonicalFiles) {
  const text = read(file);
  if (/\.claude\//i.test(text) || /\.codex\//i.test(text)) fail(`${file}: canonical knowledge must not depend on vendor adapter paths`);
  if (/\b(model\s*:\s*(sonnet|opus)|gpt-\d)/i.test(text)) fail(`${file}: vendor/model selection belongs in adapters, not canonical knowledge`);
  if (/(show your chain[- ]of[- ]thought|reveal your chain[- ]of[- ]thought|show your private reasoning)/i.test(text)) fail(`${file}: canonical instructions must not require private reasoning disclosure`);
}

const seenSkillNames = new Map();
for (const file of walk('.agents/skills').filter((p) => p.endsWith('/SKILL.md'))) {
  const text = read(file);
  if (file.split('/').length !== 4) fail(`${file}: nested SKILL.md files are not registry-routed; store reference material under a non-SKILL filename`);
  const directoryName = path.posix.basename(path.posix.dirname(file));
  const name = skillMetadataScalar(text, 'name', file);
  const description = skillMetadataScalar(text, 'description', file);
  if (!name) fail(`${file}: missing skill frontmatter name`);
  else {
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name)) fail(`${file}: skill name must be lowercase kebab-case`);
    if (name !== directoryName) fail(`${file}: skill name ${name} must match directory ${directoryName}`);
    if (seenSkillNames.has(name)) fail(`${file}: duplicate skill name also declared by ${seenSkillNames.get(name)}`);
    else seenSkillNames.set(name, file);
  }
  if (!description) fail(`${file}: missing skill frontmatter description`);
  else if (description.length > 1024) fail(`${file}: skill description exceeds 1024 characters`);
}

let registry;
try { registry = loadRegistry(); } catch (error) { fail(`.agents/registry.json: invalid JSON: ${error.message}`); }

if (registry) {
  if (registry.version !== 2) fail(`.agents/registry.json: unsupported registry version ${registry.version}`);
  if (JSON.stringify(registry.riskOrder) !== JSON.stringify(['low', 'medium', 'high', 'critical'])) fail('.agents/registry.json: riskOrder must be low, medium, high, critical');

  const skillRoot = path.join(ROOT, '.agents/skills');
  const localSkillIds = fs.readdirSync(skillRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && fs.existsSync(path.join(skillRoot, entry.name, 'SKILL.md')))
    .map((entry) => entry.name);
  for (const skillId of localSkillIds) if (!registry.skills?.[skillId]) fail(`unregistered canonical skill directory: ${skillId}`);
  for (const [skillId, skillPath] of Object.entries(registry.skills || {})) {
    if (!localSkillIds.includes(skillId)) fail(`registry skill ${skillId} has no top-level skill directory`);
    const expectedPath = `.agents/skills/${skillId}/SKILL.md`;
    if (path.posix.normalize(skillPath.replaceAll('\\', '/')) !== expectedPath) fail(`registry skill ${skillId} must resolve to ${expectedPath}, got ${skillPath}`);
  }

  for (const [workflowId, workflowPath] of Object.entries(registry.workflows || {})) if (!exists(workflowPath)) fail(`workflow ${workflowId} references missing ${workflowPath}`);
  for (const [skillId, skillPath] of Object.entries(registry.skills || {})) if (!exists(skillPath)) fail(`skill ${skillId} references missing ${skillPath}`);

  const rolePaths = new Map();
  for (const [roleId, role] of Object.entries(registry.roles || {})) {
    if (!exists(role.path)) { fail(`role ${roleId} references missing ${role.path}`); continue; }
    if (!['read', 'write'].includes(role.mode)) fail(`role ${roleId} has invalid mode ${role.mode}`);
    if (rolePaths.has(role.path)) fail(`roles ${rolePaths.get(role.path)} and ${roleId} share ${role.path}`);
    rolePaths.set(role.path, roleId);
    for (const skillId of role.requiredSkills || []) if (!registry.skills?.[skillId]) fail(`role ${roleId} references unknown skill ${skillId}`);
    const roleText = read(role.path);
    const declaredId = frontmatterValue(roleText, 'id');
    if (declaredId !== roleId) fail(`${role.path}: frontmatter id must be ${roleId}`);
    const fm = frontmatter(roleText);
    if (/^mode:/m.test(fm)) fail(`${role.path}: routing mode belongs in registry.json, not role frontmatter`);
    if (/^must_load:/m.test(fm)) fail(`${role.path}: required skill routing belongs in registry.json, not role frontmatter`);
  }

  for (const [domainId, domain] of Object.entries(registry.domains || {})) {
    if (!domain || typeof domain !== 'object' || Array.isArray(domain)) { fail(`domain ${domainId} must be an object`); continue; }
    if (!registry.roles?.[domain.subjectRole]) fail(`domain ${domainId} references unknown subjectRole ${domain.subjectRole}`);
    if (domain.implementationRole) {
      if (!registry.roles?.[domain.implementationRole]) fail(`domain ${domainId} references unknown implementationRole ${domain.implementationRole}`);
      else if (registry.roles[domain.implementationRole].mode !== 'write') fail(`domain ${domainId} implementationRole must be write-capable`);
    }
    if (domain.minimumRisk && !registry.riskOrder.includes(domain.minimumRisk)) fail(`domain ${domainId} has invalid minimumRisk ${domain.minimumRisk}`);
  }

  for (const [intentId, intent] of Object.entries(registry.intents || {})) {
    if (!registry.workflows?.[intent.workflow]) fail(`intent ${intentId} references unknown workflow ${intent.workflow}`);
    if (intent.role && !registry.roles?.[intent.role]) fail(`intent ${intentId} references unknown role ${intent.role}`);
    if (intent.execution && intent.execution !== 'domain-write') fail(`intent ${intentId} has unsupported execution ${intent.execution}`);
    for (const [domainId, roleId] of Object.entries(intent.domainRoleOverrides || {})) {
      if (!registry.domains?.[domainId]) fail(`intent ${intentId} override references unknown domain ${domainId}`);
      if (!registry.roles?.[roleId]) fail(`intent ${intentId} override references unknown role ${roleId}`);
    }
    for (const skillId of intent.skills || []) if (!registry.skills?.[skillId]) fail(`intent ${intentId} references unknown skill ${skillId}`);
  }

  for (const [flagId, flag] of Object.entries(registry.flags || {})) {
    for (const skillId of flag.skills || []) if (!registry.skills?.[skillId]) fail(`flag ${flagId} references unknown skill ${skillId}`);
    for (const reviewerId of flag.reviewers || []) {
      if (!registry.roles?.[reviewerId]) fail(`flag ${flagId} references unknown reviewer ${reviewerId}`);
      else if (registry.roles[reviewerId].mode !== 'read') fail(`flag ${flagId} reviewer ${reviewerId} must be read-only`);
    }
    if (flag.minimumRisk && !registry.riskOrder.includes(flag.minimumRisk)) fail(`flag ${flagId} has invalid minimumRisk ${flag.minimumRisk}`);
  }

  for (const [risk, reviewerIds] of Object.entries(registry.riskReviewers || {})) {
    if (!registry.riskOrder.includes(risk)) fail(`unknown riskReviewers level ${risk}`);
    for (const reviewerId of reviewerIds) {
      if (!registry.roles?.[reviewerId]) fail(`risk ${risk} references unknown reviewer ${reviewerId}`);
      else if (registry.roles[reviewerId].mode !== 'read') fail(`risk ${risk} reviewer ${reviewerId} must be read-only`);
    }
  }

  const samples = [
    ['implement', 'frontend', ['ui', 'responsive'], 'low'],
    ['redesign', 'pdp', [], 'low'],
    ['change', 'orders', ['queue-identity'], 'low'],
    ['implement', 'integrations', ['provider'], 'low'],
    ['context-maintenance', 'ai-governance', [], 'low']
  ];
  console.log('DTB AI context profile:');
  for (const [intent, domain, flags, risk] of samples) {
    try {
      const resolved = resolveTask({ intent, domain, flags, risk }, registry);
      if (resolved.context.includes('.agents/README.md')) fail(`${intent}/${domain}: README must not be in ordinary resolved context`);
      const bytes = resolved.context.filter(exists).reduce((sum, file) => sum + size(file), 0);
      console.log(`- ${intent}/${domain}${flags.length ? ` [${flags.join(',')}]` : ''}: ${resolved.context.length} files, ${bytes} bytes, ${resolved.skills.length} skills, ${resolved.reviewers.length} reviewers`);
      if (bytes > 75000) warn(`${intent}/${domain}: resolved canonical context is ${bytes} bytes; review for accidental bloat`);
    } catch (error) { fail(`${intent}/${domain}: ${error.message}`); }
  }
}

const adapterFiles = ['CLAUDE.md', '.codex/README.md', '.github/copilot-instructions.md', ...walk('.claude/agents').filter((p) => p.endsWith('.md')), ...walk('.codex/agents').filter((p) => p.endsWith('.toml'))].filter(exists);
for (const file of adapterFiles) {
  const text = read(file);
  if (!text.includes('.agents/')) fail(`${file}: adapter must point to canonical .agents/ knowledge`);
  if (/source precedence/i.test(text)) fail(`${file}: adapter must not redefine source precedence`);
}

for (const contextFile of walk('.agents/context').filter((p) => p.endsWith('.md'))) {
  const text = read(contextFile);
  for (const key of ['status', 'owner', 'scope', 'source_paths', 'review_triggers']) if (!new RegExp(`^${key}:`, 'm').test(frontmatter(text))) fail(`${contextFile}: missing ${key} metadata`);
}

for (const taskPath of walk('docs/work').filter((p) => p.endsWith('/task.json'))) {
  try {
    const manifest = JSON.parse(read(taskPath));
    for (const error of validateTaskManifest(manifest, registry)) fail(`${taskPath}: ${error}`);
    const taskDir = path.posix.dirname(taskPath);
    if (manifest.taskId !== path.posix.basename(taskDir)) fail(`${taskPath}: taskId must match containing directory`);
    for (const file of ['brief.md', 'evidence.md', 'verification.md']) if (!exists(path.posix.join(taskDir, file))) fail(`${taskDir}: missing ${file}`);
  } catch (error) { fail(`${taskPath}: invalid task manifest: ${error.message}`); }
}

const loaderPath = 'drywalltoolbox/wp/wp-content/mu-plugins/00-dtb-loader.php';
if (exists(loaderPath)) {
  const loader = read(loaderPath);
  const agents = read('AGENTS.md');
  const modules = [...loader.matchAll(/\/(dtb-[a-z0-9-]+)\/bootstrap\.php/gi)].map((m) => m[1]);
  for (const moduleName of [...new Set(modules)]) if (!agents.includes(`\`${moduleName}\``)) fail(`AGENTS.md: missing active MU-plugin module ${moduleName}`);
}

for (const forbidden of ['TODO_dtb-seo.md', 'TODO_refactoring-expert.md', 'progress.md']) if (exists(forbidden)) fail(`${forbidden}: global mutable AI task-state file is prohibited; use docs/work/<task-id>/`);

if (warnings.length) {
  console.warn('\nDTB AI context warnings:');
  for (const item of warnings) console.warn(`- ${item}`);
}
if (failures.length) {
  console.error('\nDTB AI context validation failed:');
  for (const item of failures) console.error(`- ${item}`);
  process.exit(1);
}
console.log(`DTB AI context validation passed (${canonicalFiles.length} canonical files, ${seenSkillNames.size} SKILL.md files checked, ${adapterFiles.length} adapters checked).`);

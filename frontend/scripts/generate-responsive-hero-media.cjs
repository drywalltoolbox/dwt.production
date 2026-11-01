'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const sharp = require('sharp');

const FRONTEND_ROOT = path.resolve(__dirname, '..');
const CONFIG_PATH = path.join(__dirname, 'responsive-hero-media.json');
const OUTPUT_DIRECTORY = path.join(FRONTEND_ROOT, 'src/assets/media/generated/heroes');
const MANIFEST_PATH = path.join(FRONTEND_ROOT, 'src/assets/media/responsiveHeroMedia.js');
const PRELOAD_MANIFEST_PATH = path.join(FRONTEND_ROOT, 'src/assets/media/responsiveHeroPreload.json');
const FINGERPRINT_PATH = path.join(OUTPUT_DIRECTORY, '.responsive-hero-media-fingerprint');
const LOCK_PATH = path.join(OUTPUT_DIRECTORY, '.responsive-hero-media.lock');

function toKebabCase(value) {
  return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`).replace(/^-/, '');
}

function importName(setId, suffix) {
  return `hero${setId[0].toUpperCase()}${setId.slice(1)}${suffix}`;
}

function jsString(value) {
  return JSON.stringify(value);
}

function resolveWithinFrontend(relativePath, label) {
  if (typeof relativePath !== 'string' || relativePath.trim() === '' || path.isAbsolute(relativePath)) {
    throw new Error(`${label} must be a non-empty relative path inside the frontend workspace.`);
  }
  const resolved = path.resolve(FRONTEND_ROOT, relativePath);
  const relative = path.relative(FRONTEND_ROOT, resolved);
  if (relative === '' || relative.startsWith('..') || path.isAbsolute(relative)) {
    throw new Error(`${label} resolves outside the frontend workspace: ${relativePath}`);
  }
  return resolved;
}

function processExists(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    return error.code === 'EPERM';
  }
}

async function acquireLock() {
  await fs.mkdir(OUTPUT_DIRECTORY, { recursive: true });
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      await fs.writeFile(LOCK_PATH, JSON.stringify({ pid: process.pid, startedAt: new Date().toISOString() }), { flag: 'wx' });
      return async () => {
        try {
          const owner = JSON.parse(await fs.readFile(LOCK_PATH, 'utf8'));
          if (owner.pid === process.pid) await fs.unlink(LOCK_PATH);
        } catch (error) {
          if (error.code !== 'ENOENT') throw error;
        }
      };
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
      let owner = null;
      let ageMs = 0;
      try {
        owner = JSON.parse(await fs.readFile(LOCK_PATH, 'utf8'));
        ageMs = Date.now() - (await fs.stat(LOCK_PATH)).mtimeMs;
      } catch (readError) {
        if (readError.code !== 'ENOENT' && readError instanceof SyntaxError) {
          ageMs = Date.now() - (await fs.stat(LOCK_PATH)).mtimeMs;
        } else if (readError.code !== 'ENOENT') {
          throw readError;
        }
      }
      if (owner && processExists(owner.pid)) {
        throw new Error(`Responsive hero generation is already running (pid ${owner.pid}).`);
      }
      if (!owner && ageMs < 60_000) {
        throw new Error('Responsive hero generator lock is initializing; retry after a moment.');
      }
      await fs.unlink(LOCK_PATH).catch((unlinkError) => {
        if (unlinkError.code !== 'ENOENT') throw unlinkError;
      });
    }
  }
  throw new Error('Could not acquire the responsive hero generator lock.');
}

async function writeAtomic(destination, write) {
  await fs.mkdir(path.dirname(destination), { recursive: true });
  const temporary = `${destination}.${process.pid}.${crypto.randomBytes(6).toString('hex')}.tmp${path.extname(destination)}`;
  try {
    await write(temporary);
    await fs.rename(temporary, destination);
  } catch (error) {
    await fs.unlink(temporary).catch(() => {});
    throw error;
  }
}

function validateConfig(config) {
  if (!config || !Array.isArray(config.widths) || !config.sets || typeof config.sets !== 'object') {
    throw new Error('Responsive hero config requires widths and source sets.');
  }
  if (!config.webp || !Number.isInteger(config.webp.effort) || config.webp.effort < 0 || config.webp.effort > 6) {
    throw new Error('Responsive hero WebP effort must be an integer from 0 through 6.');
  }
  if (config.webp.quality !== undefined && (!Number.isInteger(config.webp.quality) || config.webp.quality < 1 || config.webp.quality > 100)) {
    throw new Error('Responsive hero WebP quality must be an integer from 1 through 100.');
  }
  const widths = config.widths;
  if (widths.some((width) => !Number.isInteger(width) || width <= 0) || new Set(widths).size !== widths.length) {
    throw new Error('Responsive hero widths must be unique positive integers.');
  }
  if (widths.some((width, index) => index > 0 && width <= widths[index - 1])) {
    throw new Error('Responsive hero widths must be in strictly ascending order.');
  }
  if (!config.preload?.group || !config.groups?.[config.preload.group] || !config.mobileMediaQuery?.trim() || !config.preload.desktopMedia?.trim()) {
    throw new Error('Responsive hero config requires valid preload and media-query settings.');
  }
  const setIds = new Set(Object.keys(config.sets));
  if (setIds.size === 0 || [...setIds].some((setId) => !/^[A-Za-z][A-Za-z0-9]*$/.test(setId))) {
    throw new Error('Responsive hero set IDs must be unique JavaScript identifiers.');
  }
  const groups = Object.values(config.groups || {});
  for (const group of groups) {
    for (const setId of Object.values(group)) {
      if (!setIds.has(setId)) throw new Error(`Responsive hero group references unknown set: ${setId}`);
    }
  }
  for (const setId of config.singleAssetSets || []) {
    if (!setIds.has(setId)) throw new Error(`singleAssetSets references unknown set: ${setId}`);
  }
  for (const setId of config.webp.losslessSets || []) {
    if (!setIds.has(setId)) throw new Error(`losslessSets references unknown set: ${setId}`);
  }
  const sourcePaths = new Set(Object.entries(config.sets)
    .map(([setId, sourcePath]) => resolveWithinFrontend(sourcePath, `sets.${setId}`)));
  const sourceOutputs = new Set();
  for (const [setId, outputPath] of Object.entries(config.sourceWebpOutputs || {})) {
    if (!setIds.has(setId)) throw new Error(`sourceWebpOutputs references unknown set: ${setId}`);
    if (path.extname(outputPath).toLowerCase() !== '.webp') {
      throw new Error(`sourceWebpOutputs.${setId} must use the .webp extension.`);
    }
    const resolvedOutput = resolveWithinFrontend(outputPath, `sourceWebpOutputs.${setId}`);
    if (sourcePaths.has(resolvedOutput)) throw new Error(`sourceWebpOutputs.${setId} cannot overwrite a source image.`);
    if (sourceOutputs.has(resolvedOutput)) throw new Error(`Multiple sets cannot write ${outputPath}.`);
    sourceOutputs.add(resolvedOutput);
  }
}

async function getOrientedDimensions(sourcePath) {
  const metadata = await sharp(sourcePath, { failOn: 'warning', limitInputPixels: 100_000_000 }).metadata();
  const rotated = metadata.orientation >= 5 && metadata.orientation <= 8;
  return {
    width: rotated ? metadata.height : metadata.width,
    height: rotated ? metadata.width : metadata.height,
    format: metadata.format,
  };
}

async function generateSet(config, setId, relativeSource) {
  const sourcePath = resolveWithinFrontend(relativeSource, `sets.${setId}`);
  const { width: sourceWidth, height: sourceHeight, format } = await getOrientedDimensions(sourcePath);
  const sourceWebpPath = format === 'webp'
    ? sourcePath
    : config.sourceWebpOutputs?.[setId]
      ? resolveWithinFrontend(config.sourceWebpOutputs[setId], `sourceWebpOutputs.${setId}`)
      : path.join(OUTPUT_DIRECTORY, `${toKebabCase(setId)}-source.webp`);

  if (format !== 'webp') {
    await writeAtomic(sourceWebpPath, (temporary) => sharp(sourcePath, { failOn: 'warning', limitInputPixels: 100_000_000 })
      .rotate()
      .webp({ lossless: true, effort: config.webp.effort, alphaQuality: 100 })
      .toFile(temporary));
  }

  const candidates = [];
  const singleAssetSets = new Set(config.singleAssetSets || []);
  const losslessSets = new Set(config.webp.losslessSets || []);
  const derivativeWebpOptions = losslessSets.has(setId)
    ? { effort: config.webp.effort, lossless: true, alphaQuality: 100 }
    : Object.fromEntries(Object.entries(config.webp).filter(([key]) => key !== 'losslessSets'));
  const widths = singleAssetSets.has(setId) ? [] : config.widths;
  for (const width of widths) {
    if (width >= sourceWidth) continue;
    const filename = `${toKebabCase(setId)}-${width}.webp`;
    const outputPath = path.join(OUTPUT_DIRECTORY, filename);
    await writeAtomic(outputPath, (temporary) => sharp(sourcePath, { failOn: 'warning', limitInputPixels: 100_000_000 })
      .rotate()
      .resize({ width, withoutEnlargement: true, fit: 'inside', kernel: 'lanczos3' })
      .webp(derivativeWebpOptions)
      .toFile(temporary));
    candidates.push({ filename, width });
  }

  return {
    id: setId,
    sourceImport: `./${path.relative(path.dirname(MANIFEST_PATH), sourceWebpPath).replaceAll(path.sep, '/')}`,
    sourceFilename: path.relative(path.dirname(MANIFEST_PATH), sourceWebpPath).replaceAll(path.sep, '/'),
    sourceWidth,
    sourceHeight,
    candidates,
  };
}

function buildManifest(generatedSets, groups, mobileMediaQuery) {
  const imports = [];
  const definitions = [];

  for (const set of generatedSets) {
    const sourceIdentifier = importName(set.id, 'Source');
    imports.push(`import ${sourceIdentifier} from ${jsString(set.sourceImport)};`);
    const variantIdentifiers = set.candidates.map(({ filename, width }) => {
      const identifier = importName(set.id, `W${width}`);
      imports.push(`import ${identifier} from ${jsString(`./generated/heroes/${filename}`)};`);
      return `${identifier} + ' ${width}w'`;
    });
    variantIdentifiers.push(`${sourceIdentifier} + ' ${set.sourceWidth}w'`);
    definitions.push(
      `  ${set.id}: Object.freeze({ src: ${sourceIdentifier}, srcSet: [${variantIdentifiers.join(', ')}].join(', '), width: ${set.sourceWidth}, height: ${set.sourceHeight} }),`,
    );
  }

  const groupsBlock = Object.entries(groups).map(([groupId, group]) => {
    const lanes = Object.entries(group).map(([lane, setId]) => `${lane}: imageSets.${setId}`).join(', ');
    return `  ${groupId}: Object.freeze({ ${lanes} }),`;
  }).join('\n');

  return [
    '/* This file is generated by scripts/generate-responsive-hero-media.cjs. */',
    ...imports,
    '',
    'export const RESPONSIVE_HERO_MEDIA_QUERY = ' + jsString(mobileMediaQuery) + ';',
    'const imageSets = Object.freeze({',
    ...definitions,
    '});',
    '',
    'export const RESPONSIVE_HERO_MEDIA = Object.freeze({',
    groupsBlock,
    '});',
    '',
    'export function selectHeroImageSet(media, mobile = false) {',
    '  return (mobile && media.mobile ? media.mobile : media.desktop) || null;',
    '}',
    '',
    'export default RESPONSIVE_HERO_MEDIA;',
    '',
  ].join('\n');
}

async function main() {
  const config = JSON.parse(await fs.readFile(CONFIG_PATH, 'utf8'));
  validateConfig(config);
  const releaseLock = await acquireLock();
  try {
    await generateAll(config);
  } finally {
    await releaseLock();
  }
}

async function generateAll(config) {
  const singleAssetSets = new Set(config.singleAssetSets || []);
  await fs.mkdir(OUTPUT_DIRECTORY, { recursive: true });

  const fingerprintHash = crypto.createHash('sha256');
  fingerprintHash.update(await fs.readFile(CONFIG_PATH));
  fingerprintHash.update(sharp.versions.sharp || 'sharp');
  const expectedOutputs = [MANIFEST_PATH, PRELOAD_MANIFEST_PATH];
  for (const [setId, relativeSource] of Object.entries(config.sets)) {
    const sourcePath = resolveWithinFrontend(relativeSource, `sets.${setId}`);
    fingerprintHash.update(await fs.readFile(sourcePath));
    const metadata = await getOrientedDimensions(sourcePath);
    if (metadata.format !== 'webp') {
      expectedOutputs.push(config.sourceWebpOutputs?.[setId]
        ? resolveWithinFrontend(config.sourceWebpOutputs[setId], `sourceWebpOutputs.${setId}`)
        : path.join(OUTPUT_DIRECTORY, `${toKebabCase(setId)}-source.webp`));
    }
    const widths = singleAssetSets.has(setId) ? [] : config.widths;
    for (const width of widths) {
      if (width < metadata.width) {
        expectedOutputs.push(path.join(OUTPUT_DIRECTORY, `${toKebabCase(setId)}-${width}.webp`));
      }
    }
  }
  const fingerprint = fingerprintHash.digest('hex');
  const existingFingerprint = await fs.readFile(FINGERPRINT_PATH, 'utf8').catch(() => '');
  const outputFilesExist = await Promise.all(expectedOutputs.map((file) => fs.access(file).then(() => true, () => false)));
  if (existingFingerprint === fingerprint && outputFilesExist.every(Boolean)) {
    console.log('Responsive hero WebP assets are current.');
    return;
  }

  const generatedSets = [];
  for (const [setId, source] of Object.entries(config.sets)) {
    generatedSets.push(await generateSet(config, setId, source));
  }

  const manifest = buildManifest(generatedSets, config.groups, config.mobileMediaQuery);
  await writeAtomic(MANIFEST_PATH, (temporary) => fs.writeFile(temporary, manifest, 'utf8'));
  const sets = Object.fromEntries(generatedSets.map((set) => [set.id, {
    sourceFilename: set.sourceFilename,
    sourceWidth: set.sourceWidth,
    candidates: set.candidates,
  }]));
  await writeAtomic(PRELOAD_MANIFEST_PATH, (temporary) => fs.writeFile(temporary, `${JSON.stringify({
    mobileMediaQuery: config.mobileMediaQuery,
    desktopMediaQuery: config.preload.desktopMedia,
    group: config.preload.group,
    groups: config.groups,
    sets,
  }, null, 2)}\n`, 'utf8'));

  const expectedWebpOutputs = new Set(expectedOutputs.map((file) => path.resolve(file)));
  const ownedPrefixes = Object.keys(config.sets).map((setId) => `${toKebabCase(setId)}-`);
  const generatedFiles = await fs.readdir(OUTPUT_DIRECTORY, { withFileTypes: true });
  for (const entry of generatedFiles) {
    if (!entry.isFile() || !entry.name.toLowerCase().endsWith('.webp')) continue;
    if (!ownedPrefixes.some((prefix) => entry.name.startsWith(prefix))) continue;
    const generatedPath = path.resolve(OUTPUT_DIRECTORY, entry.name);
    if (!expectedWebpOutputs.has(generatedPath)) await fs.unlink(generatedPath);
  }

  await writeAtomic(FINGERPRINT_PATH, (temporary) => fs.writeFile(temporary, fingerprint, 'utf8'));
  console.log(`Generated ${generatedSets.reduce((total, set) => total + set.candidates.length, 0)} responsive WebP variants across ${generatedSets.length} source images.`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

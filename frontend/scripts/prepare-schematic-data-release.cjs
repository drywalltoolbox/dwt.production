const fs = require('node:fs');
const path = require('node:path');

const sourceRoot = path.resolve(__dirname, '..', 'public', 'brands');
const outputRoot = path.resolve(__dirname, '..', 'dist', 'brands');
const excludedNames = new Set(['schematic_data.schema.json', 'schematic_data_template.json']);
const allowedName = /^schematic_data.*\.json$/i;

if (!fs.existsSync(sourceRoot) || !fs.statSync(sourceRoot).isDirectory()) {
  throw new Error(`Schematic source directory is missing: ${sourceRoot}`);
}
if (fs.existsSync(outputRoot)) {
  throw new Error(`Refusing to replace existing build brands directory: ${outputRoot}`);
}

const files = [];

function collect(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    const absolute = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) {
      throw new Error(`Symlinks are not allowed in schematic source data: ${absolute}`);
    }
    if (entry.isDirectory()) {
      collect(absolute);
      continue;
    }
    if (!entry.isFile() || !allowedName.test(entry.name) || excludedNames.has(entry.name)) continue;

    const relative = path.relative(sourceRoot, absolute);
    JSON.parse(fs.readFileSync(absolute, 'utf8'));
    files.push({ absolute, relative });
  }
}

collect(sourceRoot);
if (files.length === 0) {
  throw new Error(`No schematic_data*.json source files found under ${sourceRoot}`);
}

for (const { absolute, relative } of files) {
  const destination = path.join(outputRoot, relative);
  fs.mkdirSync(path.dirname(destination), { recursive: true });
  fs.copyFileSync(absolute, destination);
}

console.log(`Prepared ${files.length} validated schematic data files for the production release.`);

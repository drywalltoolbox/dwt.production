const { spawnSync } = require('node:child_process');
const path = require('node:path');

const command = process.platform === 'win32' ? 'python' : 'python3';
const generator = path.resolve(__dirname, '../../scripts/catalog/generate_repair_service_catalog.py');
const result = spawnSync(command, [generator], { stdio: 'inherit' });

if (result.error) {
  console.error(`Could not run the repair catalog generator with ${command}: ${result.error.message}`);
  process.exit(1);
}
process.exit(result.status ?? 1);

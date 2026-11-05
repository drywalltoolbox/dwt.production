const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const rendererPath = 'drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/ProductMappingRenderer.php';
const renderer = fs.readFileSync(rendererPath, 'utf8');
const scriptPath = 'drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/assets/dtb-product-relationships.js';
const script = fs.readFileSync(scriptPath, 'utf8');
assert.match(renderer, /dtb_product_mapping_enqueue_assets/);
assert.match(renderer, /dtb-product-relationships\.js/);
new vm.Script(script);
const match = script.match(/function summarizeAudit\(rows\)\{[\s\S]*?return stats\}/);
assert.ok(match, 'The relationship audit summary function must exist.');
const summarizeAudit = vm.runInNewContext(`${match[0]}; summarizeAudit`);
const findingMatch = script.match(/function collectAuditFindings\(rows,limit\)\{[\s\S]*?return\{items:items,total:total,truncated:total>items.length\}\}/);
assert.ok(findingMatch, 'The relationship audit must produce bounded review findings.');
const collectAuditFindings = vm.runInNewContext(`${findingMatch[0]}; collectAuditFindings`);
const visibilitySummaryMatch = script.match(/function summarizeCatalogVisibility\(rows,stats\)\{[\s\S]*?return stats\}/);
assert.ok(visibilitySummaryMatch, 'The relationship audit must summarize public catalog visibility separately from fitment coverage.');
const summarizeCatalogVisibility = vm.runInNewContext(`${visibilitySummaryMatch[0]}; summarizeCatalogVisibility`);
const visibilityFindingsMatch = script.match(/function collectCatalogVisibilityFindings\(rows,limit\)\{[\s\S]*?return\{items:items,total:total,truncated:total>items.length\}\}/);
assert.ok(visibilityFindingsMatch, 'The relationship audit must identify hidden fitment and merchandising targets.');
const collectCatalogVisibilityFindings = vm.runInNewContext(`${visibilityFindingsMatch[0]}; collectCatalogVisibilityFindings`);
const candidateMatch = script.match(/function getAvailableCrossSellCandidates\(items,upsellIds,crosssellIds\)\{[\s\S]*?\n/);
assert.ok(candidateMatch, 'Fitment suggestions must apply the explicit merchandising and availability filters.');
const getAvailableCrossSellCandidates = vm.runInNewContext(`${candidateMatch[0]}; getAvailableCrossSellCandidates`);
assert.match(script, /\/compatible-parts\?limit=100/, 'The workbench must request the API maximum so current exact-fitment candidate sets are not silently clipped at 25.');

const rows = [
	{ id: 1, post_type: 'product', status: 'publish', is_part: true, fields: { sku: 'PART-1', compatible_tool_skus: ['VAR-1', 'VAR-BAD', 'VAR-MISMATCH', 'PART-1', ' TOOL-1 ', '', '[invalid:json]', '[invalid:json_object]'], legacy_compatible_tools: [404], upsell_ids: [1, 999, 4, 4], crosssell_ids: [4] } },
	{ id: 2, post_type: 'product', status: 'draft', is_part: true, fields: { sku: 'DRAFT-PART', compatible_tool_skus: ['TOOL-1'] } },
	{ id: 3, post_type: 'product_variation', status: 'publish', is_part: false, parent_id: 4, parent_sku: 'TOOL-1', parent_status: 'publish', parent_is_part: false, fields: { sku: 'VAR-1', compatible_tool_skus: ['TOOL-1'] } },
	{ id: 4, post_type: 'product', status: 'publish', is_part: false, fields: { sku: 'TOOL-1', compatible_tool_skus: ['PART-1'] } },
	{ id: 5, post_type: 'product_variation', status: 'publish', is_part: false, parent_id: 4, parent_sku: 'OTHER-TOOL', parent_status: 'publish', parent_is_part: false, fields: { sku: 'VAR-BAD', compatible_tool_skus: [] } },
	{ id: 6, post_type: 'product_variation', status: 'publish', is_part: false, parent_id: 4, parent_sku: 'PART-1', parent_status: 'publish', parent_is_part: false, fields: { sku: 'VAR-MISMATCH', compatible_tool_skus: [] } },
];
rows[0].catalog_visibility = 'hidden';
rows[2].catalog_visibility = 'hidden';
rows[3].catalog_visibility = 'hidden';
const summary = summarizeCatalogVisibility(rows, summarizeAudit(rows));

assert.equal(summary.published_parts, 1);
assert.equal(summary.published_parts_hidden_from_catalog, 1);
assert.equal(summary.published_parts_with_catalog_visibility, 0);
assert.equal(summary.parts_with_structured_fitment, 1);
assert.equal(summary.nonpublished_part_fitment_owners, 1);
assert.equal(summary.variation_fitment_owners, 1);
assert.equal(summary.non_part_fitment_owners, 1);
assert.equal(summary.fitment_self_links, 1);
assert.equal(summary.variation_fitment_targets, 3);
assert.equal(summary.invalid_variation_parent_targets, 2);
assert.equal(summary.fitment_target_whitespace, 1);
assert.equal(summary.invalid_fitment_sku_values, 3);
assert.equal(summary.nonexact_fitment_target_skus, 1);
const findings = collectAuditFindings(rows, 100);
assert.ok(findings.items.some((item) => item.relationship === 'compatible_tool_skus' && item.problem.includes('exactly match')));
assert.ok(findings.items.some((item) => item.relationship === 'legacy product ID' && item.problem.includes('does not resolve')));
const visibilityFindings = collectCatalogVisibilityFindings(rows, 100).items;
assert.ok(visibilityFindings.some((item) => item.source_sku === 'PART-1' && item.target === 'VAR-1' && item.problem.includes('public compatibility lookup omits')));
assert.ok(visibilityFindings.some((item) => item.relationship === 'upsell' && item.target === 'TOOL-1' && item.problem.includes('customer access')));
assert.ok(findings.items.some((item) => item.relationship === 'upsell/cross-sell' && item.problem.includes('both directional')));
assert.ok(findings.items.some((item) => item.relationship === 'cross-sell' && item.problem.includes('self-link')) === false);
assert.equal(true, collectAuditFindings(rows, 2).truncated); console.log('Product relationship audit ownership summary checks passed.');
const candidates = getAvailableCrossSellCandidates([
	{ id: 11, sku: 'PART-11', inventory: { stockStatus: 'instock', purchasable: true } },
	{ id: 11, sku: 'PART-11-DUP', inventory: { stockStatus: 'instock', purchasable: true } },
	{ id: 12, sku: 'ALREADY-UPSELL', inventory: { stockStatus: 'instock', purchasable: true } },
	{ id: 13, sku: 'ALREADY-CROSSSELL', inventory: { stockStatus: 'instock', purchasable: true } },
	{ id: 14, sku: 'OUT-OF-STOCK', inventory: { stockStatus: 'outofstock', purchasable: true } },
	{ id: 15, sku: 'NOT-PURCHASABLE', inventory: { stockStatus: 'instock', purchasable: false } },
	{ id: 0, sku: 'INVALID-ID', inventory: { stockStatus: 'instock', purchasable: true } },
	{ id: 16, sku: '', inventory: { stockStatus: 'instock', purchasable: true } },
], [12], [13]);
assert.deepEqual(Array.from(candidates, (item) => item.sku), ['PART-11']);
assert.equal(getAvailableCrossSellCandidates(null, [], []).length, 0);
console.log('Product relationship audit ownership summary checks passed.');

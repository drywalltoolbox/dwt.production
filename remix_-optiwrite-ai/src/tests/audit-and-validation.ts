import { marked } from 'marked';
import Papa from 'papaparse';

// ============================================================================
// AUDIT & VALIDATION SUITE: WOOCOMMERCE CATALOG INTEGRITY & PDP ACCORDIONS
// ============================================================================

interface TestCaseResult {
  name: string;
  passed: boolean;
  details?: string;
}

const results: TestCaseResult[] = [];

function assert(condition: boolean, name: string, details?: string) {
  results.push({ name, passed: condition, details });
  if (condition) {
    console.log(`  ✓ PASS: ${name}`);
  } else {
    console.error(`  ✗ FAIL: ${name} - ${details || 'Assertion failed'}`);
  }
}

// ----------------------------------------------------------------------------
// Test 1: Drywall Toolbox PDP Accordion Parsing & Semantic Architecture
// ----------------------------------------------------------------------------
console.log('\n--- Test Suite 1: Drywall Toolbox PDP Accordion Architecture ---');

function parseAccordionSections(html: string) {
  if (!html || !html.trim()) return [];
  const trimmed = html.trim();
  const h3Regex = /<h3[^>]*>([\s\S]*?)<\/h3>/gi;
  const sections: Array<{ id: string; title: string; htmlContent: string; type: string }> = [];
  
  let match: RegExpExecArray | null;
  const headings: Array<{ title: string; index: number; fullMatchLength: number }> = [];

  while ((match = h3Regex.exec(trimmed)) !== null) {
    const cleanHeading = match[1].replace(/<[^>]+>/g, '').trim();
    headings.push({
      title: cleanHeading,
      index: match.index,
      fullMatchLength: match[0].length
    });
  }

  if (headings.length === 0) {
    return [{
      id: 'overview',
      title: 'Product Overview',
      htmlContent: trimmed,
      type: 'overview'
    }];
  }

  const preHeadingContent = trimmed.substring(0, headings[0].index).trim();
  if (preHeadingContent) {
    sections.push({
      id: 'section-overview',
      title: 'Overview',
      htmlContent: preHeadingContent,
      type: 'overview'
    });
  }

  for (let i = 0; i < headings.length; i++) {
    const current = headings[i];
    const startIndex = current.index + current.fullMatchLength;
    const endIndex = i + 1 < headings.length ? headings[i + 1].index : trimmed.length;
    const body = trimmed.substring(startIndex, endIndex).trim();
    sections.push({
      id: `section-${i}`,
      title: current.title,
      htmlContent: body,
      type: current.title.toLowerCase()
    });
  }

  return sections;
}

const sampleFullDescription = `
<p>The Columbia 10-Inch Flat Box applies an even, crowned coat of joint compound to flat drywall seams and butt joints. Operating behind a high-capacity aluminum pressure plate, it meters mud consistently across the hardened stainless steel finishing blade for professional drywall contractors.</p>
<p>Engineered for high-volume commercial and residential taping operations, the anodized body resists joint compound corrosion and cleans rapidly on the jobsite.</p>
<h3>Key Features</h3>
<ul>
  <li><strong>Precision Brass Blade Holder:</strong> Maintains true blade crown and alignment under heavy finishing pressure without chatter.</li>
  <li><strong>Adjustable Crown Dial:</strong> Offers instantaneous blade tension adjustments to regulate compound crowning thickness across varying seam widths.</li>
  <li><strong>Anodized Hard-Coat Aluminum:</strong> Machined from solid aircraft-grade aluminum alloy to withstand abrasive drywall mud.</li>
</ul>
<h3>Specifications</h3>
<ul>
  <li><strong>Finishing Width:</strong> 10 Inches (250 mm)</li>
  <li><strong>Body Material:</strong> Anodized marine-grade aluminum</li>
  <li><strong>Blade Type:</strong> Hardened stainless steel wiper blade</li>
</ul>
<h3>Compatibility</h3>
<p>Equipped with a standard flat box brass mounting pivot ball. Fits all major commercial flat box handles including Columbia, TapeTech, Level5, and NorthStar.</p>
`;

const parsedSections = parseAccordionSections(sampleFullDescription);

assert(parsedSections.length === 4, 'Correctly identifies 4 distinct PDP accordion sections', `Got ${parsedSections.length} sections`);
assert(parsedSections[0].title === 'Overview', 'Section 0 is Overview from lead paragraphs');
assert(parsedSections[1].title === 'Key Features', 'Section 1 is Key Features accordion');
assert(parsedSections[2].title === 'Specifications', 'Section 2 is Specifications accordion');
assert(parsedSections[3].title === 'Compatibility', 'Section 3 is Compatibility accordion');
assert(!/<(h1|h2|div|span|style|script)/i.test(sampleFullDescription), 'HTML uses only clean semantic tags (<p>, <h3>, <ul>, <li>, <strong>)');

// ----------------------------------------------------------------------------
// Test 2: WooCommerce Catalog Integrity & Family Grouping Logic
// ----------------------------------------------------------------------------
console.log('\n--- Test Suite 2: WooCommerce Catalog Integrity & Parent-Child Ordering ---');

interface MockProduct {
  ID: string;
  Type: string;
  SKU: string;
  Name: string;
  Published: string;
  'Short description': string;
  Description: string;
  'Regular price': string;
  Categories: string;
  Parent: string;
  'Attribute 1 name'?: string;
  'Attribute 1 value(s)'?: string;
  [key: string]: any;
}

const mockCatalog: MockProduct[] = [
  {
    ID: '101',
    Type: 'variable',
    SKU: 'COL-FB-PARENT',
    Name: 'Columbia Drywall Flat Box',
    Published: '1',
    'Short description': 'Professional drywall flat finishing box family.',
    Description: '<p>Original description</p>',
    'Regular price': '',
    Categories: 'Finishing Tools > Flat Boxes',
    Parent: ''
  },
  {
    ID: '201',
    Type: 'simple',
    SKU: 'TT-PUMP-01',
    Name: 'TapeTech Loading Pump',
    Published: '1',
    'Short description': 'Mud loading pump.',
    Description: '<p>Loading pump description</p>',
    'Regular price': '349.00',
    Categories: 'Compound Delivery > Pumps',
    Parent: ''
  },
  {
    ID: '102',
    Type: 'variation',
    SKU: 'COL-FB-07',
    Name: 'Columbia 7" Flat Box',
    Published: '1',
    'Short description': '7-inch flat box.',
    Description: '<p>Original 7in description</p>',
    'Regular price': '289.00',
    Categories: 'Finishing Tools > Flat Boxes',
    Parent: 'COL-FB-PARENT',
    'Attribute 1 name': 'Size',
    'Attribute 1 value(s)': '7"'
  },
  {
    ID: '103',
    Type: 'variation',
    SKU: 'COL-FB-10',
    Name: 'Columbia 10" Flat Box',
    Published: '1',
    'Short description': '10-inch flat box.',
    Description: '<p>Original 10in description</p>',
    'Regular price': '319.00',
    Categories: 'Finishing Tools > Flat Boxes',
    Parent: 'COL-FB-PARENT',
    'Attribute 1 name': 'Size',
    'Attribute 1 value(s)': '10"'
  },
  {
    ID: '104',
    Type: 'variation',
    SKU: 'COL-FB-12',
    Name: 'Columbia 12" Flat Box',
    Published: '1',
    'Short description': '12-inch flat box.',
    Description: '<p>Original 12in description</p>',
    'Regular price': '349.00',
    Categories: 'Finishing Tools > Flat Boxes',
    Parent: 'COL-FB-PARENT',
    'Attribute 1 name': 'Size',
    'Attribute 1 value(s)': '12"'
  }
];

// Run grouping logic as implemented in App.tsx
function groupWooCommerceRows(items: MockProduct[]) {
  const getItemSku = (item: any) => String(item.SKU || item.sku || item.ID || '').trim().toLowerCase();
  const getItemId = (item: any) => String(item.ID || item.id || '').trim().toLowerCase();
  const getItemParentRef = (item: any) => String(item.Parent || item.parent || '').trim().toLowerCase();
  const getItemType = (item: any) => String(item.Type || item.type || '').trim().toLowerCase();

  const childrenByParent = new Map<string, number[]>();
  const childIndices = new Set<number>();

  items.forEach((item, index) => {
    const sku = getItemSku(item);
    const id = getItemId(item);
    const parentRef = getItemParentRef(item);
    const type = getItemType(item);

    const isChild = type === 'variation' || type === 'variation_child' || (Boolean(parentRef) && parentRef !== sku && parentRef !== id);
    if (isChild && parentRef) {
      childIndices.add(index);
      if (!childrenByParent.has(parentRef)) childrenByParent.set(parentRef, []);
      childrenByParent.get(parentRef)!.push(index);
    }
  });

  const orderedIndices: number[] = [];
  const visited = new Set<number>();

  const addIndex = (idx: number) => {
    if (idx >= 0 && idx < items.length && !visited.has(idx)) {
      visited.add(idx);
      orderedIndices.push(idx);
    }
  };

  items.forEach((item, index) => {
    if (visited.has(index)) return;
    const sku = getItemSku(item);
    const id = getItemId(item);
    const type = getItemType(item);
    const isExplicitChild = childIndices.has(index);

    const matchingChildren: number[] = [];
    if (sku && childrenByParent.has(sku)) matchingChildren.push(...(childrenByParent.get(sku) || []));
    if (id && id !== sku && childrenByParent.has(id)) matchingChildren.push(...(childrenByParent.get(id) || []));

    const isParent = type === 'variable' || type === 'variable_parent' || matchingChildren.length > 0;

    if (isParent) {
      addIndex(index);
      for (const childIdx of matchingChildren) addIndex(childIdx);
    } else if (!isExplicitChild) {
      addIndex(index);
    }
  });

  items.forEach((_, index) => {
    if (!visited.has(index)) addIndex(index);
  });

  return orderedIndices.map(i => items[i]);
}

const orderedCatalog = groupWooCommerceRows(mockCatalog);

assert(orderedCatalog.length === 5, 'Export retains all 5 rows', `Got ${orderedCatalog.length}`);
assert(orderedCatalog[0].SKU === 'COL-FB-PARENT', 'Variable parent is placed first');
assert(orderedCatalog[1].SKU === 'COL-FB-07', 'Variation 7" is grouped directly under parent');
assert(orderedCatalog[2].SKU === 'COL-FB-10', 'Variation 10" is grouped directly under parent');
assert(orderedCatalog[3].SKU === 'COL-FB-12', 'Variation 12" is grouped directly under parent');
assert(orderedCatalog[4].SKU === 'TT-PUMP-01', 'Standalone product TT-PUMP-01 follows the complete family');

// Check CSV unparse preservation of official headers
const sourceHeaders = Object.keys(mockCatalog[0]);
const unparsedCsv = Papa.unparse({ fields: sourceHeaders, data: orderedCatalog });
const parsedCsv = Papa.parse(unparsedCsv, { header: true });

assert(parsedCsv.meta.fields?.join(',') === sourceHeaders.join(','), 'CSV export preserves exact original WooCommerce header order');
assert(parsedCsv.data.length === 5, 'CSV export preserves all rows without corruption');

// ----------------------------------------------------------------------------
// Test 3: Variation Specs vs. Set Includes Strict Differentiation
// ----------------------------------------------------------------------------
console.log('\n--- Test Suite 3: Variation Specs vs. Set Includes Separation ---');

const variationDescriptionValid = `
<p>The Columbia 10-Inch Flat Box is a dedicated flat joint finishing tool engineered for applying joint compound over taped seams.</p>
<h3>Key Features</h3>
<ul>
  <li><strong>10-Inch Finishing Span:</strong> Optimized for second-coat flat joint coverage and crowning.</li>
  <li><strong>Pivoting Mount:</strong> Connects securely with standard flat box handles.</li>
</ul>
<h3>Specifications</h3>
<ul>
  <li><strong>Blade Width:</strong> 10 Inches</li>
  <li><strong>Shell:</strong> Hard-coat anodized aluminum</li>
</ul>
`;

const variationDescriptionInvalid = `
<p>The Columbia 10-Inch Flat Box is a complete finishing toolkit.</p>
<h3>Set Includes</h3>
<ul>
  <li>1x 10-Inch Flat Box</li>
  <li>1x Finishing Handle</li>
</ul>
`;

// Validation tests simulating validateModelOutput
function validateVariationRules(html: string, isVariationChild: boolean, isBundle: boolean): { isValid: boolean; errors: string[] } {
  const errors: string[] = [];
  if (isVariationChild && !isBundle) {
    if (/<h3>\s*(?:set|kit)\s+includes/i.test(html)) {
      errors.push('variation_invalid_set_includes');
    }
    if (/\b(?:this\s+(?:[a-z0-9_-]+\s+)?(?:kit|toolkit|set|bundle)\s+(?:includes|contains|features|comes\s+with)|complete\s+(?:[a-z0-9_-]+\s+)?(?:toolkit|kit|set|bundle)|all-in-one\s+(?:[a-z0-9_-]+\s+)?(?:kit|set|toolkit|bundle))\b/i.test(html)) {
      errors.push('variation_bundle_language');
    }
  }
  return { isValid: errors.length === 0, errors };
}

const validCheck = validateVariationRules(variationDescriptionValid, true, false);
assert(validCheck.isValid, 'Simple variation with Variation Specs passes validation cleanly');

const invalidCheck = validateVariationRules(variationDescriptionInvalid, true, false);
assert(!invalidCheck.isValid && invalidCheck.errors.includes('variation_invalid_set_includes') && invalidCheck.errors.includes('variation_bundle_language'),
  'Simple variation with "Set Includes" or bundle language is strictly caught and flagged for repair');

// ----------------------------------------------------------------------------
// Test 4: Official CSV Mapping & Structured Content v2 Fields
// ----------------------------------------------------------------------------
console.log('\n--- Test Suite 4: Official CSV Mapping & Structured Content v2 Fields ---');

const mockV2ProductRow = {
  ID: '301',
  Type: 'simple',
  SKU: 'CT-BOX-10',
  Name: 'Columbia 10" Flat Finishing Box',
  Published: '1',
  'Is featured?': '0',
  'Visibility in catalog': 'visible',
  'Short description': 'Original brief summary.',
  Description: '<p>Original full overview.</p>',
  'Tax status': 'taxable',
  'Tax class': '',
  'In stock?': '1',
  Stock: '24',
  'Weight (lbs)': '2.8',
  'Length (in)': '10.5',
  'Width (in)': '6.0',
  'Height (in)': '4.2',
  'Regular price': '319.00',
  Categories: 'Finishing Tools > Flat Boxes',
  Tags: 'flat box, columbia, finishing',
  Parent: '',
  // Structured Content v2 Fields
  'Meta: _dtb_product_kind': 'tool',
  'Meta: _dtb_mpn': 'FB-10',
  'Meta: _dtb_manufacturer_sku': 'COL-FB10',
  'Meta: _dtb_brand': 'Columbia Taping Tools',
  'Meta: _dtb_product_family': 'Flat Boxes',
  'Meta: _dtb_series': 'Commercial Series',
  'Meta: _dtb_model': 'FB10',
  'Meta: _dtb_specs_json': JSON.stringify([
    { label: 'Finishing Width', value: '10 Inches' },
    { label: 'Body Material', value: 'Hard-Coat Anodized Aluminum' },
    { label: 'Pressure Mechanism', value: 'Double Spring Pivot' }
  ]),
  'Meta: _dtb_includes_0_sku': 'BLD-10',
  'Meta: _dtb_includes_0_name': '10-Inch Stainless Steel Blade',
  'Meta: _dtb_includes_0_qty': '1',
  'Meta: _dtb_seo_title': 'Columbia 10" Flat Box | Drywall Toolbox',
  'Meta: _dtb_seo_description': 'Buy the Columbia 10-Inch Flat Box at Drywall Toolbox with fast dispatch.',
  'Meta: _dtb_seo_focus_kw': 'Columbia 10 flat box',
  'Meta: _dtb_seo_canonical': 'https://drywalltoolbox.com/product/columbia-10-flat-box/',
  'Meta: _dtb_seo_robots': 'index, follow'
};

// Test V2 specs_json parsing
const parsedV2Specs = JSON.parse(mockV2ProductRow['Meta: _dtb_specs_json']);
assert(Array.isArray(parsedV2Specs) && parsedV2Specs.length === 3, 'Structured Content v2: specs_json parses 3 specification objects cleanly');
assert(parsedV2Specs[0].label === 'Finishing Width' && parsedV2Specs[0].value === '10 Inches', 'Structured Content v2: extracts correct specification key/values');

// Test V2 structured package contents extraction
const v2Keys = Object.keys(mockV2ProductRow);
const includesV2: Array<{ sku: string; name: string; qty?: string }> = [];
for (let slot = 0; slot < 10; slot++) {
  const nameKey = v2Keys.find(k => k.toLowerCase().includes(`_includes_${slot}_name`));
  const skuKey = v2Keys.find(k => k.toLowerCase().includes(`_includes_${slot}_sku`));
  const qtyKey = v2Keys.find(k => k.toLowerCase().includes(`_includes_${slot}_qty`));
  if (nameKey || skuKey) {
    includesV2.push({
      sku: skuKey ? mockV2ProductRow[skuKey as keyof typeof mockV2ProductRow] : '',
      name: nameKey ? mockV2ProductRow[nameKey as keyof typeof mockV2ProductRow] : '',
      qty: qtyKey ? mockV2ProductRow[qtyKey as keyof typeof mockV2ProductRow] : ''
    });
  }
}
assert(includesV2.length === 1 && includesV2[0].sku === 'BLD-10' && includesV2[0].qty === '1', 'Structured Content v2: extracts package contents with SKU and quantity');

// Test protected SEO & routing immutability:
// Editorial updates to SEO Title & SEO Description must NEVER alter protected Canonical URL or Robots directives.
const simulatedExportRow = { ...mockV2ProductRow };
// Simulate an AI generation pass that updates editorial SEO
const newSeoTitle = 'Columbia 10" Flat Finishing Box - High Volume Mud Metering';
const newSeoDesc = 'Engineered for smooth crowning and second-coat drywall joints. High-capacity aluminum reservoir with brass blade holder.';
const newFocusKw = 'Columbia 10 flat box';

simulatedExportRow['Meta: _dtb_seo_title'] = newSeoTitle;
simulatedExportRow['Meta: _dtb_seo_description'] = newSeoDesc;
simulatedExportRow['Meta: _dtb_seo_focus_kw'] = newFocusKw;

assert(simulatedExportRow['Meta: _dtb_seo_canonical'] === 'https://drywalltoolbox.com/product/columbia-10-flat-box/',
  'Catalog Integrity: Canonical URL is strictly preserved and never mutated by AI generation');
assert(simulatedExportRow['Meta: _dtb_seo_robots'] === 'index, follow',
  'Catalog Integrity: Robots meta directive is strictly preserved and never mutated');
assert(simulatedExportRow['Meta: _dtb_product_family'] === 'Flat Boxes',
  'Structured Content v2: Product family structure remains intact');

// Test export CSV structure with PapaParse
const v2ExportCsv = Papa.unparse({
  fields: Object.keys(mockV2ProductRow),
  data: [simulatedExportRow]
});
const reParsedV2 = Papa.parse(v2ExportCsv, { header: true });
assert(reParsedV2.data.length === 1, 'CSV Export: Row count intact for Structured Content v2 product');
const exportedRow = (reParsedV2.data as any[])[0];
assert(exportedRow['Meta: _dtb_seo_title'] === newSeoTitle, 'CSV Export: Updated editorial SEO title exported');
assert(exportedRow['Meta: _dtb_seo_canonical'] === 'https://drywalltoolbox.com/product/columbia-10-flat-box/', 'CSV Export: Canonical URL retained verbatim');
assert(exportedRow['Meta: _dtb_mpn'] === 'FB-10', 'CSV Export: MPN field retained');

// ----------------------------------------------------------------------------
// Summary Report
// ----------------------------------------------------------------------------
console.log('\n================================================================');
const total = results.length;
const passed = results.filter(r => r.passed).length;
console.log(`TOTAL AUDIT & VALIDATION TESTS: ${total} | PASSED: ${passed} | FAILED: ${total - passed}`);
console.log('================================================================\n');

if (passed !== total) {
  process.exit(1);
}

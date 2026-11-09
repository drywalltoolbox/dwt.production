export const REPAIR_TOOL_FAMILIES = {
  automatic_taper: {
    label: 'Automatic Tapers',
    categoryHints: [ 'auto taper', 'automatic taper', 'taper' ],
  },
  flat_box: {
    label: 'Flat / Finishing Boxes',
    categoryHints: [ 'flat box', 'finishing box', 'angle box', 'box' ],
  },
  pump: {
    label: 'Pumps',
    categoryHints: [ 'pump' ],
  },
  corner_finisher: {
    label: 'Corner Finishers',
    categoryHints: [ 'corner finisher', 'angle head', 'flusher' ],
  },
  corner_roller: {
    label: 'Corner Rollers',
    categoryHints: [ 'corner roller', 'roller' ],
  },
  corner_applicator: {
    label: 'Corner Applicators',
    categoryHints: [ 'corner applicator', 'applicator' ],
  },
  nail_spotter: {
    label: 'Nail Spotters',
    categoryHints: [ 'nail spotter', 'spotter' ],
  },
  handle: {
    label: 'Handles & Accessories',
    categoryHints: [ 'handle', 'extension', 'gooseneck', 'accessory' ],
  },
  diagnostic: {
    label: 'Diagnostic / Custom',
    categoryHints: [],
  },
};

export const REPAIR_SERVICE_TOOL_CATEGORIES = Object.entries(REPAIR_TOOL_FAMILIES)
  .filter(([id]) => id !== 'diagnostic')
  .map(([id, family]) => ({
    id,
    label: family.label,
  }));

export const REPAIR_PACKAGES = [
  {
    id: 'taper_tune_up',
    toolFamily: 'automatic_taper',
    name: 'Taper Tune-Up',
    shortName: 'Tune-Up',
    routeType: 'standard_package',
    startingPrice: 179,
    priceLabel: 'Starts at $179',
    includes: [ 'Deep clean and lubrication', 'Needle and cable calibration', 'Wear-part inspection' ],
    commonSymptoms: [ 'Skipping tape', 'Dry tape edges', 'Preventive service' ],
    estimatedTurnaroundDays: { standard: 7, expedited: 3 },
    warrantyDays: 30,
    recommendedFor: [ 'Preventive maintenance', 'Light wear', 'Production tools due for service' ],
    notFor: [ 'Severe impact damage', 'Unknown internal damage' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'standard_taper_rebuild',
    toolFamily: 'automatic_taper',
    name: 'Standard Taper Rebuild',
    shortName: 'Standard Rebuild',
    routeType: 'standard_package',
    startingPrice: 299,
    priceLabel: 'Starts at $299',
    includes: [ 'Full teardown', 'Common wear-kit replacement', 'Needle, cable, blade, and wheel service', 'Calibration and test run' ],
    commonSymptoms: [ 'Weak mud flow', 'Tape jams', 'Worn wheels', 'Leaking tube' ],
    estimatedTurnaroundDays: { standard: 10, expedited: 5 },
    warrantyDays: 60,
    recommendedFor: [ 'Most worn automatic tapers', 'Daily-use tools', 'Known rebuild symptoms' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'premium_taper_overhaul',
    toolFamily: 'automatic_taper',
    name: 'Premium Taper Overhaul',
    shortName: 'Premium Overhaul',
    routeType: 'standard_package',
    startingPrice: 499,
    priceLabel: 'Starts at $499',
    includes: [ 'Everything in Standard Rebuild', 'Chain and sprocket inspection', 'High-wear component review', 'Priority bench testing' ],
    commonSymptoms: [ 'High-mileage wear', 'Repeated failures', 'Fleet restoration' ],
    estimatedTurnaroundDays: { standard: 12, expedited: 6 },
    warrantyDays: 90,
    recommendedFor: [ 'Production crews', 'Fleet tools', 'Heavy wear' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'blade_wheel_refresh',
    toolFamily: 'flat_box',
    name: 'Blade & Wheel Refresh',
    shortName: 'Refresh',
    routeType: 'standard_package',
    startingPrice: 49,
    priceLabel: 'Starts at $49',
    includes: [ 'Blade replacement', 'Wheel inspection', 'Basic adjustment and test' ],
    commonSymptoms: [ 'Streaking', 'Drag', 'Uneven finish' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 30,
    recommendedFor: [ 'Light box wear', 'Quick performance reset' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'full_box_rebuild',
    toolFamily: 'flat_box',
    name: 'Full Box Rebuild',
    shortName: 'Full Rebuild',
    routeType: 'standard_package',
    startingPrice: 89,
    priceLabel: 'Starts at $89',
    includes: [ 'Full disassembly', 'Blades, wheels, skids, and seals review', 'Spring and O-ring service', 'Calibration' ],
    commonSymptoms: [ 'Leaking', 'Streaking', 'Heavy drag', 'Worn skids' ],
    estimatedTurnaroundDays: { standard: 7, expedited: 3 },
    warrantyDays: 60,
    recommendedFor: [ 'Most flat boxes', 'Known wear issues' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'multi_box_service',
    toolFamily: 'flat_box',
    name: 'Multi-Box Service',
    shortName: 'Multi-Box',
    routeType: 'standard_package',
    startingPrice: 149,
    priceLabel: 'Starts at $149',
    includes: [ 'Coordinated service for three or more boxes', 'Per-box notes', 'Bundle pricing review' ],
    commonSymptoms: [ 'Fleet tune-up', 'Mixed-size box service', 'Crew maintenance' ],
    estimatedTurnaroundDays: { standard: 10, expedited: 5 },
    warrantyDays: 60,
    recommendedFor: [ 'Multiple flat boxes', 'Production sets' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'seal_screen_service',
    toolFamily: 'pump',
    name: 'Seal & Screen Service',
    shortName: 'Seal & Screen',
    routeType: 'standard_package',
    startingPrice: 59,
    priceLabel: 'Starts at $59',
    includes: [ 'Gaskets and u-cups', 'Screen and valve-disc service', 'Pressure test' ],
    commonSymptoms: [ 'Weak pressure', 'Minor leakage', 'Clogging' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 30,
    recommendedFor: [ 'Minor pump flow issues', 'Preventive pump service' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'full_pump_rebuild',
    toolFamily: 'pump',
    name: 'Full Pump Rebuild',
    shortName: 'Full Rebuild',
    routeType: 'standard_package',
    startingPrice: 119,
    priceLabel: 'Starts at $119',
    includes: [ 'Complete disassembly', 'Wear-part replacement review', 'Housing inspection', 'Flow calibration' ],
    commonSymptoms: [ 'Weak pressure', 'Major leakage', 'Poor flow recovery' ],
    estimatedTurnaroundDays: { standard: 8, expedited: 4 },
    warrantyDays: 60,
    recommendedFor: [ 'Worn pumps', 'Daily-use pumps' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'pump_hose_system_service',
    toolFamily: 'pump',
    name: 'Pump + Hose System Service',
    shortName: 'Pump + Hose',
    routeType: 'standard_package',
    startingPrice: 159,
    priceLabel: 'Starts at $159',
    includes: [ 'Full pump service', 'Hose inspection', 'Fitting check', 'End-to-end fluid test' ],
    commonSymptoms: [ 'System leaks', 'Pressure loss through hose', 'Fitting problems' ],
    estimatedTurnaroundDays: { standard: 10, expedited: 5 },
    warrantyDays: 60,
    recommendedFor: [ 'Pump and hose assemblies', 'Unknown flow restrictions' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'corner_finisher_tune_up',
    toolFamily: 'corner_finisher',
    name: 'Corner Finisher Tune-Up',
    shortName: 'Tune-Up',
    routeType: 'standard_package',
    startingPrice: 69,
    priceLabel: 'Starts at $69',
    includes: [ 'Blade and spring inspection', 'Pivot service', 'Calibration' ],
    commonSymptoms: [ 'Poor corner finish', 'Blade drag', 'Uneven mud flow' ],
    estimatedTurnaroundDays: { standard: 6, expedited: 3 },
    warrantyDays: 30,
    recommendedFor: [ 'Angle heads', 'Corner finishers', 'Flushers' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'corner_roller_tune_up',
    toolFamily: 'corner_roller',
    name: 'Corner Roller Tune-Up',
    shortName: 'Tune-Up',
    routeType: 'standard_package',
    startingPrice: 45,
    priceLabel: 'Starts at $45',
    includes: [ 'Roller inspection', 'Bearing and wheel review', 'Alignment check' ],
    commonSymptoms: [ 'Skipping', 'Binding', 'Uneven seating' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 30,
    recommendedFor: [ 'Corner rollers', 'Routine roller service' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'corner_applicator_tune_up',
    toolFamily: 'corner_applicator',
    name: 'Corner Applicator Tune-Up',
    shortName: 'Tune-Up',
    routeType: 'standard_package',
    startingPrice: 59,
    priceLabel: 'Starts at $59',
    includes: [ 'Applicator inspection', 'Flow-path cleaning', 'Seal review' ],
    commonSymptoms: [ 'Inconsistent flow', 'Clogs', 'Leakage' ],
    estimatedTurnaroundDays: { standard: 6, expedited: 3 },
    warrantyDays: 30,
    recommendedFor: [ 'Corner applicators', 'MudRunner-style tools' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'handle_tune_up',
    toolFamily: 'handle',
    name: 'Finishing Box Handle Tune-Up',
    shortName: 'Handle Tune-Up',
    routeType: 'standard_package',
    startingPrice: 49,
    priceLabel: 'Starts at $49',
    includes: [ 'Brake adjuster service', 'Spring and coupler review', 'Functional test' ],
    commonSymptoms: [ 'Loose brake', 'Sticky control', 'Coupler wear' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 30,
    recommendedFor: [ 'Finishing box handles', 'Extension handles' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'nail_spotter_service',
    toolFamily: 'nail_spotter',
    name: 'Nail Spotter Service',
    shortName: 'Nail Spotter',
    routeType: 'standard_package',
    startingPrice: 45,
    priceLabel: 'Starts at $45',
    includes: [ 'Plunger service', 'Seal replacement review', 'Trigger mechanism check' ],
    commonSymptoms: [ 'Inconsistent spotting', 'Trigger sticking', 'Leaking' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 30,
    recommendedFor: [ 'Nail spotters', 'Spotter tune-ups' ],
    requiresApproval: false,
    allowPreApproval: true,
  },
  {
    id: 'diagnose_and_quote',
    toolFamily: 'diagnostic',
    name: 'Diagnose and Quote',
    shortName: 'Quote First',
    routeType: 'diagnostic_quote',
    priceLabel: 'Quote required',
    includes: [ 'Technician inspection', 'Written estimate', 'No repair work until approval' ],
    commonSymptoms: [ 'Unknown damage', 'Severe damage', 'Not sure what package fits' ],
    estimatedTurnaroundDays: { standard: 3, expedited: 1 },
    warrantyDays: 0,
    recommendedFor: [ 'Uncertain damage', 'Warranty evaluation', 'Custom repair needs' ],
    requiresApproval: true,
    allowPreApproval: false,
  },
  {
    id: 'custom_damage_repair',
    toolFamily: 'diagnostic',
    name: 'Custom Damage Repair',
    shortName: 'Custom Repair',
    routeType: 'custom_repair',
    priceLabel: 'Quote required',
    includes: [ 'Custom technician review', 'Parts availability check', 'Repair plan before work begins' ],
    commonSymptoms: [ 'Bent frame', 'Impact damage', 'Missing parts', 'Prior failed repair' ],
    estimatedTurnaroundDays: { standard: 5, expedited: 2 },
    warrantyDays: 0,
    recommendedFor: [ 'Severe or unusual damage', 'Quote-first repairs' ],
    requiresApproval: true,
    allowPreApproval: true,
  },
];

export function getRepairPackageById( packageId ) {
  return REPAIR_PACKAGES.find( ( pkg ) => pkg.id === packageId ) || null;
}

export function getRepairToolFamilyFromCategory( category = '' ) {
  const raw = String( category ).trim();
  const catalogCategoryFamilies = {
    'Automatic Tapers': 'automatic_taper',
    'Semi-Automatic Tapers & Banjos': 'automatic_taper',
    'Flat Boxes': 'flat_box',
    'Corner Finishers': 'corner_finisher',
    'Corner Flushers': 'corner_finisher',
    'Angle Boxes & Corner Applicators': 'corner_applicator',
    'Applicator Heads': 'corner_applicator',
    'Powered Compound Applicators': 'corner_applicator',
    'Automatic Corner Rollers': 'corner_roller',
    'Corner Rollers': 'corner_roller',
    'Corner Bead Rollers': 'corner_roller',
    'Automatic Nail Spotters': 'nail_spotter',
    'Loading Pumps': 'pump',
    'Goosenecks, Box Fillers & Adapters': 'handle',
    'Compound Tubes': 'handle',
    Handles: 'handle',
  };
  if ( catalogCategoryFamilies[raw] ) return catalogCategoryFamilies[raw];

  const normalized = raw.toLowerCase();
  if ( ! normalized ) return 'diagnostic';

  const family = Object.entries( REPAIR_TOOL_FAMILIES ).find( ( [ key, config ] ) => {
    if ( key === 'diagnostic' ) return false;
    return config.categoryHints.some( ( hint ) => normalized.includes( hint ) );
  } );

  return family?.[0] || 'diagnostic';
}

export function getRepairPackagesForToolFamily( toolFamily = 'diagnostic', options = {} ) {
  const includeDiagnostic = options.includeDiagnostic !== false;
  const packages = REPAIR_PACKAGES.filter( ( pkg ) => pkg.toolFamily === toolFamily );

  if ( toolFamily === 'diagnostic' || ! includeDiagnostic ) {
    return packages.length ? packages : REPAIR_PACKAGES.filter( ( pkg ) => pkg.toolFamily === 'diagnostic' );
  }

  return [
    ...packages,
    ...REPAIR_PACKAGES.filter( ( pkg ) => pkg.toolFamily === 'diagnostic' ),
  ];
}

export function getRepairPackageGroups() {
  return Object.entries( REPAIR_TOOL_FAMILIES )
    .map( ( [ id, family ] ) => ( {
      id,
      label: family.label,
      packages: getRepairPackagesForToolFamily( id, { includeDiagnostic: id === 'diagnostic' } ),
    } ) )
    .filter( ( group ) => group.packages.length > 0 );
}

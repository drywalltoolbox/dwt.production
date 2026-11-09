import { OFFICIAL_REPAIR_CATALOG } from './repairCatalogOfficial.generated.js';
import { getRepairToolFamilyFromCategory } from './repairPackages.js';

const brands = OFFICIAL_REPAIR_CATALOG?.brands || {};

export function getOfficialRepairBrands() {
  return Object.keys(brands).sort((a, b) => a.localeCompare(b));
}

export function getOfficialRepairCategoriesForBrand(brand = '') {
  return Object.keys(brands[brand]?.modelsByCategory || {}).sort((a, b) => a.localeCompare(b));
}

export function getOfficialRepairCategoriesForFamily(brand = '', family = '') {
  const categories = getOfficialRepairCategoriesForBrand(brand);
  if (!family) return categories;
  return categories.filter((category) => getRepairToolFamilyFromCategory(category) === family);
}

export function getOfficialRepairModelsForBrandCategory(brand = '', category = '') {
  return (brands[brand]?.modelsByCategory?.[category] || [])
    .filter((model) => model?.sku)
    .map((model) => ({ value: model.sku, label: `${model.name} — ${model.sku}` }))
    .sort((a, b) => a.label.localeCompare(b.label));
}

export function getOfficialRepairModelLabel(brand = '', category = '', sku = '') {
  const model = brands[brand]?.modelsByCategory?.[category]?.find((entry) => entry.sku === sku);
  return model ? `${model.name} — ${model.sku}` : sku;
}

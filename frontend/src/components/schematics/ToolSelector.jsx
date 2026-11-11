/**
 * frontend/src/components/schematics/ToolSelector.jsx
 *
 * Renders the schematic cards for a selected brand + category. Preview
 * priority is resolved server-side (`preview.source`); presentation is the
 * same shared media selector card used by product-category discovery.
 */
import ProductsCategorySelector from '../catalog/ProductsCategorySelector.jsx';

export default function ToolSelector({ brandName, brandLogo, categoryName, tools, onBack, onSelectTool }) {
  if (tools.length === 0) {
    return (
      <div className="dtb-schematics-empty" role="status">
        <p>No schematics are available for {categoryName || 'this category'} yet.</p>
      </div>
    );
  }

  return (
    <ProductsCategorySelector
      brand={brandName}
      headerTitle={`${categoryName || 'Tool'} schematics`}
      headerDescription="Select a diagram to view its parts and callouts."
      schematicSelector
      brandLogo={brandLogo}
      categories={tools.map((tool) => ({
        ...tool,
        key: tool.id,
        slug: tool.id,
        name: tool.title,
        count: tool.page_count || 0,
      }))}
      onBack={onBack}
      onSelectCategory={(tool) => onSelectTool(tool.id)}
      includeAllProducts={false}
      loadAllProductsPreview={false}
      itemLabel="page"
    />
  );
}

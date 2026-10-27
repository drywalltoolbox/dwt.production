/**
 * frontend/src/components/schematics-v2/SchematicHeader.jsx
 *
 * Title/back-navigation bar for the schematic viewer. Page label text is
 * plain derived React state — no DOM mutation runtime required.
 */
import BackButton from '../shared/BackButton';
import { getBrandLogo } from '../../utils/brandAssets';

export default function SchematicHeader({ title, brandName, categoryName, onBack }) {
  const brandLogo = getBrandLogo(brandName);

  return (
    <div className="dtb-schematic-viewer__header">
      <BackButton onClick={onBack} label="Back" hideLabelOnMobile className="dtb-schematic-viewer__back" />
      <div className="dtb-schematic-viewer__heading">
        {brandLogo && (
          <img
            src={brandLogo}
            alt={`${brandName} logo`}
            className={`dtb-schematic-viewer__brand-logo${/columbia/i.test(brandName || '') ? ' dtb-schematic-viewer__brand-logo--inverse' : ''}`}
          />
        )}
        <h1 className="dtb-schematic-viewer__title">{title}</h1>
        {!brandLogo && (brandName || categoryName) && (
          <p className="dtb-schematic-viewer__subtitle">
            {[brandName, categoryName].filter(Boolean).join(' · ')}
          </p>
        )}
      </div>
    </div>
  );
}

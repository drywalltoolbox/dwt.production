/**
 * frontend/src/components/schematics/DiagramImage.jsx
 *
 * Renders the authoritative full schematic page while reserving real layout
 * space via width/height from the API (avoids CLS). Diagram pages deliberately
 * do not use the general WordPress attachment `srcset`: that set also contains
 * hard-cropped card/banner derivatives whose aspect ratios do not match the
 * source diagram. The legacy viewer likewise used the original page asset so
 * the complete drawing and hotspot coordinate space stayed intact.
 */

export default function DiagramImage({ page, onLoad, imgRef }) {
  if (!page?.url) return null;

  return (
    <img
      ref={imgRef}
      src={page.url}
      width={page.width || undefined}
      height={page.height || undefined}
      alt={page.label || `Schematic diagram page ${page.page_number}`}
      className="dtb-schematic-diagram__image"
      decoding="async"
      draggable={false}
      onLoad={onLoad}
    />
  );
}

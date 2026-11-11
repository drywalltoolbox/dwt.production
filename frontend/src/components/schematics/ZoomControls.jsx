/**
 * frontend/src/components/schematics/ZoomControls.jsx
 */
import { Minus, Plus, RotateCcw } from 'lucide-react';

export default function ZoomControls({ scale, minScale, maxScale, onZoomIn, onZoomOut, onReset }) {
  return (
    <div className="dtb-schematic-zoom-controls" role="group" aria-label="Diagram zoom controls">
      <button
        type="button"
        onClick={onZoomOut}
        disabled={scale <= minScale}
        aria-label="Zoom out"
        title="Zoom out"
        className="dtb-schematic-zoom-btn"
      >
        <Minus size={18} aria-hidden="true" />
      </button>
      <span className="dtb-schematic-zoom-value" aria-live="polite">{Math.round(scale * 100)}%</span>
      <button
        type="button"
        onClick={onZoomIn}
        disabled={scale >= maxScale}
        aria-label="Zoom in"
        title="Zoom in"
        className="dtb-schematic-zoom-btn"
      >
        <Plus size={18} aria-hidden="true" />
      </button>
      <button
        type="button"
        onClick={onReset}
        disabled={scale === 1}
        aria-label="Reset zoom"
        title="Reset zoom"
        className="dtb-schematic-zoom-btn"
      >
        <RotateCcw size={16} aria-hidden="true" />
      </button>
    </div>
  );
}

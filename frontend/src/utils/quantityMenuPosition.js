export default function getQuantityMenuPosition(trigger, { preferredHeight = 198, preferredWidth = 128 } = {}) {
  if (!trigger || typeof window === 'undefined') return null;

  const rect = trigger.getBoundingClientRect();
  const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
  const viewportHeight = window.innerHeight;
  const gutter = Math.min(12, viewportWidth / 2);
  const gap = 8;
  const width = Math.max(0, Math.min(preferredWidth, viewportWidth - gutter * 2));
  const minLeft = gutter;
  const maxLeft = Math.max(minLeft, viewportWidth - width - gutter);
  const left = Math.min(Math.max(minLeft, rect.left + (rect.width - width) / 2), maxLeft);
  const availableBelow = Math.max(0, viewportHeight - rect.bottom - gap - gutter);
  const availableAbove = Math.max(0, rect.top - gap - gutter);
  const openBelow = availableBelow >= preferredHeight || availableBelow >= availableAbove;
  const availableHeight = openBelow ? availableBelow : availableAbove;
  const maxHeight = Math.max(40, Math.min(preferredHeight, availableHeight, viewportHeight - gutter * 2));
  if (width === 0) return null;

  const desiredTop = openBelow ? rect.bottom + gap : rect.top - gap - maxHeight;
  const top = Math.min(Math.max(gutter, desiredTop), Math.max(gutter, viewportHeight - gutter - maxHeight));
  return {
    top,
    left,
    width,
    maxHeight,
    '--dtb-quantity-menu-origin': openBelow ? 'top center' : 'bottom center',
    '--dtb-quantity-menu-offset': openBelow ? '-3px' : '3px',
  };
}

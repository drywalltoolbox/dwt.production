export function shouldShowCompareAtPrice(currentPrice, compareAtPrice) {
  if (currentPrice == null || compareAtPrice == null) return false;

  const current = Number.parseFloat(currentPrice);
  const compareAt = Number.parseFloat(compareAtPrice);

  return Number.isFinite(current) && Number.isFinite(compareAt) && compareAt > current;
}

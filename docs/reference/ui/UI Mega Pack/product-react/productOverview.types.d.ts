import type { ReactNode } from 'react';

export interface ProductImage {
  src: string;
  alt: string;
  width?: number;
  height?: number;
}
export interface ProductBreadcrumb { label: string; href?: string }
export interface ProductOption {
  value: string;
  label: string;
  color?: string;
  disabled?: boolean;
}
export interface ProductOptionGroup {
  id: string;
  label: string;
  type?: 'text' | 'color';
  showSelected?: boolean;
  options: ProductOption[];
}
export interface ProductVariant {
  id: string;
  options?: Record<string, string>;
  price?: number;
  compareAtPrice?: number;
  available?: boolean;
}
export interface ProductDetail { id?: string; title: string; content: ReactNode | string }
export interface ProductBenefit { id?: string; label: string; icon?: ReactNode }
export interface ProductOverviewData {
  id: string;
  name: string;
  description?: string;
  image?: ProductImage;
  images?: ProductImage[];
  price?: number;
  compareAtPrice?: number;
  currency?: string;
  locale?: string;
  available?: boolean;
  breadcrumbs?: ProductBreadcrumb[];
  badge?: string;
  rating?: { value: number; count?: number };
  showDiscount?: boolean;
  optionGroups?: ProductOptionGroup[];
  variants?: ProductVariant[];
  purchaseLabels?: { addToCart?: string; buyNow?: string };
  messages?: { addedToCart?: string; cartError?: string; checkoutError?: string };
  benefits?: ProductBenefit[];
  details?: ProductDetail[];
}
export interface ProductActionPayload {
  product: ProductOverviewData;
  variant: ProductVariant | null;
  quantity: number;
  selection: Record<string, string>;
}
export interface ProductOverviewProps {
  product: ProductOverviewData;
  onAddToCart?: (payload: ProductActionPayload) => void | Promise<void>;
  onBuyNow?: (payload: ProductActionPayload) => void | Promise<void>;
  onWishlistChange?: (payload: { product: ProductOverviewData; wishlisted: boolean }) => void;
  initialSelection?: Record<string, string>;
  initialQuantity?: number;
  defaultTheme?: 'dark' | 'light';
  showThemeToggle?: boolean;
  className?: string;
}
export declare function ProductOverview(props: ProductOverviewProps): ReactNode;
export declare function ProductGallery(props: { images?: ProductImage[]; productName?: string }): ReactNode;
export declare function ProductOptions(props: { groups?: ProductOptionGroup[]; selection?: Record<string, string>; onChange: (groupId: string, value: string) => void }): ReactNode;
export declare function ProductPurchaseActions(props: Record<string, unknown>): ReactNode;
export declare function ProductDetails(props: { items?: ProductDetail[] }): ReactNode;

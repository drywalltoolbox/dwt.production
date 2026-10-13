# Product overview React template

Standalone, data-driven React components based on the supplied Framer product overview reference. This folder is intentionally isolated from the storefront: it imports no Lagom application modules, aliases, commerce clients, or Framer runtime. The Framer editor badge is export chrome and is not part of the product UI.

## Use

Import the template and its stylesheet through the barrel:

```jsx
import { ProductOverview } from './product-react/index.js';

export function ProductPage({ product, cart }) {
  return (
    <ProductOverview
      product={product}
      onAddToCart={({ product, variant, quantity }) => cart.add(product, variant, quantity)}
      onBuyNow={({ product, variant, quantity }) => cart.buyNow(product, variant, quantity)}
    />
  );
}
```

`onAddToCart` and `onBuyNow` are optional callbacks. The buy button only appears when a checkout callback is provided; add-to-cart remains disabled until a product price is provided and a callback is connected. The component does not make network requests or assume cart, inventory, shipping, age, or legal behavior.

## Product data

Provide only values backed by your commerce/CMS data. Badges, ratings, prices, sale prices, breadcrumbs, benefits, and detail sections are omitted unless explicitly passed. Discount percentages are calculated from the current and compare-at prices. No default delivery promise or review count is supplied.

```js
const product = {
  id: 'item-id',
  name: 'Product name',
  description: 'Factual product description from your source data.',
  currency: 'USD',
  images: [{ src: '/images/product-front.webp', alt: 'Product shown from the front', width: 1200, height: 1200 }],
  optionGroups: [
    { id: 'color', label: 'Color', type: 'color', options: [{ value: 'brown', label: 'Brown', color: '#805333' }] },
    { id: 'size', label: 'Size', options: [{ value: 'm', label: 'M' }] },
  ],
  variants: [{ id: 'brown-m', options: { color: 'brown', size: 'm' }, price: 49, available: true }],
  benefits: [{ id: 'returns', label: 'Return information verified by your store policy' }],
  details: [{ id: 'materials', title: 'Materials', content: 'Verified materials information.' }],
};
```

Each option group `id` must match its key in a variant's `options` map. An unavailable product/variant disables purchasing. Error copy is reported in an accessible status message and can be overridden through `product.messages`.

## Included UI

- Responsive two-column product media and information layout, collapsing to one column on narrow screens.
- Main image with selectable thumbnail controls and an image-unavailable fallback.
- Optional breadcrumb, badge, and rating presentation.
- Currency formatting and derived compare-at discount.
- Accessible color/text option groups, quantity control, add-to-cart, conditional buy-now, and wishlist control.
- Optional caller-provided purchase notes and native expandable product details.
- Dark/light presentation toggle, visible keyboard focus, reduced-motion handling, and mobile safe-area spacing.

No build, dev server, or storefront integration is configured here. The consuming React app owns package resolution and its build pipeline.

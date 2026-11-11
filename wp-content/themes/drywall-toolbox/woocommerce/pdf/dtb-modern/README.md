# Drywall Toolbox PDF template

`dtb-modern` is the production Drywall Toolbox template family for the WP Overnight / WooCommerce PDF Invoices & Packing Slips plugin.

## Scope

This folder is a theme-owned custom template. It must not edit plugin files under `wp-content/plugins/woocommerce-pdf-invoices-packing-slips/`.

Files:

- `invoice.php` — customer/accounting invoice document.
- `packing-slip.php` — fulfillment-focused packing slip document.
- `style.css` — shared Letter-sized PDF print system.
- `template-functions.php` — template-scoped helpers only.

## Activation

In wp-admin, select this template in WooCommerce PDF Invoices & Packing Slips settings after uploading the theme files:

`wp-content/themes/drywall-toolbox/woocommerce/pdf/dtb-modern/`

## Design contract

- U.S. Letter portrait, document-safe typography, compact one-page default for common orders.
- Invoice includes billing, shipping, invoice/order metadata, product identity, prices, totals, and support URL.
- Packing slip emphasizes ship-to, order metadata, SKU/product identity, quantities, pack checkboxes, and packing notes. It intentionally omits pricing.
- Template code must remain presentation-only. Do not add order writes, fulfillment writes, accounting writes, payment lifecycle behavior, external API calls, or secret reads here.
- Product identity may include SKU, brand, and MPN when already present on WooCommerce product/order data.

## Operational notes

If a store logo is configured in the PDF plugin, the template renders it. If not, it falls back to a text Drywall Toolbox wordmark so documents remain branded and reliable without remote images.
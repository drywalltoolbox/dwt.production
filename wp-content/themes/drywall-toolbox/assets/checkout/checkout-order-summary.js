/**
 * Drywall Toolbox — order summary line item SKU.
 *
 * Renders the item's SKU as its own small, muted line beneath the product
 * name in the checkout order summary via the officially documented Cart and
 * Checkout Blocks `itemName` filter (`window.wc.blocksCheckout.registerCheckoutFilters`).
 * This does not touch the DOM directly and does not duplicate or replace
 * WooCommerce's own rendering — it supplies the string WooCommerce itself
 * renders for the item name, for the checkout ("summary") context only.
 *
 * The Product Name component renders this value as HTML (RawHTML), which is
 * how WooCommerce's own examples add markup — e.g. sale/on-sale badges — to
 * this same filter. The returned markup here is limited to a single <span>
 * with a class this stylesheet owns (see checkout.css,
 * .dtb-order-summary-sku), no attributes are interpolated unescaped besides
 * the SKU text itself.
 *
 * Reference: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/filters-in-cart-and-checkout/cart-line-items/
 *
 * Handle: dtb-checkout-order-summary (see functions.php
 * dtb_enqueue_native_checkout_assets()). No-ops if the filter registry
 * or a given item's SKU isn't available.
 */
( function () {
	'use strict';

	var api = window.wc && window.wc.blocksCheckout;
	if ( ! api || typeof api.registerCheckoutFilters !== 'function' ) {
		return;
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	try {
		api.registerCheckoutFilters( 'dtb-order-summary-sku', {
			itemName: function ( defaultValue, extensions, args ) {
				var isCheckoutSummary = ! args || args.context !== 'cart';
				var sku = args && args.cartItem && args.cartItem.sku;
				if ( ! isCheckoutSummary || ! sku ) {
					return defaultValue;
				}
				return defaultValue + '<span class="dtb-order-summary-sku">SKU: ' + escapeHtml( sku ) + '</span>';
			},
		} );
	} catch ( e ) {
		// Filter registry shape changed or isn't ready; leave native rendering untouched.
	}
} )();

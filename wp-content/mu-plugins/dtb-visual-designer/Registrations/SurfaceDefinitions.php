<?php
/**
 * Registrations — SurfaceDefinitions: the concrete, active React storefront
 * surfaces registered with the DTB Visual UI Designer.
 *
 * Every `route` below is a real route defined in frontend/src/App.jsx, and
 * every component id is expected to be mirrored on the frontend by a
 * `useEditableComponent('<surface-id>', '<component-id>')` call (see
 * frontend/src/designer/useEditableComponent.js). v1 wires that hook — plus
 * hover/select overlays and live preview application — into the Global
 * Header, Global Footer, and Homepage Hero as the reference implementation.
 * The remaining surfaces below are fully real (matched to actual pages/
 * components) and fully editable at the token + top-level property level;
 * their deeper component trees are intentionally shallow until the
 * corresponding frontend components adopt the same registration hook.
 *
 * Checkout is registered as `commerce_critical` at the surface level per the
 * architecture boundary: the live implementation
 * (frontend/src/pages/WooNativeCheckout.jsx) is a redirect shim to
 * WordPress/WooCommerce-hosted checkout, not a React form, so this module
 * intentionally does not claim control over WooCommerce's own checkout DOM.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_vd_enum_schema( string $name, array $allowed, string $default ): array {
	return [ 'type' => 'enum', 'name' => $name, 'allowed_values' => $allowed, 'default' => $default ];
}

function dtb_vd_spacing_prop( string $name, float $default, float $min = 0, float $max = 120 ): array {
	return [ 'type' => 'spacing', 'name' => $name, 'default' => $default, 'min' => $min, 'max' => $max ];
}

function dtb_vd_bool_prop( string $name, bool $default = false ): array {
	return [ 'type' => 'boolean', 'name' => $name, 'default' => $default ];
}

function dtb_vd_color_ref_prop( string $name, string $default_token_id ): array {
	return [ 'type' => 'color_ref', 'name' => $name, 'default' => $default_token_id ];
}

add_action( 'init', function (): void {

	// ── Global Header ────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'global-header',
		'name'     => 'Global Header',
		'route'    => 'frontend/src/components/shell/Header.jsx -> StorefrontHeader.jsx',
		'category' => 'global',
		'components' => [
			'header-root'      => [
				'name' => 'Header Container', 'type' => 'header', 'kind' => 'structural',
				'properties' => [
					'background_color' => dtb_vd_color_ref_prop( 'Background', 'color-shell' ),
					'sticky'            => dtb_vd_bool_prop( 'Sticky on Scroll', true ),
					'density'           => dtb_vd_enum_schema( 'Density', [ 'compact', 'comfortable' ], 'comfortable' ),
				],
				'responsive' => true,
			],
			'announcement-bar' => [
				'name' => 'Announcement Bar', 'type' => 'banner', 'kind' => 'content', 'parent' => 'header-root',
				'visibility_toggle' => true,
				'properties' => [
					'background_color' => dtb_vd_color_ref_prop( 'Background', 'color-primary' ),
					'text_align'        => [ 'type' => 'text_align', 'name' => 'Text Align', 'default' => 'center' ],
				],
			],
			'logo'             => [
				'name' => 'Logo', 'type' => 'brand-mark', 'kind' => 'content', 'parent' => 'header-root',
				'properties' => [
					'max_width' => dtb_vd_spacing_prop( 'Max Width', 160, 80, 320 ),
				],
				'responsive' => true,
			],
			'primary-nav'      => [
				'name' => 'Primary Navigation', 'type' => 'nav', 'kind' => 'structural', 'parent' => 'header-root',
				'properties' => [
					'gap' => dtb_vd_spacing_prop( 'Item Gap', 24, 8, 48 ),
				],
				'responsive' => true,
			],
			'search-bar'       => [
				'name' => 'Search Bar', 'type' => 'search', 'kind' => 'content', 'parent' => 'header-root',
				'visibility_toggle' => true,
				'properties'        => [ 'width' => dtb_vd_spacing_prop( 'Width', 320, 200, 560 ) ],
				'responsive'        => true,
			],
			'account-menu'     => [
				'name' => 'Account Menu', 'type' => 'menu', 'kind' => 'content', 'parent' => 'header-root',
				'visibility_toggle' => true,
				'properties'        => [],
			],
			'cart-trigger'     => [
				'name' => 'Cart Trigger', 'type' => 'trigger', 'kind' => 'presentation', 'parent' => 'header-root',
				'properties' => [ 'badge_color' => dtb_vd_color_ref_prop( 'Badge Color', 'color-primary' ) ],
			],
		],
	] );

	// ── Global Footer ────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'global-footer',
		'name'     => 'Global Footer',
		'route'    => 'frontend/src/components/shell/Footer.jsx',
		'category' => 'global',
		'components' => [
			'footer-root'        => [
				'name' => 'Footer Container', 'type' => 'footer', 'kind' => 'structural',
				'properties' => [ 'background_color' => dtb_vd_color_ref_prop( 'Background', 'color-shell' ) ],
			],
			'footer-columns'     => [
				'name' => 'Link Columns', 'type' => 'column-group', 'kind' => 'structural', 'parent' => 'footer-root',
				'reorder' => [ 'allowed_siblings' => [ 'footer-columns' ], 'movable' => true ],
				'properties' => [ 'columns' => dtb_vd_enum_schema( 'Column Count', [ '2', '3', '4' ], '4' ) ],
				'responsive'  => true,
			],
			'newsletter-signup'  => [
				'name' => 'Newsletter Signup', 'type' => 'form-region', 'kind' => 'content', 'parent' => 'footer-root',
				'visibility_toggle' => true,
				'properties'        => [],
			],
			'footer-legal-bar'   => [
				'name' => 'Legal Bar', 'type' => 'bar', 'kind' => 'structural', 'parent' => 'footer-root',
				'properties' => [ 'text_align' => [ 'type' => 'text_align', 'name' => 'Text Align', 'default' => 'center' ] ],
			],
		],
	] );

	// ── Homepage ─────────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'home',
		'name'     => 'Homepage',
		'route'    => '/',
		'category' => 'global',
		'components' => [
			'hero-section'    => [
				'name' => 'Hero', 'type' => 'hero', 'kind' => 'content',
				'properties' => [
					'background_color' => dtb_vd_color_ref_prop( 'Background', 'color-shell' ),
					'cta_visible'       => dtb_vd_bool_prop( 'Show CTA Button', true ),
				],
				'visibility_toggle' => true,
				'responsive'        => true,
			],
			'brand-rail'      => [
				'name' => 'Brand Rail', 'type' => 'rail', 'kind' => 'content', 'visibility_toggle' => true,
				'properties' => [ 'gap' => dtb_vd_spacing_prop( 'Tile Gap', 16, 4, 40 ) ],
				'responsive'  => true,
			],
			'trending-rail'   => [
				'name' => 'Trending Products Rail', 'type' => 'rail', 'kind' => 'content', 'visibility_toggle' => true,
				'properties' => [ 'columns' => dtb_vd_enum_schema( 'Visible Columns', [ '3', '4', '5', '6' ], '4' ) ],
				'responsive'  => true,
			],
		],
	] );

	// ── Catalog / Product Listing ────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'catalog',
		'name'     => 'Catalog & Product Listing',
		'route'    => '/products, /products/brands/:brandSlug, /products/brands/:brandSlug/categories/:categorySlug',
		'category' => 'catalog',
		'components' => [
			'listing-toolbar' => [
				'name' => 'Filter & Sort Toolbar', 'type' => 'toolbar', 'kind' => 'structural',
				'properties' => [ 'density' => dtb_vd_enum_schema( 'Density', [ 'compact', 'comfortable' ], 'comfortable' ) ],
			],
			'product-grid'    => [
				'name' => 'Product Grid', 'type' => 'grid', 'kind' => 'content',
				'properties' => [
					'columns' => dtb_vd_enum_schema( 'Columns', [ '2', '3', '4', '5' ], '4' ),
					'gap'     => dtb_vd_spacing_prop( 'Gap', 20, 8, 48 ),
				],
				'responsive' => true,
			],
		],
	] );

	// ── Product Detail ───────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'product-detail',
		'name'     => 'Product Detail',
		'route'    => '/products/:slug',
		'category' => 'catalog',
		'components' => [
			'image-gallery'   => [
				'name' => 'Image Gallery', 'type' => 'gallery', 'kind' => 'content',
				'properties' => [ 'aspect_ratio' => [ 'type' => 'aspect_ratio', 'name' => 'Aspect Ratio', 'default' => '1:1' ] ],
			],
			'purchase-panel'  => [
				'name' => 'Purchase Panel', 'type' => 'panel', 'kind' => 'commerce_critical',
				'properties' => [ 'sticky' => dtb_vd_bool_prop( 'Sticky on Scroll', true ) ],
				'responsive'  => true,
			],
			'detail-tabs'     => [
				'name' => 'Detail Tabs', 'type' => 'tabs', 'kind' => 'content', 'visibility_toggle' => true,
				'properties' => [],
			],
			'spec-table'      => [
				'name' => 'Specification Table', 'type' => 'table', 'kind' => 'content', 'visibility_toggle' => true,
				'properties' => [ 'density' => dtb_vd_enum_schema( 'Row Density', [ 'compact', 'comfortable' ], 'comfortable' ) ],
			],
		],
	] );

	// ── Cart ──────────────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'cart',
		'name'     => 'Cart',
		'route'    => '/cart',
		'category' => 'commerce',
		'components' => [
			'cart-items-list'      => [
				'name' => 'Cart Items', 'type' => 'list', 'kind' => 'content', 'properties' => [],
			],
			'order-summary'        => [
				'name' => 'Order Summary', 'type' => 'panel', 'kind' => 'commerce_critical', 'properties' => [],
			],
			'proceed-to-checkout'  => [
				'name' => 'Proceed to Checkout Button', 'type' => 'button', 'kind' => 'commerce_critical',
				'properties' => [ 'width' => dtb_vd_enum_schema( 'Width', [ 'auto', 'full' ], 'full' ) ],
			],
		],
	] );

	// ── Checkout (presentation is WordPress/WooCommerce-hosted; see note above) ──
	dtb_vd_register_surface( [
		'id'                => 'checkout',
		'name'              => 'Checkout',
		'route'             => '/checkout (redirects to WooCommerce-hosted checkout)',
		'category'          => 'commerce',
		'commerce_critical' => true,
		'description'       => 'React only renders a transitional redirect state; the checkout form itself is WooCommerce-hosted and out of this module\'s presentation authority.',
		'components' => [
			'redirect-transition' => [
				'name' => 'Redirect Transition State', 'type' => 'loader', 'kind' => 'presentation',
				'properties' => [ 'message_visible' => dtb_vd_bool_prop( 'Show Redirect Message', true ) ],
			],
		],
	] );

	// ── Order Confirmation ────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'order-confirmation',
		'name'     => 'Order Confirmation',
		'route'    => '/order/:id',
		'category' => 'commerce',
		'components' => [
			'confirmation-summary' => [
				'name' => 'Confirmation Summary', 'type' => 'panel', 'kind' => 'commerce_critical', 'properties' => [],
			],
			'next-steps'           => [
				'name' => 'Next Steps', 'type' => 'panel', 'kind' => 'content', 'visibility_toggle' => true, 'properties' => [],
			],
		],
	] );

	// ── Order Tracking ────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'order-tracking',
		'name'     => 'Order Tracking',
		'route'    => '/order-tracking/:id',
		'category' => 'commerce',
		'components' => [
			'tracking-timeline' => [ 'name' => 'Tracking Timeline', 'type' => 'timeline', 'kind' => 'content', 'properties' => [] ],
		],
	] );

	// ── Customer Account ──────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'account',
		'name'     => 'Customer Account',
		'route'    => '/dashboard',
		'category' => 'account',
		'components' => [
			'account-nav'  => [ 'name' => 'Account Navigation', 'type' => 'nav', 'kind' => 'structural', 'properties' => [] ],
			'account-body' => [ 'name' => 'Account Content Panel', 'type' => 'panel', 'kind' => 'content', 'properties' => [] ],
		],
	] );

	// ── Repair Service ────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'repair-landing',
		'name'     => 'Repair Service Landing',
		'route'    => '/repairs',
		'category' => 'repair-service',
		'components' => [
			'repair-hero'     => [ 'name' => 'Hero', 'type' => 'hero', 'kind' => 'content', 'visibility_toggle' => true, 'properties' => [
				'background_color' => dtb_vd_color_ref_prop( 'Background', 'color-shell' ),
			] ],
			'package-gallery' => [ 'name' => 'Repair Packages', 'type' => 'gallery', 'kind' => 'content', 'properties' => [] ],
		],
	] );

	dtb_vd_register_surface( [
		'id'       => 'repair-intake',
		'name'     => 'Repair Intake',
		'route'    => '/repairs/start',
		'category' => 'repair-service',
		'components' => [
			'intake-form-region' => [ 'name' => 'Intake Form Region', 'type' => 'form-region', 'kind' => 'commerce_critical', 'properties' => [] ],
		],
	] );

	// ── Calculators ───────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'calculator-hub',
		'name'     => 'Calculator Hub',
		'route'    => '/calculators',
		'category' => 'calculators',
		'components' => [
			'calculator-grid' => [
				'name' => 'Calculator Grid', 'type' => 'grid', 'kind' => 'content',
				'properties' => [ 'columns' => dtb_vd_enum_schema( 'Columns', [ '2', '3', '4' ], '3' ) ],
				'responsive'  => true,
			],
		],
	] );

	foreach ( [
		'sheet-calculator'       => 'Sheet Calculator',
		'mud-calculator'         => 'Mud Calculator',
		'screw-calculator'       => 'Screw Calculator',
		'corner-bead-calculator' => 'Corner Bead Calculator',
		'tape-calculator'        => 'Tape Calculator',
	] as $calc_id => $calc_name ) {
		dtb_vd_register_surface( [
			'id'       => $calc_id,
			'name'     => $calc_name,
			'route'    => '/calculators (' . $calc_name . ' panel)',
			'category' => 'calculators',
			'components' => [
				'calculator-panel' => [ 'name' => $calc_name . ' Panel', 'type' => 'panel', 'kind' => 'commerce_critical', 'properties' => [] ],
			],
		] );
	}

	// ── System States ─────────────────────────────────────────────────────
	dtb_vd_register_surface( [
		'id'       => 'error-state',
		'name'     => 'Error State (404 / Errors)',
		'route'    => '/error/:code, * (catch-all)',
		'category' => 'system-state',
		'components' => [
			'error-panel' => [
				'name' => 'Error Panel', 'type' => 'panel', 'kind' => 'content',
				'properties' => [ 'content_align' => dtb_vd_enum_schema( 'Content Alignment', [ 'left', 'center' ], 'center' ) ],
			],
		],
	] );

}, 6 ); // priority 6: after TokenDefinitions (5) so color_ref defaults resolve against registered tokens.

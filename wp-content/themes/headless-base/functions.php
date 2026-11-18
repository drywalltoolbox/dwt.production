<?php
/**
 * Headless Base Theme — functions.php
 *
 * Pure REST API backend theme for the Drywall Toolbox headless architecture.
 * This theme produces zero frontend output. The React SPA at elliottm4.sg-host.com
 * owns all rendering. WordPress and WooCommerce serve exclusively as the REST API
 * and CMS backend.
 *
 * Responsibilities:
 *  1. Theme feature registration (WooCommerce, menus, image sizes).
 *  2. Block all frontend PHP template rendering.
 *  3. Strip all unnecessary WordPress head output.
 *  4. REST API configuration, caching headers, and index filtering.
 *  5. Custom REST endpoint: navigation menus.
 *  6. Custom REST endpoint: site settings / bootstrap data.
 *  7. Featured image enrichment on post/page/product REST responses.
 *  8. WooCommerce product field enrichment (gallery, availability, pricing).
 *
 * Cross-cutting security, CORS, Woo public read, admin compatibility, and
 * performance policy lives in mu-plugins so theme swaps are production-safe.
 *
 * @package headless-base
 * @version 2.0.0
 */

defined( 'ABSPATH' ) || exit;


// =============================================================================
// CONSTANTS
// =============================================================================

define( 'HB_VERSION',   '2.0.0' );
define( 'HB_NAMESPACE', 'headless/v1' );
define( 'HB_DOMAIN',    'headless-base' );


// =============================================================================
// 1. THEME SETUP
// =============================================================================

add_action( 'after_setup_theme', 'hb_theme_setup' );
/**
 * Register theme features, image sizes, and navigation menus.
 * Keep lean — this theme renders nothing on the frontend.
 */
function hb_theme_setup(): void {
	load_theme_textdomain( HB_DOMAIN, get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	/**
	 * Image sizes consumed by the React frontend.
	 * These are generated on upload and served via REST API _images field.
	 * Sizes are tuned for e-commerce product display at standard breakpoints.
	 */
	add_image_size( 'product-thumb',   120,  120,  true  ); // Cart / order line items.
	add_image_size( 'product-card',    480,  480,  true  ); // Product grid cards.
	add_image_size( 'product-hero',    960,  960,  false ); // Product detail hero.
	add_image_size( 'product-zoom',   1600, 1600,  false ); // Lightbox / zoom.
	add_image_size( 'category-banner', 1440, 480,  true  ); // Category page headers.

	// Navigation menus exposed via REST API for dynamic React navigation rendering.
	register_nav_menus(
		[
			'primary'   => __( 'Primary Navigation',   HB_DOMAIN ),
			'secondary' => __( 'Secondary Navigation', HB_DOMAIN ),
			'footer'    => __( 'Footer Navigation',    HB_DOMAIN ),
			'mobile'    => __( 'Mobile Navigation',    HB_DOMAIN ),
			'account'   => __( 'Account Navigation',   HB_DOMAIN ),
		]
	);
}


// =============================================================================
// 2. DISABLE ALL FRONTEND RENDERING
// WordPress never renders PHP templates — React owns the frontend entirely.
// =============================================================================

add_filter( 'template_include', 'hb_block_frontend_templates', 99 );
/**
 * Intercept all template requests and return the minimal theme index.php.
 * Explicitly preserves: wp-admin, REST API, WP-CLI, and cron contexts.
 *
 * @param string $template The resolved template file path.
 * @return string          The minimal index.php or the original template.
 */
function hb_block_frontend_templates( string $template ): string {
	if (
		is_admin() ||
		( defined( 'REST_REQUEST' )  && REST_REQUEST  ) ||
		( defined( 'DOING_CRON' )    && DOING_CRON    ) ||
		( defined( 'WP_CLI' )        && WP_CLI        ) ||
		( defined( 'DOING_AJAX' )    && DOING_AJAX    )
	) {
		return $template;
	}
	return get_template_directory() . '/index.php';
}

/**
 * Suppress all frontend scripts and styles enqueued by plugins or WordPress core.
 * Nothing is rendered server-side so no assets should be output on the frontend.
 */
add_action( 'wp_enqueue_scripts', 'hb_dequeue_all_frontend_assets', 9999 );
function hb_dequeue_all_frontend_assets(): void {
	global $wp_scripts, $wp_styles;
	if ( isset( $wp_scripts->queue ) ) {
		$wp_scripts->queue = [];
	}
	if ( isset( $wp_styles->queue ) ) {
		$wp_styles->queue = [];
	}
}


// =============================================================================
// 3. CLEAN WORDPRESS HEAD OUTPUT
// Remove all meta tags, links, and scripts that serve no purpose in a headless
// context and would only leak information or add unnecessary overhead.
// =============================================================================

add_action( 'init', 'hb_clean_head' );
function hb_clean_head(): void {
	// Version disclosure — removes WordPress version from head and feeds.
	remove_action( 'wp_head', 'wp_generator' );
	add_filter( 'the_generator', '__return_empty_string' );

	// Discovery and remote editing protocol links.
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );

	// Pagination links — unused in headless context.
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

	// Feed auto-discovery links.
	remove_action( 'wp_head', 'feed_links',       2 );
	remove_action( 'wp_head', 'feed_links_extra',  3 );

	// Resource hints (dns-prefetch, preconnect) — React manages its own.
	remove_action( 'wp_head', 'wp_resource_hints', 2 );

	// Emoji — completely unused.
	remove_action( 'wp_head',          'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles',  'print_emoji_styles' );
	add_filter( 'emoji_svg_url',       '__return_false' );

	// Block REST API oembed autodiscovery.
	add_filter( 'embed_oembed_discover', '__return_false' );
}


// Theme policy is intentionally thin: security/CORS/admin compatibility lives
// in mu-plugins so theme swaps do not change production hardening behavior.


// =============================================================================
// 6. REST API CONFIGURATION
// =============================================================================

// Filter verbose internals from the public REST API index response.
add_filter( 'rest_index', 'hb_filter_rest_index' );
function hb_filter_rest_index( WP_REST_Response $response ): WP_REST_Response {
	$data = $response->get_data();
	unset( $data['authentication'] );
	unset( $data['_links'] );
	$response->set_data( $data );
	return $response;
}

/**
 * Add cache-control headers to public GET REST responses.
 * Authenticated requests are never cached (WP nonce invalidates them).
 */
add_filter( 'rest_post_dispatch', 'hb_rest_cache_headers', 10, 3 );
function hb_rest_cache_headers(
	WP_REST_Response $result,
	WP_REST_Server   $server,
	WP_REST_Request  $request
): WP_REST_Response {
	if ( 'GET' !== $request->get_method() || is_user_logged_in() ) {
		return $result;
	}

	// Route → max-age in seconds.
	$cache_rules = [
		'#^/wc/v3/products#'         => 300,   // Products: 5 min.
		'#^/wc/v3/products/categories#' => 600, // Categories: 10 min.
		'#^/wp/v2/categories#'       => 600,
		'#^/wp/v2/tags#'             => 600,
		'#^/' . HB_NAMESPACE . '/settings#' => 600,
		'#^/' . HB_NAMESPACE . '/menus#'    => 300,
	];

	foreach ( $cache_rules as $pattern => $max_age ) {
		if ( preg_match( $pattern, $request->get_route() ) ) {
			$stale = (int) ( $max_age * 0.2 ); // 20% stale-while-revalidate window.
			$result->header( 'Cache-Control', "public, max-age={$max_age}, stale-while-revalidate={$stale}" );
			$result->header( 'CDN-Cache-Control', "max-age={$max_age}" );
			break;
		}
	}

	return $result;
}


// =============================================================================
// 7. REST API: NAVIGATION MENU ENDPOINT
// GET /wp-json/headless/v1/menus/<location>
//
// Returns a flat array of menu items. The parent field allows React to build
// nested/dropdown navigation structures client-side.
// =============================================================================

add_action( 'rest_api_init', 'hb_register_menu_endpoint' );
function hb_register_menu_endpoint(): void {
	register_rest_route(
		HB_NAMESPACE,
		'/menus/(?P<location>[a-zA-Z0-9_-]+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'hb_get_menu_by_location',
			'permission_callback' => '__return_true',
			'args'                => [
				'location' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) > 0,
					'description'       => 'Registered menu location slug.',
				],
			],
		]
	);

	// List all registered menu locations — useful for React app bootstrapping.
	register_rest_route(
		HB_NAMESPACE,
		'/menus',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'hb_get_all_menu_locations',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * Return menu items for a given registered menu location.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function hb_get_menu_by_location( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$location  = sanitize_key( $request->get_param( 'location' ) );
	$locations = get_nav_menu_locations();

	if ( ! isset( $locations[ $location ] ) ) {
		return new WP_Error(
			'menu_location_not_found',
			sprintf( 'No menu is assigned to location "%s".', esc_html( $location ) ),
			[ 'status' => 404 ]
		);
	}

	$menu = wp_get_nav_menu_object( $locations[ $location ] );

	if ( ! $menu || is_wp_error( $menu ) ) {
		return new WP_Error(
			'menu_load_failed',
			'The menu object could not be loaded.',
			[ 'status' => 500 ]
		);
	}

	$items = wp_get_nav_menu_items( $menu->term_id );

	if ( ! $items ) {
		return rest_ensure_response( [] );
	}

	$output = array_map(
		fn( WP_Post $item ): array => [
			'id'          => (int) $item->ID,
			'title'       => wp_strip_all_tags( $item->title ),
			'url'         => esc_url_raw( $item->url ),
			'target'      => sanitize_text_field( $item->target ),
			'parent'      => (int) $item->menu_item_parent,
			'order'       => (int) $item->menu_order,
			'object'      => sanitize_text_field( $item->object ),
			'object_id'   => (int) $item->object_id,
			'type'        => sanitize_text_field( $item->type ),
			'classes'     => array_values( array_filter( (array) $item->classes ) ),
			'description' => wp_strip_all_tags( $item->description ?? '' ),
			'attr_title'  => sanitize_text_field( $item->attr_title ?? '' ),
			'xfn'         => sanitize_text_field( $item->xfn ?? '' ),
		],
		$items
	);

	$response = rest_ensure_response( $output );
	$response->header( 'X-Menu-Location', $location );
	$response->header( 'X-Menu-Name',     esc_attr( $menu->name ) );
	$response->header( 'X-Menu-Count',    (string) count( $output ) );

	return $response;
}

/**
 * Return all registered menu locations and which menus (if any) are assigned.
 *
 * @return WP_REST_Response
 */
function hb_get_all_menu_locations(): WP_REST_Response {
	$registered = get_registered_nav_menus();
	$assigned   = get_nav_menu_locations();
	$output     = [];

	foreach ( $registered as $slug => $label ) {
		$menu_id   = $assigned[ $slug ] ?? null;
		$menu_obj  = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
		$output[]  = [
			'location'  => $slug,
			'label'     => $label,
			'assigned'  => ! empty( $menu_id ),
			'menu_name' => $menu_obj ? $menu_obj->name : null,
			'menu_id'   => $menu_id ? (int) $menu_id : null,
		];
	}

	return rest_ensure_response( $output );
}


// =============================================================================
// 8. REST API: SITE SETTINGS ENDPOINT
// GET /wp-json/headless/v1/settings
//
// Returns all key site configuration data needed by the React app on initial
// load. Centralises bootstrapping data into a single cacheable request.
// =============================================================================

add_action( 'rest_api_init', 'hb_register_settings_endpoint' );
function hb_register_settings_endpoint(): void {
	register_rest_route(
		HB_NAMESPACE,
		'/settings',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'hb_get_site_settings',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * Return all site settings needed by the React frontend for bootstrapping.
 *
 * @return WP_REST_Response
 */
function hb_get_site_settings(): WP_REST_Response {
	$wc_active = class_exists( 'WooCommerce' );

	$settings = [
		// Identity.
		'site_name'           => get_bloginfo( 'name' ),
		'site_description'    => get_bloginfo( 'description' ),
		'site_url'            => esc_url( home_url( '/' ) ),
		'logo_url'            => hb_get_site_logo_url(),
		'favicon_url'         => hb_get_favicon_url(),

		// WooCommerce / commerce settings.
		'woocommerce_active'  => $wc_active,
		'currency'            => $wc_active ? get_woocommerce_currency()                                 : 'USD',
		'currency_symbol'     => $wc_active ? html_entity_decode( get_woocommerce_currency_symbol() )    : '$',
		'currency_position'   => $wc_active ? get_option( 'woocommerce_currency_pos', 'left' )           : 'left',
		'thousand_separator'  => $wc_active ? get_option( 'woocommerce_price_thousand_sep', ',' )        : ',',
		'decimal_separator'   => $wc_active ? get_option( 'woocommerce_price_decimal_sep', '.' )         : '.',
		'decimals'            => $wc_active ? (int) get_option( 'woocommerce_price_num_decimals', 2 )    : 2,
		'wc_version'          => $wc_active ? WC_VERSION                                                 : null,
		'tax_display_shop'    => $wc_active ? get_option( 'woocommerce_tax_display_shop', 'excl' )       : 'excl',
		'shipping_enabled'    => $wc_active ? 'yes' === get_option( 'woocommerce_ship_to_countries' )    : false,

		// Locale / formatting.
		'language'            => get_bloginfo( 'language' ),
		'date_format'         => get_option( 'date_format', 'F j, Y' ),
		'time_format'         => get_option( 'time_format', 'g:i a' ),
		'timezone'            => wp_timezone_string(),

		// Contact.
		'contact_email'       => antispambot( get_option( 'admin_email', '' ) ),

		// WordPress version — useful for REST API feature detection.
		'wp_version'          => get_bloginfo( 'version' ),
	];

	$response = rest_ensure_response( $settings );
	$response->header( 'Cache-Control', 'public, max-age=600, stale-while-revalidate=120' );
	return $response;
}

/**
 * Return the URL of the site's custom logo set in the Customizer.
 *
 * @return string Logo URL or empty string.
 */
function hb_get_site_logo_url(): string {
	$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
	if ( ! $logo_id ) {
		return '';
	}
	$src = wp_get_attachment_image_src( $logo_id, 'full' );
	return $src ? esc_url( $src[0] ) : '';
}

/**
 * Return the site favicon / site icon URL.
 *
 * @return string Favicon URL or empty string.
 */
function hb_get_favicon_url(): string {
	$icon_id = (int) get_option( 'site_icon', 0 );
	if ( ! $icon_id ) {
		return '';
	}
	$src = wp_get_attachment_image_src( $icon_id, [ 32, 32 ] );
	return $src ? esc_url( $src[0] ) : '';
}


// =============================================================================
// 9. REST API: FEATURED IMAGE ENRICHMENT
// Registers a _images field on post, page, and product REST responses.
// Returns all registered image sizes as { url, width, height } objects so
// the React frontend can pick the most appropriate size without extra fetches.
// =============================================================================

add_action( 'rest_api_init', 'hb_register_image_fields' );
function hb_register_image_fields(): void {
	foreach ( [ 'post', 'page', 'product' ] as $type ) {
		register_rest_field(
			$type,
			'_images',
			[
				'get_callback'    => 'hb_get_post_images',
				'update_callback' => null,
				'schema'          => [
					'description' => 'All registered image sizes for the post featured image.',
					'type'        => 'object',
					'context'     => [ 'view', 'embed' ],
				],
			]
		);
	}
}

/**
 * Build a keyed map of image_size => { url, width, height } for the featured image.
 *
 * @param array $post REST post data array.
 * @return array      Map of size name to image data, or empty array if no thumbnail.
 */
function hb_get_post_images( array $post ): array {
	$thumbnail_id = (int) get_post_thumbnail_id( $post['id'] );
	if ( ! $thumbnail_id ) {
		return [];
	}

	$sizes   = get_intermediate_image_sizes();
	$sizes[] = 'full';
	$output  = [];

	foreach ( $sizes as $size ) {
		$src = wp_get_attachment_image_src( $thumbnail_id, $size );
		if ( $src ) {
			$output[ $size ] = [
				'url'    => esc_url( $src[0] ),
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			];
		}
	}

	// Also expose alt text for accessibility.
	$output['_alt'] = (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );

	return $output;
}


// =============================================================================
// 10. WOOCOMMERCE: PRODUCT FIELD ENRICHMENT
// Registers additional _prefixed fields on WC product REST responses.
// Eliminates the need for secondary API calls from React components.
// =============================================================================

add_action( 'rest_api_init', 'hb_register_product_fields' );
function hb_register_product_fields(): void {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	// All registered gallery images with multiple sizes.
	register_rest_field( 'product', '_gallery_images', [
		'get_callback' => 'hb_get_product_gallery_images',
		'schema'       => [ 'type' => 'array', 'description' => 'Gallery images for all registered sizes.' ],
	] );

	// Structured stock availability.
	register_rest_field( 'product', '_availability', [
		'get_callback' => 'hb_get_product_availability',
		'schema'       => [ 'type' => 'object', 'description' => 'Stock availability status and labels.' ],
	] );

	// Formatted price strings for direct display in React.
	register_rest_field( 'product', '_price_display', [
		'get_callback' => 'hb_get_product_price_display',
		'schema'       => [ 'type' => 'object', 'description' => 'Formatted price strings including sale and range prices.' ],
	] );

	// SKU and brand for product card and detail displays.
	register_rest_field( 'product', '_meta', [
		'get_callback' => 'hb_get_product_meta',
		'schema'       => [ 'type' => 'object', 'description' => 'Supplementary product metadata.' ],
	] );

	// Related product IDs for "You may also like" sections.
	register_rest_field( 'product', '_related_ids', [
		'get_callback' => fn( array $p ): array => array_map(
			'intval',
			wc_get_related_products( $p['id'], 6 )
		),
		'schema' => [ 'type' => 'array', 'description' => 'IDs of related products.' ],
	] );
}

/**
 * Return gallery images for a product across all registered image sizes.
 *
 * @param array $post REST post array.
 * @return array
 */
function hb_get_product_gallery_images( array $post ): array {
	$product = wc_get_product( $post['id'] );
	if ( ! $product ) {
		return [];
	}

	$target_sizes = [ 'product-thumb', 'product-card', 'product-hero', 'product-zoom', 'full' ];
	$output       = [];

	foreach ( $product->get_gallery_image_ids() as $attachment_id ) {
		$image = [
			'id'  => (int) $attachment_id,
			'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		];
		foreach ( $target_sizes as $size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $src ) {
				$image[ $size ] = [
					'url'    => esc_url( $src[0] ),
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				];
			}
		}
		$output[] = $image;
	}

	return $output;
}

/**
 * Return structured stock availability for a product.
 *
 * @param array $post REST post array.
 * @return array
 */
function hb_get_product_availability( array $post ): array {
	$product = wc_get_product( $post['id'] );
	if ( ! $product ) {
		return [];
	}

	$availability = $product->get_availability();

	return [
		'text'               => $availability['availability'] ?? '',
		'class'              => $availability['class'] ?? '',
		'in_stock'           => $product->is_in_stock(),
		'stock_quantity'     => $product->get_stock_quantity(),
		'stock_status'       => $product->get_stock_status(),
		'backorders_allowed' => $product->backorders_allowed(),
		'sold_individually'  => $product->is_sold_individually(),
		'purchasable'        => $product->is_purchasable(),
	];
}

/**
 * Return formatted price strings for a product.
 *
 * @param array $post REST post array.
 * @return array
 */
function hb_get_product_price_display( array $post ): array {
	$product = wc_get_product( $post['id'] );
	if ( ! $product ) {
		return [];
	}

	return [
		'price'              => wc_price( $product->get_price() ),
		'regular_price'      => wc_price( $product->get_regular_price() ),
		'sale_price'         => $product->is_on_sale() ? wc_price( $product->get_sale_price() ) : null,
		'on_sale'            => $product->is_on_sale(),
		'price_html'         => $product->get_price_html(),
		'price_range'        => 'variable' === $product->get_type() ? $product->get_price_html() : null,
		'raw_price'          => (float) $product->get_price(),
		'raw_regular_price'  => (float) $product->get_regular_price(),
		'raw_sale_price'     => $product->is_on_sale() ? (float) $product->get_sale_price() : null,
	];
}

/**
 * Return supplementary product metadata.
 *
 * @param array $post REST post array.
 * @return array
 */
function hb_get_product_meta( array $post ): array {
	$product = wc_get_product( $post['id'] );
	if ( ! $product ) {
		return [];
	}

	return [
		'sku'            => $product->get_sku(),
		'weight'         => $product->get_weight(),
		'dimensions'     => [
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		],
		'shipping_class' => $product->get_shipping_class(),
		'tax_class'      => $product->get_tax_class(),
		'virtual'        => $product->is_virtual(),
		'downloadable'   => $product->is_downloadable(),
		'featured'       => $product->is_featured(),
		'catalog_visibility' => $product->get_catalog_visibility(),
		'review_count'   => (int) $product->get_review_count(),
		'average_rating' => (float) $product->get_average_rating(),
	];
}


// Theme-specific REST shape/enrichment stays here. Cross-cutting Woo public read,
// admin notice policy, oEmbed, heartbeat, revisions, and autosave live in mu-plugins.

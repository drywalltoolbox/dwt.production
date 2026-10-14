<?php
/**
 * Checkout runtime integrity boundary.
 *
 * WooCommerce Checkout Block and WooPayments own their complete
 * JavaScript dependency graph and execution order. DTB currently loads no checkout
 * presentation controller; diagnostics must never impose async/defer strategy or
 * dependency coupling on critical payment assets.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutRuntimeIntegrity {
	/** DTB-owned diagnostic handles allowed on native checkout. */
	private const DTB_CHECKOUT_SCRIPT_HANDLES = [
		'dtb-woo-native-checkout-performance',
	];

	/**
	 * Known critical checkout handles. The SiteGround boundary also excludes every
	 * registered checkout script because optimization plugins can combine transitive
	 * dependencies under generated handles.
	 */
	private const CRITICAL_CHECKOUT_SCRIPT_HANDLES = [
		'jquery',
		'jquery-core',
		'jquery-migrate',
		'wp-api-fetch',
		'wp-compose',
		'wp-components',
		'wp-data',
		'wp-dom',
		'wp-element',
		'wp-hooks',
		'wp-html-entities',
		'wp-i18n',
		'wp-keycodes',
		'wp-plugins',
		'wp-primitives',
		'wc-settings',
		'wc-blocks-data-store',
		'wc-blocks-registry',
		'wc-blocks-components',
		'wc-cart-checkout-vendors',
		'wc-blocks-checkout',
		'wc-checkout-block-frontend',
		'woocommerce-payments',
	];

	public static function register(): void {
		add_action( 'wp', [ __CLASS__, 'enforce_native_theme_exception' ], PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enforce_native_theme_exception' ], 0 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enforce_dtb_checkout_script_invariants' ], PHP_INT_MAX );

		add_filter( 'sgo_js_async_exclude', [ __CLASS__, 'exclude_checkout_scripts_from_optimizer' ] );
		add_filter( 'sgo_javascript_combine_exclude', [ __CLASS__, 'exclude_checkout_scripts_from_optimizer' ] );
		add_filter( 'sgo_js_minify_exclude', [ __CLASS__, 'exclude_checkout_scripts_from_optimizer' ] );
		add_filter( 'sgo_javascript_combine_excluded_external_paths', [ __CLASS__, 'exclude_checkout_external_scripts_from_combine' ] );
		add_filter( 'sgo_javascript_combine_exclude_all_inline', [ __CLASS__, 'exclude_checkout_inline_scripts_from_combine' ] );
		add_filter( 'sgo_javascript_combine_exclude_all_inline_modules', [ __CLASS__, 'exclude_checkout_inline_scripts_from_combine' ] );
		add_filter( 'sgo_exclude_urls_from_cache', [ __CLASS__, 'exclude_checkout_urls_from_cache' ] );
	}

	/** Ensure the headless SPA cannot strip/replace the native Woo checkout runtime. */
	public static function enforce_native_theme_exception(): void {
		if ( ! self::is_native_checkout_request() ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', 'dtb_enqueue_react_app', 10 );
		remove_action( 'wp_enqueue_scripts', 'dtb_dequeue_non_react_assets', 9999 );
		remove_filter( 'template_include', 'dtb_force_react_template', 99 );
	}

	/** Prevent host strategy mutation on the DTB diagnostic asset. */
	public static function enforce_dtb_checkout_script_invariants(): void {
		if ( ! self::is_primary_checkout_request() ) {
			return;
		}

		global $wp_scripts;
		if ( ! $wp_scripts instanceof WP_Scripts ) {
			return;
		}

		foreach ( self::DTB_CHECKOUT_SCRIPT_HANDLES as $handle ) {
			$registered = $wp_scripts->registered[ $handle ] ?? null;
			if ( ! is_object( $registered ) ) {
				continue;
			}
			if ( isset( $registered->extra ) && is_array( $registered->extra ) ) {
				unset( $registered->extra['strategy'] );
			}
		}
	}

	/**
	 * Exclude the complete registered script graph from SiteGround transforms on
	 * the primary native checkout request.
	 *
	 * @param mixed $exclude_list Existing SiteGround exclusion list.
	 * @return array<int,string>
	 */
	public static function exclude_checkout_scripts_from_optimizer( $exclude_list ): array {
		$exclude_list = is_array( $exclude_list ) ? $exclude_list : [];
		if ( ! self::is_primary_checkout_request() ) {
			return $exclude_list;
		}

		$handles = array_merge( self::CRITICAL_CHECKOUT_SCRIPT_HANDLES, self::DTB_CHECKOUT_SCRIPT_HANDLES );
		global $wp_scripts;
		if ( $wp_scripts instanceof WP_Scripts ) {
			$handles = array_merge( $handles, array_keys( $wp_scripts->registered ), array_values( $wp_scripts->queue ) );
		}

		$handles = array_filter(
			array_map( 'strval', $handles ),
			static fn ( string $handle ): bool => '' !== $handle
		);
		return array_values( array_unique( array_merge( $exclude_list, $handles ) ) );
	}

	/** Keep provider-owned external payment scripts outside host-generated bundles. */
	public static function exclude_checkout_external_scripts_from_combine( $exclude_list ): array {
		$exclude_list = is_array( $exclude_list ) ? $exclude_list : [];
		if ( ! self::is_primary_checkout_request() ) {
			return $exclude_list;
		}

		// WooPayments may load processor SDK assets from Stripe-hosted origins.
		$exclude_list[] = 'js.stripe.com';
		$exclude_list[] = 'pay.google.com';
		return array_values( array_unique( $exclude_list ) );
	}

	/** @param mixed $exclude */
	public static function exclude_checkout_inline_scripts_from_combine( $exclude ): bool {
		return self::is_primary_checkout_request() ? true : (bool) $exclude;
	}

	/** @param mixed $excluded_urls @return array<int,string> */
	public static function exclude_checkout_urls_from_cache( $excluded_urls ): array {
		$excluded_urls   = is_array( $excluded_urls ) ? $excluded_urls : [];
		$excluded_urls[] = '/checkout/*';
		return array_values( array_unique( $excluded_urls ) );
	}

	private static function is_native_checkout_request(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		return (bool) preg_match( '#/checkout(?:/|$)#i', $path );
	}

	private static function is_primary_checkout_request(): bool {
		if ( ! self::is_native_checkout_request() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return false;
		}
		return true;
	}
}

DTB_CheckoutRuntimeIntegrity::register();

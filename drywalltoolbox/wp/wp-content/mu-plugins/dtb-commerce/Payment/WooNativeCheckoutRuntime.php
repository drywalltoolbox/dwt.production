<?php
/**
 * Native WooCommerce checkout runtime adapter for the headless DTB theme.
 *
 * The active public theme normally forces frontend requests into the React SPA
 * and strips non-React assets. Checkout is the deliberate exception: WooCommerce
 * must own the server-rendered runtime so Checkout Block and the official Stripe
 * extension can enqueue their supported assets and execute native endpoint state.
 *
 * Presentation belongs to the active theme's checkout template/assets. This
 * adapter owns only the runtime exception boundary and never renders payment
 * methods, checkout blocks, or a competing presentation implementation itself.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_WooNativeCheckoutRuntime {
	public static function register(): void {
		add_action( 'wp', [ __CLASS__, 'prepare_runtime' ], 1 );
		/* Re-assert immediately before enqueue processing so theme hook timing cannot
		 * restore the SPA asset stripper on a transactional checkout request. */
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'prepare_runtime' ], 0 );
		add_filter( 'template_include', [ __CLASS__, 'template_include' ], 1000 );
		add_filter( 'request', [ __CLASS__, 'resolve_staging_checkout_query' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'preserve_staging_checkout_canonical' ], 10, 2 );
		add_action( 'send_headers', [ __CLASS__, 'send_private_headers' ], 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'persist_store_api_checkout_metadata' ], 100 );
	}

	public static function prepare_runtime(): void {
		if ( ! self::is_native_checkout_surface() ) {
			return;
		}

		/* The React shell is not authoritative on Woo checkout/payment endpoints. */
		remove_action( 'wp_enqueue_scripts', 'dtb_enqueue_react_app', 10 );
		remove_action( 'wp_enqueue_scripts', 'dtb_dequeue_non_react_assets', 9999 );
		remove_filter( 'template_include', 'dtb_force_react_template', 99 );
	}

	public static function template_include( string $template ): string {
		if ( ! self::is_native_checkout_surface() ) {
			return $template;
		}

		/* The active theme is the presentation owner. Fail open to Woo/WordPress's
		 * resolved template if the expected theme template is unavailable. */
		$theme_template = locate_template( 'templates/checkout/native-checkout.php', false, false );
		return is_string( $theme_template ) && '' !== $theme_template && is_readable( $theme_template )
			? $theme_template
			: $template;
	}

	/**
	 * Resolve only the native checkout aliases served by staging/.htaccess.
	 * The shared WordPress installation retains its production home URL. Use
	 * the original request path, never an Origin, referrer, or redirect input,
	 * to identify the staging presentation mount. Do not decode path segments.
	 */
	public static function staging_checkout_base_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		if ( ! str_starts_with( $request_uri, '/' ) || str_starts_with( $request_uri, '//' ) ) {
			return '';
		}
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		return preg_match( '#^/staging/(?:checkout(?:/(?:order-pay|order-received)/[0-9]+)?|order-pay/[0-9]+)/?$#D', $path )
			? '/staging'
			: '';
	}

	/** Keep an internal staging alias from being canonicalized to production. */
	public static function preserve_staging_checkout_canonical( $redirect_url, $requested_url ) {
		unset( $requested_url );
		if ( self::is_native_checkout_surface() && '' !== self::staging_checkout_base_path() ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Apache's staging alias executes wp/index.php with pagename=checkout, but
	 * WordPress reparses the original /staging/checkout/ request against its own
	 * permalink rules. The resulting staging/checkout page does not exist.
	 * Restore only the page identity; retain Woo endpoint and provider query vars.
	 */
	public static function resolve_staging_checkout_query( array $query_vars ): array {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || '' === self::staging_checkout_base_path() ) {
			return $query_vars;
		}

		$query_vars['pagename'] = 'checkout';
		// A rejected staging permalink can leave WordPress's error=404 query var
		// set even after the checkout page identity is restored.
		unset( $query_vars['name'], $query_vars['page_id'], $query_vars['error'] );
		return $query_vars;
	}

	public static function send_private_headers(): void {
		if ( ! self::is_native_checkout_surface() || headers_sent() ) {
			return;
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
	}

	/**
	 * Checkout Block processes orders through the Store API. DTB checkout tagging
	 * runs earlier on that lifecycle and updates the order object in memory; save
	 * the metadata explicitly at a late priority so the production contract is
	 * durable before payment/lifecycle observers inspect it.
	 */
	public static function persist_store_api_checkout_metadata( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if (
			'woo_native_stripe' !== (string) $order->get_meta( '_dtb_checkout_gateway', true ) ||
			'woo-stripe-v1' !== (string) $order->get_meta( '_dtb_checkout_contract_version', true )
		) {
			return;
		}

		$order->save_meta_data();
	}

	private static function is_native_checkout_surface(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return '' !== self::staging_checkout_base_path() || self::request_uri_targets_checkout();
	}

	private static function request_uri_targets_checkout(): bool {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		return (bool) preg_match( '#/checkout(?:/|$)#i', $path );
	}

	/**
	 * Host-level and third-party page caches (e.g. SiteGround SuperCacher) decide
	 * whether to buffer/serve a cached response before WordPress finishes loading
	 * plugins/theme — long before is_checkout() is available and often ignoring
	 * response Cache-Control headers emitted later in the request lifecycle. The
	 * DONOTCACHEPAGE constant is the de-facto convention those caches check for,
	 * so it must be defined as early as this mu-plugin file executes (mu-plugins
	 * load before the 'muplugins_loaded' action even fires). Without this, a
	 * cached checkout document can be served to guests with a stale/empty cart
	 * state captured at cache-generation time, independent of their real session.
	 */
	public static function define_donotcachepage_if_checkout(): void {
		if ( defined( 'DONOTCACHEPAGE' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return;
		}

		if ( '' !== self::staging_checkout_base_path() || self::request_uri_targets_checkout() ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}

DTB_WooNativeCheckoutRuntime::define_donotcachepage_if_checkout();
DTB_WooNativeCheckoutRuntime::register();


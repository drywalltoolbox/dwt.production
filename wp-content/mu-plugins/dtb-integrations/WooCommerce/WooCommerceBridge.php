<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: DTB WooCommerce Configuration
 * Description: WooCommerce loopback, REST URL rewriting, wizard suppression,
 *              checkout customisation and webhook
 *              auto-registration, and server-side validation.
 * Version: 8.0.0
 * Author: Drywall Toolbox
 *
 * Must-use plugin: wp/wp-content/mu-plugins/dtb-woocommerce.php
 * Last Updated: 2026-04-04
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// SECTION 1 — LOOPBACK REQUESTS
//
// WooCommerce setup wizard and background tasks make server-to-server HTTP
// calls back to the same site. Keep a bounded 30-second timeout for known
// same-origin WordPress targets. TLS verification remains enabled by default;
// an explicit emergency-only constant may relax it for a diagnosed host issue.
// Blocking MUST remain true because core-profiler depends on synchronous responses.
// ============================================================================
add_filter( 'http_request_args', function ( $args, $url ) {
	$request_host = wp_parse_url( $url, PHP_URL_HOST );
	$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
	$request_path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$is_loopback  = $request_host && $site_host && strtolower( $request_host ) === strtolower( $site_host );
	$is_wp_target = str_starts_with( $request_path, '/wp-json/' )
		|| str_starts_with( $request_path, '/wp/wp-json/' )
		|| str_ends_with( $request_path, '/wp-admin/admin-ajax.php' )
		|| str_ends_with( $request_path, '/wp-cron.php' );

	if ( $is_loopback && $is_wp_target ) {
		if ( dtb_feature_enabled( 'DTB_RELAX_LOOPBACK_SSLVERIFY', false ) ) {
			$args['sslverify'] = false;
		}

		$args['timeout'] = max( (int) ( $args['timeout'] ?? 5 ), 30 );
	}

	return $args;
}, 10, 2 );


// ============================================================================
// SECTION 2 — REST URL REWRITING
//
// Rewrites /wp/wp-json/ → /wp-json/ so the React SPA can call /wp-json/
// from the domain root.
//
// In wp-admin we now keep the canonical /wp-json root by default because some
// hosts return 404 for /wp/wp-json/* even when WordPress lives in /wp.
//
// The rest_url_prefix filter is intentionally absent. Changing the prefix
// corrupts wpApiSettings.root — the base URL injected into @wordpress/api-fetch
// for ALL REST calls. When wrong, /wc/v3/settings/general/woocommerce_default_country
// resolves to the full settings group, returning ~22 kB that JS reads as a
// single setting object and crashes.
// ============================================================================
add_filter( 'rest_url', function ( $url, $path, $blog_id, $scheme ) {
	if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return dtb_wc_admin_rest_url( $url );
	}

	return str_replace( '/wp/wp-json/', '/wp-json/', $url );
}, 10, 4 );

function dtb_wc_admin_rest_url( string $url ): string {
	// Canonicalize first. This avoids wpApiSettings.root pointing to a 404 base
	// such as /wp/wp-json/ on hosts where only /wp-json/ is routed.
	return str_replace( '/wp/wp-json/', '/wp-json/', $url );
}


// ============================================================================
// SECTION 3 — WOOCOMMERCE DEFAULT COUNTRY + SETTINGS REST RESPONSES
//
// THE CRASH (confirmed via network log):
//   core-profiler.js fetches /wc/v3/settings/general/woocommerce_default_country.
//   When woocommerce_default_country is stored as "US:CA" the endpoint may
//   return the full 22 kB settings/general group instead of the single setting.
//   JS then calls countries.find(c => c.code === setting.value) where setting
//   is an object (not a string), .title on the found item is undefined → crash.
//
// ROOT CAUSES:
//   A) Option stored as "US:CA" — state suffix breaks country code lookup.
//   B) REST response for the individual setting returns raw "US:CA" with colon.
//   C) Full group endpoint falls back when URL is malformed by apiFetch.
//
// FIX:
//   1. Filter single-setting response to always return bare country code.
//   2. Filter the full settings/general group response as a safety net.
//   3. Handle the onboarding-profile group in the SAME filter callback to
//      avoid the duplicate-registration bug from the previous version.
//
// The stored option itself (woocommerce_default_country, e.g. "US:MN") is
// deliberately left untouched — it must keep the state suffix for the store
// address and "Calculate tax based on: Shop base address" to work. An earlier
// version of this fix also rewrote the stored option back to a bare country
// code on every woocommerce_init, which silently discarded the configured
// state on every page load (visible as the state field resetting to the
// first alphabetical option, Alabama, right after saving). Only the REST
// response is normalized now; the database value stays intact.
// ============================================================================

// 3b — Single-setting REST response: /wc/v3/settings/general/woocommerce_default_country
add_filter( 'woocommerce_rest_prepare_setting', function ( $response, $item ) {
	if ( ! empty( $item['id'] ) && 'woocommerce_default_country' === $item['id'] ) {
		$data = $response->get_data();
		if ( isset( $data['value'] ) && str_contains( (string) $data['value'], ':' ) ) {
			$data['value'] = strstr( $data['value'], ':', true );
			$response->set_data( $data );
		}
	}
	return $response;
}, 10, 2 );

// 3c — Settings-group REST response. Handles BOTH the general settings group
//      (country colon-stripping) AND the onboarding-profile group (field
//      defaults). Single registration avoids the duplicate-hook bug.
add_filter( 'woocommerce_rest_prepare_setting_group', function ( $response, $group_name ) {
	$data = $response->get_data();

	// — General settings group: strip colon from woocommerce_default_country ——
	if ( is_array( $data ) ) {
		foreach ( $data as &$setting ) {
			if (
				is_array( $setting ) &&
				isset( $setting['id'], $setting['value'] ) &&
				'woocommerce_default_country' === $setting['id'] &&
				str_contains( (string) $setting['value'], ':' )
			) {
				$setting['value'] = strstr( $setting['value'], ':', true );
			}
		}
		unset( $setting );
	}

	// — Onboarding profile group: ensure all expected fields exist ——
	if ( 'woocommerce-admin-onboarding' === $group_name ) {
		if ( isset( $data['onboarding_profile'] ) && is_array( $data['onboarding_profile'] ) ) {
			$profile = $data['onboarding_profile'];
			$profile['title']                = $profile['title'] ?? '';
			$profile['industries']           = $profile['industries'] ?? [];
			$profile['products']             = $profile['products'] ?? [];
			$profile['business_extensions']  = $profile['business_extensions'] ?? [];
			$data['onboarding_profile'] = $profile;
		}
	}

	$response->set_data( $data );
	return $response;
}, 10, 2 );


// ============================================================================
// SECTION 4 — SUPPRESS SETUP WIZARD & ONBOARDING
//
// Marks all wizard-completion flags so WooCommerce treats this as a configured
// store and skips the onboarding flow. WC 8+ checks woocommerce_onboarding_profile
// in addition to the legacy wizard flags.
// ============================================================================
add_action( 'woocommerce_init', function () {
	$flags = [
		'woocommerce_setup_wizard_complete'  => 'yes',
		'woocommerce_task_list_complete'     => 'yes',
		'woocommerce_task_list_hidden'       => 'yes',
	];

	foreach ( $flags as $option => $value ) {
		if ( get_option( $option ) !== $value ) {
			update_option( $option, $value );
		}
	}

	// WC 8+ onboarding profile — mark as completed.
	$profile = get_option( 'woocommerce_onboarding_profile', [] );
	if ( empty( $profile['completed'] ) ) {
		$profile['completed'] = true;
		update_option( 'woocommerce_onboarding_profile', $profile );
	}

	// Remove the legacy install timestamp that triggers wizard redirect.
	delete_option( 'woocommerce_admin_install_timestamp' );
}, 99 );


// ============================================================================
// SECTION 5 — REMOVE REDIRECT HOOKS BEFORE THEY FIRE
//
// WooCommerce registers an admin_init callback that redirects to the setup
// wizard on first activation. We remove it at priority 0, before WooCommerce's
// own priority-10 callback can run.
// ============================================================================
add_action( 'admin_init', function () {
	// Legacy wizard redirect (WooCommerce < 7).
	remove_action( 'admin_init', [ 'WC_Admin_Setup_Wizard', 'setup_wizard_redirect' ] );

	// Core-profiler redirect (WooCommerce 7+).
	remove_all_actions( 'woocommerce_admin_onboarding_wizard_redirect' );
}, 0 );


// ============================================================================
// SECTION 6 — FILTER-BASED ONBOARDING SUPPRESSION (WooCommerce 8+)
// ============================================================================

// Disable offline / core-profiler onboarding flow entirely.
add_filter( 'woocommerce_admin_should_load_offline_onboarding', '__return_false' );

// Prevent automatic wizard redirect via the dedicated filter (WC 8+).
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );

// Remove core-profiler from the admin features list so its JS never loads.
add_filter( 'woocommerce_admin_features', function ( $features ) {
	$key = array_search( 'core-profiler', $features, true );
	if ( false !== $key ) {
		unset( $features[ $key ] );
	}
	return array_values( $features );
} );

// Hide setup-related admin notices.
add_filter( 'woocommerce_show_admin_notice', function ( $show, $notice ) {
	$suppressed = [ 'install', 'update', 'no_shipping_methods' ];
	return in_array( $notice, $suppressed, true ) ? false : $show;
}, 10, 2 );

/**
 * Detect the WooCommerce product importer admin screen.
 *
 * We key off query vars because this runs in multiple hook timings.
 */
function dtb_is_wc_product_importer_admin_page(): bool {
	if ( ! is_admin() ) {
		return false;
	}

	$page = isset( $_GET['page'] )
		? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	if ( 'product_importer' !== $page ) {
		return false;
	}

	$post_type = isset( $_GET['post_type'] )
		? sanitize_text_field( wp_unslash( (string) $_GET['post_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	return '' === $post_type || 'product' === $post_type;
}

/**
 * Dequeue non-essential telemetry/dashboard scripts on the importer page.
 */
add_action(
	'admin_enqueue_scripts',
	static function (): void {
		if ( ! dtb_is_wc_product_importer_admin_page() ) {
			return;
		}

		$remove_handles = [
			'wc-admin-app',
			'wc-admin-homepage',
			'wc-admin-layout',
			'wc-admin-notes',
			'wc-admin-navigation',
			'wc-customer-effort-score-tracks',
		];

		foreach ( $remove_handles as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'registered' ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}

		// Fallback: remove any remaining importer-irrelevant scripts by src
		// fragment in case host/plugin updates changed the script handles.
		global $wp_scripts;
		if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
			return;
		}

		$blocked_src_fragments = [
			'/woocommerce/assets/client/admin/',
			'/plugins/woocommerce/assets/client/admin/',
		];

		foreach ( $wp_scripts->registered as $handle => $script ) {
			if ( ! ( $script instanceof _WP_Dependency ) ) {
				continue;
			}

			$src = (string) ( $script->src ?? '' );
			if ( '' === $src ) {
				continue;
			}

			foreach ( $blocked_src_fragments as $fragment ) {
				if ( false !== strpos( $src, $fragment ) ) {
					wp_dequeue_script( $handle );
					wp_deregister_script( $handle );
					break;
				}
			}
		}
	},
	100
);

/**
 * Remove unused module preloads that trigger Chrome warnings on importer.
 */
add_filter(
	'wp_preload_resources',
	static function ( array $preloads ): array {
		if ( ! dtb_is_wc_product_importer_admin_page() ) {
			return $preloads;
		}

		$blocked_fragments = [
			'/woocommerce/assets/client/admin/embed/index.js',
			'/woocommerce/assets/client/admin/components/index.js',
		];

		return array_values(
			array_filter(
				$preloads,
				static function ( $entry ) use ( $blocked_fragments ): bool {
					if ( ! is_array( $entry ) || empty( $entry['href'] ) ) {
						return true;
					}

					$href = (string) $entry['href'];
					foreach ( $blocked_fragments as $fragment ) {
						if ( false !== strpos( $href, $fragment ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	},
	20
);


// ============================================================================
// SECTION 7 — WEBHOOK AUTO-CREATION
//
// On WooCommerce init, checks the WooCommerce webhooks table for the four
// product lifecycle webhooks. Any missing webhook is auto-created using
// WC_Webhook with status active, the production delivery URL, and the
// WC_WEBHOOK_SECRET constant from wp-config.php.
//
// dtb_get_config() is defined in dtb-utils.php, which 00-dtb-loader.php
// loads before this file. The function_exists guard below makes this safe
// even if called out of order.
// ============================================================================
add_action( 'woocommerce_init', 'dtb_wc_ensure_webhooks', 20 );
add_action( 'admin_init', 'dtb_wc_ensure_webhooks', 999 );

function dtb_wc_ensure_webhooks(): array {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	if ( defined( 'DTB_DISABLE_PRODUCT_WEBHOOKS' ) && DTB_DISABLE_PRODUCT_WEBHOOKS ) {
		$result = [
			'status'    => 'skipped',
			'reason'    => 'product_webhooks_disabled',
			'created'   => [],
			'existing'  => [],
			'delivery'  => '',
			'secret'    => '',
			'debug'     => [
				'disabled_by_constant' => true,
			],
		];

		return $result;
	}

	$debug = [
		'wc_get_webhooks' => function_exists( 'wc_get_webhooks' ),
		'WC_Webhook'      => class_exists( 'WC_Webhook' ),
		'WC_VERSION'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
	];

	if ( ! function_exists( 'wc_get_webhooks' ) && class_exists( 'WC_Webhook' ) ) {
		$wc_path = '';
		if ( defined( 'WC_PLUGIN_FILE' ) ) {
			$wc_path = dirname( WC_PLUGIN_FILE );
		} elseif ( function_exists( 'WC' ) && method_exists( WC(), 'plugin_path' ) ) {
			$wc_path = WC()->plugin_path();
		}

		if ( $wc_path && file_exists( $wc_path . '/includes/wc-webhook-functions.php' ) ) {
			require_once $wc_path . '/includes/wc-webhook-functions.php';
			$debug['wc_get_webhooks'] = function_exists( 'wc_get_webhooks' );
		}
	}


	if ( ! class_exists( 'WC_Webhook' ) ) {
		$result = [
			'status'    => 'skipped',
			'reason'    => 'woocommerce_not_loaded',
			'created'   => [],
			'existing'  => [],
			'delivery'  => '',
			'secret'    => '',
			'debug'     => $debug,
		];

		return $result;
	}

	// Fallback config if dtb-utils.php hasn't loaded (should not happen in production).
	if ( function_exists( 'dtb_get_config' ) ) {
		$config       = dtb_get_config();
		$secret       = $config['webhook_secret'];
		$delivery_url = $config['webhook_delivery'];
	} else {
		$secret       = defined( 'WC_WEBHOOK_SECRET' ) ? WC_WEBHOOK_SECRET : '';
		$delivery_url = defined( 'DRYWALL_ALLOWED_ORIGIN' )
			? rtrim( DRYWALL_ALLOWED_ORIGIN, '/' ) . '/wp-json/drywall/v1/webhooks/products'
			: '';
	}

	if ( '' === $secret || '' === $delivery_url ) {
		$result = [
			'status'    => 'skipped',
			'reason'    => empty( $secret ) ? 'missing_secret' : 'missing_delivery_url',
			'created'   => [],
			'existing'  => [],
			'delivery'  => $delivery_url,
			'secret'    => $secret,
			'debug'     => $debug,
		];

		return $result;
	}

	$required_topics = [
		'product.created',
		'product.updated',
		'product.deleted',
		'product.restored',
	];

	$existing_ids = dtb_wc_get_webhook_ids_by_delivery_url( $delivery_url );

	$registered = [];
	$created = [];
	foreach ( $existing_ids as $id ) {
		$webhook = new WC_Webhook( $id );
		$registered[ $webhook->get_topic() ] = true;
	}

	foreach ( $required_topics as $topic ) {
		if ( isset( $registered[ $topic ] ) ) {
			continue;
		}

		$webhook = new WC_Webhook();
		$webhook->set_name( 'DTB Cache Invalidation — ' . $topic );
		$webhook->set_topic( $topic );
		$webhook->set_delivery_url( $delivery_url );
		$webhook->set_secret( $secret );
		$webhook->set_status( 'active' );
		$webhook->save();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[DryWall Toolbox] Webhook created: ' . $topic );
		$created[] = $topic;
	}

	$result = [
		'status'   => 'completed',
		'created'  => $created,
		'existing' => array_keys( $registered ),
		'delivery' => $delivery_url,
		'secret'   => $secret,
		'debug'    => $debug,
	];

	return $result;
}

/**
 * Return webhook IDs matching the given delivery URL.
 *
 * WooCommerce stores webhooks in a dedicated wc_webhooks table on current
 * versions. Query that table directly because wc_get_webhooks() does not
 * reliably support delivery_url filtering across storage implementations.
 */
function dtb_wc_get_webhook_ids_by_delivery_url( string $delivery_url ): array {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wc_webhooks';
	$table_like = $wpdb->esc_like( $table_name );
	$table      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_like ) );

	if ( $table === $table_name ) {
		return array_map(
			'absint',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT webhook_id FROM {$table_name} WHERE delivery_url = %s",
					$delivery_url
				)
			)
		);
	}

	if ( function_exists( 'wc_get_webhooks' ) ) {
		$webhooks = wc_get_webhooks( [
			'limit' => -1,
		] );

		$ids = [];
		foreach ( $webhooks as $webhook ) {
			if ( $webhook instanceof WC_Webhook && $webhook->get_delivery_url() === $delivery_url ) {
				$ids[] = absint( $webhook->get_id() );
			}
		}

		return $ids;
	}

	$query = new WP_Query( [
		'post_type'      => 'shop_webhook',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$ids = [];
	foreach ( $query->posts as $webhook_id ) {
		$webhook = new WC_Webhook( $webhook_id );
		if ( $webhook->get_delivery_url() === $delivery_url ) {
			$ids[] = $webhook_id;
		}
	}

	return $ids;
}


// ============================================================================
// SECTION 7b — WEBHOOK SECRET AUTO-SYNC
//
// When WC_WEBHOOK_SECRET is rotated in wp-config.php the new value is read by
// dtb_get_config() on the next boot, but the existing wc_webhooks DB rows still
// hold the old secret.  WooCommerce uses the per-row stored secret to sign
// every outgoing delivery; if it does not match the receiver's constant the
// HMAC check fails and all product-update webhook cache invalidations are
// silently dropped.
//
// This function stores a SHA-256 fingerprint of the current secret in
// wp_options (dtb_wc_webhook_secret_hash).  On each boot it compares the
// fingerprint against the live constant.  When they differ (rotation detected)
// it calls WC_Webhook::set_secret() + save() on every DTB webhook row and
// updates the stored fingerprint.
//
// The function is a no-op (single option read) when secrets are already in
// sync, so boot overhead is negligible.
// ============================================================================
add_action( 'woocommerce_init', 'dtb_wc_sync_webhook_secrets', 25 );

/**
 * Sync the WC_WEBHOOK_SECRET constant to all DTB wc_webhooks rows.
 *
 * Safe to call multiple times — acts only when a rotation is detected.
 * Can be forced by deleting the dtb_wc_webhook_secret_hash wp_option before
 * calling (done automatically by the admin REST endpoint).
 *
 * @return array{status: string, updated?: int, errors?: array}
 */
function dtb_wc_sync_webhook_secrets(): array {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}

	if ( defined( 'DTB_DISABLE_PRODUCT_WEBHOOKS' ) && DTB_DISABLE_PRODUCT_WEBHOOKS ) {
		return $result = [ 'status' => 'skipped', 'reason' => 'product_webhooks_disabled' ];
	}

	if ( ! class_exists( 'WC_Webhook' ) ) {
		return $result = [ 'status' => 'skipped', 'reason' => 'woocommerce_not_loaded' ];
	}

	$config       = function_exists( 'dtb_get_config' ) ? dtb_get_config() : [];
	$secret       = (string) ( $config['webhook_secret'] ?? ( defined( 'WC_WEBHOOK_SECRET' ) ? WC_WEBHOOK_SECRET : '' ) );
	$delivery_url = (string) ( $config['webhook_delivery'] ?? '' );

	if ( '' === $secret || '' === $delivery_url ) {
		return $result = [ 'status' => 'skipped', 'reason' => 'missing_config' ];
	}

	// Compare fingerprint: acts only when the secret has changed since last sync.
	$current_hash = hash( 'sha256', 'dtb-wc:' . $secret );
	$stored_hash  = (string) get_option( 'dtb_wc_webhook_secret_hash', '' );

	if ( '' !== $stored_hash && hash_equals( $current_hash, $stored_hash ) ) {
		return $result = [ 'status' => 'in_sync', 'updated' => 0 ];
	}

	// Rotation detected (or first run). Re-save secret on all DTB webhook rows.
	$webhook_ids = function_exists( 'dtb_wc_get_webhook_ids_by_delivery_url' )
		? dtb_wc_get_webhook_ids_by_delivery_url( $delivery_url )
		: [];

	$updated = 0;
	$errors  = [];
	foreach ( $webhook_ids as $id ) {
		$webhook = new WC_Webhook( (int) $id );
		if ( ! $webhook->get_id() ) {
			continue;
		}
		try {
			$webhook->set_secret( $secret );
			$webhook->save();
			$updated++;
		} catch ( Throwable $e ) {
			$errors[] = [ 'id' => (int) $id, 'error' => $e->getMessage() ];
		}
	}

	// Only advance the fingerprint when all rows were updated cleanly.
	if ( empty( $errors ) ) {
		update_option( 'dtb_wc_webhook_secret_hash', $current_hash, false );
	}

	$result = [
		'status'  => empty( $errors ) ? 'synced' : 'partial',
		'updated' => $updated,
		'errors'  => $errors,
	];

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[DTB] WC webhook secret sync: ' . wp_json_encode( $result ) );

	return $result;
}


// ============================================================================
// SECTION 8 — CHECKOUT FIELD CUSTOMISATION
//
// Strips fields not needed by this storefront from billing and shipping
// sections to keep the checkout form lean and match the headless React UI.
// ============================================================================
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	$remove = [
		'billing'  => [ 'billing_company', 'billing_address_2' ],
		'shipping' => [ 'shipping_company', 'shipping_address_2' ],
	];

	foreach ( $remove as $group => $keys ) {
		foreach ( $keys as $key ) {
			unset( $fields[ $group ][ $key ] );
		}
	}

	return $fields;
} );


// ============================================================================
// SECTION 10 — SERVER-SIDE CHECKOUT VALIDATION
//
// Runs after WooCommerce's own validation. Complements client-side checks
// in the React frontend. Disposable email domain list should be expanded in
// dtb-utils.php and passed here as a constant or filtered array in the future.
// ============================================================================
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	// Require 10+ digits for US phone numbers.
	$phone = preg_replace( '/\D/', '', $data['billing_phone'] ?? '' );
	if ( ! empty( $data['billing_phone'] ) && strlen( $phone ) < 10 ) {
		$errors->add(
			'billing_phone_invalid',
			__( 'Please enter a valid phone number (10 or more digits).', 'woocommerce' )
		);
	}

	// Block known disposable email domains.
	// TODO: move this list to a constant in dtb-utils.php.
	$blocked_domains = [ 'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwam.com' ];
	$email_parts     = explode( '@', strtolower( $data['billing_email'] ?? '' ) );
	$email_domain    = end( $email_parts );

	if ( in_array( $email_domain, $blocked_domains, true ) ) {
		$errors->add(
			'billing_email_disposable',
			__( 'Please use a permanent email address to place your order.', 'woocommerce' )
		);
	}
}, 10, 2 );


// ============================================================================
// SECTION 12 — CSV IMPORTER: CUSTOM COLUMN MAPPING
//
// Maps non-standard CSV columns to WooCommerce product fields:
//
//   MPN           → persisted to `_mpn` product meta key.
//   Brands        → should use the site's native WooCommerce brand taxonomy
//                   mapping. After import we mirror imported product_brand
//                   terms into `_dtb_brand` for backward compatibility with the
//                   existing frontend and API code.
//   Slug          → imported into the product's `post_name`.
// ============================================================================

add_filter( 'woocommerce_csv_product_import_mapping_options', function ( array $options ): array {
	$options['mpn']  = __( 'MPN', 'woocommerce' );
	$options['slug'] = __( 'Slug', 'woocommerce' );
	return $options;
} );

add_filter( 'woocommerce_csv_product_import_mapping_default_columns', function ( array $columns ): array {
	$columns['MPN']  = 'mpn';
	$columns['mpn']  = 'mpn';
	$columns['Slug'] = 'slug';
	$columns['slug'] = 'slug';
	return $columns;
} );

// Priority 10 (default) — runs after the skip-images safety net (priority 5,
// Section 14) so the product object is already stripped of images before meta
// is written. Execution order on this hook: 5 (image clear) → 10 (meta save).
add_filter( 'woocommerce_product_import_pre_insert_product_object', function ( \WC_Product $product, array $data ): \WC_Product {
	if ( ! empty( $data['mpn'] ) ) {
		$product->update_meta_data( '_mpn', sanitize_text_field( (string) $data['mpn'] ) );
	}
	if ( ! empty( $data['slug'] ) ) {
		$product->set_slug( sanitize_title( (string) $data['slug'] ) );
	}
	return $product;
}, 10, 2 );

add_filter( 'woocommerce_product_import_inserted_product_object', function ( \WC_Product $product, array $data ): \WC_Product {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		return $product;
	}

	$brands = wp_get_post_terms( $product->get_id(), 'product_brand', [ 'fields' => 'names' ] );
	if ( is_wp_error( $brands ) || empty( $brands ) ) {
		return $product;
	}

	$product->update_meta_data( '_dtb_brand', sanitize_text_field( implode( ', ', $brands ) ) );
	$product->save_meta_data();

	return $product;
}, 10, 2 );


// ============================================================================
// SECTION 13 — CSV IMPORTER: WEBP SUPPORT + BATCH PERFORMANCE
//
// Root causes that limited import to only ~20 products:
//
//   A) "Invalid image: Sorry, you are not allowed to upload this file type."
//      WordPress core does not include image/webp in its default allowed-MIME
//      list on all PHP/server configurations. The importer tries to sideload
//      each image through wp_handle_sideload() which calls
//      wp_check_filetype_and_ext(). If the server's mime_content_type() or
//      finfo doesn't return 'image/webp', WP falls back to the extension map
//      and the upload_mimes filter. We add webp to both the extension map and
//      the file_is_valid_image check so sideloading succeeds.
//
//   B) Timeout — only ~20 products imported per session.
//      wp-catalog.csv has ~1,150 rows averaging 1.8 images each (2,072 total
//      image sideloads). At ~5 s per remote image download + sub-size
//      generation, a batch of 100 requires ~900 s — far beyond any PHP time
//      limit. Shared hosting also commonly ignores set_time_limit()
//      when PHP runs as CGI/FPM, enforcing a hard 30 s limit from php.ini.
//      At 30 s, only ~20 products could complete per Ajax request.
//
//      Fix: batch size reduced to 25. At 25 products × 1.8 images × 5 s =
//      ~225 s, safely inside the 300 s limit we enforce below. Server-level
//      limits are locked in via wp/.user.ini so ini_set() restrictions
//      cannot block us.
//
//      RECOMMENDED WORKFLOW: run dtb-image-sync BEFORE importing the CSV.
//      When images are already registered in the Media Library, WooCommerce
//      calls attachment_url_to_postid() (a single indexed DB lookup, ~1 ms)
//      instead of sideloading. A batch of 25 then completes in <1 s.
//      After import, use dtb-image-sync link-only to attach any remaining
//      unlinked images by SKU.
//
//      SKIP-IMAGES MODE: set the dtb_import_skip_images WP option to 1
//      (via wp-admin › Settings › DTB Import, or WP-CLI:
//       wp option update dtb_import_skip_images 1)
//      to import all products without images first, then run
//      dtb-image-sync link-only to link images in a separate pass.
// ============================================================================

// A1 — Add webp to WordPress allowed upload MIME types.
add_filter( 'upload_mimes', function ( array $mimes ): array {
	$mimes['webp'] = 'image/webp';
	return $mimes;
} );

// A2 — Confirm webp files pass the filetype-and-ext check.
//      The second filter argument $file is the tmp path; $filename is the
//      original name. We only override when the extension is webp.
add_filter( 'wp_check_filetype_and_ext', function ( array $data, string $file, string $filename ): array {
	if ( empty( $data['ext'] ) ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'webp' === $ext ) {
			$data['ext']  = 'webp';
			$data['type'] = 'image/webp';
		}
	}
	return $data;
}, 10, 3 );

/**
 * Detect the built-in WooCommerce product importer across both page loads and
 * importer AJAX batches.
 */
function dtb_is_wc_product_import_request(): bool {
	$action = isset( $_REQUEST['action'] )
		? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	if ( in_array( $action, [ 'woocommerce_do_ajax_product_import', 'woocommerce_ajax_import_products' ], true ) ) {
		return true;
	}

	$page = isset( $_GET['page'] )
		? sanitize_text_field( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	return 'product_importer' === $page;
}

/**
 * Shared-hosting safe import mode.
 *
 * The catalog already points at files hosted on this same server, but
 * WooCommerce still performs full sideload/media handling for each image during
 * import. On a site with a very large Action Scheduler backlog, that first AJAX
 * batch becomes fragile enough to appear "stuck" in the importer UI.
 *
 * We keep the explicit option as an override, but default built-in imports to
 * skip images. Images can then be linked in a separate pass via dtb-image-sync.
 */
function dtb_should_skip_import_images(): bool {
	if ( get_option( 'dtb_import_skip_images' ) ) {
		return true;
	}

	return dtb_is_wc_product_import_request();
}

// B1 — Keep batch sizes small when image sideloading is enabled, but allow
// moderately larger batches in shared-hosting safe mode. WooCommerce updates
// its progress bar only after an AJAX batch returns, so ten rows keeps the
// first visible update responsive without creating excessive request overhead.
add_filter( 'woocommerce_product_import_batch_size', function (): int {
	return dtb_should_skip_import_images() ? 10 : 5;
} );

// B1.5 — Prevent Action Scheduler's default queue runner from competing with
// the importer request. Action Scheduler documents that its queue is initiated
// by WP-Cron and the shutdown hook on admin requests, which is a poor fit for a
// long-running importer AJAX batch on a site with a massive overdue queue.
add_action( 'init', function (): void {
	if ( ! dtb_is_wc_product_import_request() ) {
		return;
	}

	// Keep importer requests isolated from shutdown-triggered queue processing
	// without depending on Action Scheduler internal APIs that vary by version.
	global $wp_filter;
	if ( isset( $wp_filter['action_scheduler_run_queue'] ) ) {
		unset( $wp_filter['action_scheduler_run_queue'] );
	}
}, 20 );

// B2 — Enforce execution time and memory limits early in each import Ajax call.
// Current WooCommerce runs the batch directly from its importer controller and
// does not expose the older product-import start/end lifecycle hooks.
//
// Server-level limits (max_execution_time, memory_limit, upload_max_filesize)
// are also set in wp/.user.ini to override the host php.ini where supported
// without relying on ini_set() or set_time_limit() being available.
add_action( 'admin_init', function (): void {
	if ( dtb_is_wc_product_import_request() ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '768M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted
			ini_set( 'default_socket_timeout', '60' ); // phpcs:ignore WordPress.PHP.IniSet.blacklist
		}
	}
} );

// ============================================================================
// SECTION 14 — CSV IMPORTER: SKIP-IMAGES MODE
//
// When the WP option dtb_import_skip_images is truthy, the importer clears
// image fields from every parsed CSV row before WooCommerce attempts to
// sideload them. This makes each batch complete in milliseconds instead of
// minutes, allowing all 1,150 products to import without any timeout risk.
//
// Intended workflow:
//   1. Enable skip-images mode:
//        wp option update dtb_import_skip_images 1
//   2. Import wp-catalog.csv via WooCommerce › Products › Import.
//      All products are created without images.
//   3. Run dtb-image-sync (sync + link) to register pre-placed image files
//      and link them to products by SKU.
//   4. Disable skip-images mode:
//        wp option delete dtb_import_skip_images
//
// Filter: woocommerce_product_importer_parsed_data (WooCommerce 3.1+)
//   Fires after each CSV row is parsed and column-mapped, before any
//   sideloading or product-object construction. Removing the 'images' key
//   here is the earliest and safest interception point — no HTTP requests
//   are made and no orphaned attachments are created.
// ============================================================================

add_filter( 'woocommerce_product_importer_parsed_data', function ( array $data ): array {
	if ( dtb_should_skip_import_images() ) {
		unset( $data['images'] );
		unset( $data['thumbnail'] );
	}
	return $data;
} );

// Safety net: also clear images from the product object right before save.
// Runs only in skip-images mode. Priority 5 ensures this fires BEFORE the
// meta-persistence handler (Section 12, priority 10), so the product object
// is clean (no image IDs) when meta is written. This also prevents edge cases
// where woocommerce_product_importer_parsed_data fired after sideloading.
add_filter( 'woocommerce_product_import_pre_insert_product_object', function ( \WC_Product $product, array $data ): \WC_Product {
	if ( dtb_should_skip_import_images() ) {
		$product->set_image_id( '' );
		$product->set_gallery_image_ids( [] );
	}
	return $product;
}, 5, 2 );

// Surface the safe-mode behavior so the importer UI matches what the backend
// is doing. This only shows on the mapping/upload screens, not during the AJAX
// batch itself.
add_action( 'admin_notices', function (): void {
	if ( ! is_admin() || ! dtb_is_wc_product_import_request() ) {
		return;
	}

	if ( get_option( 'dtb_import_skip_images' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>DTB import safe mode is active.</strong> This WooCommerce CSV import will skip image sideloading to avoid shared-hosting timeouts. After the products import, run DTB image linking to attach the registered images by SKU.</p></div>';
} );

// Temporary importer diagnostics: show effective REST root on importer pages.
// Enable with:
//   define( 'DTB_DEBUG_IMPORTER_REST_ROOT_NOTICE', true );
add_action( 'admin_notices', function (): void {
	if ( ! dtb_feature_enabled( 'DTB_DEBUG_IMPORTER_REST_ROOT_NOTICE', false ) ) {
		return;
	}

	if ( ! is_admin() || ! dtb_is_wc_product_import_request() ) {
		return;
	}

	$rest_root = esc_url( trailingslashit( rest_url() ) );
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$page        = isset( $_GET['page'] )
		? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';
	$action      = isset( $_REQUEST['action'] )
		? sanitize_text_field( wp_unslash( (string) $_REQUEST['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	echo '<div class="notice notice-info"><p><strong>DTB importer REST root debug:</strong> <code>' . $rest_root . '</code><br><strong>Request URI:</strong> <code>' . esc_html( $request_uri ) . '</code><br><strong>$_GET[page]:</strong> <code>' . esc_html( $page ) . '</code><br><strong>$_REQUEST[action]:</strong> <code>' . esc_html( $action ) . '</code></p></div>';
} );

// =============================================================================
// OPS DASHBOARD ANALYTICS HELPERS
// =============================================================================

/**
 * Return the total number of WooCommerce orders placed today (midnight to now).
 *
 * Results are cached for 5 minutes via dtb_ops transient store.
 *
 * @return int Order count for today.
 */
function dtb_woo_get_orders_today(): int {
if ( function_exists( 'dtb_ops_cache_get' ) ) {
return (int) dtb_ops_cache_get( 'orders', 'today_count', DTB_OPS_TTL_ORDERS ?? 300, static function () {
return dtb_woo_query_orders_today();
} );
}
return dtb_woo_query_orders_today();
}

/**
 * Internal: run the database query for today's order count.
 *
 * @return int
 */
function dtb_woo_query_orders_today(): int {
$today_start = gmdate( 'Y-m-d 00:00:00' );
$today_end   = gmdate( 'Y-m-d 23:59:59' );

$orders = wc_get_orders( [
'limit'        => -1,
'return'       => 'ids',
'date_created' => $today_start . '...' . $today_end,
'status'       => array_keys( wc_get_order_statuses() ),
] );

return is_array( $orders ) ? count( $orders ) : 0;
}

/**
 * Return the count of WooCommerce orders grouped by status.
 *
 * @param string[] $statuses WC order status slugs (without 'wc-' prefix).
 *                           Defaults to [ 'pending', 'processing', 'on-hold' ].
 * @return array { status => count } keyed by status slug.
 */
function dtb_woo_get_orders_by_status( array $statuses = [] ): array {
if ( empty( $statuses ) ) {
$statuses = [ 'pending', 'processing', 'on-hold' ];
}

if ( function_exists( 'dtb_ops_cache_get' ) ) {
$cache_key = md5( implode( ',', $statuses ) );
return (array) dtb_ops_cache_get( 'orders', 'by_status_' . $cache_key, DTB_OPS_TTL_ORDERS ?? 300, static function () use ( $statuses ) {
return dtb_woo_query_orders_by_status( $statuses );
} );
}

return dtb_woo_query_orders_by_status( $statuses );
}

/**
 * Internal: run the query for orders-by-status.
 *
 * @param string[] $statuses
 * @return array
 */
function dtb_woo_query_orders_by_status( array $statuses ): array {
$result = [];
foreach ( $statuses as $status ) {
$orders = wc_get_orders( [
'limit'  => -1,
'return' => 'ids',
'status' => 'wc-' . ltrim( $status, 'wc-' ),
] );
$result[ $status ] = is_array( $orders ) ? count( $orders ) : 0;
}
return $result;
}

/**
 * Return the count of WooCommerce products that are at or below their low-stock
 * threshold, or with zero/negative stock.
 *
 * @param int $threshold Stock quantity at or below which a product is "low stock".
 * @return int Low-stock product count.
 */
function dtb_woo_get_low_stock_count( int $threshold = 5 ): int {
if ( function_exists( 'dtb_ops_cache_get' ) ) {
return (int) dtb_ops_cache_get( 'inventory', 'woo_low_stock_' . $threshold, DTB_OPS_TTL_INVENTORY ?? 300, static function () use ( $threshold ) {
return dtb_woo_query_low_stock_count( $threshold );
} );
}
return dtb_woo_query_low_stock_count( $threshold );
}

/**
 * Internal: run the low-stock query.
 *
 * @param int $threshold
 * @return int
 */
function dtb_woo_query_low_stock_count( int $threshold ): int {
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$count = (int) $wpdb->get_var(
$wpdb->prepare(
"SELECT COUNT(DISTINCT p.ID)
 FROM {$wpdb->posts} p
 INNER JOIN {$wpdb->postmeta} pm_manage ON pm_manage.post_id = p.ID
     AND pm_manage.meta_key = '_manage_stock'
     AND pm_manage.meta_value = 'yes'
 INNER JOIN {$wpdb->postmeta} pm_qty ON pm_qty.post_id = p.ID
     AND pm_qty.meta_key = '_stock'
     AND CAST(pm_qty.meta_value AS SIGNED) <= %d
 WHERE p.post_type IN ('product', 'product_variation')
   AND p.post_status = 'publish'",
$threshold
)
);

return $count;
}

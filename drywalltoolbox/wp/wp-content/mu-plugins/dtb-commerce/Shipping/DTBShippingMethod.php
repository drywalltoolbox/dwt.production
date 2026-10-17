<?php

defined( 'ABSPATH' ) || exit;

defined( 'DTB_SHIPPING_METHOD_ID' ) || define( 'DTB_SHIPPING_METHOD_ID', 'dtb_veeqo_rates' );
defined( 'DTB_SHIPPING_ZONE_BOOTSTRAP_VERSION' ) || define( 'DTB_SHIPPING_ZONE_BOOTSTRAP_VERSION', '4' );

// =============================================================================
// SERVER-AUTHORITATIVE WOOCOMMERCE SHIPPING METHOD
//
// Registers the server-authoritative Drywall Toolbox shipping policy as a
// WooCommerce shipping method available in WooCommerce shipping zones.
//
// The method derives its inputs from WooCommerce's server-side cart package.
// For a domestic (US) destination it is a policy method *and* a live Veeqo
// carrier-rating adapter: the free/discounted Standard tier is always DTB's
// own merchant policy (Veeqo has no concept of a subsidized "free" rate),
// but Express/Overnight are live carrier quotes from Veeqo's Rate Shopping
// API (see DTB_VeeqoShippingService::live_domestic_rates() in
// mu-plugins/dtb-integrations/Veeqo/VeeqoShippingService.php) whenever
// Veeqo is configured and reachable, falling back to the local weight-tiered
// estimates otherwise. International destinations remain entirely local
// policy — the Rate Shopping API is documented as US-only.
// =============================================================================

add_action( 'woocommerce_shipping_init', 'dtb_commerce_register_shipping_method' );

function dtb_commerce_register_shipping_method(): void {
	if ( ! class_exists( 'DTB_Shipping_Method' ) ) {

		/**
		 * WooCommerce shipping method: DTB Shipping Policy.
		 *
		 * Shows Standard, Express, and Overnight options calculated from the
		 * cart total and total weight. Free standard shipping is applied
		 * automatically for domestic orders >= $50.
		 */
		class DTB_Shipping_Method extends WC_Shipping_Method {

			public function __construct( int $instance_id = 0 ) {
				$this->id                 = DTB_SHIPPING_METHOD_ID;
				$this->instance_id        = $instance_id;
				$this->method_title       = __( 'Drywall Toolbox Shipping', 'woocommerce' );
				$this->method_description = __( 'Server-authoritative Drywall Toolbox shipping policy.', 'woocommerce' );
				$this->supports           = [ 'shipping-zones', 'instance-settings' ];
				$this->title              = $this->get_option( 'title', __( 'Shipping', 'woocommerce' ) );
				$this->enabled            = 'yes';

				$this->init();
			}

			public function init(): void {
				$this->init_form_fields();
				$this->init_settings();
				add_action(
					'woocommerce_update_options_shipping_' . $this->id,
					[ $this, 'process_admin_options' ]
				);
			}

			public function init_form_fields(): void {
				$this->form_fields = [
					'title' => [
						'title'   => __( 'Method title', 'woocommerce' ),
						'type'    => 'text',
						'default' => __( 'Shipping', 'woocommerce' ),
					],
				];
			}

			/**
			 * Calculate shipping rates for the current cart and destination.
			 *
			 * @param array $package WooCommerce shipping package (destination + contents).
			 */
			public function calculate_shipping( $package = [] ): void {
				$destination = $package['destination'] ?? [];
				$contents    = $package['contents'] ?? [];

				$subtotal     = 0.0;
				$total_weight = 0.0;
				$has_repair   = false;

				foreach ( $contents as $cart_item ) {
					$product = $cart_item['data'] ?? null;
					$qty     = (int) ( $cart_item['quantity'] ?? 1 );
					$price   = $product ? (float) $product->get_price() : 0.0;
					$weight  = $product ? (float) $product->get_weight() : 0.5;

					$subtotal     += $price * $qty;
					$total_weight += $weight * $qty;

					$cats = $product ? wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ) : [];
					foreach ( (array) $cats as $cat ) {
						if ( str_contains( strtolower( $cat ), 'repair' ) || str_contains( strtolower( $cat ), 'service' ) ) {
							$has_repair = true;
						}
					}
				}

				$country = strtoupper( sanitize_text_field( $destination['country'] ?? 'US' ) );

				if ( $has_repair ) {
					$this->add_rate( [
						'id'    => $this->get_rate_id( 'repair_prepaid' ),
						'label' => __( 'Repair Service — Prepaid Label', 'woocommerce' ),
						'cost'  => 0.00,
					] );
					return;
				}

				$is_domestic = ( 'US' === $country );

				if ( $is_domestic ) {
					$standard = $subtotal >= 50.0 ? 0.00
						: ( $total_weight <= 1.0 ? 7.99
							: ( $total_weight <= 5.0 ? 12.99
								: ( $total_weight <= 15.0 ? 19.99 : 29.99 ) ) );

					$this->add_rate( [
						'id'    => $this->get_rate_id( 'standard' ),
						'label' => $subtotal >= 50.0
							? __( 'Free Standard Shipping (5–7 business days)', 'woocommerce' )
							: __( 'Standard Shipping (5–7 business days)', 'woocommerce' ),
						'cost'  => $standard,
					] );

					// Live carrier quotes (Veeqo's Rate Shopping API — see
					// DTB_VeeqoShippingService::live_domestic_rates()) replace the
					// synthetic Express/Overnight estimates below whenever they're
					// available. The free/discounted Standard tier above is a DTB
					// merchant policy, not a carrier rate, so it always stays as-is
					// regardless of live-rate availability.
					$live_rates = class_exists( 'DTB_VeeqoShippingService' )
						? DTB_VeeqoShippingService::live_domestic_rates( $destination, $total_weight )
						: [ 'ok' => false, 'quotes' => [] ];

					if ( ! empty( $live_rates['ok'] ) && ! empty( $live_rates['quotes'] ) ) {
						foreach ( $live_rates['quotes'] as $quote ) {
							$this->add_rate( [
								'id'    => $this->get_rate_id( 'veeqo_' . $quote['id'] ),
								'label' => sprintf( '%s — %s', $quote['carrier'], $quote['service'] ),
								'cost'  => $quote['cost'],
							] );
						}
					} else {
						// Live rates unavailable (Veeqo not configured, API error,
						// incomplete address, non-US, or no usable quotes) — fall back
						// to the local weight-tiered estimates so checkout always shows
						// expedited options.
						$this->add_rate( [
							'id'    => $this->get_rate_id( 'express' ),
							'label' => __( 'Express Shipping (2–3 business days)', 'woocommerce' ),
							'cost'  => max( 0.00, $standard + 10.00 ),
						] );
						$this->add_rate( [
							'id'    => $this->get_rate_id( 'overnight' ),
							'label' => __( 'Overnight Shipping (next business day)', 'woocommerce' ),
							'cost'  => max( 0.00, $standard + 30.00 ),
						] );
					}
				} else {
					$base = $total_weight <= 2.0 ? 29.99 : ( $total_weight <= 10.0 ? 49.99 : 79.99 );
					$this->add_rate( [
						'id'    => $this->get_rate_id( 'intl_standard' ),
						'label' => __( 'International Standard (10–15 business days)', 'woocommerce' ),
						'cost'  => $base,
					] );
					$this->add_rate( [
						'id'    => $this->get_rate_id( 'intl_express' ),
						'label' => __( 'International Express (5–7 business days)', 'woocommerce' ),
						'cost'  => $base + 30.00,
					] );
				}
			}
		}
	}
}

add_filter( 'woocommerce_shipping_methods', function ( array $methods ): array {
	$methods[ DTB_SHIPPING_METHOD_ID ] = 'DTB_Shipping_Method';
	return $methods;
} );

/**
 * Return whether a shipping zone already contains a DTB policy instance.
 *
 * Disabled instances count as existing so migrations never create duplicates
 * or override an operator's explicit disabled state.
 */
function dtb_commerce_zone_has_shipping_method( WC_Shipping_Zone $zone ): bool {
	foreach ( (array) $zone->get_shipping_methods( false, 'admin' ) as $method ) {
		if ( is_object( $method ) && DTB_SHIPPING_METHOD_ID === (string) ( $method->id ?? '' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return whether the zone's DTB policy instance is present *and enabled*.
 *
 * Distinct from `dtb_commerce_zone_has_shipping_method()` above on purpose:
 * that check intentionally treats a disabled instance as "present" so the
 * self-heal below never overrides an operator's explicit disable. But that
 * same design means a disabled instance is otherwise invisible — checkout
 * silently falls back to whatever other method is enabled on the zone (e.g.
 * a bare flat rate) with no signal to an operator that anything is
 * unusual. This helper exists only to let the bootstrap below detect that
 * specific, easy-to-miss state and surface it via an admin notice, without
 * changing the self-heal's behavior of respecting the disable itself.
 */
function dtb_commerce_zone_shipping_method_enabled( WC_Shipping_Zone $zone ): bool {
	foreach ( (array) $zone->get_shipping_methods( false, 'admin' ) as $method ) {
		if ( ! is_object( $method ) || DTB_SHIPPING_METHOD_ID !== (string) ( $method->id ?? '' ) ) {
			continue;
		}
		return method_exists( $method, 'is_enabled' )
			? (bool) $method->is_enabled()
			: 'yes' === (string) ( $method->enabled ?? '' );
	}

	return false;
}

/**
 * Add DTB policy rates in memory when the matching zone has no DTB instance.
 *
 * This keeps public quote requests read-only. The rate fallback uses the same
 * server-authoritative method calculation without mutating WooCommerce shipping
 * zone configuration during an interactive checkout request. A disabled DTB
 * instance is treated as explicit operator intent and is not bypassed.
 *
 * @param array<string,WC_Shipping_Rate> $rates   Rates from the matching zone.
 * @param array<string,mixed>            $package Active shipping package.
 * @return array<string,WC_Shipping_Rate>
 */
function dtb_commerce_add_policy_rates_when_missing( array $rates, array $package ): array {
	foreach ( $rates as $rate ) {
		$method_id = is_object( $rate ) && method_exists( $rate, 'get_method_id' )
			? (string) $rate->get_method_id()
			: '';
		if ( DTB_SHIPPING_METHOD_ID === $method_id ) {
			return $rates;
		}
	}

	if ( class_exists( 'WC_Shipping_Zones' ) && class_exists( 'WC_Shipping_Zone' ) ) {
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( $zone instanceof WC_Shipping_Zone && dtb_commerce_zone_has_shipping_method( $zone ) ) {
			return $rates;
		}
	}

	if ( ! class_exists( 'DTB_Shipping_Method' ) && class_exists( 'WC_Shipping_Method' ) ) {
		dtb_commerce_register_shipping_method();
	}
	if ( ! class_exists( 'DTB_Shipping_Method' ) ) {
		return $rates;
	}

	$method = new DTB_Shipping_Method( 0 );
	foreach ( (array) $method->get_rates_for_package( $package ) as $rate_id => $rate ) {
		if ( $rate instanceof WC_Shipping_Rate && ! isset( $rates[ $rate_id ] ) ) {
			$rates[ $rate_id ] = $rate;
		}
	}

	return $rates;
}
add_filter( 'woocommerce_package_rates', 'dtb_commerce_add_policy_rates_when_missing', 100, 2 );

/**
 * Remove WooCommerce's overlapping Free Shipping rate when DTB policy rates are
 * present for the package.
 *
 * DTB is the shipping-policy authority and already emits a free standard tier
 * for eligible domestic carts. Keeping Woo's separate `free_shipping` method
 * exposes two equivalent zero-cost choices with different labels and can persist
 * an obsolete selected rate in the cart session. We therefore suppress only the
 * conflicting core Free Shipping method, and only when at least one DTB policy
 * rate is present. Other third-party or pickup methods are preserved.
 *
 * @param array<string,WC_Shipping_Rate> $rates   Calculated package rates.
 * @param array<string,mixed>            $package Active shipping package.
 * @return array<string,WC_Shipping_Rate>
 */
function dtb_commerce_remove_overlapping_free_shipping_rate( array $rates, array $package ): array {
	$has_dtb_rate = false;

	foreach ( $rates as $rate ) {
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			continue;
		}

		if ( DTB_SHIPPING_METHOD_ID === (string) $rate->get_method_id() ) {
			$has_dtb_rate = true;
			break;
		}
	}

	if ( ! $has_dtb_rate ) {
		return $rates;
	}

	foreach ( $rates as $rate_id => $rate ) {
		if ( $rate instanceof WC_Shipping_Rate && 'free_shipping' === (string) $rate->get_method_id() ) {
			unset( $rates[ $rate_id ] );
		}
	}

	return $rates;
}
add_filter( 'woocommerce_package_rates', 'dtb_commerce_remove_overlapping_free_shipping_rate', 110, 2 );

/**
 * Force every visitor's cached shipping rates stale, not just the current
 * request's own session.
 *
 * WooCommerce caches calculated package rates in each customer's own session
 * (`shipping_for_package_N`), keyed by a hash of the package plus
 * `WC_Cache_Helper::get_transient_version( 'shipping' )`. The admin UI bumps
 * that version whenever a shipping zone/method is edited through wp-admin
 * (see WC_AJAX / WC_Admin shipping-zone handlers), so a saved change is
 * immediately visible to every customer regardless of what they already had
 * cached. `dtb_bootstrap_shipping_zones()` calls `add_shipping_method()`
 * programmatically, which bypasses those admin-side hooks entirely — so a
 * zone repair alone left every customer who already had a package-rate
 * calculation cached (from before the zone had a working DTB method)
 * permanently stuck on that stale result until their session expired or
 * their address changed, even though the zone itself was already fixed.
 * Bumping the version here (the same `get_transient_version( group, true )`
 * pattern already used elsewhere in this codebase for 'settings' and
 * 'product') invalidates every cached package hash at once.
 */
function dtb_commerce_bump_shipping_cache_version(): void {
	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
}

/**
 * Clear WooCommerce's request/session package-rate cache.
 *
 * @param array<int,array<string,mixed>> $packages Current cart shipping packages.
 */
function dtb_commerce_invalidate_shipping_package_cache( array $packages = [] ): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$package_keys = array_keys( $packages );
	if ( empty( $package_keys ) ) {
		$package_keys = [ 0 ];
	}

	foreach ( $package_keys as $package_key ) {
		$session_key = 'shipping_for_package_' . absint( $package_key );
		if ( method_exists( WC()->session, '__unset' ) ) {
			WC()->session->__unset( $session_key );
		} else {
			WC()->session->set( $session_key, null );
		}
	}
}

/**
 * Return whether a zone location can match a United States destination.
 */
function dtb_commerce_zone_matches_us( WC_Shipping_Zone $zone ): bool {
	foreach ( (array) $zone->get_zone_locations() as $location ) {
		$type = sanitize_key( (string) ( $location->type ?? '' ) );
		$code = strtoupper( sanitize_text_field( (string) ( $location->code ?? '' ) ) );
		if ( ( 'country' === $type && 'US' === $code ) || str_starts_with( $code, 'US:' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Bootstrap and repair the required persisted policy instances.
 *
 * Runs on a single `init` hook (covers frontend and wp-admin requests alike;
 * previously two separate hooks — `woocommerce_init` and `admin_init` — did
 * the same job, which was never actually necessary once this function
 * guards its own class-existence check itself).
 *
 * SELF-HEALING: re-checks every real zone matching a US location (not just
 * the Rest-of-World zone 0) and re-attaches the DTB method to any of them
 * that are missing it, regardless of the version option — see git history
 * for the bug this fixed (a version-gated short-circuit on zone 0 alone
 * used to skip the US-zone repair loop entirely). The version option is
 * only a write-avoidance signal for the cache-bump below, not a gate on
 * whether repair happens.
 *
 * RATE-LIMITED, NOT PER-REQUEST: the checks above require live DB reads
 * (zone list, zone locations, zone methods) for state that essentially
 * never changes between one request and the next — running them on every
 * single storefront and wp-admin page load in perpetuity was pure
 * overhead for a condition that, in practice, only changes when an
 * operator edits shipping settings or this code ships a new bootstrap
 * version. A transient caps the actual DB work at once per hour
 * site-wide; a version bump (a real code change to what "correct" means)
 * always forces an immediate full check regardless of the transient,
 * since the transient has no way to know about a change that happened
 * only in code.
 *
 * WHAT SELF-HEALING CANNOT AND SHOULD NOT DO: if the DTB method exists on
 * the US zone but an operator disabled it, that is respected as explicit
 * intent and never silently re-enabled — but that also means it's
 * otherwise invisible, since checkout just quietly falls back to whatever
 * other method is enabled instead. This exact, easy-to-miss state is what
 * produced a real production incident (checkout showing only a flat "Free
 * shipping" rate with no visible cause). Auto-repair cannot both respect
 * an intentional disable and safely guess whether a given disable was
 * intentional, so instead of adding more repair logic here, this now
 * surfaces that state as a wp-admin notice — see
 * `dtb_commerce_render_shipping_method_disabled_notice()` below — so an
 * operator sees it directly instead of it requiring an investigation.
 */
add_action( 'init', 'dtb_bootstrap_shipping_zones', 20 );
// Priority 1: dtb_commerce_render_shipping_method_disabled_notice() below
// calls DTB_AdminNoticeService::add_error(), which itself registers another
// default-priority (10) admin_notices callback. If this function ran at the
// default priority too, that registration would happen *while* WordPress is
// already iterating the priority-10 bucket on this same admin_notices firing,
// and the newly-added callback can be skipped for this page load — the
// notice would silently not render. Running this at priority 1 guarantees
// its add_error() call registers before priority 10 is ever reached.
add_action( 'admin_notices', 'dtb_commerce_render_shipping_method_disabled_notice', 1 );

function dtb_bootstrap_shipping_zones(): void {
	if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
		return;
	}

	$version_match = DTB_SHIPPING_ZONE_BOOTSTRAP_VERSION === (string) get_option( 'dtb_shipping_zones_bootstrapped' );

	if ( $version_match && false !== get_transient( 'dtb_shipping_zone_bootstrap_checked' ) ) {
		return;
	}

	// get_transient()/set_transient() alone are not atomic: two requests
	// arriving after the transient expires (or on first-ever load) could
	// both pass the check above and both run the repair below concurrently.
	// WooCommerce's own WC_Shipping_Zone::add_shipping_method() has no
	// built-in duplicate guard — it always inserts a new zone-method row —
	// so an unguarded race here could leave a zone with two DTB method
	// instances. add_option() is atomic (a single INSERT relying on the
	// unique key on option_name, unlike update_option()/set_transient()),
	// which makes it WordPress's standard lightweight mutex idiom; used here
	// instead of a bespoke locking mechanism.
	if ( ! dtb_commerce_acquire_shipping_bootstrap_lock() ) {
		return;
	}

	$repaired            = false;
	$disabled_on_us_zone = false;

	$has_us_zone = false;
	foreach ( (array) WC_Shipping_Zones::get_zones() as $zone_data ) {
		$zone = WC_Shipping_Zones::get_zone( (int) ( $zone_data['zone_id'] ?? 0 ) );
		if ( ! $zone instanceof WC_Shipping_Zone || ! dtb_commerce_zone_matches_us( $zone ) ) {
			continue;
		}

		$has_us_zone = true;
		if ( ! dtb_commerce_zone_has_shipping_method( $zone ) ) {
			$zone->add_shipping_method( DTB_SHIPPING_METHOD_ID );
			$repaired = true;
		} elseif ( ! dtb_commerce_zone_shipping_method_enabled( $zone ) ) {
			$disabled_on_us_zone = true;
		}
	}

	if ( ! $has_us_zone ) {
		$us_zone = new WC_Shipping_Zone();
		$us_zone->set_zone_name( 'United States' );
		$us_zone->set_zone_order( 1 );
		$us_zone->add_location( 'US', 'country' );
		$us_zone->save();
		$us_zone->add_shipping_method( DTB_SHIPPING_METHOD_ID );
		$repaired = true;
	}

	$row_zone = new WC_Shipping_Zone( 0 );
	if ( ! dtb_commerce_zone_has_shipping_method( $row_zone ) ) {
		$row_zone->add_shipping_method( DTB_SHIPPING_METHOD_ID );
		$repaired = true;
	}

	update_option( 'dtb_shipping_zone_method_disabled_warning', $disabled_on_us_zone ? '1' : '', false );

	if ( $repaired || ! $version_match ) {
		// A repaired zone (or a version bump, e.g. this fix shipping in the
		// first place) means some request may already have a stale rate
		// list cached from before the fix — bump the shared version so
		// every visitor's next calculation recomputes against the current
		// zone state instead of quietly keeping whatever it cached earlier.
		dtb_commerce_bump_shipping_cache_version();
		dtb_commerce_invalidate_shipping_package_cache();
		update_option( 'dtb_shipping_zones_bootstrapped', DTB_SHIPPING_ZONE_BOOTSTRAP_VERSION );
	}

	// The transient is set only after a full pass completes, and only marks
	// "recently checked" as a cheap fast-path skip for the common case —
	// the add_option() lock above (not this) is what actually prevents a
	// concurrent duplicate repair.
	set_transient( 'dtb_shipping_zone_bootstrap_checked', 1, HOUR_IN_SECONDS );
	dtb_commerce_release_shipping_bootstrap_lock();
}

/**
 * Acquire a short-lived, atomic lock so two concurrent requests can never
 * both run the shipping-zone repair pass at once. `add_option()` fails
 * (returns false) if the option row already exists — an atomic DB-level
 * check, unlike `update_option()`/transients — making it WordPress's
 * standard idiom for a lightweight mutex. Reclaims a lock older than 60
 * seconds (far longer than this repair pass should ever take) so a request
 * that crashed mid-repair can't wedge every future bootstrap attempt.
 */
function dtb_commerce_acquire_shipping_bootstrap_lock(): bool {
	$lock_option = 'dtb_shipping_zone_bootstrap_lock';

	if ( add_option( $lock_option, time(), '', 'no' ) ) {
		return true;
	}

	$acquired_at = (int) get_option( $lock_option );
	if ( $acquired_at > 0 && ( time() - $acquired_at ) > 60 ) {
		delete_option( $lock_option );
		return add_option( $lock_option, time(), '', 'no' );
	}

	return false;
}

/**
 * Release the lock acquired by `dtb_commerce_acquire_shipping_bootstrap_lock()`.
 */
function dtb_commerce_release_shipping_bootstrap_lock(): void {
	delete_option( 'dtb_shipping_zone_bootstrap_lock' );
}

/**
 * Warn an operator in wp-admin if the United States zone's DTB shipping
 * method is disabled — the state `dtb_bootstrap_shipping_zones()` above
 * deliberately never auto-corrects (see that function's comment). Reads
 * only the option that function already computed during its last rate-
 * limited pass; performs no DB query of its own on every admin page load.
 */
function dtb_commerce_render_shipping_method_disabled_notice(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( '1' !== get_option( 'dtb_shipping_zone_method_disabled_warning' ) ) {
		return;
	}
	if ( ! class_exists( 'DTB_AdminNoticeService' ) ) {
		return;
	}

	DTB_AdminNoticeService::add_error(
		'Drywall Toolbox Shipping is disabled on the "United States" shipping zone. ' .
		'Customers only see whatever other method is enabled there (e.g. a flat Free Shipping rate) ' .
		'instead of Standard/Express/Overnight. Enable it under WooCommerce → Settings → Shipping → United States.'
	);
}

<?php
/**
 * Guarded cutover from the legacy DTB shipping method to preconfigured
 * WooCommerce core Flat Rate and Free Shipping methods.
 *
 * This command never invents prices, creates methods, assigns product classes,
 * purchases labels, or changes orders. Configure and verify core methods first.
 *
 * Preview:
 * wp --require=scripts/wordpress/migrate-native-shipping.php dtb shipping migrate
 *
 * Apply only after the checkout rates, destination coverage, $50 free-shipping
 * eligibility has been verified for all carts, including oversized items:
 * wp --require=scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --apply --allow-production --coverage-confirmed --free-shipping-reviewed
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class DTB_Native_Shipping_Cutover_Command {
	public function __invoke( $args, $assoc_args ): void {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
			WP_CLI::error( 'WooCommerce shipping is unavailable.' );
		}

		$apply = isset( $assoc_args['apply'] );
		if ( $apply && 'production' === wp_get_environment_type() && ! isset( $assoc_args['allow-production'] ) ) {
			WP_CLI::error( 'Production cutover requires --allow-production.' );
		}

		$zones = [ new WC_Shipping_Zone( 0 ) ];
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone = WC_Shipping_Zones::get_zone( (int) ( $zone_data['zone_id'] ?? 0 ) );
			if ( $zone instanceof WC_Shipping_Zone ) {
				$zones[] = $zone;
			}
		}

		$us_zones = [];
		$methods_by_zone = [];
		$legacy_instances = [];
		foreach ( $zones as $zone ) {
			$zone_id = (int) $zone->get_id();
			$methods = (array) $zone->get_shipping_methods( false, 'admin' );
			$methods_by_zone[ $zone_id ] = $methods;

			foreach ( $methods as $method ) {
				if ( ! is_object( $method ) ) {
					continue;
				}
				$type = (string) ( $method->id ?? '' );
				$instance_id = (int) ( $method->instance_id ?? 0 );
				$enabled = method_exists( $method, 'is_enabled' )
					? $method->is_enabled()
					: 'yes' === (string) ( $method->enabled ?? '' );

				if ( 'dtb_veeqo_rates' === $type && $enabled ) {
					$legacy_instances[] = [ 'zone' => $zone_id, 'instance' => $instance_id ];
				}
			}

			if ( 0 === $zone_id ) {
				continue;
			}
			foreach ( (array) $zone->get_zone_locations() as $location ) {
				if ( 'country' === (string) $location->type && 'US' === strtoupper( (string) $location->code ) ) {
					$us_zones[] = $zone_id;
					break;
				}
			}
		}

		if ( 1 !== count( $us_zones ) ) {
			WP_CLI::error( 'Expected exactly one United States shipping zone; resolve zone ordering and scope before cutover.' );
		}
		$us_zone_id = $us_zones[0];
		$us_methods = $methods_by_zone[ $us_zone_id ] ?? [];
		$flat_rates = [];
		$free_rates = [];

		foreach ( $us_methods as $method ) {
			if ( ! is_object( $method ) || ! method_exists( $method, 'get_option' ) ) {
				continue;
			}
			if ( 'flat_rate' === (string) ( $method->id ?? '' ) && 'yes' === (string) ( $method->enabled ?? '' ) ) {
				$flat_rates[] = $method;
			}
			if ( 'free_shipping' === (string) ( $method->id ?? '' ) && 'yes' === (string) ( $method->enabled ?? '' ) ) {
				$free_rates[] = $method;
			}
		}

		if ( 1 !== count( $flat_rates ) ) {
			WP_CLI::error( 'US zone must have exactly one enabled WooCommerce core Flat Rate method before cutover.' );
		}
		if ( 1 !== count( $free_rates ) ) {
			WP_CLI::error( 'US zone must have exactly one enabled WooCommerce core Free Shipping method before cutover.' );
		}

		$flat_rate = $flat_rates[0];
		$free_rate = $free_rates[0];
		$flat_settings = (array) ( $flat_rate->instance_settings ?? [] );
		$free_requires = (string) $free_rate->get_option( 'requires', '' );
		$free_minimum = wc_format_decimal( (string) $free_rate->get_option( 'min_amount', '' ), 2 );
		if ( empty( $flat_settings['cost'] ) ) {
			WP_CLI::error( 'Core Flat Rate has no configured base charge; enter a merchant-approved price before cutover.' );
		}
		if ( 'min_amount' !== $free_requires || '50.00' !== $free_minimum ) {
			WP_CLI::error( 'Core Free Shipping must require a $50.00 minimum before cutover.' );
		}

		$ship_to = (string) get_option( 'woocommerce_ship_to_countries', '' );
		$selling = (string) get_option( 'woocommerce_allowed_countries', 'all' );
		$domestic_only = 'disabled' === $ship_to
			|| ( 'specific' === $ship_to
				&& ! array_diff( (array) get_option( 'woocommerce_specific_ship_to_countries', [] ), [ 'US' ] ) )
			|| ( '' === $ship_to && 'specific' === $selling
				&& ! array_diff( (array) get_option( 'woocommerce_specific_allowed_countries', [] ), [ 'US' ] ) );

		if ( ! $domestic_only ) {
			$world_methods = $methods_by_zone[0] ?? [];
			$world_flat = false;
			foreach ( $world_methods as $method ) {
				if ( is_object( $method ) && 'flat_rate' === (string) ( $method->id ?? '' )
					&& 'yes' === (string) ( $method->enabled ?? '' ) ) {
					$world_flat = true;
				}
			}
			if ( ! $world_flat ) {
				WP_CLI::error( 'Non-US shipping remains enabled but Rest of World has no enabled core Flat Rate. Verify destination policy or configure an approved method first.' );
			}
		}

		WP_CLI::log( 'Enabled core Flat Rate instance: ' . (int) $flat_rate->instance_id );
		WP_CLI::log( 'Enabled core Free Shipping instance: ' . (int) $free_rate->instance_id . ' (minimum $50.00)' );
		WP_CLI::log( 'Enabled legacy DTB instances to disable: ' . wp_json_encode( $legacy_instances ) );
		WP_CLI::log( 'Destination policy: ' . ( $domestic_only ? 'US only' : 'non-US destinations enabled; Rest-of-World Flat Rate present' ) );

		if ( ! $apply ) {
			WP_CLI::success( 'Read-only preview. No methods or migration markers were changed.' );
			return;
		}
		foreach ( [ 'coverage-confirmed', 'free-shipping-reviewed' ] as $required ) {
			if ( ! isset( $assoc_args[ $required ] ) ) {
				WP_CLI::error( 'Apply requires --coverage-confirmed and --free-shipping-reviewed after reviewing active WooCommerce destination and $50 free-shipping settings.' );
			}
		}

		foreach ( $legacy_instances as $legacy ) {
			$zone = new WC_Shipping_Zone( $legacy['zone'] );
			if ( ! $zone->set_shipping_method_status( $legacy['instance'], false ) ) {
				WP_CLI::error( 'Could not disable legacy instance ' . $legacy['instance'] . '. Stop and inspect method states; migration marker remains unchanged.' );
			}
		}

		update_option( 'dtb_native_shipping_migration_version', '1', false );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		WP_CLI::success( 'Native WooCommerce shipping cutover completed. Verify public Checkout Block rates and order shipping lines.' );
	}
}
WP_CLI::add_command( 'dtb shipping migrate', 'DTB_Native_Shipping_Cutover_Command' );

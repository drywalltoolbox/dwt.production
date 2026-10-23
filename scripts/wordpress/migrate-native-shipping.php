<?php
/**
 * Cut over from DTB's legacy checkout shipping method to operator-configured
 * Octolize Flexible Shipping. This command NEVER invents prices or edits
 * Flexible Shipping rules. Existing carrier/fulfillment integrations are kept.
 *
 * Preview:
 * wp --require=scripts/wordpress/migrate-native-shipping.php dtb shipping migrate
 *
 * Execute only after shipping rule and destination verification:
 * wp --require=scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --apply --allow-production --coverage-confirmed --free-shipping-reviewed
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

final class DTB_Flexible_Shipping_Cutover_Command {
    public function __invoke( $args, $assoc_args ): void {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            WP_CLI::error( 'WooCommerce shipping is unavailable.' );
        }
        $apply = isset( $assoc_args['apply'] );
        if ( $apply && 'production' === wp_get_environment_type() && ! isset( $assoc_args['allow-production'] ) ) {
            WP_CLI::error( 'Production cutover requires --allow-production.' );
        }

        $zones = [ new WC_Shipping_Zone( 0 ) ];
        foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
            $zones[] = WC_Shipping_Zones::get_zone( (int) $zone['zone_id'] );
        }
        $legacy_instances = [];
        $flexible_enabled = [];
        $all_zone_methods = [];

        foreach ( $zones as $zone ) {
            $zone_id = (int) $zone->get_id();
            $methods = (array) $zone->get_shipping_methods( false, 'admin' );
            $all_zone_methods[ $zone_id ] = $methods;
            foreach ( $methods as $method ) {
                if ( ! is_object( $method ) ) {
                    continue;
                }
                $id = (string) ( $method->id ?? '' );
                $instance_id = (int) ( $method->instance_id ?? 0 );
                $enabled = method_exists( $method, 'is_enabled' )
                    ? $method->is_enabled()
                    : 'yes' === (string) ( $method->enabled ?? '' );
                if ( 'dtb_veeqo_rates' === $id && $enabled ) {
                    $legacy_instances[] = [ 'zone' => $zone_id, 'instance' => $instance_id ];
                }
                // Octolize's modern single-method ID is flexible_shipping_single.
                if ( 'flexible_shipping_single' === $id && $enabled ) {
                    $flexible_enabled[ $zone_id ] = true;
                }
            }
        }

        $us_zones = [];
        foreach ( $zones as $zone ) {
            if ( 0 === $zone->get_id() ) {
                continue;
            }
            foreach ( (array) $zone->get_zone_locations() as $location ) {
                if ( 'country' === (string) $location->type && 'US' === strtoupper( (string) $location->code ) ) {
                    $us_zones[] = (int) $zone->get_id();
                    break;
                }
            }
        }
        WP_CLI::log( 'Legacy enabled instances: ' . wp_json_encode( $legacy_instances ) );
        WP_CLI::log( 'Enabled Flexible Shipping zone IDs: ' . wp_json_encode( array_keys( $flexible_enabled ) ) );
        WP_CLI::log( 'United States zone IDs: ' . wp_json_encode( $us_zones ) );

        if ( empty( $us_zones ) || 1 !== count( $us_zones ) || ! isset( $flexible_enabled[ $us_zones[0] ] ) ) {
            WP_CLI::error( 'US shipping zone must contain an enabled, fully configured Flexible Shipping single method before cutover.' );
        }

        // Existing site currently allows worldwide shipping. The legacy ROW
        // method cannot be retired while it is the only enabled ROW service.
        $ship_to = (string) get_option( 'woocommerce_ship_to_countries', '' );
        $selling = (string) get_option( 'woocommerce_allowed_countries', 'all' );
        $domestic_only = 'specific' === $ship_to
            ? ! array_diff( (array) get_option( 'woocommerce_specific_ship_to_countries', [] ), [ 'US' ] )
            : ( '' === $ship_to && 'specific' === $selling
                && ! array_diff( (array) get_option( 'woocommerce_specific_allowed_countries', [] ), [ 'US' ] ) );

        if ( ! $domestic_only && ! isset( $flexible_enabled[0] ) ) {
            WP_CLI::error( 'Worldwide checkout remains enabled and Rest of World has no active Flexible Shipping method. Set an approved country policy or configure a real ROW shipping method first.' );
        }

        if ( ! $apply ) {
            WP_CLI::success( 'Read-only preview. No method statuses or migration markers were changed.' );
            return;
        }
        if ( ! isset( $assoc_args['coverage-confirmed'] ) || ! isset( $assoc_args['free-shipping-reviewed'] ) ) {
            WP_CLI::error( 'Requires --coverage-confirmed and --free-shipping-reviewed after checking packing, weight tiers, missing weights, exclusions and existing $50 free shipping.' );
        }
        foreach ( $legacy_instances as $legacy ) {
            $zone = new WC_Shipping_Zone( $legacy['zone'] );
            if ( ! $zone->set_shipping_method_status( $legacy['instance'], false ) ) {
                WP_CLI::error( 'Could not disable legacy method ' . $legacy['instance'] . '; check partial statuses and restore before retrying. Cutover marker remains unchanged.' );
            }
        }
        // Stop old fallback rates and legacy-zone self-repair only after methods
        // have been disabled. The historical shipping method remains registered.
        update_option( 'dtb_native_shipping_migration_version', '1', false );
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
        WP_CLI::success( 'Flexible Shipping cutover activated. Confirm representative public Checkout Block rates and Veeqo service mapping.' );
    }
}
WP_CLI::add_command( 'dtb shipping migrate', 'DTB_Flexible_Shipping_Cutover_Command' );

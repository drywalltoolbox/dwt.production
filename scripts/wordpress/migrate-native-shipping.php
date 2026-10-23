<?php
/**
 * Explicit, idempotent WooCommerce native shipping migration via WP-CLI only.
 *
 * Invoke from a deployed checkout source tree (not from wp-admin requests):
 * wp --require=/path/to/scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --standard-rate=7.99
 * wp --require=/path/to/scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --standard-rate=7.99 --apply --allow-production
 *
 * The second command is intentionally gated. Rates are merchant inputs;
 * this script never determines carrier prices, buys labels or changes orders.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

final class DTB_Native_Shipping_Migration_Command {
    /**
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ): void {
        if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
            WP_CLI::error( 'WooCommerce shipping is unavailable.' );
        }
        $apply = isset( $assoc_args['apply'] );
        if ( $apply && 'production' === wp_get_environment_type() && ! isset( $assoc_args['allow-production'] ) ) {
            WP_CLI::error( 'Production cutover requires explicit --allow-production.' );
        }

        $raw = (string) ( $assoc_args['standard-rate'] ?? '' );
        if ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > 1000 ) {
            WP_CLI::error( 'Set a merchant-approved --standard-rate=NUMBER (USD). No invented checkout pricing.' );
        }
        $standard_rate = wc_format_decimal( $raw, 2 );
        $free_threshold = '50'; // Existing $50 domestic merchant policy.

        $us_zone = null;
        foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
            $zone = WC_Shipping_Zones::get_zone( (int) ( $zone_data['zone_id'] ?? 0 ) );
            if ( ! $zone instanceof WC_Shipping_Zone ) {
                continue;
            }
            $locations = $zone->get_zone_locations();
            foreach ( $locations as $location ) {
                if ( 'country' === (string) $location->type && 'US' === strtoupper( (string) $location->code ) ) {
                    if ( null !== $us_zone ) {
                        WP_CLI::error( 'Multiple US shipping zones detected; operator must resolve ordering and scope.' );
                    }
                    $us_zone = $zone;
                }
            }
        }
        if ( ! $us_zone ) {
            WP_CLI::error( 'No US country shipping zone found. Preserve current zones; configure manually.' );
        }

        $zone0  = new WC_Shipping_Zone( 0 );
        $world  = (array) $zone0->get_shipping_methods( false, 'admin' );
        $has_world_fallback = false;
        foreach ( $world as $method ) {
            if ( is_object( $method ) && 'yes' === (string) ( $method->enabled ?? '' )
                && 'dtb_veeqo_rates' !== (string) ( $method->id ?? '' ) ) {
                $has_world_fallback = true;
            }
        }
        $ship_to = (string) get_option( 'woocommerce_ship_to_countries', '' );
        $allowed = (string) get_option( 'woocommerce_allowed_countries', 'all' );
        // Woo option values: disabled, specific, all, all_except, and blank
        // (inherit selling-country restrictions). Never infer international
        // eligibility from an empty country list when 'all' is configured.
        if ( 'disabled' === $ship_to ) {
            $allows_international = false;
        } elseif ( 'specific' === $ship_to ) {
            $countries = (array) get_option( 'woocommerce_specific_ship_to_countries', [] );
            $allows_international = (bool) array_diff( $countries, [ 'US' ] );
        } elseif ( '' === $ship_to && 'specific' === $allowed ) {
            $countries = (array) get_option( 'woocommerce_specific_allowed_countries', [] );
            $allows_international = (bool) array_diff( $countries, [ 'US' ] );
        } else {
            $allows_international = true;
        }
        // A generic global fallback does not prove that international
        // destinations have approved prices, taxes, or physical service.
        // Require explicit operator attestation and separately verified
        // methods before disabling international legacy shipping.
        if ( $apply && $allows_international && ! isset( $assoc_args['international-verified'] ) ) {
            WP_CLI::error( 'International checkout is permitted. Require --international-verified after confirming every supported destination has an approved native zone/method and order fulfillment.' );
        }
        if ( $apply && $allows_international && ! $has_world_fallback ) {
            WP_CLI::error( 'International shipping is enabled but Rest-of-World has no verified native provider. Configure a verified international method or intentionally restrict supported countries BEFORE cutover.' );
        }

        $methods = (array) $us_zone->get_shipping_methods( false, 'admin' );
        $standard_id = 0;
        $free_id = 0;
        $legacy = [];
        foreach ( $methods as $method ) {
            if ( ! is_object( $method ) ) { continue; }
            $id = (int) ( $method->instance_id ?? 0 );
            $type = (string) ( $method->id ?? '' );
            if ( 'dtb_veeqo_rates' === $type ) { $legacy[] = $id; }
            if ( in_array( $type, [ 'flat_rate', 'free_shipping' ], true ) ) {
                $settings = (array) get_option( 'woocommerce_' . $type . '_' . $id . '_settings', [] );
                if ( '1' !== (string) ( $settings['dtb_managed'] ?? '' ) ) {
                    WP_CLI::error( 'An existing native ' . $type . ' method is operator-owned. Do not overwrite; review the zone manually.' );
                }
                if ( 'flat_rate' === $type ) { $standard_id = $id; }
                if ( 'free_shipping' === $type ) { $free_id = $id; }
            }
        }

        WP_CLI::log( sprintf(
            '%s: US zone %d, legacy instances [%s], managed standard=%d, managed free=%d, international-ready=%s, proposed standard=%s, free minimum=%s.',
            $apply ? 'APPLY' : 'DRY RUN',
            $us_zone->get_id(),
            implode( ',', $legacy ),
            $standard_id, $free_id,
            $has_world_fallback ? 'yes' : 'no',
            $standard_rate, $free_threshold
        ) );

        if ( ! $apply ) {
            WP_CLI::warning( 'Dry run only. Products still need shipping-class review; expedited services must be carrier-validated. No changes were made.' );
            return;
        }
        foreach ( [
            'small-parts'       => 'Small Replacement Parts',
            'standard-tools'   => 'Standard Tools',
            'long-bulky-tools' => 'Long / Bulky Tools',
            'complete-toolsets'=> 'Complete Toolsets',
            'oversized-freight'=> 'Oversized / Freight',
        ] as $slug => $label ) {
            if ( ! term_exists( $slug, 'product_shipping_class' ) ) {
                $term = wp_insert_term( $label, 'product_shipping_class', [ 'slug' => $slug ] );
                if ( is_wp_error( $term ) ) {
                    WP_CLI::error( 'Cannot create shipping class ' . $slug . ': ' . $term->get_error_message() );
                }
            }
        }

        if ( ! $standard_id ) {
            $standard_id = (int) $us_zone->add_shipping_method( 'flat_rate' );
        }
        if ( ! $free_id ) {
            $free_id = (int) $us_zone->add_shipping_method( 'free_shipping' );
        }
        if ( $standard_id <= 0 || $free_id <= 0 ) {
            WP_CLI::error( 'Could not create both native methods; legacy shipping remains intact.' );
        }

        // Forbid a blanket US Flat Rate unless merchant has explicitly
        // accepted handling for toolsets, long tools, and freight. Shipping
        // classes alone do not block Flat Rate, Free Shipping or estimate
        // multiple cartons.
        if ( ! isset( $assoc_args['special-handling-verified'] ) ) {
            WP_CLI::error( 'Native universal Flat Rate/Free Shipping would cover heavy/long/toolset carts. Require --special-handling-verified only after approved class rules or a vetted extension are active.' );
        }
        update_option( 'woocommerce_flat_rate_' . $standard_id . '_settings', [
            'title' => 'Standard Shipping',
            'tax_status' => 'taxable',
            'cost' => $standard_rate,
            'no_class_cost' => '',
            'type' => 'class',
            'dtb_managed' => '1',
        ] );
        update_option( 'woocommerce_free_shipping_' . $free_id . '_settings', [
            'title' => 'Free Standard Shipping',
            'requires' => 'min_amount',
            'min_amount' => $free_threshold,
            'ignore_discounts' => 'no',
            'dtb_managed' => '1',
        ] );

        $new_methods = (array) $us_zone->get_shipping_methods( false, 'admin' );
        $verified = [ 'flat_rate' => false, 'free_shipping' => false ];
        foreach ( $new_methods as $method ) {
            $id = (int) ( $method->instance_id ?? 0 );
            if ( $id === $standard_id && 'flat_rate' === $method->id && 'yes' === $method->enabled ) {
                $verified['flat_rate'] = true;
            }
            if ( $id === $free_id && 'free_shipping' === $method->id && 'yes' === $method->enabled ) {
                $verified['free_shipping'] = true;
            }
        }
        if ( ! $verified['flat_rate'] || ! $verified['free_shipping'] ) {
            WP_CLI::error( 'Native methods failed readback. Legacy methods have NOT been disabled.' );
        }

        // Cut over only once native methods pass readback.
        foreach ( $legacy as $id ) {
            $us_zone->set_shipping_method_status( $id, false );
        }
        foreach ( $world as $method ) {
            if ( 'dtb_veeqo_rates' === (string) ( $method->id ?? '' ) ) {
                $zone0->set_shipping_method_status( (int) $method->instance_id, false );
            }
        }
        update_option( 'woocommerce_shipping_hide_rates_when_free', 'no' );
        update_option( 'dtb_native_shipping_standard_instance_id', $standard_id, false );
        update_option( 'dtb_native_shipping_migration_version', '1', false );
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
        WP_CLI::success( 'Native shipping methods configured. Verify checkout before deploying the legacy method removal.' );
    }
}
WP_CLI::add_command( 'dtb shipping migrate', 'DTB_Native_Shipping_Migration_Command' );

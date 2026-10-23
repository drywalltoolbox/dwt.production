<?php
/**
 * Drywall Toolbox — native WooCommerce shipping compatibility.
 *
 * WooCommerce shipping zones, methods, classes, rates and Checkout Block own
 * storefront shipping. Veeqo owns post-order carrier selection/label/tracking.
 * This module deliberately registers NO custom shipping method, runs NO zone
 * self-healing writes and performs NO checkout-time Veeqo rate shopping.
 *
 * Deploy only AFTER applying and verifying the native WooCommerce zone
 * migration (scripts/wordpress/migrate-native-shipping.php). The legacy
 * dtb_veeqo_rates instance must be disabled during the cutover.
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

defined( 'DTB_SHIPPING_METHOD_ID' ) || define( 'DTB_SHIPPING_METHOD_ID', 'dtb_veeqo_rates' );

/**
 * Invalidate globally cached shipping packages after deliberate configuration
 * changes. No mutation occurs on ordinary requests.
 */
function dtb_commerce_bump_shipping_cache_version(): void {
    if ( class_exists( 'WC_Cache_Helper' ) ) {
        WC_Cache_Helper::get_transient_version( 'shipping', true );
    }
}

/**
 * Legacy helper retained for other DTB integrations, if any.
 * Scope is the current Woo session; does not alter the source of truth.
 *
 * @param array<int,array<string,mixed>> $packages Current packages.
 */
function dtb_commerce_invalidate_shipping_package_cache( array $packages = [] ): void {
    if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
        return;
    }

    $keys = array_keys( $packages );
    if ( empty( $keys ) ) {
        $keys = [ 0 ];
    }

    foreach ( $keys as $key ) {
        $session_key = 'shipping_for_package_' . absint( $key );
        if ( method_exists( WC()->session, '__unset' ) ) {
            WC()->session->__unset( $session_key );
        } else {
            WC()->session->set( $session_key, null );
        }
    }
}

/**
 * Suppress only our redundant paid Standard flat-rate instance when native
 * Free Shipping qualifies. Retain any genuinely available paid upgrades,
 * third-party carrier rates and local-pickup rates.
 *
 * This is purely an option-display preference. WooCommerce still calculates
 * shipping costs, selects the chosen rate and persists the order shipping line.
 */
function dtb_commerce_hide_redundant_standard_when_free( array $rates, array $package ): array {
    $standard_instance_id = (int) get_option( 'dtb_native_shipping_standard_instance_id', 0 );
    if ( $standard_instance_id <= 0 ) {
        return $rates;
    }

    $has_native_free = false;
    foreach ( $rates as $rate ) {
        if ( $rate instanceof WC_Shipping_Rate && 'free_shipping' === (string) $rate->get_method_id() ) {
            $has_native_free = true;
            break;
        }
    }
    if ( ! $has_native_free ) {
        return $rates;
    }
    foreach ( $rates as $key => $rate ) {
        if ( $rate instanceof WC_Shipping_Rate
            && 'flat_rate' === (string) $rate->get_method_id()
            && $standard_instance_id === (int) $rate->get_instance_id() ) {
            unset( $rates[ $key ] );
        }
    }
    return $rates;
}
add_filter( 'woocommerce_package_rates', 'dtb_commerce_hide_redundant_standard_when_free', 110, 2 );

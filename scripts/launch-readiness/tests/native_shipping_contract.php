<?php
/**
 * Offline contract for native WooCommerce shipping option presentation.
 * No WordPress bootstrap, checkout requests, orders, or provider calls.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['dtb_shipping_filters'] = [];
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['dtb_shipping_filters'][ $tag ][] = $callback;
}
function get_option( $key, $default = false ) {
    return 'dtb_native_shipping_standard_instance_id' === $key ? 11 : $default;
}
class WC_Shipping_Rate {
    private string $method_id;
    private int $instance_id;
    public function __construct( string $method_id, int $instance_id ) {
        $this->method_id = $method_id;
        $this->instance_id = $instance_id;
    }
    public function get_method_id(): string { return $this->method_id; }
    public function get_instance_id(): int { return $this->instance_id; }
}
require __DIR__ . '/../../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Shipping/DTBShippingMethod.php';
function dtb_assert( bool $ok, string $message ): void {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
}
dtb_assert( 1 === count( $GLOBALS['dtb_shipping_filters']['woocommerce_package_rates'] ?? [] ),
    'Shipping should install only one native presentation filter.' );
$rates = [
    'flat_rate:11' => new WC_Shipping_Rate( 'flat_rate', 11 ),
    'free_shipping:12' => new WC_Shipping_Rate( 'free_shipping', 12 ),
    'flat_rate:13' => new WC_Shipping_Rate( 'flat_rate', 13 ), // real paid upgrade.
    'local_pickup:14' => new WC_Shipping_Rate( 'local_pickup', 14 ),
];
$result = dtb_commerce_hide_redundant_standard_when_free( $rates, [] );
dtb_assert( ! isset( $result['flat_rate:11'] ), 'Paid standard duplicated despite free standard.' );
dtb_assert( count( $result ) === 3 && isset( $result['flat_rate:13'], $result['free_shipping:12'], $result['local_pickup:14'] ),
    'Real paid upgrade, free shipping or pickup was suppressed.' );
unset( $rates['free_shipping:12'] );
dtb_assert( count( dtb_commerce_hide_redundant_standard_when_free( $rates, [] ) ) === count( $rates ),
    'Paid standard rate removed for non-qualifying cart.' );
echo "Native WooCommerce shipping presentation contract: PASS\n";

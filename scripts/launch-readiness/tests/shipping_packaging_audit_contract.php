<?php
/**
 * Offline validation of read-only shipping product inspection.
 */
define( 'WP_CLI', true );
class WP_CLI {
    public static array $commands = [];
    public static function add_command( $name, $callback ): void { self::$commands[$name] = $callback; }
    public static function error( $message ): void { throw new RuntimeException( $message ); }
}
class WC_Product {
    protected array $data;
    public function __construct( array $data ) { $this->data = $data; }
    public function get_id(): int { return $this->data['id'] ?? 1; }
    public function get_sku( $context = 'view' ): string { return $this->data['sku'] ?? 'SKU-1'; }
    public function get_weight( $context = 'view' ): string { return $this->data['weight'] ?? ''; }
    public function get_length( $context = 'view' ): string { return $this->data['length'] ?? ''; }
    public function get_width( $context = 'view' ): string { return $this->data['width'] ?? ''; }
    public function get_height( $context = 'view' ): string { return $this->data['height'] ?? ''; }
    public function get_shipping_class( $context = 'view' ): string { return $this->data['shipping_class'] ?? ''; }
    public function get_type(): string { return $this->data['type'] ?? 'simple'; }
    public function is_type( $value ): bool { return $value === $this->get_type(); }
    public function get_meta( $key, $single = true ): string { return $this->data['meta'][$key] ?? ''; }
}
require __DIR__ . '/../../wordpress/audit-shipping-packaging.php';
function assert_shipping_audit( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
assert_shipping_audit( isset( WP_CLI::$commands['dtb shipping audit'] ), 'WP-CLI audit not registered.' );
$valid = [ 'weight' => '55', 'length' => '54', 'width' => '21', 'height' => '16', 'shipping_class' => 'standard-tools' ];
$r = dtb_shipping_audit_product_facts( new WC_Product( $valid ) );
assert_shipping_audit( '' === $r['issues'], 'Complete classified product flagged.' );
$r = dtb_shipping_audit_product_facts( new WC_Product( array_merge( $valid, [ 'height' => '', 'shipping_class' => '' ] ) ) );
assert_shipping_audit( str_contains( $r['issues'], 'missing_product_height' ) && str_contains( $r['issues'], 'unassigned_shipping_class' ), 'Missing data not flagged.' );
$r = dtb_shipping_audit_product_facts( new WC_Product( array_merge( $valid, [ 'shipping_class' => 'complete-toolsets' ] ) ) );
assert_shipping_audit( str_contains( $r['issues'], 'packaging_review_required' ), 'Toolset without verified packing incorrectly passed.' );
$r = dtb_shipping_audit_product_facts( new WC_Product( array_merge( $valid, [ 'shipping_class' => 'complete-toolsets', 'meta' => [ '_dtb_shipping_carton_reviewed' => 'yes' ] ] ) ) );
assert_shipping_audit( '' === $r['issues'], 'Reviewed toolset incorrectly flagged.' );
$r = dtb_shipping_audit_product_facts( new WC_Product( array_merge( $valid, [ 'type' => 'variable' ] ) ) );
assert_shipping_audit( str_contains( $r['issues'], 'review_variation_level_shipping' ), 'Variable parent review omitted.' );
echo "Shipping packaging audit contract: PASS\n";

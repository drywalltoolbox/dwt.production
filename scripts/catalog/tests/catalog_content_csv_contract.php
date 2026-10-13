<?php
/** Standalone import adapter regression without changing WooCommerce data. */
class WP_Error {
    public function __construct( private string $code, private string $message ) {}
    public function get_error_message(): string { return $this->message; }
}
class WC_Product {
    private array $meta = [];
    public function get_id(): int { return 1; }
    public function delete_meta_data( $key ): void { unset( $this->meta[$key] ); }
    public function update_meta_data( $key, $value ): void { $this->meta[$key] = $value; }
    public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[$key] ?? ''; }
}
function add_filter( ...$args ): void {}
function wc_get_product( $id ) { return $GLOBALS['previous_product'] ?? false; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_strip_all_tags( $text ): string { return strip_tags( $text ); }
function wp_json_encode( $value, $flags = 0 ): string|false { return json_encode( $value, $flags ); }
require_once dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Application/CatalogContentCsv.php';
function check( bool $ok, string $reason ): void {
    if ( ! $ok ) { throw new RuntimeException( $reason ); }
}
$input = '{"schema_version":1,"features":[{"label":"Clean","description":"Quick maintenance."}]}';
$p = new WC_Product();
$row = [ 'meta_data' => [
    [ 'key' => DTB_CatalogContentCsv::FEATURES, 'value' => $input ],
    [ 'key' => DTB_CatalogContentCsv::VERSION, 'value' => '2' ],
] ];
DTB_CatalogContentCsv::pre_insert( $p, $row );
$before = $p->get_meta( DTB_CatalogContentCsv::FEATURES );
$GLOBALS['previous_product'] = clone $p;
DTB_CatalogContentCsv::pre_insert( $p, $row );
check( $before === $p->get_meta( DTB_CatalogContentCsv::FEATURES ), 'Repeat import changed Features JSON' );
check( '2' === $p->get_meta( DTB_CatalogContentCsv::VERSION ), 'Version missing' );
DTB_CatalogContentCsv::pre_insert( $p, [ 'meta_data' => [] ] );
check( $before === $p->get_meta( DTB_CatalogContentCsv::FEATURES ), 'Missing column cleared metadata' );
// Mirror WooCommerce's set_meta_data() behavior before its pre-insert hook.
$p->update_meta_data( DTB_CatalogContentCsv::FEATURES, '' );
$p->update_meta_data( DTB_CatalogContentCsv::VERSION, '' );
DTB_CatalogContentCsv::pre_insert( $p, [ 'meta_data' => [
    [ 'key' => DTB_CatalogContentCsv::FEATURES, 'value' => '' ],
    [ 'key' => DTB_CatalogContentCsv::VERSION, 'value' => '' ],
] ] );
check( $before === $p->get_meta( DTB_CatalogContentCsv::FEATURES ), 'Blank column cleared metadata' );
try {
    DTB_CatalogContentCsv::pre_insert( $p, [ 'meta_data' => [
        [ 'key' => DTB_CatalogContentCsv::FEATURES, 'value' => '{"schema_version":1,"features":[{"label":"<script>","description":"Unsafe"}]}' ],
        [ 'key' => DTB_CatalogContentCsv::VERSION, 'value' => '2' ],
    ] ] );
    throw new RuntimeException( 'Invalid HTML not rejected' );
} catch ( RuntimeException $e ) {
    check( 'Invalid HTML not rejected' !== $e->getMessage(), 'Invalid HTML was accepted' );
}
check( $before === $p->get_meta( DTB_CatalogContentCsv::FEATURES ), 'Rejected import mutated product' );
$meta = (object) [ 'key' => DTB_CatalogContentCsv::FEATURES ];
check( $before === DTB_CatalogContentCsv::export_meta_value( $before, $meta, $p ), 'CSV export changed JSON' );
check( '' === DTB_CatalogContentCsv::export_meta_value( [ 1 ], $meta, $p ), 'Non-scalar export not rejected' );
echo "Catalog Features importer validation: PASS\n";

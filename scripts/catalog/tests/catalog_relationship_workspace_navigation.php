<?php
/** Capability-aware shared entry and navigation for relationship/parts tools. */

$GLOBALS['catalog_capabilities'] = [];
define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
function add_action( string $hook, string $callback, int $priority = 10 ): void {}
function dtb_is_admin_or_ajax_request(): bool { return true; }
function current_user_can( string $capability ): bool { return ! empty( $GLOBALS['catalog_capabilities'][ $capability ] ); }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
function wp_unslash( string $value ): string { return $value; }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function admin_url( string $path ): string { return '/wp-admin/' . $path; }

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-platform/Admin/CatalogOperationsPage.php';

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$workspace_cards = static function (): array {
	return array_values( array_filter( dtb_catalog_operations_cards(), static fn( array $card ): bool => str_contains( $card['title'], 'Relationships' ) ) );
};

$GLOBALS['catalog_capabilities'] = [ 'dtb_manage_product_mapping' => true, 'dtb_manage_parts' => true ];
$cards = $workspace_cards();
$check( 1 === count( $cards ) && 'dtb-product-mapping' === $cards[0]['slug'], 'Operators with both capabilities must see one combined card opening the relationship workspace.' );

$GLOBALS['catalog_capabilities'] = [ 'dtb_manage_parts' => true ];
$cards = $workspace_cards();
$check( 1 === count( $cards ) && 'dtb-parts-manager' === $cards[0]['slug'], 'Parts-only operators must retain access through the combined entry.' );

$GLOBALS['catalog_capabilities'] = [ 'dtb_manage_product_mapping' => true, 'dtb_manage_parts' => true ];
$_GET['page'] = 'dtb-parts-manager';
ob_start();
dtb_catalog_operations_render_context_navigation();
$navigation = (string) ob_get_clean();
$check( 1 === substr_count( $navigation, 'Relationships &amp; Parts' ) && str_contains( $navigation, 'page=dtb-product-mapping' ), 'Both operator routes must share one context-navigation entry pointing to the relationship workspace.' );

fwrite( STDOUT, "Catalog relationship workspace navigation checks passed.\n" );

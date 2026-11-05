<?php
/** Capability boundary and embeddable rendering check for Parts Manager. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'DTB_BRANDS', [] );
$GLOBALS['parts_workspace_capability'] = false;
$GLOBALS['product_mapping_capability'] = true;
function add_action( string $hook, string $callback ): void {}
function dtb_is_admin_or_ajax_request(): bool { return true; }
function current_user_can( string $capability ): bool { return ( 'dtb_manage_product_mapping' === $capability && $GLOBALS['product_mapping_capability'] ) || ( 'dtb_manage_parts' === $capability && $GLOBALS['parts_workspace_capability'] ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
function plugin_dir_url( string $file ): string { return 'https://example.test/wp-content/mu-plugins/dtb-catalog-platform/Admin/'; }
function wp_enqueue_style( string $handle, string $src, array $deps = [], string $version = '' ): void { $GLOBALS['relationship_enqueued'][] = [ 'style', $handle, $src, $version ]; }
function wp_enqueue_script( string $handle, string $src, array $deps = [], string $version = '', bool $in_footer = false ): void { $GLOBALS['relationship_enqueued'][] = [ 'script', $handle, $src, $version, $in_footer ]; }
function wp_localize_script( string $handle, string $object_name, array $data ): void { unset( $handle, $object_name, $data ); }
function wp_create_nonce( string $action ): string { return 'test-nonce'; }
function wp_max_upload_size(): int { return 1048576; }
function __( string $text, string $domain = '' ): string { return $text; }
function admin_url( string $path ): string { return '/wp-admin/' . $path; }
function rest_url( string $path ): string { return '/wp-json/' . $path; }
function dtb_admin_shell_open( array $config ): void {}
function dtb_admin_shell_close(): void {}
function esc_html_e( string $text, string $domain = '' ): void { echo htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr_e( string $text, string $domain = '' ): void { echo htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }

final class DTB_ProductRelationshipProjectionService { public const MAX_ARTIFACT_BYTES = 2097152; }

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/PartsManagerPage.php';
require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/ProductMappingRenderer.php';

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

ob_start();
dtb_parts_manager_render_workspace();
$denied_output = (string) ob_get_clean();
$check( '' === $denied_output, 'Parts workspace must render no content without its owning capability.' );

$GLOBALS['parts_workspace_capability'] = true;
ob_start();
dtb_parts_manager_render_workspace();
$workspace = (string) ob_get_clean();
$check( str_contains( $workspace, 'data-dtb-parts-manager' ), 'Combined workspace must render the existing Parts Manager interface.' );
$check( str_contains( $workspace, 'dtb-pm-tab-imports' ) && str_contains( $workspace, 'dtb-pm-tab-universal' ), 'Combined workspace must retain schematic-import and universal-part workflows.' );
$check( ! str_contains( $workspace, 'wp-admin' ), 'Embeddable workspace must not emit a nested admin shell.' );
$check( str_contains( $workspace, 'dtb-pm-hero' ) && str_contains( $workspace, 'Parts Manager summary' ), 'Standalone parts workspace must retain its independent page header and summary.' );

ob_start();
dtb_parts_manager_render_workspace( true );
$embedded_workspace = (string) ob_get_clean();
$check( str_contains( $embedded_workspace, 'dtb-pm-shell--embedded' ) && str_contains( $embedded_workspace, 'Catalog &amp; parts data' ), 'Combined workspace must use the compact catalog header.' );
$check( ! str_contains( $embedded_workspace, 'Parts Manager summary' ) && ! str_contains( $embedded_workspace, 'Backend parts operations' ), 'Combined workspace must not nest the standalone dashboard header and metric cards.' );
$check( str_contains( $embedded_workspace, 'data-protected-hint' ) && str_contains( $embedded_workspace, 'SKU is fixed after creation' ) && str_contains( $embedded_workspace, 'Manufacturer part number is fixed' ), 'Part identity fields must explain their protected status.' );
$parts_script = file_get_contents( dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/assets/dtb-parts-manager.js' );
$parts_actions = file_get_contents( dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/PartsManagerActions.php' );
$check( is_string( $parts_script ) && str_contains( $parts_script, "$('#dtb-pm-sku,#dtb-pm-msku').prop('readOnly', false)" ) && str_contains( $parts_script, "$('#dtb-pm-sku').prop('readOnly', true)" ) && str_contains( $parts_script, "$('#dtb-pm-msku').prop('readOnly', true)" ), 'Existing product SKU and manufacturer part number must be read-only in the editor.' );
$check( ! str_contains( $embedded_workspace, 'id="dtb-pm-universal-id"' ) && ! str_contains( $parts_script, 'universal_part_id:' ) && ! str_contains( $parts_script, "$('#dtb-pm-universal-id')" ), 'Generic part editing must not expose a second universal-projection writer.' );
$save_start = is_string( $parts_actions ) ? strpos( $parts_actions, 'function dtb_ajax_parts_save' ) : false;
$import_start = is_string( $parts_actions ) ? strpos( $parts_actions, 'function dtb_ajax_parts_import_csv' ) : false;
$generic_save_actions = false !== $save_start && false !== $import_start ? substr( $parts_actions, $save_start, $import_start - $save_start ) : '';
$check( is_string( $parts_actions ) && false !== $save_start && '' !== $generic_save_actions && ! str_contains( $generic_save_actions, 'universal_part_' ) && ! str_contains( $parts_actions, 'dtb_parts_apply_universal_meta' ), 'Generic part save actions must not mutate canonical universal-part projections.' );

$GLOBALS['parts_workspace_capability'] = true;
ob_start();
dtb_product_mapping_render_page();
$combined_page = (string) ob_get_clean();
$repo_root = dirname( __DIR__, 3 );
$relationship_renderer = file_get_contents( $repo_root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/ProductMappingRenderer.php' );
$relationship_script = file_get_contents( $repo_root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/assets/dtb-product-relationships.js' );
$check( is_string( $relationship_renderer ) && str_contains( $relationship_renderer, 'wp_enqueue_script' ) && is_string( $relationship_script ), 'Relationship client behavior must be loaded as a local enqueued asset.' );
$_GET['page'] = 'dtb-product-mapping';
$GLOBALS['relationship_enqueued'] = [];
dtb_product_mapping_enqueue_assets();
$check( 2 === count( $GLOBALS['relationship_enqueued'] ) && 'dtb-product-relationships' === $GLOBALS['relationship_enqueued'][0][1] && 'dtb-product-relationships' === $GLOBALS['relationship_enqueued'][1][1], 'Relationship CSS and JavaScript must enqueue only through the page asset hook.' );
$_GET['page'] = 'other-admin-page';
dtb_product_mapping_enqueue_assets();
$check( 2 === count( $GLOBALS['relationship_enqueued'] ), 'Relationship assets must not enqueue on unrelated admin pages.' );
$check( dtb_parts_manager_uses_combined_workspace(), 'Operators with relationship access must use the consolidated workspace.' );
$_GET['page'] = 'dtb-parts-manager';
dtb_parts_manager_enqueue_assets();
$check( 2 === count( $GLOBALS['relationship_enqueued'] ), 'The legacy Parts Manager route must not load duplicate assets for operators routed to the consolidated workspace.' );
$GLOBALS['product_mapping_capability'] = false;
$check( ! dtb_parts_manager_uses_combined_workspace(), 'Parts-only operators must retain the standalone Parts Manager route.' );
dtb_parts_manager_enqueue_assets();
$check( 4 === count( $GLOBALS['relationship_enqueued'] ) && 'dtb-parts-manager' === $GLOBALS['relationship_enqueued'][2][1], 'Parts-only operators must retain the Parts Manager assets.' );
$GLOBALS['product_mapping_capability'] = true;
$check( str_contains( $combined_page, 'data-workspace-tab="parts"' ), 'Operators with both capabilities must see the shared Parts workspace tab.' );
$check( str_contains( $combined_page, 'data-dtb-parts-manager' ), 'Relationship workspace must compose the existing Parts Manager body.' );
$check( 1 === substr_count( $combined_page, 'data-dtb-parts-manager' ), 'Combined workspace must render one Parts Manager instance.' );
$check( str_contains( $combined_page, 'data-compatible-parts-base="/wp-json/dtb/v1/products/"' ), 'Relationship workbench must use the same-origin public compatibility route for exact candidate discovery.' );
$check( str_contains( $combined_page, 'data-max-artifact-bytes="1048576"' ), 'Relationship workbench must expose the smaller configured WordPress upload limit to the browser.' );
$check( str_contains( $combined_page, 'Exact fitment candidates' ) && str_contains( $combined_page, 'Adding one creates a separate cross-sell placement; it does not edit fitment.' ), 'Fitment-derived candidate assistance must state its source and separate placement semantics.' );
$check( str_contains( $combined_page, 'data-crosssell-candidates' ) && str_contains( $combined_page, 'data-crosssell-candidate-status' ), 'Cross-sell candidate workflow must expose an accessible result and status region.' );
$check( str_contains( $combined_page, 'data-merch-reload' ) && str_contains( $combined_page, 'Discard draft and reload saved relationships' ), 'Merchandising conflict recovery must offer an explicit current-state reload action.' );
$check( str_contains( $relationship_script, "xhr.status===409&&data&&data.code==='relationship_conflict'" ) && str_contains( $relationship_script, 'Your draft is still visible but cannot be saved' ), 'Concurrent merchandising conflicts must preserve the draft, prevent stale retries, and explain recovery.' );
$check( str_contains( $relationship_script, 'No eligible exact-fitment candidates are available to add. They may already be selected or unavailable.' ), 'Empty candidate messaging must distinguish filtered candidates from a lack of fitment data.' );
$check( str_contains( $relationship_script, 'No published, catalog-visible compatible parts were returned. Hidden or unpublished parts may still have fitment links; this does not mean other parts are incompatible.' ), 'Empty public-route results must not be presented as proof that no fitment links exist.' );
$check( str_contains( $relationship_script, 'hidden from catalog') && str_contains( $relationship_script, 'catalog_visibility'), 'Merchandising search results and saved selections must disclose hidden catalog visibility.' );
$check( str_contains( $relationship_script, 'No curated upsells selected.') && str_contains( $relationship_script, 'No curated cross-sells selected.' ), 'Empty saved merchandising lists must be explicit and distinguish curated relationships from fitment candidates.' );

$GLOBALS['parts_workspace_capability'] = false;
ob_start();
dtb_product_mapping_render_page();
$restricted_page = (string) ob_get_clean();
$check( ! str_contains( $restricted_page, 'data-workspace-tab="parts"' ) && ! str_contains( $restricted_page, 'data-dtb-parts-manager' ), 'Relationship-only operators must not receive the Parts workspace or its controls.' );

fwrite( STDOUT, "Parts Manager embedded render and capability checks passed.\n" );

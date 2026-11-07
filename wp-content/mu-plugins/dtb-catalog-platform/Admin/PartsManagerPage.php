<?php
/**
 * Parts Manager admin page registration + renderer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

add_action( 'admin_enqueue_scripts', 'dtb_parts_manager_enqueue_assets' );

/** Whether this operator should use the consolidated Product & Parts workspace. */
function dtb_parts_manager_uses_combined_workspace(): bool {
	return current_user_can( 'dtb_manage_product_mapping' );
}

/**
 * Load Parts Manager assets only on the DTB Parts Manager admin page.
 */
function dtb_parts_manager_enqueue_assets(): void {
	$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_parts_page = 'dtb-parts-manager' === $page && ! dtb_parts_manager_uses_combined_workspace();
	$is_combined_workspace = 'dtb-product-mapping' === $page && current_user_can( 'dtb_manage_parts' );
	if ( ! $is_parts_page && ! $is_combined_workspace ) {
		return;
	}

	$asset_dir = __DIR__ . '/assets/';
	$asset_url = plugin_dir_url( __FILE__ ) . 'assets/';

	$css = $asset_dir . 'dtb-parts-manager.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'dtb-parts-manager',
			$asset_url . 'dtb-parts-manager.css',
			[],
			(string) filemtime( $css )
		);
	}

	$js = $asset_dir . 'dtb-parts-manager.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script(
			'dtb-parts-manager',
			$asset_url . 'dtb-parts-manager.js',
			[ 'jquery' ],
			(string) filemtime( $js ),
			true
		);
		wp_localize_script(
			'dtb-parts-manager',
			'DtbPartsManager',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dtb_parts_manager_nonce' ),
			]
		);
	}
}

/**
 * Render Parts Manager admin UI.
 */
function dtb_parts_manager_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_parts' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}
	if ( dtb_parts_manager_uses_combined_workspace() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=dtb-product-mapping' ) );
		exit;
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Parts Manager', 'drywall-toolbox' ),
		'subtitle' => __( 'Manage brand-specific parts, schematic mappings, and backend universal-part synchronization.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-parts-manager',
		'template' => 'tool',
		'icon'     => 'dashicons-admin-tools',
	] );
	dtb_parts_manager_render_workspace();
	dtb_admin_shell_close();
}

/** Render the capability-checked Parts Manager body for standalone or combined workspaces. */
function dtb_parts_manager_render_workspace( bool $embedded = false ): void {
	if ( ! current_user_can( 'dtb_manage_parts' ) ) {
		return;
	}
	$brands = defined( 'DTB_BRANDS' ) && is_array( DTB_BRANDS ) ? DTB_BRANDS : [];
	?>
	<div class="dtb-pm-shell<?php echo $embedded ? ' dtb-pm-shell--embedded' : ''; ?>" data-dtb-parts-manager>
		<?php if ( $embedded ) : ?>
		<header class="dtb-pm-embedded-header">
			<div><h2><?php esc_html_e( 'Catalog & parts data', 'drywall-toolbox' ); ?></h2><p><?php esc_html_e( 'Manage catalog part records, schematic maps, and universal-part projections.', 'drywall-toolbox' ); ?></p></div>
			<div class="dtb-pm-hero-actions"><button type="button" class="dtb-pm-btn dtb-pm-btn-primary" data-dtb-pm-add><?php esc_html_e( 'Add Part', 'drywall-toolbox' ); ?></button><button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-pm-refresh><?php esc_html_e( 'Refresh', 'drywall-toolbox' ); ?></button></div>
		</header>
		<?php else : ?>
		<section class="dtb-pm-hero">
			<div>
				<p class="dtb-pm-eyebrow"><?php esc_html_e( 'Backend parts operations', 'drywall-toolbox' ); ?></p>
				<h1><?php esc_html_e( 'Parts Manager', 'drywall-toolbox' ); ?></h1>
				<p><?php esc_html_e( 'Manage brand-specific repair parts and schematic maps. Universal-part values are read-only projections from the seed records below.', 'drywall-toolbox' ); ?></p>
			</div>
			<div class="dtb-pm-hero-actions">
				<button type="button" class="dtb-pm-btn dtb-pm-btn-primary" data-dtb-pm-add><?php esc_html_e( 'Add Part', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-pm-refresh><?php esc_html_e( 'Refresh', 'drywall-toolbox' ); ?></button>
			</div>
		</section>

		<section class="dtb-pm-metrics" aria-label="Parts Manager summary">
			<div class="dtb-pm-metric"><span data-dtb-metric="parts_total">—</span><label><?php esc_html_e( 'Parts', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-pm-metric"><span data-dtb-metric="universal_parts">—</span><label><?php esc_html_e( 'Universal Seeds', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-pm-metric"><span data-dtb-metric="active_universal">—</span><label><?php esc_html_e( 'Active Universal', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-pm-metric"><span data-dtb-metric="review_universal">—</span><label><?php esc_html_e( 'Review / Quarantine', 'drywall-toolbox' ); ?></label></div>
		</section>
		<?php endif; ?>

		<nav class="dtb-pm-tabs" aria-label="Parts Manager sections">
			<button type="button" class="active" data-dtb-tab="parts"><?php esc_html_e( 'Parts', 'drywall-toolbox' ); ?></button>
			<button type="button" data-dtb-tab="imports"><?php esc_html_e( 'Imports', 'drywall-toolbox' ); ?></button>
			<button type="button" data-dtb-tab="universal"><?php esc_html_e( 'Universal Parts', 'drywall-toolbox' ); ?></button>
			<button type="button" data-dtb-tab="sync"><?php esc_html_e( 'Sync & Review', 'drywall-toolbox' ); ?></button>
		</nav>

		<section id="dtb-pm-tab-parts" class="dtb-pm-panel active">
			<div class="dtb-pm-card">
				<div class="dtb-pm-card-header">
					<div><h2><?php esc_html_e( 'Brand-specific parts', 'drywall-toolbox' ); ?></h2><p><?php esc_html_e( 'Search and edit WooCommerce part products. Universal metadata is backend-only and does not replace brand SKUs.', 'drywall-toolbox' ); ?></p></div>
					<div class="dtb-pm-export-actions">
						<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-export="csv"><?php esc_html_e( 'Export CSV', 'drywall-toolbox' ); ?></button>
						<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-export="json"><?php esc_html_e( 'Export JSON', 'drywall-toolbox' ); ?></button>
					</div>
				</div>
				<div class="dtb-pm-toolbar">
					<input id="dtb-pm-search" class="dtb-pm-input dtb-pm-input--wide" type="search" placeholder="Search title, SKU, universal ID...">
					<select id="dtb-pm-brand" class="dtb-pm-select">
						<option value=""><?php esc_html_e( 'All brands', 'drywall-toolbox' ); ?></option>
						<?php foreach ( $brands as $brand ) : ?>
							<option value="<?php echo esc_attr( $brand ); ?>"><?php echo esc_html( $brand ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="dtb-pm-status" class="dtb-pm-select">
						<option value=""><?php esc_html_e( 'All publish states', 'drywall-toolbox' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'drywall-toolbox' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'drywall-toolbox' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'drywall-toolbox' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'drywall-toolbox' ); ?></option>
					</select>
					<select id="dtb-pm-universal-status" class="dtb-pm-select">
						<option value=""><?php esc_html_e( 'Any universal state', 'drywall-toolbox' ); ?></option>
						<option value="active"><?php esc_html_e( 'Universal: active', 'drywall-toolbox' ); ?></option>
						<option value="review"><?php esc_html_e( 'Universal: review', 'drywall-toolbox' ); ?></option>
						<option value="quarantine"><?php esc_html_e( 'Universal: quarantine', 'drywall-toolbox' ); ?></option>
						<option value="none"><?php esc_html_e( 'No universal ID', 'drywall-toolbox' ); ?></option>
					</select>
					<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-pm-search><?php esc_html_e( 'Search', 'drywall-toolbox' ); ?></button>
					<span class="dtb-pm-count" id="dtb-pm-count"></span>
				</div>
				<div id="dtb-pm-loading" class="dtb-pm-loading"><?php esc_html_e( 'Loading parts…', 'drywall-toolbox' ); ?></div>
				<div class="dtb-pm-table-wrap">
					<table class="dtb-pm-table" id="dtb-pm-table">
						<thead><tr><th><?php esc_html_e( 'Part', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Brand', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Universal', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Commerce', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Actions', 'drywall-toolbox' ); ?></th></tr></thead>
						<tbody id="dtb-pm-body"></tbody>
					</table>
				</div>
				<div id="dtb-pm-empty" class="dtb-pm-empty"><?php esc_html_e( 'No parts found.', 'drywall-toolbox' ); ?></div>
				<div id="dtb-pm-pagination" class="dtb-pm-pagination"></div>
			</div>
		</section>

		<section id="dtb-pm-tab-imports" class="dtb-pm-panel">
			<div class="dtb-pm-grid-2">
				<div class="dtb-pm-card">
					<h2><?php esc_html_e( 'Import brand-specific parts', 'drywall-toolbox' ); ?></h2>
					<p class="dtb-form-note">Required columns: <code>sku</code>, <code>title</code>. Optional: <code>id</code>, <code>brand_label</code>, <code>manufacturer_sku</code>, <code>price</code>, <code>status</code>, <code>description</code>. Universal-part projection fields are read-only here; edit the seed records and use reviewed sync.</p>
					<input id="dtb-pm-import-file" type="file" accept=".csv,text/csv">
					<div class="dtb-toolbar-inline-wrap"><button type="button" id="dtb-pm-import" class="dtb-pm-btn dtb-pm-btn-primary"><?php esc_html_e( 'Import Parts CSV', 'drywall-toolbox' ); ?></button><span id="dtb-pm-import-spinner" class="dtb-inline-hidden"><span class="spinner is-active"></span></span></div>
					<div id="dtb-pm-import-msg" class="dtb-form-msg"></div>
					<pre id="dtb-pm-import-errors" class="dtb-pm-error-log"></pre>
				</div>
				<div class="dtb-pm-card">
					<h2><?php esc_html_e( 'Import schematic parts map', 'drywall-toolbox' ); ?></h2>
					<p class="dtb-form-note">Required columns: <code>schematic_id</code>, <code>part_id</code>, <code>part_name</code>, <code>qty</code>, <code>source_sku</code>.</p>
					<input id="dtb-pm-map-import-file" type="file" accept=".csv,text/csv">
					<div class="dtb-toolbar-inline-wrap"><button type="button" id="dtb-pm-map-import" class="dtb-pm-btn dtb-pm-btn-primary"><?php esc_html_e( 'Import Schematic Map', 'drywall-toolbox' ); ?></button><span id="dtb-pm-map-import-spinner" class="dtb-inline-hidden"><span class="spinner is-active"></span></span></div>
					<div id="dtb-pm-map-import-msg" class="dtb-form-msg"></div>
					<pre id="dtb-pm-map-import-errors" class="dtb-pm-error-log"></pre>
				</div>
			</div>
		</section>

		<section id="dtb-pm-tab-universal" class="dtb-pm-panel">
			<div class="dtb-pm-card">
				<div class="dtb-pm-card-header">
					<div><h2><?php esc_html_e( 'Universal parts seed index', 'drywall-toolbox' ); ?></h2><p><?php esc_html_e( 'The canonical parts, members, and compatibility seed files own universal-part identities. Active member rows may be synced to exact Woo part products; review/quarantine rows remain protected.', 'drywall-toolbox' ); ?></p></div>
					<div class="dtb-pm-export-actions">
						<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-export="parts"><?php esc_html_e( 'Export Parts', 'drywall-toolbox' ); ?></button>
						<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-export="members"><?php esc_html_e( 'Export Members', 'drywall-toolbox' ); ?></button>
						<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-export="compatibility"><?php esc_html_e( 'Export Compatibility', 'drywall-toolbox' ); ?></button>
					</div>
				</div>
				<div class="dtb-pm-toolbar">
					<input id="dtb-pm-universal-search" class="dtb-pm-input dtb-pm-input--wide" type="search" placeholder="Search universal ID, canonical name, brand, SKU...">
					<select id="dtb-pm-universal-filter" class="dtb-pm-select"><option value=""><?php esc_html_e( 'All seed statuses', 'drywall-toolbox' ); ?></option><option value="active"><?php esc_html_e( 'Active', 'drywall-toolbox' ); ?></option><option value="review"><?php esc_html_e( 'Review', 'drywall-toolbox' ); ?></option><option value="quarantine"><?php esc_html_e( 'Quarantine', 'drywall-toolbox' ); ?></option></select>
					<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-search><?php esc_html_e( 'Load Seeds', 'drywall-toolbox' ); ?></button>
					<span class="dtb-pm-count" id="dtb-pm-universal-count"></span>
				</div>
				<div class="dtb-pm-table-wrap"><table class="dtb-pm-table"><thead><tr><th><?php esc_html_e( 'Universal Part', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Spec', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Status', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Brands / SKUs', 'drywall-toolbox' ); ?></th></tr></thead><tbody id="dtb-pm-universal-body"></tbody></table></div>
				<div id="dtb-pm-universal-empty" class="dtb-pm-empty"><?php esc_html_e( 'No universal seed rows found.', 'drywall-toolbox' ); ?></div>
				<div id="dtb-pm-universal-pagination" class="dtb-pm-pagination"></div>
			</div>
		</section>

		<section id="dtb-pm-tab-sync" class="dtb-pm-panel">
			<div class="dtb-pm-card">
				<h2><?php esc_html_e( 'Universal synchronization', 'drywall-toolbox' ); ?></h2>
				<p class="dtb-form-note"><?php esc_html_e( 'Dry run first. Apply only updates Woo product universal metadata for member rows that resolve to existing part SKUs or manufacturer SKUs.', 'drywall-toolbox' ); ?></p>
				<div class="dtb-pm-sync-actions">
					<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-sync="dry_run"><?php esc_html_e( 'Dry Run', 'drywall-toolbox' ); ?></button>
					<button type="button" class="dtb-pm-btn dtb-pm-btn-primary" data-dtb-sync="apply"><?php esc_html_e( 'Apply Resolved Metadata', 'drywall-toolbox' ); ?></button>
				</div>
				<div id="dtb-pm-sync-msg" class="dtb-form-msg"></div>
				<pre id="dtb-pm-sync-output" class="dtb-pm-sync-output"></pre>
			</div>
		</section>
	</div>

	<div class="dtb-pm-modal-overlay" id="dtb-pm-modal-wrap" aria-hidden="true">
		<div class="dtb-pm-modal" role="dialog" aria-modal="true" aria-labelledby="dtb-pm-modal-title">
			<div class="dtb-pm-card-header"><h2 id="dtb-pm-modal-title" class="dtb-form-title"><?php esc_html_e( 'Add Part', 'drywall-toolbox' ); ?></h2><button type="button" id="dtb-pm-close-x" class="dtb-pm-icon-btn" aria-label="Close">×</button></div>
			<input type="hidden" id="dtb-pm-id" value="0">
			<div class="dtb-pm-grid">
				<div class="dtb-pm-row"><label><?php esc_html_e( 'Title *', 'drywall-toolbox' ); ?></label><input id="dtb-pm-title" class="dtb-pm-input dtb-w-full" type="text"></div>
				<div class="dtb-pm-row"><label><?php esc_html_e( 'SKU *', 'drywall-toolbox' ); ?> <span class="dtb-pm-protected-hint" data-protected-hint hidden><?php esc_html_e( 'Protected', 'drywall-toolbox' ); ?></span></label><input id="dtb-pm-sku" class="dtb-pm-input dtb-w-full" type="text" aria-describedby="dtb-pm-sku-help"><small id="dtb-pm-sku-help" class="dtb-pm-field-help"><?php esc_html_e( 'SKU is fixed after creation. Use an approved catalog identity change to correct it.', 'drywall-toolbox' ); ?></small></div>
				<div class="dtb-pm-row"><label><?php esc_html_e( 'Brand', 'drywall-toolbox' ); ?></label><input id="dtb-pm-brand-label" class="dtb-pm-input dtb-w-full" type="text"></div>
				<div class="dtb-pm-row"><label><?php esc_html_e( 'Manufacturer SKU', 'drywall-toolbox' ); ?> <span class="dtb-pm-protected-hint" data-protected-hint hidden><?php esc_html_e( 'Protected', 'drywall-toolbox' ); ?></span></label><input id="dtb-pm-msku" class="dtb-pm-input dtb-w-full" type="text" aria-describedby="dtb-pm-msku-help"><small id="dtb-pm-msku-help" class="dtb-pm-field-help"><?php esc_html_e( 'Manufacturer part number is fixed after creation and must remain exact.', 'drywall-toolbox' ); ?></small></div>
				<div class="dtb-pm-row"><label><?php esc_html_e( 'Price', 'drywall-toolbox' ); ?></label><input id="dtb-pm-price" class="dtb-pm-input dtb-w-full" type="text" placeholder="0.00"></div>
				<div class="dtb-pm-row"><label><?php esc_html_e( 'Status', 'drywall-toolbox' ); ?></label><select id="dtb-pm-post-status" class="dtb-pm-select dtb-w-full"><option value="draft">Draft</option><option value="publish">Publish</option><option value="private">Private</option><option value="pending">Pending</option></select></div>
			</div>
			<div class="dtb-pm-row"><label><?php esc_html_e( 'Description', 'drywall-toolbox' ); ?></label><textarea id="dtb-pm-description" class="dtb-pm-input dtb-pm-textarea"></textarea></div>
			<div class="dtb-pm-modal-actions">
				<button type="button" id="dtb-pm-save" class="dtb-pm-btn dtb-pm-btn-primary"><?php esc_html_e( 'Save Part', 'drywall-toolbox' ); ?></button>
				<button type="button" id="dtb-pm-close" class="dtb-pm-btn dtb-pm-btn-secondary"><?php esc_html_e( 'Close', 'drywall-toolbox' ); ?></button>
				<button type="button" id="dtb-pm-trash" class="dtb-pm-btn dtb-pm-btn-danger dtb-pm-trash"><?php esc_html_e( 'Move to Trash', 'drywall-toolbox' ); ?></button>
				<span id="dtb-pm-spinner" class="dtb-inline-hidden"><span class="spinner is-active"></span></span>
			</div>
			<div id="dtb-pm-msg" class="dtb-form-msg"></div>
		</div>
	</div>
	<?php
}

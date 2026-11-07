<?php
/**
 * Inventory Intelligence admin page.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

add_action( 'admin_enqueue_scripts', 'dtb_inventory_intelligence_enqueue_assets' );

/**
 * Load Inventory Intelligence assets only on the Inventory Intelligence page.
 */
function dtb_inventory_intelligence_enqueue_assets(): void {
	$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'dtb-inventory-intelligence' !== $page ) {
		return;
	}

	$asset_dir = __DIR__ . '/assets/';
	$asset_url = plugin_dir_url( __FILE__ ) . 'assets/';

	$css = $asset_dir . 'dtb-inventory-intelligence.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'dtb-inventory-intelligence',
			$asset_url . 'dtb-inventory-intelligence.css',
			[],
			(string) filemtime( $css )
		);
	}

	$js = $asset_dir . 'dtb-inventory-intelligence.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script(
			'dtb-inventory-intelligence',
			$asset_url . 'dtb-inventory-intelligence.js',
			[ 'jquery' ],
			(string) filemtime( $js ),
			true
		);
		wp_localize_script(
			'dtb-inventory-intelligence',
			'DtbInventoryIntelligence',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dtb_inventory_intelligence_nonce' ),
			]
		);
	}
}

/**
 * Render Inventory Intelligence admin UI.
 */
function dtb_inventory_intelligence_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_inventory_intelligence' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Inventory Intelligence', 'drywall-toolbox' ),
		'subtitle' => __( 'Cross-brand universal stock awareness for parts, repairs, and substitution planning.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-inventory-intelligence',
		'template' => 'tool',
		'icon'     => 'dashicons-chart-area',
	] );
	?>
	<div class="dtb-ii-shell" data-dtb-inventory-intelligence>
		<section class="dtb-ii-hero">
			<div>
				<p class="dtb-ii-eyebrow"><?php esc_html_e( 'Universal Parts × Stock', 'drywall-toolbox' ); ?></p>
				<h1><?php esc_html_e( 'Inventory Intelligence', 'drywall-toolbox' ); ?></h1>
				<p><?php esc_html_e( 'Turn brand-specific stock counts into physical-part availability. Start by projecting universal parts into Woo part metadata, then sync stock and recompute rollups.', 'drywall-toolbox' ); ?></p>
			</div>
			<div class="dtb-ii-hero-actions">
				<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-project-universal="dry_run"><?php esc_html_e( 'Dry Run Universal Import', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-project-universal="apply"><?php esc_html_e( 'Apply Universal Import', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-sync-stock><?php esc_html_e( 'Sync Stock Cache', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-ii-btn dtb-ii-btn-primary" data-dtb-ii-recompute><?php esc_html_e( 'Recompute Rollups', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-ii-btn dtb-ii-btn-primary" data-dtb-ii-full-rebuild><?php esc_html_e( 'Full Rebuild', 'drywall-toolbox' ); ?></button>
			</div>
		</section>

		<section class="dtb-ii-health" aria-label="Inventory integration health">
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="stock_rows">—</span><label><?php esc_html_e( 'Tracked SKUs', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="rollup_rows">—</span><label><?php esc_html_e( 'Universal Rollups', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="critical_rollups">—</span><label><?php esc_html_e( 'True Stockouts', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="latest_sync">—</span><label><?php esc_html_e( 'Last Stock Sync', 'drywall-toolbox' ); ?></label></div>
		</section>

		<section class="dtb-ii-health dtb-ii-health--seed" aria-label="<?php esc_attr_e( 'Universal seed import health', 'drywall-toolbox' ); ?>">
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="seed_parts">—</span><label><?php esc_html_e( 'Seed Parts', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="seed_members">—</span><label><?php esc_html_e( 'Seed Members', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="seed_compatibility">—</span><label><?php esc_html_e( 'Compatibility Rows', 'drywall-toolbox' ); ?></label></div>
			<div class="dtb-ii-health-item"><span data-dtb-ii-metric="seed_files">—</span><label><?php esc_html_e( 'Seed Files', 'drywall-toolbox' ); ?></label></div>
		</section>

		<div id="dtb-ii-message" class="dtb-ii-message" role="status"></div>

		<section class="dtb-ii-card dtb-ii-card--workflow">
			<h2><?php esc_html_e( 'Required Build Sequence', 'drywall-toolbox' ); ?></h2>
			<p><?php esc_html_e( 'If rollups show zero, the universal seed members have not been projected onto Woo part products yet. Run the sequence below or use Full Rebuild.', 'drywall-toolbox' ); ?></p>
			<ol class="dtb-ii-sequence">
				<li><strong><?php esc_html_e( 'Dry Run Universal Import', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'Resolves members.csv rows to Woo SKUs/manufacturer SKUs without writing metadata.', 'drywall-toolbox' ); ?></span></li>
				<li><strong><?php esc_html_e( 'Apply Universal Import', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'Writes _dtb_universal_part_* metadata to resolved Woo part products.', 'drywall-toolbox' ); ?></span></li>
				<li><strong><?php esc_html_e( 'Sync Stock Cache', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'Copies Woo/Veeqo stock projection into the local inventory cache.', 'drywall-toolbox' ); ?></span></li>
				<li><strong><?php esc_html_e( 'Recompute Rollups', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'Aggregates effective stock by universal physical part.', 'drywall-toolbox' ); ?></span></li>
			</ol>
			<pre id="dtb-ii-projection-output" class="dtb-ii-output"></pre>
		</section>

		<section class="dtb-ii-grid">
			<div class="dtb-ii-card dtb-ii-card--wide">
				<div class="dtb-ii-card-header">
					<div>
						<h2><?php esc_html_e( 'Universal Stock Overview', 'drywall-toolbox' ); ?></h2>
						<p><?php esc_html_e( 'Aggregated availability by backend universal physical part. Effective availability counts only active, high-or-verified equivalent members.', 'drywall-toolbox' ); ?></p>
					</div>
				</div>
				<div class="dtb-ii-toolbar">
					<input id="dtb-ii-search" class="dtb-ii-input dtb-ii-input--wide" type="search" placeholder="Search universal ID or part name...">
					<select id="dtb-ii-signal" class="dtb-ii-select">
						<option value=""><?php esc_html_e( 'All signals', 'drywall-toolbox' ); ?></option>
						<option value="critical"><?php esc_html_e( 'Critical', 'drywall-toolbox' ); ?></option>
						<option value="reorder"><?php esc_html_e( 'Reorder', 'drywall-toolbox' ); ?></option>
						<option value="watch"><?php esc_html_e( 'Watch', 'drywall-toolbox' ); ?></option>
						<option value="none"><?php esc_html_e( 'No signal', 'drywall-toolbox' ); ?></option>
					</select>
					<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-load-rollups><?php esc_html_e( 'Load', 'drywall-toolbox' ); ?></button>
					<span id="dtb-ii-rollup-count" class="dtb-ii-count"></span>
				</div>
				<div class="dtb-ii-table-wrap">
					<table class="dtb-ii-table">
						<thead><tr><th><?php esc_html_e( 'Universal Part', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Effective Stock', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Brand Breakdown', 'drywall-toolbox' ); ?></th><th><?php esc_html_e( 'Signal', 'drywall-toolbox' ); ?></th></tr></thead>
						<tbody id="dtb-ii-rollup-body"></tbody>
					</table>
				</div>
				<div id="dtb-ii-rollup-empty" class="dtb-ii-empty"><?php esc_html_e( 'No rollups computed yet. Apply universal import, sync stock cache, then recompute rollups.', 'drywall-toolbox' ); ?></div>
				<div id="dtb-ii-rollup-pagination" class="dtb-ii-pagination"></div>
			</div>

			<div class="dtb-ii-card">
				<h2><?php esc_html_e( 'True Stockout Feed', 'drywall-toolbox' ); ?></h2>
				<p><?php esc_html_e( 'Universal parts with zero effective availability across active, high-confidence equivalents.', 'drywall-toolbox' ); ?></p>
				<div id="dtb-ii-stockout-list" class="dtb-ii-list"></div>
			</div>

			<div class="dtb-ii-card">
				<h2><?php esc_html_e( 'Substitution Preview', 'drywall-toolbox' ); ?></h2>
				<p><?php esc_html_e( 'Enter a SKU to see active/high-confidence equivalents with available stock.', 'drywall-toolbox' ); ?></p>
				<div class="dtb-ii-inline-form">
					<input id="dtb-ii-substitute-sku" class="dtb-ii-input" type="text" placeholder="e.g. 059091">
					<button type="button" class="dtb-ii-btn dtb-ii-btn-primary" data-dtb-ii-substitute><?php esc_html_e( 'Preview', 'drywall-toolbox' ); ?></button>
				</div>
				<pre id="dtb-ii-substitute-output" class="dtb-ii-output"></pre>
			</div>
		</section>
	</div>
	<?php
	dtb_admin_shell_close();
}

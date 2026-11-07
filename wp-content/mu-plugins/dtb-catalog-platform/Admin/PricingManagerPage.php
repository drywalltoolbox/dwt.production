<?php
/**
 * DTB Catalog Platform — Pricing Manager wp-admin workspace.
 *
 * Presentation is intentionally composed from the shared DTB admin shell so
 * BrikPanel remains the owner of global wp-admin chrome and theme tokens.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/** Render the Catalog Pricing workspace. */
function dtb_pricing_manager_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_catalog_pricing' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$allowed_tabs = [ 'products', 'optimizer', 'data' ];
	$active_tab   = sanitize_key( (string) ( $_GET['tab'] ?? 'products' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
		$active_tab = 'products';
	}

	$base_url = admin_url( 'admin.php?page=dtb-pricing-manager' );
	$tabs     = [
		[ 'id' => 'products', 'label' => __( 'Products', 'drywall-toolbox' ), 'active' => 'products' === $active_tab, 'url' => add_query_arg( 'tab', 'products', $base_url ) ],
		[ 'id' => 'optimizer', 'label' => __( 'Optimizer', 'drywall-toolbox' ), 'active' => 'optimizer' === $active_tab, 'url' => add_query_arg( 'tab', 'optimizer', $base_url ) ],
		[ 'id' => 'data', 'label' => __( 'Data', 'drywall-toolbox' ), 'active' => 'data' === $active_tab, 'url' => add_query_arg( 'tab', 'data', $base_url ) ],
	];

	dtb_admin_shell_open(
		[
			'title'    => __( 'Catalog Pricing', 'drywall-toolbox' ),
			'subtitle' => __( 'Manage Cost of Goods floors, evidence-backed margin policy, MAP compliance, guarded recommendations, and approved WooCommerce price changes from one workspace.', 'drywall-toolbox' ),
			'section'  => 'tools',
			'page'     => 'dtb-pricing-manager',
			'template' => 'tool',
			'tabs'     => $tabs,
		]
	);

	echo '<div class="dtb-pricing-admin" data-dtb-pricing-root data-active-tab="' . esc_attr( $active_tab ) . '">';
	echo '<div class="dtb-pricing-status" data-pricing-message role="status" aria-live="polite"></div>';
	echo '<p class="dtb-pricing-sync" data-pricing-sync role="status" aria-live="polite">' . esc_html__( 'Connected to the live WooCommerce catalog. Refreshes every 30 seconds while this page is open.', 'drywall-toolbox' ) . '</p>';

	if ( 'products' === $active_tab ) {
		dtb_pricing_manager_render_products_tab();
	} elseif ( 'optimizer' === $active_tab ) {
		dtb_pricing_manager_render_optimizer_tab();
	} else {
		dtb_pricing_manager_render_data_tab();
	}

	dtb_pricing_manager_render_drawer();
	echo '</div>';
	dtb_admin_shell_close();
}

/** Render the primary product pricing table workspace. */
function dtb_pricing_manager_render_products_tab(): void {
	?>
	<section class="dtb-pricing-summary" aria-label="<?php esc_attr_e( 'Pricing summary', 'drywall-toolbox' ); ?>" data-pricing-summary>
		<div class="dtb-pricing-summary__item"><strong data-summary-total>—</strong><span><?php esc_html_e( 'Price records', 'drywall-toolbox' ); ?></span></div>
		<div class="dtb-pricing-summary__item"><strong data-summary-cost>—</strong><span><?php esc_html_e( 'With cost', 'drywall-toolbox' ); ?></span></div>
		<div class="dtb-pricing-summary__item"><strong data-summary-map>—</strong><span><?php esc_html_e( 'With MAP', 'drywall-toolbox' ); ?></span></div>
		<div class="dtb-pricing-summary__item"><strong data-summary-review>—</strong><span><?php esc_html_e( 'Needs attention', 'drywall-toolbox' ); ?></span></div>
	</section>

	<section class="dtb-pricing-workspace" aria-label="<?php esc_attr_e( 'Product pricing workspace', 'drywall-toolbox' ); ?>">
		<div class="dtb-pricing-toolbar">
			<div class="dtb-pricing-toolbar__primary">
				<label class="screen-reader-text" for="dtb-pricing-search"><?php esc_html_e( 'Search products', 'drywall-toolbox' ); ?></label>
				<input id="dtb-pricing-search" type="search" placeholder="<?php esc_attr_e( 'Search product or SKU…', 'drywall-toolbox' ); ?>" data-pricing-search>
				<label class="screen-reader-text" for="dtb-pricing-brand"><?php esc_html_e( 'Filter by brand', 'drywall-toolbox' ); ?></label>
				<select id="dtb-pricing-brand" data-pricing-brand><option value=""><?php esc_html_e( 'All brands', 'drywall-toolbox' ); ?></option></select>
				<label class="screen-reader-text" for="dtb-pricing-status"><?php esc_html_e( 'Filter by pricing status', 'drywall-toolbox' ); ?></label>
				<select id="dtb-pricing-status" data-pricing-status>
					<option value="all"><?php esc_html_e( 'All statuses', 'drywall-toolbox' ); ?></option>
					<option value="below_cogs"><?php esc_html_e( 'Below COGS', 'drywall-toolbox' ); ?></option>
					<option value="below_minimum"><?php esc_html_e( 'Below minimum margin', 'drywall-toolbox' ); ?></option>
					<option value="below_map"><?php esc_html_e( 'MAP violations', 'drywall-toolbox' ); ?></option>
					<option value="below_target"><?php esc_html_e( 'Below target', 'drywall-toolbox' ); ?></option>
					<option value="healthy"><?php esc_html_e( 'Healthy', 'drywall-toolbox' ); ?></option>
					<option value="missing_map"><?php esc_html_e( 'MAP not configured', 'drywall-toolbox' ); ?></option>
					<option value="missing_cost"><?php esc_html_e( 'Missing cost', 'drywall-toolbox' ); ?></option>
					<option value="missing_price"><?php esc_html_e( 'Missing price', 'drywall-toolbox' ); ?></option>
					<option value="sale_active"><?php esc_html_e( 'Sale active', 'drywall-toolbox' ); ?></option>
				</select>
			</div>
		</div>

		<div class="dtb-table-wrap dtb-pricing-table-wrap">
			<table class="dtb-table dtb-pricing-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Product', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cost', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'MAP', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Effective', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Margin', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hard floor / policy', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'drywall-toolbox' ); ?></th>
					<th scope="col" class="dtb-pricing-table__actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'drywall-toolbox' ); ?></span></th>
				</tr></thead>
				<tbody data-pricing-products-body><tr><td colspan="8" class="dtb-pricing-loading"><?php esc_html_e( 'Loading pricing data…', 'drywall-toolbox' ); ?></td></tr></tbody>
			</table>
		</div>
		<div class="dtb-pricing-pagination" data-pricing-pagination></div>
	</section>
	<?php
}

/** Render deterministic production pricing recommendations. */
function dtb_pricing_manager_render_optimizer_tab(): void {
	?>
	<section class="dtb-card dtb-pricing-optimizer-intro">
		<div class="dtb-card__body">
			<div>
				<h2><?php esc_html_e( 'Production pricing optimizer', 'drywall-toolbox' ); ?></h2>
				<p><?php esc_html_e( 'Cost of Goods and the resolved minimum margin define the economic floor. Official MAP is an additional hard floor when configured. Target margin resolves by supported category, then brand, then global fallback; healthy higher prices are never lowered.', 'drywall-toolbox' ); ?></p>
			</div>
			<div class="dtb-pricing-policy-chip"><span><?php esc_html_e( 'Global fallback', 'drywall-toolbox' ); ?></span><strong data-optimizer-target>—</strong></div>
		</div>
	</section>

	<section class="dtb-pricing-workspace" aria-label="<?php esc_attr_e( 'Pricing optimizer', 'drywall-toolbox' ); ?>">
		<div class="dtb-pricing-toolbar">
			<div class="dtb-pricing-toolbar__primary">
				<select data-optimizer-filter aria-label="<?php esc_attr_e( 'Recommendation type', 'drywall-toolbox' ); ?>">
					<option value="needs_action"><?php esc_html_e( 'Needs action', 'drywall-toolbox' ); ?></option>
					<option value="below_cogs"><?php esc_html_e( 'Below COGS', 'drywall-toolbox' ); ?></option>
					<option value="below_minimum"><?php esc_html_e( 'Below minimum margin', 'drywall-toolbox' ); ?></option>
					<option value="below_map"><?php esc_html_e( 'MAP violations', 'drywall-toolbox' ); ?></option>
					<option value="below_target"><?php esc_html_e( 'Below target margin', 'drywall-toolbox' ); ?></option>
					<option value="needs_review"><?php esc_html_e( 'Review / blocked', 'drywall-toolbox' ); ?></option>
				</select>
			</div>
			<div class="dtb-pricing-toolbar__actions">
				<button type="button" class="button button-primary" data-optimizer-apply disabled><?php esc_html_e( 'Apply selected', 'drywall-toolbox' ); ?></button>
			</div>
		</div>

		<div class="dtb-table-wrap dtb-pricing-table-wrap">
			<table class="dtb-table dtb-pricing-table">
				<thead><tr>
					<th scope="col" class="dtb-pricing-check"><input type="checkbox" data-optimizer-select-all aria-label="<?php esc_attr_e( 'Select all recommendations on this page', 'drywall-toolbox' ); ?>"></th>
					<th scope="col"><?php esc_html_e( 'Product', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cost', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current regular', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current margin', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recommended', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Resulting margin', 'drywall-toolbox' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason / policy', 'drywall-toolbox' ); ?></th>
				</tr></thead>
				<tbody data-optimizer-body><tr><td colspan="8" class="dtb-pricing-loading"><?php esc_html_e( 'Loading recommendations…', 'drywall-toolbox' ); ?></td></tr></tbody>
			</table>
		</div>
		<div class="dtb-pricing-pagination" data-optimizer-pagination></div>
	</section>
	<?php
}

/** Render source coverage and deterministic global pricing guardrails. */
function dtb_pricing_manager_render_data_tab(): void {
	?>
	<section class="dtb-pricing-data-grid">
		<div class="dtb-card">
			<div class="dtb-card__header"><div><h2 class="dtb-card__title"><?php esc_html_e( 'Pricing data', 'drywall-toolbox' ); ?></h2><p class="dtb-card__subtitle"><?php esc_html_e( 'Current runtime sources used by the rules engine.', 'drywall-toolbox' ); ?></p></div></div>
			<div class="dtb-card__body">
				<div class="dtb-pricing-source-list">
					<div class="dtb-pricing-source"><div><strong><?php esc_html_e( 'Retail prices', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'WooCommerce product and variation prices', 'drywall-toolbox' ); ?></span></div><span class="dtb-badge dtb-badge--success"><?php esc_html_e( 'Live', 'drywall-toolbox' ); ?></span></div>
					<div class="dtb-pricing-source"><div><strong><?php esc_html_e( 'Supplier cost', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'WooCommerce Cost of Goods', 'drywall-toolbox' ); ?></span></div><span class="dtb-badge" data-data-cost-coverage>—</span></div>
					<div class="dtb-pricing-source"><div><strong><?php esc_html_e( 'MAP', 'drywall-toolbox' ); ?></strong><span><?php esc_html_e( 'Official DTB MAP field when configured', 'drywall-toolbox' ); ?></span></div><span class="dtb-badge" data-data-map-coverage>—</span></div>
				</div>
			</div>
		</div>

		<div class="dtb-card">
			<div class="dtb-card__header"><div><h2 class="dtb-card__title"><?php esc_html_e( 'Global fallback & guardrails', 'drywall-toolbox' ); ?></h2><p class="dtb-card__subtitle"><?php esc_html_e( 'Supported category policies take priority, then brand, then these global fallback values.', 'drywall-toolbox' ); ?></p></div></div>
			<div class="dtb-card__body">
				<form class="dtb-pricing-policy-form" data-pricing-policy-form>
					<div class="dtb-pricing-field"><label for="dtb-pricing-minimum-margin"><?php esc_html_e( 'Global minimum gross margin', 'drywall-toolbox' ); ?></label><div class="dtb-pricing-percentage-input"><input id="dtb-pricing-minimum-margin" type="number" min="0.01" max="95" step="0.1" data-policy-field="minimum_margin"><span>%</span></div></div>
					<div class="dtb-pricing-field"><label for="dtb-pricing-target-margin"><?php esc_html_e( 'Global target gross margin', 'drywall-toolbox' ); ?></label><div class="dtb-pricing-percentage-input"><input id="dtb-pricing-target-margin" type="number" min="0.01" max="95" step="0.1" data-pricing-target-margin><span>%</span></div></div>
					<div class="dtb-pricing-field"><label for="dtb-pricing-no-change"><?php esc_html_e( 'No-change threshold', 'drywall-toolbox' ); ?></label><div class="dtb-pricing-percentage-input"><input id="dtb-pricing-no-change" type="number" min="0" max="25" step="0.1" data-policy-field="no_change_threshold_pct"><span>%</span></div></div>
					<div class="dtb-pricing-field"><label for="dtb-pricing-review-change"><?php esc_html_e( 'Manual-review change threshold', 'drywall-toolbox' ); ?></label><div class="dtb-pricing-percentage-input"><input id="dtb-pricing-review-change" type="number" min="0" max="100" step="0.1" data-policy-field="review_change_threshold_pct"><span>%</span></div></div>
					<div class="dtb-pricing-field"><label for="dtb-pricing-block-change"><?php esc_html_e( 'Blocked change threshold', 'drywall-toolbox' ); ?></label><div class="dtb-pricing-percentage-input"><input id="dtb-pricing-block-change" type="number" min="0" max="500" step="0.1" data-policy-field="block_change_threshold_pct"><span>%</span></div></div>
					<p class="description"><?php esc_html_e( 'Hard floor = max(COGS, minimum-margin price, MAP when configured). Target price = COGS ÷ (1 − target margin). Hard violations remain actionable; large normal target-margin changes are routed to review or blocked by the configured thresholds.', 'drywall-toolbox' ); ?></p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save pricing policy', 'drywall-toolbox' ); ?></button>
				</form>
			</div>
		</div>
	</section>

	<section class="dtb-card dtb-pricing-data-health">
		<div class="dtb-card__header"><div><h2 class="dtb-card__title"><?php esc_html_e( 'Catalog pricing health', 'drywall-toolbox' ); ?></h2></div></div>
		<div class="dtb-card__body">
			<div class="dtb-pricing-summary dtb-pricing-summary--inside">
				<div class="dtb-pricing-summary__item"><strong data-data-total>—</strong><span><?php esc_html_e( 'Price records', 'drywall-toolbox' ); ?></span></div>
				<div class="dtb-pricing-summary__item"><strong data-data-missing-cost>—</strong><span><?php esc_html_e( 'Missing cost', 'drywall-toolbox' ); ?></span></div>
				<div class="dtb-pricing-summary__item"><strong data-data-below-cogs>—</strong><span><?php esc_html_e( 'Below COGS', 'drywall-toolbox' ); ?></span></div>
				<div class="dtb-pricing-summary__item"><strong data-data-below-minimum>—</strong><span><?php esc_html_e( 'Below minimum', 'drywall-toolbox' ); ?></span></div>
				<div class="dtb-pricing-summary__item"><strong data-data-below-target>—</strong><span><?php esc_html_e( 'Below target', 'drywall-toolbox' ); ?></span></div>
				<div class="dtb-pricing-summary__item"><strong data-data-below-map>—</strong><span><?php esc_html_e( 'MAP violations', 'drywall-toolbox' ); ?></span></div>
			</div>
		</div>
	</section>
	<?php
}

/** Render the reusable product pricing side drawer. */
function dtb_pricing_manager_render_drawer(): void {
	?>
	<aside class="dtb-pricing-drawer" data-pricing-drawer hidden aria-labelledby="dtb-pricing-drawer-title">
		<div class="dtb-pricing-drawer__header">
			<div><span class="dtb-pricing-drawer__eyebrow" data-drawer-sku></span><h2 id="dtb-pricing-drawer-title" data-drawer-title><?php esc_html_e( 'Product pricing', 'drywall-toolbox' ); ?></h2></div>
			<button type="button" class="dtb-pricing-drawer__close" data-drawer-close aria-label="<?php esc_attr_e( 'Close pricing panel', 'drywall-toolbox' ); ?>">×</button>
		</div>
		<div class="dtb-pricing-drawer__body">
			<div class="dtb-pricing-drawer__metrics">
				<div><span><?php esc_html_e( 'Cost', 'drywall-toolbox' ); ?></span><strong data-drawer-cost>—</strong></div>
				<div><span><?php esc_html_e( 'Gross margin', 'drywall-toolbox' ); ?></span><strong data-drawer-margin>—</strong></div>
				<div><span><?php esc_html_e( 'Markup', 'drywall-toolbox' ); ?></span><strong data-drawer-markup>—</strong></div>
				<div><span><?php esc_html_e( 'Recommended', 'drywall-toolbox' ); ?></span><strong data-drawer-target>—</strong></div>
			</div>
			<form data-pricing-drawer-form>
				<input type="hidden" data-drawer-product-id>
				<div class="dtb-pricing-field"><label for="dtb-drawer-regular-price"><?php esc_html_e( 'Regular price', 'drywall-toolbox' ); ?></label><input id="dtb-drawer-regular-price" type="number" min="0" step="0.01" data-drawer-regular-price></div>
				<div class="dtb-pricing-field"><label for="dtb-drawer-map-price"><?php esc_html_e( 'MAP', 'drywall-toolbox' ); ?></label><input id="dtb-drawer-map-price" type="number" min="0" step="0.01" data-drawer-map-price></div>
				<div class="dtb-pricing-field"><label for="dtb-drawer-map-source"><?php esc_html_e( 'MAP source', 'drywall-toolbox' ); ?></label><input id="dtb-drawer-map-source" type="text" maxlength="200" placeholder="<?php esc_attr_e( 'Manufacturer MAP sheet, TSW reference…', 'drywall-toolbox' ); ?>" data-drawer-map-source></div>
				<div class="dtb-pricing-drawer__sale-note" data-drawer-sale-note hidden><?php esc_html_e( 'This product has an active sale price. Existing sale prices are protected by COGS, the resolved minimum-margin floor, and MAP when configured.', 'drywall-toolbox' ); ?></div>
				<div class="dtb-pricing-drawer__actions">
					<button type="button" class="button button-secondary" data-drawer-use-target><?php esc_html_e( 'Use recommendation', 'drywall-toolbox' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save pricing', 'drywall-toolbox' ); ?></button>
				</div>
			</form>
		</div>
	</aside>
	<?php
}

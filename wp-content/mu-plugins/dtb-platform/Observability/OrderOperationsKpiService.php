<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Order Operations Dashboard
 *
 * Adds the WP-Admin → DTB Ops → Order Operations submenu page and renders the
 * full tabbed dashboard shell with inline CSS, Bootstrap JS, and AJAX wiring.
 *
 * Tabs:
 *   Overview | Product Orders | Repair Orders | Queue / Actions | Audit Log | Settings
 *
 * Depends on (loaded first by WordPress MU-Plugin autoloader):
 *   dtb-order-operations-read-models.php  — query/projection helpers
 *   dtb-order-operations-actions.php      — mutation handlers
 *   dtb-order-operations-ajax.php         — AJAX endpoint registrations
 *   dtb-platform/Observability/OpsDashboard.php — parent DTB Ops menu
 *
 * @package drywall-toolbox
 */


if ( ! is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
	return;
}

// =============================================================================
// SECTION 1 — CAPABILITY BOOTSTRAP
// =============================================================================
//
// `dtb_manage_order_operations` is granted centrally by
// `dtb-platform/Admin/AdminCapabilities.php` (`dtb_admin_all_capabilities()`)
// — see that file's header comment. This used to independently re-grant the
// same capability to `administrator` on every `init`; removed as a redundant
// duplicate of that canonical grant.

// =============================================================================
// SECTION 2 — ADMIN MENU REGISTRATION
// =============================================================================

add_action( 'admin_menu', 'dtb_oo_register_menu', 7 ); // Priority 7 — after dtb_ops_register_menu (6).

/**
 * Register Order Operations as a submenu under DTB Ops.
 */
function dtb_oo_register_menu(): void {
	if ( ! dtb_oo_can_view() ) {
		return;
	}

	add_submenu_page(
		'dtb-ops',
		__( 'Order Operations', 'dtb' ),
		__( 'Order Operations', 'dtb' ),
		'manage_woocommerce',
		'dtb-ops-order-operations',
		'dtb_oo_render_page'
	);
}

// =============================================================================
// SECTION 3 — ASSET ENQUEUE
// =============================================================================

add_action( 'admin_enqueue_scripts', 'dtb_oo_enqueue_assets' );

/**
 * Enqueue CSS + JS only on the Order Operations page.
 *
 * @param string $hook Current admin page hook suffix.
 */
function dtb_oo_enqueue_assets( string $hook ): void {
    $on_order_ops_page = false !== strpos( $hook, 'dtb-ops-order-operations' );
    $on_dtb_ops_page   = false !== strpos( $hook, 'dtb-ops' );

    if ( ! $on_order_ops_page && ! $on_dtb_ops_page ) {
		return;
	}

    $settings = function_exists( 'dtb_oo_get_settings' ) ? dtb_oo_get_settings() : [];

    // Attach dashboard JS to a guaranteed admin script handle.
    wp_enqueue_script( 'jquery' );

    // Bootstrap data for JS.
    $bootstrap = [
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( DTB_OO_NONCE_ACTION ),
		'pollInterval'    => max( 180, (int) ( $settings['poll_interval'] ?? 180 ) ) * 1000,
		'slaWarningHours' => (int) ( $settings['sla_warning_hours'] ?? 72 ),
		'slaBreachHours'  => (int) ( $settings['sla_breach_hours'] ?? 120 ),
		'repairStatuses'  => function_exists( 'dtb_get_all_repair_statuses' ) ? dtb_get_all_repair_statuses() : [],
		'repairAllowedTransitions' => function_exists( 'dtb_get_allowed_transitions' ) ? dtb_get_allowed_transitions() : [],
		'strings'         => [
			'confirm_action'  => __( 'Are you sure you want to perform this action?', 'dtb' ),
			'confirm_bulk'    => __( 'Apply bulk action to %d selected items?', 'dtb' ),
			'loading'         => __( 'Loading…', 'dtb' ),
			'no_results'      => __( 'No results found.', 'dtb' ),
			'error_generic'   => __( 'An error occurred. Please try again.', 'dtb' ),
        ],
    ];

    wp_add_inline_script(
        'jquery',
        'window.dtbOpsOrd = ' . wp_json_encode( $bootstrap ) . ';',
        'before'
    );

    wp_add_inline_script( 'jquery', dtb_oo_inline_js(), 'after' );
}

// =============================================================================
// SECTION 4 — PAGE RENDER
// =============================================================================

/**
 * Render the full Order Operations dashboard page.
 */
function dtb_oo_render_page(): void {
	if ( ! dtb_oo_can_view() ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'dtb' ) );
	}

    if ( function_exists( 'dtb_ops_render_dashboard' ) ) {
        dtb_ops_render_dashboard();
        return;
    }

    dtb_oo_render_dashboard_shell( false );
}

/**
 * Render Order Operations inside the DTB Ops dashboard section.
 */
function dtb_oo_render_embedded_section(): void {
    if ( ! dtb_oo_can_view() ) {
        echo '<p class="dtb-oo-error">' . esc_html__( 'You do not have permission to view Order Operations.', 'dtb' ) . '</p>';
        return;
    }

    echo '<div id="dtb-order-operations" class="dtb-oo-embedded">';
    dtb_oo_render_dashboard_shell( true );
    echo '</div>';
}

/**
 * Render the shared Order Operations shell.
 *
 * @param bool $embedded Whether rendering inside DTB Ops.
 */
function dtb_oo_render_dashboard_shell( bool $embedded = false ): void {
    $container_classes = $embedded
        ? 'dtb-oo-wrap dtb-oo-wrap--embedded'
        : 'wrap dtb-oo-wrap';
	?>
    <div class="<?php echo esc_attr( $container_classes ); ?>">
        <?php if ( $embedded ) : ?>
        <div class="dtb-oo-topbar">
            <div>
                <h3 class="dtb-oo-embedded-title"><?php esc_html_e( 'Order Operations', 'dtb' ); ?></h3>
                <p class="dtb-oo-embedded-subtitle"><?php esc_html_e( 'Live queue, SLA health, and fulfillment actions.', 'dtb' ); ?></p>
            </div>
            <div class="dtb-oo-topbar-actions">
                <button type="button" id="dtb-oo-refresh-btn" class="button button-primary">
                    <?php esc_html_e( '↺ Refresh', 'dtb' ); ?>
                </button>
                <span id="dtb-oo-poll-indicator" class="dtb-oo-poll-indicator" aria-live="polite"></span>
            </div>
        </div>
        <?php else : ?>
		<h1 class="wp-heading-inline">
			<?php esc_html_e( 'Order Operations', 'dtb' ); ?>
		</h1>
		<button type="button" id="dtb-oo-refresh-btn" class="page-title-action">
			<?php esc_html_e( '↺ Refresh', 'dtb' ); ?>
		</button>
		<span id="dtb-oo-poll-indicator" class="dtb-oo-poll-indicator" aria-live="polite"></span>
        <?php endif; ?>

		<?php /* Tab Navigation */ ?>
		<nav class="dtb-oo-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Order Operations Tabs', 'dtb' ); ?>">
            <?php if ( ! $embedded ) : ?>
			<a href="#" class="dtb-oo-tab nav-tab nav-tab-active" data-tab="overview" role="tab" aria-selected="true" aria-controls="dtb-oo-tab-overview">
				<?php esc_html_e( 'Overview', 'dtb' ); ?>
			</a>
            <?php endif; ?>
            <a href="#" class="dtb-oo-tab nav-tab<?php echo $embedded ? ' nav-tab-active' : ''; ?>" data-tab="product_orders" role="tab" aria-selected="<?php echo $embedded ? 'true' : 'false'; ?>" aria-controls="dtb-oo-tab-product_orders">
				<?php esc_html_e( 'Product Orders', 'dtb' ); ?>
			</a>
			<a href="#" class="dtb-oo-tab nav-tab" data-tab="repair_orders" role="tab" aria-selected="false" aria-controls="dtb-oo-tab-repair_orders">
				<?php esc_html_e( 'Repair Orders', 'dtb' ); ?>
			</a>
			<a href="#" class="dtb-oo-tab nav-tab" data-tab="queue" role="tab" aria-selected="false" aria-controls="dtb-oo-tab-queue">
				<?php esc_html_e( 'Queue / Actions', 'dtb' ); ?>
			</a>
			<a href="#" class="dtb-oo-tab nav-tab" data-tab="audit_log" role="tab" aria-selected="false" aria-controls="dtb-oo-tab-audit_log">
				<?php esc_html_e( 'Audit Log', 'dtb' ); ?>
			</a>
			<?php if ( dtb_oo_can_manage_settings() ) : ?>
			<a href="#" class="dtb-oo-tab nav-tab" data-tab="settings" role="tab" aria-selected="false" aria-controls="dtb-oo-tab-settings">
				<?php esc_html_e( 'Settings', 'dtb' ); ?>
			</a>
			<?php endif; ?>
		</nav>

		<?php /* Tab Panels */ ?>

        <?php if ( ! $embedded ) : ?>
        <?php /* Overview Tab */ ?>
        <div id="dtb-oo-tab-overview" class="dtb-oo-tab-panel" role="tabpanel" data-tab="overview">
            <div id="dtb-oo-overview-kpis" class="dtb-oo-kpi-grid">
                <p class="dtb-oo-loading"><?php esc_html_e( 'Loading KPIs…', 'dtb' ); ?></p>
            </div>
        </div>
        <?php endif; ?>

		<?php /* Product Orders Tab */ ?>
		<div id="dtb-oo-tab-product_orders" class="dtb-oo-tab-panel" role="tabpanel" data-tab="product_orders" hidden>
			<form id="dtb-oo-po-filter-form" class="dtb-oo-filter-bar">
				<select name="woo_status" aria-label="<?php esc_attr_e( 'WooCommerce Status', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Statuses', 'dtb' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'dtb' ); ?></option>
					<option value="processing"><?php esc_html_e( 'Processing', 'dtb' ); ?></option>
					<option value="on-hold"><?php esc_html_e( 'On Hold', 'dtb' ); ?></option>
					<option value="completed"><?php esc_html_e( 'Completed', 'dtb' ); ?></option>
					<option value="cancelled"><?php esc_html_e( 'Cancelled', 'dtb' ); ?></option>
					<option value="refunded"><?php esc_html_e( 'Refunded', 'dtb' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'dtb' ); ?></option>
				</select>
				<select name="fulfillment_substate" aria-label="<?php esc_attr_e( 'Fulfillment Substate', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Substates', 'dtb' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'dtb' ); ?></option>
					<option value="inventory_reserved"><?php esc_html_e( 'Inventory Reserved', 'dtb' ); ?></option>
					<option value="picked"><?php esc_html_e( 'Picked', 'dtb' ); ?></option>
					<option value="packed"><?php esc_html_e( 'Packed', 'dtb' ); ?></option>
					<option value="shipped"><?php esc_html_e( 'Shipped', 'dtb' ); ?></option>
					<option value="delivered"><?php esc_html_e( 'Delivered', 'dtb' ); ?></option>
				</select>
				<select name="stale" aria-label="<?php esc_attr_e( 'Staleness', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Ages', 'dtb' ); ?></option>
					<option value="stale"><?php esc_html_e( 'Stale Only', 'dtb' ); ?></option>
				</select>
				<input type="text" name="customer" placeholder="<?php esc_attr_e( 'Customer name', 'dtb' ); ?>" />
				<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'dtb' ); ?>" />
				<input type="number" name="order_id" placeholder="<?php esc_attr_e( 'Order ID', 'dtb' ); ?>" min="1" />
				<input type="date" name="date_from" aria-label="<?php esc_attr_e( 'Date from', 'dtb' ); ?>" />
				<input type="date" name="date_to" aria-label="<?php esc_attr_e( 'Date to', 'dtb' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dtb' ); ?></button>
				<button type="button" class="button dtb-oo-filter-reset"><?php esc_html_e( 'Reset', 'dtb' ); ?></button>
			</form>
			<div class="dtb-oo-bulk-bar" id="dtb-oo-po-bulk-bar" hidden>
				<span class="dtb-oo-bulk-count"></span>
				<select id="dtb-oo-po-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk action…', 'dtb' ); ?></option>
					<option value="refresh_tracking_projections"><?php esc_html_e( 'Refresh Tracking Projections', 'dtb' ); ?></option>
					<option value="refresh_order_projections"><?php esc_html_e( 'Refresh Order Projections', 'dtb' ); ?></option>
					<option value="mark_reviewed"><?php esc_html_e( 'Mark Reviewed', 'dtb' ); ?></option>
					<option value="add_bulk_internal_note"><?php esc_html_e( 'Add Internal Note', 'dtb' ); ?></option>
					<option value="export_selected"><?php esc_html_e( 'Export Selected', 'dtb' ); ?></option>
				</select>
				<input type="text" id="dtb-oo-po-bulk-note" placeholder="<?php esc_attr_e( 'Bulk note (for Add Note action)', 'dtb' ); ?>" />
				<button type="button" class="button button-primary" id="dtb-oo-po-bulk-apply"><?php esc_html_e( 'Apply', 'dtb' ); ?></button>
			</div>
			<div id="dtb-oo-product-orders-table"></div>
		</div>

		<?php /* Repair Orders Tab */ ?>
		<div id="dtb-oo-tab-repair_orders" class="dtb-oo-tab-panel" role="tabpanel" data-tab="repair_orders" hidden>
			<form id="dtb-oo-ro-filter-form" class="dtb-oo-filter-bar">
				<select name="repair_status" aria-label="<?php esc_attr_e( 'Repair Status', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Statuses', 'dtb' ); ?></option>
					<?php
					$r_statuses = function_exists( 'dtb_get_all_repair_statuses' ) ? dtb_get_all_repair_statuses() : [];
					foreach ( $r_statuses as $rs ) {
						$label = function_exists( 'dtb_get_repair_status_label' )
							? dtb_get_repair_status_label( $rs )
							: ucwords( str_replace( '_', ' ', $rs ) );
						echo '<option value="' . esc_attr( $rs ) . '">' . esc_html( $label ) . '</option>';
					}
					?>
				</select>
				<select name="brand" aria-label="<?php esc_attr_e( 'Tool Brand', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Brands', 'dtb' ); ?></option>
					<?php
					$brands = defined( 'DTB_REPAIR_ALLOWED_BRANDS' ) ? DTB_REPAIR_ALLOWED_BRANDS : [ 'TapeTech', 'Columbia Tools', 'Asgard', 'Other' ];
					foreach ( $brands as $b ) {
						echo '<option value="' . esc_attr( $b ) . '">' . esc_html( $b ) . '</option>';
					}
					?>
				</select>
				<select name="service_tier" aria-label="<?php esc_attr_e( 'Service Tier', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Tiers', 'dtb' ); ?></option>
					<option value="standard"><?php esc_html_e( 'Standard', 'dtb' ); ?></option>
					<option value="express"><?php esc_html_e( 'Express', 'dtb' ); ?></option>
					<option value="warranty"><?php esc_html_e( 'Warranty', 'dtb' ); ?></option>
				</select>
				<select name="sla_state" aria-label="<?php esc_attr_e( 'SLA State', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All SLA States', 'dtb' ); ?></option>
					<option value="warning"><?php esc_html_e( 'SLA Warning', 'dtb' ); ?></option>
					<option value="breached"><?php esc_html_e( 'SLA Breached', 'dtb' ); ?></option>
				</select>
				<input type="text" name="customer" placeholder="<?php esc_attr_e( 'Customer name', 'dtb' ); ?>" />
				<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'dtb' ); ?>" />
				<input type="number" name="repair_id" placeholder="<?php esc_attr_e( 'Repair ID', 'dtb' ); ?>" min="1" />
				<input type="text" name="model" placeholder="<?php esc_attr_e( 'Model', 'dtb' ); ?>" />
				<input type="text" name="serial" placeholder="<?php esc_attr_e( 'Serial', 'dtb' ); ?>" />
				<input type="date" name="date_from" aria-label="<?php esc_attr_e( 'Date from', 'dtb' ); ?>" />
				<input type="date" name="date_to" aria-label="<?php esc_attr_e( 'Date to', 'dtb' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dtb' ); ?></button>
				<button type="button" class="button dtb-oo-filter-reset"><?php esc_html_e( 'Reset', 'dtb' ); ?></button>
			</form>
			<div class="dtb-oo-bulk-bar" id="dtb-oo-ro-bulk-bar" hidden>
				<span class="dtb-oo-bulk-count"></span>
				<select id="dtb-oo-ro-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk action…', 'dtb' ); ?></option>
					<option value="assign_technician"><?php esc_html_e( 'Assign Technician', 'dtb' ); ?></option>
					<option value="request_customer_info"><?php esc_html_e( 'Request Customer Info', 'dtb' ); ?></option>
					<option value="close_repairs"><?php esc_html_e( 'Close Repairs', 'dtb' ); ?></option>
					<option value="refresh_repair_projections"><?php esc_html_e( 'Refresh Projections', 'dtb' ); ?></option>
					<option value="add_bulk_internal_note"><?php esc_html_e( 'Add Internal Note', 'dtb' ); ?></option>
					<option value="export_selected"><?php esc_html_e( 'Export Selected', 'dtb' ); ?></option>
				</select>
				<input type="number" id="dtb-oo-ro-bulk-tech-id" placeholder="<?php esc_attr_e( 'Tech user ID (assign only)', 'dtb' ); ?>" min="1" />
				<input type="text" id="dtb-oo-ro-bulk-note" placeholder="<?php esc_attr_e( 'Bulk note (Add Note action)', 'dtb' ); ?>" />
				<button type="button" class="button button-primary" id="dtb-oo-ro-bulk-apply"><?php esc_html_e( 'Apply', 'dtb' ); ?></button>
			</div>
			<div id="dtb-oo-repair-orders-table"></div>
		</div>

		<?php /* Queue / Actions Tab */ ?>
		<div id="dtb-oo-tab-queue" class="dtb-oo-tab-panel" role="tabpanel" data-tab="queue" hidden>
			<form id="dtb-oo-queue-filter-form" class="dtb-oo-filter-bar">
				<select name="status" aria-label="<?php esc_attr_e( 'Job Status', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Statuses', 'dtb' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'dtb' ); ?></option>
					<option value="in-progress"><?php esc_html_e( 'In Progress', 'dtb' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'dtb' ); ?></option>
					<option value="complete"><?php esc_html_e( 'Complete', 'dtb' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dtb' ); ?></button>
			</form>
			<div id="dtb-oo-queue-table"></div>
		</div>

		<?php /* Audit Log Tab */ ?>
		<div id="dtb-oo-tab-audit_log" class="dtb-oo-tab-panel" role="tabpanel" data-tab="audit_log" hidden>
			<form id="dtb-oo-audit-filter-form" class="dtb-oo-filter-bar">
				<select name="entity_type" aria-label="<?php esc_attr_e( 'Entity Type', 'dtb' ); ?>">
					<option value=""><?php esc_html_e( 'All Entities', 'dtb' ); ?></option>
					<option value="product_order"><?php esc_html_e( 'Product Orders', 'dtb' ); ?></option>
					<option value="repair_order"><?php esc_html_e( 'Repair Orders', 'dtb' ); ?></option>
				</select>
				<input type="number" name="entity_id" placeholder="<?php esc_attr_e( 'Entity ID', 'dtb' ); ?>" min="1" />
				<input type="date" name="date_from" aria-label="<?php esc_attr_e( 'Date from', 'dtb' ); ?>" />
				<input type="date" name="date_to" aria-label="<?php esc_attr_e( 'Date to', 'dtb' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dtb' ); ?></button>
				<button type="button" class="button dtb-oo-filter-reset"><?php esc_html_e( 'Reset', 'dtb' ); ?></button>
			</form>
			<div id="dtb-oo-audit-log-table"></div>
		</div>

		<?php /* Settings Tab */ ?>
		<?php if ( dtb_oo_can_manage_settings() ) : ?>
		<div id="dtb-oo-tab-settings" class="dtb-oo-tab-panel" role="tabpanel" data-tab="settings" hidden>
			<?php dtb_oo_render_settings_form(); ?>
		</div>
		<?php endif; ?>

	</div>

	<?php /* Timeline Drawer */ ?>
	<div id="dtb-oo-drawer" class="dtb-oo-drawer" aria-modal="true" role="dialog" aria-label="<?php esc_attr_e( 'Event Timeline', 'dtb' ); ?>" hidden>
		<div class="dtb-oo-drawer-backdrop"></div>
		<div class="dtb-oo-drawer-panel">
			<div class="dtb-oo-drawer-header">
				<h2 id="dtb-oo-drawer-title" class="dtb-oo-drawer-title"></h2>
				<button type="button" class="dtb-oo-drawer-close button-link" aria-label="<?php esc_attr_e( 'Close timeline', 'dtb' ); ?>">✕</button>
			</div>
			<div id="dtb-oo-drawer-body" class="dtb-oo-drawer-body">
				<p class="dtb-oo-loading"><?php esc_html_e( 'Loading timeline…', 'dtb' ); ?></p>
			</div>
		</div>
	</div>

	<?php /* Action Modal */ ?>
	<div id="dtb-oo-action-modal" class="dtb-oo-drawer" aria-modal="true" role="dialog" aria-label="<?php esc_attr_e( 'Operator Action', 'dtb' ); ?>" hidden>
		<div class="dtb-oo-drawer-backdrop"></div>
		<div class="dtb-oo-drawer-panel dtb-oo-action-panel">
			<div class="dtb-oo-drawer-header">
				<h2 id="dtb-oo-modal-title" class="dtb-oo-drawer-title"></h2>
				<button type="button" class="dtb-oo-modal-close button-link" aria-label="<?php esc_attr_e( 'Close', 'dtb' ); ?>">✕</button>
			</div>
			<div id="dtb-oo-modal-body" class="dtb-oo-drawer-body">
				<form id="dtb-oo-action-form">
					<div id="dtb-oo-modal-fields"></div>
					<div class="dtb-oo-modal-actions">
						<button type="submit" class="button button-primary" id="dtb-oo-modal-submit">
							<?php esc_html_e( 'Confirm', 'dtb' ); ?>
						</button>
						<button type="button" class="button dtb-oo-modal-close">
							<?php esc_html_e( 'Cancel', 'dtb' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<?php /* Notices container */ ?>
	<div id="dtb-oo-notices" aria-live="polite"></div>
	<?php
}

/**
 * Render the settings form (server-side initial state).
 */
function dtb_oo_render_settings_form(): void {
	$s = function_exists( 'dtb_oo_get_settings' ) ? dtb_oo_get_settings() : [];
	?>
	<div class="dtb-oo-section">
		<h2><?php esc_html_e( 'Dashboard Settings', 'dtb' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Configure dashboard behavior. Changes take effect immediately and apply to all operators.', 'dtb' ); ?></p>

		<form id="dtb-oo-settings-form" class="dtb-oo-settings-form">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="dtb-oo-poll-interval"><?php esc_html_e( 'Polling Interval', 'dtb' ); ?></label></th>
					<td>
						<input type="number" id="dtb-oo-poll-interval" name="poll_interval"
							value="<?php echo esc_attr( $s['poll_interval'] ?? 180 ); ?>" min="180" max="300" />
						<span class="description"><?php esc_html_e( 'seconds (10–300)', 'dtb' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dtb-oo-sla-warning"><?php esc_html_e( 'SLA Warning Threshold', 'dtb' ); ?></label></th>
					<td>
						<input type="number" id="dtb-oo-sla-warning" name="sla_warning_hours"
							value="<?php echo esc_attr( $s['sla_warning_hours'] ?? 72 ); ?>" min="1" max="720" />
						<span class="description"><?php esc_html_e( 'hours', 'dtb' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dtb-oo-sla-breach"><?php esc_html_e( 'SLA Breach Threshold', 'dtb' ); ?></label></th>
					<td>
						<input type="number" id="dtb-oo-sla-breach" name="sla_breach_hours"
							value="<?php echo esc_attr( $s['sla_breach_hours'] ?? 120 ); ?>" min="1" max="720" />
						<span class="description"><?php esc_html_e( 'hours', 'dtb' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dtb-oo-page-size"><?php esc_html_e( 'Default Page Size', 'dtb' ); ?></label></th>
					<td>
						<input type="number" id="dtb-oo-page-size" name="page_size"
							value="<?php echo esc_attr( $s['page_size'] ?? 25 ); ?>" min="10" max="100" />
						<span class="description"><?php esc_html_e( 'rows per page (10–100)', 'dtb' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dtb-oo-audit-retention"><?php esc_html_e( 'Audit Retention', 'dtb' ); ?></label></th>
					<td>
						<input type="number" id="dtb-oo-audit-retention" name="audit_retention_days"
							value="<?php echo esc_attr( $s['audit_retention_days'] ?? 90 ); ?>" min="7" max="365" />
						<span class="description"><?php esc_html_e( 'days (7–365)', 'dtb' ); ?></span>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'dtb' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

// =============================================================================
// SECTION 5 — INLINE CSS
// =============================================================================

/**
 * Return the inline CSS for the Order Operations dashboard.
 *
 * @return string
 */
function dtb_oo_inline_css(): string {
	return '
/* ---- Layout ---- */
.dtb-oo-wrap { max-width: 1600px; }
.dtb-oo-wrap--embedded { max-width: none; }
.dtb-oo-topbar { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin: 0 0 10px; }
.dtb-oo-topbar-actions { display: flex; align-items: center; gap: 10px; }
.dtb-oo-embedded-title { margin: 0; font-size: 17px; font-weight: 700; color: #1e293b; }
.dtb-oo-embedded-subtitle { margin: 4px 0 0; font-size: 12px; color: #64748b; }
.dtb-oo-tabs { margin: 16px 0 0; border-bottom: 1px solid #ccc; }
.dtb-oo-tab { text-decoration: none; }
.dtb-oo-tab-panel { padding: 16px 0; }
.dtb-oo-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 4px; padding: 20px; margin: 0 0 20px; }
.dtb-oo-section h2 { margin-top: 0; font-size: 15px; border-bottom: 1px solid #f0f0f0; padding-bottom: 10px; }
.dtb-oo-wrap--embedded .dtb-oo-tabs { margin-top: 14px; border-bottom-color: #e2e8f0; }
.dtb-oo-wrap--embedded .dtb-oo-tab { color: #475569; border-color: transparent; border-top-left-radius: 8px; border-top-right-radius: 8px; }
.dtb-oo-wrap--embedded .dtb-oo-tab.nav-tab-active { color: #1d4ed8; border-color: #c7d2fe #c7d2fe #fff; background: #fff; }
.dtb-oo-wrap--embedded .dtb-oo-kpi-grid { gap: 16px; margin-bottom: 18px; }
.dtb-oo-wrap--embedded .dtb-oo-kpi { position: relative; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.05); overflow: hidden; transition: box-shadow .2s ease, transform .2s ease; }
.dtb-oo-wrap--embedded .dtb-oo-kpi:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); transform: translateY(-2px); }
.dtb-oo-wrap--embedded .dtb-oo-kpi::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--green::before { background: linear-gradient(90deg,#10b981,#34d399); }
.dtb-oo-wrap--embedded .dtb-oo-kpi--blue::before { background: linear-gradient(90deg,#0ea5e9,#38bdf8); }
.dtb-oo-wrap--embedded .dtb-oo-kpi--yellow::before { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
.dtb-oo-wrap--embedded .dtb-oo-kpi--red::before { background: linear-gradient(90deg,#ef4444,#f87171); }
.dtb-oo-wrap--embedded .dtb-oo-kpi--gray::before { background: linear-gradient(90deg,#94a3b8,#cbd5e1); }
.dtb-oo-wrap--embedded .dtb-oo-kpi__top { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.dtb-oo-wrap--embedded .dtb-oo-kpi__state { font-size: 10px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; padding: 3px 9px; border-radius: 20px; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--green .dtb-oo-kpi__state { background:#ecfdf5; color:#059669; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--blue .dtb-oo-kpi__state { background:#f0f9ff; color:#0284c7; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--yellow .dtb-oo-kpi__state { background:#fffbeb; color:#d97706; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--red .dtb-oo-kpi__state { background:#fef2f2; color:#dc2626; }
.dtb-oo-wrap--embedded .dtb-oo-kpi--gray .dtb-oo-kpi__state { background:#f1f5f9; color:#475569; }
.dtb-oo-wrap--embedded .dtb-oo-kpi__value { font-size: 32px; font-weight: 900; line-height: 1; letter-spacing: -.03em; color: #1e293b; }
.dtb-oo-wrap--embedded .dtb-oo-kpi__label { margin-top: 8px; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; }
.dtb-oo-wrap--embedded .dtb-oo-filter-bar { border-color: #e2e8f0; border-radius: 8px; background: #f8fafc; }
.dtb-oo-wrap--embedded .dtb-oo-table th { background: #f8fafc; border-bottom-color: #e2e8f0; }
.dtb-oo-wrap--top-overview { margin-bottom: 18px; }

/* ---- KPI Grid ---- */
.dtb-oo-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin: 16px 0 24px; }
.dtb-oo-kpi { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.dtb-oo-kpi__label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.dtb-oo-kpi__value { font-size: 26px; font-weight: 700; color: #1d2327; line-height: 1.1; }
.dtb-oo-kpi--warn .dtb-oo-kpi__value,
.dtb-oo-kpi--red  .dtb-oo-kpi__value  { color: #d63638; }
.dtb-oo-kpi--green .dtb-oo-kpi__value { color: #00a32a; }
.dtb-oo-kpi--blue  .dtb-oo-kpi__value { color: #0073aa; }
.dtb-oo-kpi--yellow .dtb-oo-kpi__value { color: #996800; }

/* ---- Filter Bar ---- */
.dtb-oo-filter-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 12px; background: #f9f9f9; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 4px; }
.dtb-oo-filter-bar select,
.dtb-oo-filter-bar input[type=text],
.dtb-oo-filter-bar input[type=email],
.dtb-oo-filter-bar input[type=number],
.dtb-oo-filter-bar input[type=date] { font-size: 12px; height: 28px; padding: 2px 6px; }

/* ---- Bulk Bar ---- */
.dtb-oo-bulk-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; background: #fffbec; border: 1px solid #f0c84b; border-radius: 4px; padding: 8px 12px; margin-bottom: 10px; }
.dtb-oo-bulk-count { font-weight: 600; font-size: 13px; }

/* ---- Tables ---- */
.dtb-oo-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
.dtb-oo-table th { background: #f9f9f9; border-bottom: 2px solid #e0e0e0; padding: 8px 10px; text-align: left; font-weight: 600; white-space: nowrap; }
.dtb-oo-table td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.dtb-oo-table tr:hover td { background: #f9f9fc; }
.dtb-oo-table .dtb-oo-actions { white-space: nowrap; }
.dtb-oo-table .dtb-oo-actions a,
.dtb-oo-table .dtb-oo-actions button { font-size: 11px; margin-right: 4px; cursor: pointer; }

/* ---- Badges ---- */
.dtb-oo-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.dtb-oo-badge--green  { background: #d7f5e0; color: #00782a; }
.dtb-oo-badge--blue   { background: #d9edff; color: #004e8c; }
.dtb-oo-badge--yellow { background: #fff5cc; color: #7a5000; }
.dtb-oo-badge--red    { background: #ffe8e8; color: #a30000; }
.dtb-oo-badge--gray   { background: #f0f0f0; color: #555; }

/* ---- Pagination ---- */
.dtb-oo-pagination { margin: 12px 0; font-size: 13px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.dtb-oo-pagination button { padding: 3px 8px; }
.dtb-oo-pagination .dtb-oo-page-info { color: #555; }

/* ---- Poll Indicator ---- */
.dtb-oo-poll-indicator { font-size: 11px; color: #999; margin-left: 10px; }
.dtb-oo-poll-indicator.paused { color: #d63638; }

/* ---- Loading / Empty ---- */
.dtb-oo-loading { opacity: .6; font-style: italic; padding: 10px 0; }
.dtb-oo-empty { color: #777; padding: 20px; text-align: center; border: 1px dashed #ddd; border-radius: 4px; }
.dtb-oo-error { color: #d63638; background: #ffe8e8; border: 1px solid #ffc0c0; border-radius: 4px; padding: 8px 12px; font-size: 13px; }

/* ---- Drawer / Modal ---- */
.dtb-oo-drawer { position: fixed; inset: 0; z-index: 160000; }
.dtb-oo-drawer[hidden] { display: none; }
.dtb-oo-drawer-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.4); }
.dtb-oo-drawer-panel { position: absolute; top: 0; right: 0; width: 540px; max-width: 95vw; height: 100%; overflow-y: auto; background: #fff; box-shadow: -4px 0 20px rgba(0,0,0,.15); display: flex; flex-direction: column; }
.dtb-oo-action-panel { top: 50%; right: 50%; transform: translate(50%, -50%); height: auto; max-height: 80vh; border-radius: 6px; width: 480px; }
.dtb-oo-drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #e0e0e0; background: #f9f9f9; }
.dtb-oo-drawer-title { margin: 0; font-size: 15px; font-weight: 600; }
.dtb-oo-drawer-close { font-size: 16px; line-height: 1; padding: 4px 8px; }
.dtb-oo-drawer-body { padding: 16px 18px; flex: 1; overflow-y: auto; }
.dtb-oo-modal-actions { margin-top: 16px; display: flex; gap: 8px; }

/* ---- Timeline ---- */
.dtb-oo-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
.dtb-oo-timeline::before { content: ""; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #e0e0e0; }
.dtb-oo-tl-item { display: flex; gap: 10px; margin-bottom: 14px; position: relative; }
.dtb-oo-tl-dot { flex-shrink: 0; width: 20px; height: 20px; border-radius: 50%; border: 2px solid #0073aa; background: #fff; z-index: 1; margin-top: 1px; }
.dtb-oo-tl-dot--customer { border-color: #00a32a; }
.dtb-oo-tl-dot--operator { border-color: #0073aa; }
.dtb-oo-tl-dot--internal { border-color: #ccc; }
.dtb-oo-tl-content { flex: 1; font-size: 13px; }
.dtb-oo-tl-event { font-weight: 600; }
.dtb-oo-tl-meta { color: #888; font-size: 11px; margin-top: 2px; }

/* ---- Notices ---- */
#dtb-oo-notices { position: fixed; top: 60px; right: 20px; z-index: 170000; display: flex; flex-direction: column; gap: 8px; max-width: 360px; }
.dtb-oo-notice { padding: 10px 14px; border-radius: 4px; font-size: 13px; box-shadow: 0 2px 6px rgba(0,0,0,.15); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.dtb-oo-notice--success { background: #d7f5e0; color: #00782a; border: 1px solid #b0dfc0; }
.dtb-oo-notice--error   { background: #ffe8e8; color: #a30000; border: 1px solid #ffc0c0; }
.dtb-oo-notice--info    { background: #d9edff; color: #004e8c; border: 1px solid #b0d0f0; }
.dtb-oo-notice-dismiss  { cursor: pointer; font-size: 14px; line-height: 1; background: none; border: none; color: inherit; }

/* ---- Settings Form ---- */
.dtb-oo-settings-form .form-table th { width: 220px; }

/* ---- Checkbox column ---- */
.dtb-oo-table .check-column input { margin: 0; }
';
}

// =============================================================================
// SECTION 6 — INLINE JAVASCRIPT
// =============================================================================

/**
 * Return the inline JS for the Order Operations dashboard.
 *
 * @return string
 */
function dtb_oo_inline_js(): string {
	return <<<'JS'
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------
    var cfg = window.dtbOpsOrd || {};
    var ajaxUrl      = cfg.ajaxUrl    || window.ajaxurl || '';
    var nonce        = cfg.nonce      || '';
    var pollInterval = Math.max(cfg.pollInterval || 180000, 180000);
    var strings      = cfg.strings    || {};

    var state = {
        tab:        'overview',
        pollTimer:  null,
        paused:     false,
        poPage:     1,
        roPage:     1,
        qPage:      1,
        auditPage:  1,
        poFilters:  {},
        roFilters:  {},
        qFilters:   {},
        auditFilters: {},
        poSelected: [],
        roSelected: [],
    };

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function esc(str) {
        return $('<span>').text(str || '').html();
    }

    function badge(text, cls) {
        return '<span class="dtb-oo-badge dtb-oo-badge--' + esc(cls || 'gray') + '">' + esc(text) + '</span>';
    }

    function slaClass(slaState) {
        var map = { healthy: 'green', warning: 'yellow', breached: 'red' };
        return map[slaState] || 'gray';
    }

    function statusBadgeClass(status) {
        var completed = ['completed', 'closed', 'delivered', 'done'];
        var active    = ['processing', 'shipped', 'in_progress', 'in-progress', 'parts_allocated', 'ready_to_ship'];
        var waiting   = ['pending', 'on-hold', 'awaiting_customer', 'reviewed', 'quoted', 'quote_accepted', 'submitted', 'approved'];
        var failed    = ['failed', 'cancelled', 'canceled', 'quote_declined', 'refunded'];

        if (completed.indexOf(status) !== -1) return 'green';
        if (active.indexOf(status) !== -1)    return 'blue';
        if (waiting.indexOf(status) !== -1)   return 'yellow';
        if (failed.indexOf(status) !== -1)    return 'red';
        return 'gray';
    }

    function notice(message, type) {
        type = type || 'info';
        var $n = $('<div class="dtb-oo-notice dtb-oo-notice--' + type + '">' +
            '<span>' + esc(message) + '</span>' +
            '<button class="dtb-oo-notice-dismiss" aria-label="Dismiss">✕</button>' +
            '</div>');
        $n.find('.dtb-oo-notice-dismiss').on('click', function () { $n.remove(); });
        $('#dtb-oo-notices').append($n);
        setTimeout(function () { $n.fadeOut(300, function () { $n.remove(); }); }, 5000);
    }

    function ajaxPost(action, data, successCb, errorCb) {
        data = $.extend({ action: action, nonce: nonce }, data);
        $.ajax({
            url:    ajaxUrl,
            type:   'POST',
            data:   data,
            success: function (res) {
                if (res && res.success) {
                    if (successCb) successCb(res.data);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (strings.error_generic || 'Error.');
                    if (errorCb) errorCb(msg);
                    else notice(msg, 'error');
                }
            },
            error: function () {
                var msg = strings.error_generic || 'Request failed.';
                if (errorCb) errorCb(msg);
                else notice(msg, 'error');
            }
        });
    }

    function pagination(current, total, pages, onPage) {
        if (pages <= 1) return '';
        var html = '<div class="dtb-oo-pagination">';
        html += '<button class="button" ' + (current <= 1 ? 'disabled' : '') + ' data-page="' + (current - 1) + '">‹ Prev</button>';
        html += '<span class="dtb-oo-page-info">Page ' + current + ' of ' + pages + ' (' + total + ' total)</span>';
        html += '<button class="button" ' + (current >= pages ? 'disabled' : '') + ' data-page="' + (current + 1) + '">Next ›</button>';
        html += '</div>';
        var $html = $(html);
        $html.find('button[data-page]').on('click', function () {
            onPage(parseInt($(this).data('page'), 10));
        });
        return $html;
    }

    function serializeFormToObj($form) {
        var obj = {};
        $form.serializeArray().forEach(function (f) {
            if (f.value !== '') obj[f.name] = f.value;
        });
        return obj;
    }

    // -------------------------------------------------------------------------
    // Tab Management
    // -------------------------------------------------------------------------

    function switchTab(tab) {
        state.tab = tab;

        $('.dtb-oo-tab').removeClass('nav-tab-active').attr('aria-selected', 'false');
        $('.dtb-oo-tab[data-tab="' + tab + '"]').addClass('nav-tab-active').attr('aria-selected', 'true');

        $('.dtb-oo-tab-panel').attr('hidden', true);
        $('#dtb-oo-tab-' + tab).removeAttr('hidden');

        loadTab(tab);
    }

    function loadTab(tab) {
        switch (tab) {
            case 'overview':       loadOverview(); break;
            case 'product_orders': loadProductOrders(); break;
            case 'repair_orders':  loadRepairOrders(); break;
            case 'queue':          loadQueue(); break;
            case 'audit_log':      loadAuditLog(); break;
            case 'settings':       /* static form, no load needed */ break;
        }
    }

    // -------------------------------------------------------------------------
    // Overview Tab
    // -------------------------------------------------------------------------

    function loadOverview() {
        var $grids = $('#dtb-oo-overview-kpis, #dtb-oo-overview-kpis-top');
        if (!$grids.length) {
            return;
        }
        if (!ajaxUrl || !nonce) {
            $grids.removeClass('dtb-oo-loading');
            $grids.html('<p class="dtb-oo-error">Order Operations bootstrap is missing (ajax/nonce).</p>');
            return;
        }
        $grids.addClass('dtb-oo-loading');
        ajaxPost('dtb_ops_order_overview', {}, function (data) {
            $grids.removeClass('dtb-oo-loading');
            renderKpis(data.kpis || {});
        }, function (msg) {
            $grids.removeClass('dtb-oo-loading');
            $grids.html('<p class="dtb-oo-error">' + esc(msg || 'Could not load KPIs.') + '</p>');
        });
    }

    function renderKpis(kpis) {
        var $grids = $('#dtb-oo-overview-kpis, #dtb-oo-overview-kpis-top');
        if (!$grids.length) {
            return;
        }

        var excludedLabels = ['sla warnings', 'sla breached', 'failed local actions'];
        var excludedKeys = ['sla_warnings', 'sla_breached', 'failed_local_actions'];

        $grids.empty();
        $.each(kpis, function (key, kpi) {
            var keyNorm = String(key || '').toLowerCase();
            var labelNorm = String((kpi && kpi.label) || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
            if (excludedKeys.indexOf(keyNorm) !== -1 || excludedLabels.indexOf(labelNorm) !== -1) {
                return;
            }

            var cls = String(kpi.badge || (kpi.warn ? 'red' : 'green')).toLowerCase();
            if (cls === 'warn' || cls === 'warning') cls = 'yellow';
            if (cls === 'danger' || cls === 'critical') cls = 'red';
            if (cls === 'ok' || cls === 'success') cls = 'green';
            if (['green', 'blue', 'yellow', 'red', 'gray'].indexOf(cls) === -1) cls = 'gray';

            var stateLabel = 'Live';
            if (cls === 'yellow') stateLabel = 'Watch';
            if (cls === 'red') stateLabel = 'Alert';

            var cardHtml =
                '<div class="dtb-oo-kpi dtb-oo-kpi--' + esc(cls) + '" id="dtb-oo-kpi-' + esc(key) + '">' +
                '<div class="dtb-oo-kpi__top"><span class="dtb-oo-kpi__state">' + esc(stateLabel) + '</span></div>' +
                '<div class="dtb-oo-kpi__label">' + esc(kpi.label) + '</div>' +
                '<div class="dtb-oo-kpi__value">' + esc(String(kpi.value)) + '</div>' +
                '</div>';

            $grids.append(cardHtml);
        });
    }

    // -------------------------------------------------------------------------
    // Product Orders Tab
    // -------------------------------------------------------------------------

    function loadProductOrders(paged) {
        var $tbl = $('#dtb-oo-product-orders-table');
        $tbl.html('<p class="dtb-oo-loading">' + (strings.loading || 'Loading…') + '</p>');
        state.poPage = paged || state.poPage;
        var params = $.extend({ paged: state.poPage }, state.poFilters);
        ajaxPost('dtb_ops_product_orders', params, function (data) {
            renderProductOrdersTable(data);
        }, function (msg) {
            $tbl.html('<p class="dtb-oo-error">' + esc(msg) + '</p>');
        });
    }

    function renderProductOrdersTable(data) {
        var rows  = data.rows  || [];
        var total = data.total || 0;
        var pages = data.pages || 1;
        var $tbl  = $('#dtb-oo-product-orders-table');

        if (!rows.length) {
            $tbl.html('<p class="dtb-oo-empty">' + (strings.no_results || 'No orders found.') + '</p>');
            return;
        }

        var html = '<table class="dtb-oo-table wp-list-table widefat"><thead><tr>' +
            '<th class="check-column"><input type="checkbox" id="dtb-oo-po-select-all" aria-label="Select all" /></th>' +
            '<th>Order ID</th><th>Date</th><th>Customer</th><th>Woo Status</th>' +
            '<th>Fulfillment</th><th>Tracking</th><th>Items</th><th>Total</th>' +
            '<th>Age</th><th>Last Event</th><th>Actions</th></tr></thead><tbody>';

        rows.forEach(function (r) {
            var wooB    = badge(r.woo_status, statusBadgeClass(r.woo_status));
            var fulfB   = badge(r.fulfillment_substate, statusBadgeClass(r.fulfillment_substate));
            var trkB    = r.tracking_number
                ? badge('Tracking: ' + r.tracking_number, 'blue')
                : badge('No tracking', 'gray');

            html += '<tr data-order-id="' + esc(r.order_id) + '">' +
                '<td class="check-column"><input type="checkbox" class="dtb-oo-po-check" value="' + esc(r.order_id) + '" /></td>' +
                '<td><strong>#' + esc(r.order_id) + '</strong></td>' +
                '<td><small>' + esc(r.date_created ? r.date_created.substring(0, 10) : '—') + '</small></td>' +
                '<td>' + esc(r.customer_name) + '<br><small>' + esc(r.customer_email) + '</small></td>' +
                '<td>' + wooB + '</td>' +
                '<td>' + fulfB + '</td>' +
                '<td>' + trkB + '</td>' +
                '<td>' + esc(r.item_count) + '</td>' +
                '<td>' + esc(r.total) + '</td>' +
                '<td>' + esc(r.age_label) + '</td>' +
                '<td><small>' + esc(r.last_event || '—') + '</small></td>' +
                '<td class="dtb-oo-actions">' + renderPoRowActions(r) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        var $container = $('<div>').html(html);

        // Pagination.
        $container.append(pagination(state.poPage, total, pages, function (p) {
            state.poPage = p;
            loadProductOrders(p);
        }));

        $tbl.html($container);

        bindPoTableEvents();
    }

    function renderPoRowActions(r) {
        return '<button class="button button-small dtb-oo-row-action" data-action="view_timeline" data-entity-type="product_order" data-entity-id="' + r.order_id + '">Timeline</button>' +
            '<button class="button button-small dtb-oo-row-action" data-action="refresh_tracking_projection" data-entity-id="' + r.order_id + '">Refresh</button>' +
            '<button class="button button-small dtb-oo-row-action" data-action="mark_reviewed" data-entity-id="' + r.order_id + '">Reviewed</button>' +
            '<button class="button button-small dtb-oo-row-action dtb-oo-note-action" data-entity-id="' + r.order_id + '">Note</button>' +
            (r.wc_edit_url ? '<a class="button button-small" href="' + r.wc_edit_url + '" target="_blank">WC ↗</a>' : '');
    }

    function bindPoTableEvents() {
        // Select-all checkbox.
        $('#dtb-oo-po-select-all').on('change', function () {
            var checked = this.checked;
            $('.dtb-oo-po-check').prop('checked', checked);
            updatePoSelection();
        });
        $(document).on('change', '.dtb-oo-po-check', function () {
            updatePoSelection();
        });

        // Row actions.
        $(document).on('click', '.dtb-oo-row-action[data-entity-type="product_order"], [data-entity-id]:not([data-entity-type])', function () {
            handlePoRowAction($(this));
        });

        // Note action.
        $(document).on('click', '.dtb-oo-note-action', function () {
            var entityId = $(this).data('entity-id');
            openActionModal('Add Internal Note', [
                { name: 'note', label: 'Note', type: 'textarea' }
            ], function (vals) {
                execOrderAction(entityId, 'add_internal_note', vals);
            });
        });
    }

    function handlePoRowAction($btn) {
        var action   = $btn.data('action');
        var entityId = $btn.data('entity-id');
        if (!action || !entityId) return;

        if (action === 'view_timeline') {
            openTimelineDrawer('product_order', entityId);
            return;
        }

        var confirmMsg = 'Confirm: ' + action.replace(/_/g, ' ') + ' for Order #' + entityId + '?';
        if (!confirm(confirmMsg)) return; // eslint-disable-line no-alert

        execOrderAction(entityId, action, {});
    }

    function execOrderAction(entityId, action, params) {
        ajaxPost('dtb_ops_order_action', $.extend({ entity_id: entityId, action_type: action }, params), function (data) {
            notice(data.message || 'Action completed.', 'success');
            loadProductOrders();
        });
    }

    function updatePoSelection() {
        state.poSelected = $('.dtb-oo-po-check:checked').map(function () { return parseInt(this.value, 10); }).get();
        var count = state.poSelected.length;
        if (count > 0) {
            $('#dtb-oo-po-bulk-bar').removeAttr('hidden');
            $('#dtb-oo-po-bulk-bar .dtb-oo-bulk-count').text(count + ' selected');
        } else {
            $('#dtb-oo-po-bulk-bar').attr('hidden', true);
        }
    }

    // -------------------------------------------------------------------------
    // Repair Orders Tab
    // -------------------------------------------------------------------------

    function loadRepairOrders(paged) {
        var $tbl = $('#dtb-oo-repair-orders-table');
        $tbl.html('<p class="dtb-oo-loading">' + (strings.loading || 'Loading…') + '</p>');
        state.roPage = paged || state.roPage;
        var params = $.extend({ paged: state.roPage }, state.roFilters);
        ajaxPost('dtb_ops_repair_orders', params, function (data) {
            renderRepairOrdersTable(data);
        }, function (msg) {
            $tbl.html('<p class="dtb-oo-error">' + esc(msg) + '</p>');
        });
    }

    function renderRepairOrdersTable(data) {
        var rows  = data.rows  || [];
        var total = data.total || 0;
        var pages = data.pages || 1;
        var $tbl  = $('#dtb-oo-repair-orders-table');

        if (!rows.length) {
            $tbl.html('<p class="dtb-oo-empty">' + (strings.no_results || 'No repairs found.') + '</p>');
            return;
        }

        var html = '<table class="dtb-oo-table wp-list-table widefat"><thead><tr>' +
            '<th class="check-column"><input type="checkbox" id="dtb-oo-ro-select-all" aria-label="Select all" /></th>' +
            '<th>Repair ID</th><th>Submitted</th><th>Customer</th><th>Brand</th><th>Model</th>' +
            '<th>Tier</th><th>Status</th><th>Tech</th><th>SLA</th><th>Age</th><th>Last Event</th><th>Actions</th>' +
            '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var statusB = badge(r.status_label || r.repair_status, statusBadgeClass(r.repair_status));
            var slaB    = badge(r.sla_state, slaClass(r.sla_state));

            html += '<tr data-repair-id="' + esc(r.repair_id) + '">' +
                '<td class="check-column"><input type="checkbox" class="dtb-oo-ro-check" value="' + esc(r.repair_id) + '" /></td>' +
                '<td><strong>#' + esc(r.repair_id) + '</strong></td>' +
                '<td><small>' + esc(r.submitted_at ? r.submitted_at.substring(0, 10) : '—') + '</small></td>' +
                '<td>' + esc(r.customer_name) + '<br><small>' + esc(r.customer_email) + '</small></td>' +
                '<td>' + esc(r.brand) + '</td>' +
                '<td>' + esc(r.model) + '<br><small>' + esc(r.serial) + '</small></td>' +
                '<td>' + esc(r.service_tier) + '</td>' +
                '<td>' + statusB + '</td>' +
                '<td>' + esc(r.assigned_technician || '—') + '</td>' +
                '<td>' + slaB + '</td>' +
                '<td>' + esc(r.age_label) + '</td>' +
                '<td><small>' + esc(r.last_event || '—') + '</small></td>' +
                '<td class="dtb-oo-actions">' + renderRoRowActions(r) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        var $container = $('<div>').html(html);
        $container.append(pagination(state.roPage, total, pages, function (p) {
            state.roPage = p;
            loadRepairOrders(p);
        }));

        $tbl.html($container);
        bindRoTableEvents();
    }

    function renderRoRowActions(r) {
        return '<button class="button button-small dtb-oo-ro-row-action" data-action="view_timeline" data-repair-id="' + r.repair_id + '">Timeline</button>' +
            '<button class="button button-small dtb-oo-ro-row-action" data-action="assign_tech" data-repair-id="' + r.repair_id + '">Assign Tech</button>' +
            '<button class="button button-small dtb-oo-ro-row-action" data-action="transition_status" data-repair-id="' + r.repair_id + '">Transition</button>' +
            '<button class="button button-small dtb-oo-ro-row-action" data-action="add_note" data-repair-id="' + r.repair_id + '">Note</button>' +
            (r.edit_url ? '<a class="button button-small" href="' + r.edit_url + '" target="_blank">Detail ↗</a>' : '');
    }

    function bindRoTableEvents() {
        $('#dtb-oo-ro-select-all').on('change', function () {
            $('.dtb-oo-ro-check').prop('checked', this.checked);
            updateRoSelection();
        });
        $(document).on('change', '.dtb-oo-ro-check', updateRoSelection);

        $(document).on('click', '.dtb-oo-ro-row-action', function () {
            handleRoRowAction($(this));
        });
    }

    function handleRoRowAction($btn) {
        var action   = $btn.data('action');
        var repairId = $btn.data('repair-id');
        if (!action || !repairId) return;

        if (action === 'view_timeline') {
            openTimelineDrawer('repair_order', repairId);
            return;
        }
        if (action === 'assign_tech') {
            openActionModal('Assign Technician — Repair #' + repairId, [
                { name: 'tech_id', label: 'Technician User ID', type: 'number', min: 1 }
            ], function (vals) {
                execRepairAction(repairId, 'assign_technician', vals);
            });
            return;
        }
        if (action === 'transition_status') {
            var allowed = (window.dtbOpsOrd.repairAllowedTransitions || {});
            openActionModal('Transition Status — Repair #' + repairId, [
                { name: 'to_status', label: 'Target Status', type: 'text', placeholder: 'e.g. in_progress' },
                { name: 'note', label: 'Note (optional)', type: 'textarea' }
            ], function (vals) {
                execRepairAction(repairId, 'transition_status', vals);
            });
            return;
        }
        if (action === 'add_note') {
            openActionModal('Add Internal Note — Repair #' + repairId, [
                { name: 'note', label: 'Note', type: 'textarea' }
            ], function (vals) {
                execRepairAction(repairId, 'add_internal_note', vals);
            });
            return;
        }
    }

    function execRepairAction(repairId, action, params) {
        ajaxPost('dtb_ops_repair_action', $.extend({ entity_id: repairId, action_type: action }, params), function (data) {
            notice(data.message || 'Action completed.', 'success');
            loadRepairOrders();
        });
    }

    function updateRoSelection() {
        state.roSelected = $('.dtb-oo-ro-check:checked').map(function () { return parseInt(this.value, 10); }).get();
        var count = state.roSelected.length;
        if (count > 0) {
            $('#dtb-oo-ro-bulk-bar').removeAttr('hidden');
            $('#dtb-oo-ro-bulk-bar .dtb-oo-bulk-count').text(count + ' selected');
        } else {
            $('#dtb-oo-ro-bulk-bar').attr('hidden', true);
        }
    }

    // -------------------------------------------------------------------------
    // Queue / Actions Tab
    // -------------------------------------------------------------------------

    function loadQueue(paged) {
        var $tbl = $('#dtb-oo-queue-table');
        $tbl.html('<p class="dtb-oo-loading">' + (strings.loading || 'Loading…') + '</p>');
        state.qPage = paged || state.qPage;
        var params = $.extend({ paged: state.qPage }, state.qFilters);
        ajaxPost('dtb_ops_local_queue', params, function (data) {
            renderQueueTable(data);
        }, function (msg) {
            $tbl.html('<p class="dtb-oo-error">' + esc(msg) + '</p>');
        });
    }

    function renderQueueTable(data) {
        var rows  = data.rows  || [];
        var total = data.total || 0;
        var pages = data.pages || 1;
        var $tbl  = $('#dtb-oo-queue-table');

        if (!rows.length) {
            $tbl.html('<p class="dtb-oo-empty">No local queue jobs found.</p>');
            return;
        }

        var html = '<table class="dtb-oo-table wp-list-table widefat"><thead><tr>' +
            '<th>Job ID</th><th>Entity Type</th><th>Entity ID</th><th>Job Type</th>' +
            '<th>Status</th><th>Attempts</th><th>Next Run</th><th>Actions</th></tr></thead><tbody>';

        rows.forEach(function (r) {
            var statusB = badge(r.status, statusBadgeClass(r.status));
            html += '<tr>' +
                '<td>' + esc(r.job_id || '—') + '</td>' +
                '<td>' + esc(r.entity_type) + '</td>' +
                '<td>' + esc(r.entity_id || '—') + '</td>' +
                '<td><small>' + esc(r.job_type) + '</small></td>' +
                '<td>' + statusB + '</td>' +
                '<td>' + esc(r.attempts) + '</td>' +
                '<td><small>' + esc(r.next_run || '—') + '</small></td>' +
                '<td class="dtb-oo-actions">' + renderQueueRowActions(r) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        var $container = $('<div>').html(html);
        $container.append(pagination(state.qPage, total, pages, function (p) {
            state.qPage = p;
            loadQueue(p);
        }));

        $tbl.html($container);
        bindQueueEvents();
    }

    function renderQueueRowActions(r) {
        if (!r.job_id) return '<em>N/A</em>';
        return '<button class="button button-small dtb-oo-queue-action" data-action="retry_local_job" data-job-id="' + r.job_id + '">Retry</button> ' +
            '<button class="button button-small dtb-oo-queue-action" data-action="cancel_local_job" data-job-id="' + r.job_id + '">Cancel</button> ' +
            '<button class="button button-small dtb-oo-queue-action" data-action="mark_resolved" data-job-id="' + r.job_id + '">Resolved</button>';
    }

    function bindQueueEvents() {
        $(document).on('click', '.dtb-oo-queue-action', function () {
            var $btn   = $(this);
            var action = $btn.data('action');
            var jobId  = $btn.data('job-id');
            if (!action || !jobId) return;
            if (!confirm('Confirm: ' + action.replace(/_/g, ' ') + ' job #' + jobId + '?')) return; // eslint-disable-line no-alert
            ajaxPost('dtb_ops_queue_action', { action_type: action, job_id: jobId }, function (data) {
                notice(data.message || 'Queue action completed.', 'success');
                loadQueue();
            });
        });
    }

    // -------------------------------------------------------------------------
    // Audit Log Tab
    // -------------------------------------------------------------------------

    function loadAuditLog(paged) {
        var $tbl = $('#dtb-oo-audit-log-table');
        $tbl.html('<p class="dtb-oo-loading">' + (strings.loading || 'Loading…') + '</p>');
        state.auditPage = paged || state.auditPage;
        var params = $.extend({ paged: state.auditPage }, state.auditFilters);
        ajaxPost('dtb_ops_oo_audit_log', params, function (data) {
            renderAuditLogTable(data);
        }, function (msg) {
            $tbl.html('<p class="dtb-oo-error">' + esc(msg) + '</p>');
        });
    }

    function renderAuditLogTable(data) {
        var rows  = data.rows  || [];
        var total = data.total || 0;
        var pages = data.pages || 1;
        var $tbl  = $('#dtb-oo-audit-log-table');

        if (!rows.length) {
            $tbl.html('<p class="dtb-oo-empty">No audit events found.</p>');
            return;
        }

        var html = '<table class="dtb-oo-table wp-list-table widefat"><thead><tr>' +
            '<th>Time</th><th>Entity Type</th><th>Entity ID</th><th>Event</th>' +
            '<th>Actor</th><th>Source</th><th>Visibility</th><th>Summary</th></tr></thead><tbody>';

        rows.forEach(function (r) {
            var visB = badge(r.visibility, r.visibility === 'customer' ? 'green' : (r.visibility === 'internal' ? 'gray' : 'blue'));
            html += '<tr>' +
                '<td><small>' + esc(r.time ? r.time.substring(0, 16).replace('T', ' ') : '—') + '</small></td>' +
                '<td>' + esc(r.entity_type) + '</td>' +
                '<td>' + (r.entity_id ? esc(r.entity_id) : '—') + '</td>' +
                '<td><code>' + esc(r.event_type) + '</code></td>' +
                '<td>' + esc(r.actor) + '</td>' +
                '<td>' + esc(r.source) + '</td>' +
                '<td>' + visB + '</td>' +
                '<td><small>' + esc(r.summary) + '</small></td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        var $container = $('<div>').html(html);
        $container.append(pagination(state.auditPage, total, pages, function (p) {
            state.auditPage = p;
            loadAuditLog(p);
        }));

        $tbl.html($container);
    }

    // -------------------------------------------------------------------------
    // Timeline Drawer
    // -------------------------------------------------------------------------

    function openTimelineDrawer(entityType, entityId) {
        var $drawer = $('#dtb-oo-drawer');
        var $title  = $('#dtb-oo-drawer-title');
        var $body   = $('#dtb-oo-drawer-body');

        var label = entityType === 'product_order' ? 'Order #' + entityId : 'Repair #' + entityId;
        $title.text('Timeline — ' + label);
        $body.html('<p class="dtb-oo-loading">Loading timeline…</p>');
        $drawer.removeAttr('hidden');
        $('body').addClass('dtb-oo-drawer-open');

        var ajaxAction = entityType === 'product_order' ? 'dtb_ops_order_timeline' : 'dtb_ops_repair_timeline';
        var dataKey    = entityType === 'product_order' ? 'order_id' : 'repair_id';
        var postData   = {};
        postData[dataKey] = entityId;

        ajaxPost(ajaxAction, postData, function (data) {
            var events = data.timeline || [];
            if (!events.length) {
                $body.html('<p class="dtb-oo-empty">No events recorded yet.</p>');
                return;
            }
            var html = '<ul class="dtb-oo-timeline">';
            events.forEach(function (ev) {
                var dotClass = 'dtb-oo-tl-dot--' + (ev.visibility || 'internal');
                html += '<li class="dtb-oo-tl-item">' +
                    '<span class="dtb-oo-tl-dot ' + dotClass + '" aria-hidden="true"></span>' +
                    '<div class="dtb-oo-tl-content">' +
                    '<div class="dtb-oo-tl-event">' + esc(ev.event_type) + '</div>' +
                    '<div class="dtb-oo-tl-meta">' +
                    esc(ev.occurred_at ? ev.occurred_at.replace('T', ' ').substring(0, 16) : '') +
                    (ev.actor_id ? ' · actor: ' + esc(ev.actor_id) : '') +
                    ' · <span class="dtb-oo-badge dtb-oo-badge--gray">' + esc(ev.visibility) + '</span>' +
                    '</div>' +
                    '</div></li>';
            });
            html += '</ul>';
            $body.html(html);
        }, function (msg) {
            $body.html('<p class="dtb-oo-error">' + esc(msg) + '</p>');
        });
    }

    function closeDrawer() {
        $('#dtb-oo-drawer').attr('hidden', true);
        $('body').removeClass('dtb-oo-drawer-open');
    }

    // -------------------------------------------------------------------------
    // Action Modal
    // -------------------------------------------------------------------------

    function openActionModal(title, fields, onSubmit) {
        var $modal  = $('#dtb-oo-action-modal');
        var $title  = $('#dtb-oo-modal-title');
        var $fields = $('#dtb-oo-modal-fields');

        $title.text(title);

        var html = '';
        fields.forEach(function (f) {
            html += '<p><label for="dtb-oo-modal-' + esc(f.name) + '">' + esc(f.label) + '</label><br>';
            if (f.type === 'textarea') {
                html += '<textarea id="dtb-oo-modal-' + esc(f.name) + '" name="' + esc(f.name) + '" rows="3" style="width:100%"></textarea>';
            } else {
                html += '<input type="' + esc(f.type || 'text') + '" id="dtb-oo-modal-' + esc(f.name) + '" name="' + esc(f.name) + '"' +
                    (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') +
                    (f.min !== undefined ? ' min="' + esc(f.min) + '"' : '') +
                    ' style="width:100%" />';
            }
            html += '</p>';
        });

        $fields.html(html);
        $modal.removeAttr('hidden');

        // Bind submit.
        $('#dtb-oo-action-form').off('submit.dtbModal').on('submit.dtbModal', function (e) {
            e.preventDefault();
            var vals = serializeFormToObj($(this));
            $modal.attr('hidden', true);
            onSubmit(vals);
        });
    }

    function closeModal() {
        $('#dtb-oo-action-modal').attr('hidden', true);
    }

    // -------------------------------------------------------------------------
    // Bulk Actions
    // -------------------------------------------------------------------------

    $('#dtb-oo-po-bulk-apply').on('click', function () {
        var action  = $('#dtb-oo-po-bulk-action').val();
        var ids     = state.poSelected;
        var note    = $('#dtb-oo-po-bulk-note').val();

        if (!action || !ids.length) { notice('Select an action and at least one order.', 'info'); return; }

        var confirmTpl = strings.confirm_bulk || 'Apply bulk action to %d selected items?';
        if (!confirm(confirmTpl.replace('%d', ids.length))) return; // eslint-disable-line no-alert

        ajaxPost('dtb_ops_bulk_order_action', { action_type: action, entity_ids: ids, note: note }, function (data) {
            notice(data.message || 'Bulk action completed.', 'success');
            loadProductOrders();
        });
    });

    $('#dtb-oo-ro-bulk-apply').on('click', function () {
        var action  = $('#dtb-oo-ro-bulk-action').val();
        var ids     = state.roSelected;
        var note    = $('#dtb-oo-ro-bulk-note').val();
        var techId  = $('#dtb-oo-ro-bulk-tech-id').val();

        if (!action || !ids.length) { notice('Select an action and at least one repair.', 'info'); return; }

        var confirmTpl = strings.confirm_bulk || 'Apply bulk action to %d selected items?';
        if (!confirm(confirmTpl.replace('%d', ids.length))) return; // eslint-disable-line no-alert

        ajaxPost('dtb_ops_bulk_repair_action', { action_type: action, entity_ids: ids, note: note, tech_id: techId }, function (data) {
            notice(data.message || 'Bulk action completed.', 'success');
            loadRepairOrders();
        });
    });

    // -------------------------------------------------------------------------
    // Filter Forms
    // -------------------------------------------------------------------------

    $('#dtb-oo-po-filter-form').on('submit', function (e) {
        e.preventDefault();
        state.poFilters = serializeFormToObj($(this));
        state.poPage = 1;
        loadProductOrders(1);
    });

    $('#dtb-oo-ro-filter-form').on('submit', function (e) {
        e.preventDefault();
        state.roFilters = serializeFormToObj($(this));
        state.roPage = 1;
        loadRepairOrders(1);
    });

    $('#dtb-oo-queue-filter-form').on('submit', function (e) {
        e.preventDefault();
        state.qFilters = serializeFormToObj($(this));
        state.qPage = 1;
        loadQueue(1);
    });

    $('#dtb-oo-audit-filter-form').on('submit', function (e) {
        e.preventDefault();
        state.auditFilters = serializeFormToObj($(this));
        state.auditPage = 1;
        loadAuditLog(1);
    });

    // Reset buttons.
    $(document).on('click', '.dtb-oo-filter-reset', function () {
        var $form = $(this).closest('form');
        $form[0].reset();
        $form.trigger('submit');
    });

    // -------------------------------------------------------------------------
    // Settings Form
    // -------------------------------------------------------------------------

    $('#dtb-oo-settings-form').on('submit', function (e) {
        e.preventDefault();
        var data = serializeFormToObj($(this));
        ajaxPost('dtb_ops_oo_settings_save', data, function (result) {
            notice(result.message || 'Settings saved.', 'success');
        });
    });

    // -------------------------------------------------------------------------
    // Polling (Overview tab only)
    // -------------------------------------------------------------------------

    function startPolling() {
        loadOverview();
        state.pollTimer = setInterval(function () {
            var hasTopOverview = $('#dtb-oo-overview-kpis-top').length > 0;
            if (!state.paused && (state.tab === 'overview' || hasTopOverview)) {
                loadOverview();
            }
        }, pollInterval);
    }

    function stopPolling() {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    // Page Visibility API.
    document.addEventListener('visibilitychange', function () {
        state.paused = document.visibilityState === 'hidden';
        var $indicator = $('#dtb-oo-poll-indicator');
        if (state.paused) {
            $indicator.text('Polling paused').addClass('paused');
        } else {
            $indicator.text('').removeClass('paused');
            if (state.tab === 'overview' || $('#dtb-oo-overview-kpis-top').length) loadOverview();
        }
    });

    // Refresh button.
    $('#dtb-oo-refresh-btn').on('click', function (e) {
        e.preventDefault();
        loadTab(state.tab);
        if ($('#dtb-oo-overview-kpis-top').length) {
            loadOverview();
        }
    });

    // -------------------------------------------------------------------------
    // Tab click handlers
    // -------------------------------------------------------------------------

    $(document).on('click', '.dtb-oo-tab', function (e) {
        e.preventDefault();
        switchTab($(this).data('tab'));
    });

    // Drawer close.
    $(document).on('click', '.dtb-oo-drawer-close, .dtb-oo-drawer-backdrop', function () {
        closeDrawer();
        closeModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { closeDrawer(); closeModal(); }
    });

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    $(document).ready(function () {
        if (typeof dtbOpsOrd === 'undefined') {
            $('#dtb-oo-overview-kpis, #dtb-oo-overview-kpis-top').html('<p class="dtb-oo-error">Order Operations bootstrap config is unavailable.</p>');
            return;
        }
        // Restore tab from hash.
        var hasOverviewTab = $('.dtb-oo-tab[data-tab="overview"]').length > 0;
        var hash = window.location.hash.replace('#', '');
        var validTabs = hasOverviewTab
            ? ['overview', 'product_orders', 'repair_orders', 'queue', 'audit_log', 'settings']
            : ['product_orders', 'repair_orders', 'queue', 'audit_log', 'settings'];
        var defaultTab = hasOverviewTab ? 'overview' : 'product_orders';
        if (hash && validTabs.indexOf(hash) !== -1) {
            switchTab(hash);
        } else {
            switchTab(defaultTab);
        }
        startPolling();
    });

}(jQuery));
JS;
}

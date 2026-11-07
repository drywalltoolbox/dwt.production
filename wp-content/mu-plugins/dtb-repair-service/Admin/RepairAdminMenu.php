<?php
/**
 * Admin — RepairAdminMenu: capability, menu registration, assets, and list page callback.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// `dtb_manage_repairs` capability grants are owned centrally by
// `dtb-platform/Admin/AdminCapabilities.php` (`dtb_admin_all_capabilities()` /
// `dtb_admin_role_capability_map()`) — see that file's header comment. This
// used to independently re-grant the same capability to `administrator` on
// every `init`; removed as a redundant duplicate of that canonical grant.

// =============================================================================
// SECTION 2 — ADMIN MENU
// =============================================================================

// Legacy menu registration removed — the Repairs top-level menu is now owned by
// dtb-platform/Admin/OperationsMenu.php via the DTB admin registry. The callback
// dtb_repair_admin_list_page() below is preserved for reference only; it is no
// longer the active page renderer (dtb_repairs_render_page in RepairsPage.php is).
// add_action( 'admin_menu', 'dtb_repair_admin_menu' );
add_filter( 'get_user_option_screen_layout_dtb_repair_request', 'dtb_repair_force_two_column_layout' );
add_action( 'admin_enqueue_scripts', 'dtb_repair_admin_enqueue_modern_assets' );
add_filter( 'admin_body_class', 'dtb_repair_admin_body_class' );

/**
 * Force 2-column edit layout for repair requests so side cards always render.
 */
function dtb_repair_force_two_column_layout( $value ): int {
	unset( $value );
	return 2;
}

/**
 * Whether the current admin screen is owned by the repair request CPT.
 */
function dtb_repair_admin_is_repair_screen(): bool {
	$screen = get_current_screen();
	return $screen && 'dtb_repair_request' === $screen->post_type;
}

/**
 * Load the shared DTB/Modernize visual system on repair CPT screens.
 *
 * The repair detail workflow is still a WordPress CPT edit screen, so it must
 * opt in to the shared tokens/assets separately from admin.php?page=dtb-*.
 */
function dtb_repair_admin_enqueue_modern_assets(): void {
	if ( ! dtb_repair_admin_is_repair_screen() ) {
		return;
	}

	$platform_dir = WP_CONTENT_DIR . '/mu-plugins/dtb-platform/Admin/assets/';
	$platform_url = content_url( '/mu-plugins/dtb-platform/Admin/assets/' );
	$repair_dir   = __DIR__ . '/assets/';
	$repair_url   = plugin_dir_url( __FILE__ ) . 'assets/';

	wp_enqueue_style(
		'dtb-fonts',
		'https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:400,500,600,700&display=swap',
		[],
		null
	);

	$admin_css = $platform_dir . 'dtb-admin.css';
	wp_enqueue_style(
		'dtb-admin',
		$platform_url . 'dtb-admin.css',
		[ 'dtb-fonts' ],
		file_exists( $admin_css ) ? (string) filemtime( $admin_css ) : '2.0.0'
	);

	$repair_css = $repair_dir . 'dtb-repair-admin-modern.css';
	if ( file_exists( $repair_css ) ) {
		wp_enqueue_style(
			'dtb-repair-admin-modern',
			$repair_url . 'dtb-repair-admin-modern.css',
			[ 'dtb-admin' ],
			(string) filemtime( $repair_css )
		);
	}
}

/**
 * Add a stable body hook for scoped Modernize-style CPT edit overrides.
 *
 * @param string $classes Existing admin body classes.
 * @return string
 */
function dtb_repair_admin_body_class( string $classes ): string {
	if ( dtb_repair_admin_is_repair_screen() ) {
		$classes .= ' dtb-repair-admin-screen';
	}

	return $classes;
}

/**
 * Register the top-level "Repairs" menu in WP-Admin.
 */
function dtb_repair_admin_menu(): void {
	add_menu_page(
		__( 'Repairs', 'drywall-toolbox' ),
		__( 'Repairs', 'drywall-toolbox' ),
		'dtb_manage_repairs',
		'dtb-repairs',
		'dtb_repair_admin_list_page',
		'dashicons-hammer',
		30
	);

	add_submenu_page(
		'dtb-repairs',
		__( 'All Repairs', 'drywall-toolbox' ),
		__( 'All Repairs', 'drywall-toolbox' ),
		'dtb_manage_repairs',
		'dtb-repairs',
		'dtb_repair_admin_list_page'
	);
}

// =============================================================================
// SECTION 3 — ADMIN STYLES
// =============================================================================

// Repair admin styling now loads from Admin/assets/dtb-repair-admin-modern.css.

/**
 * Deprecated no-op retained for backward compatibility with older hooks.
 * Repair admin styling now loads from Admin/assets/dtb-repair-admin-modern.css.
 */
function dtb_repair_admin_inline_styles(): void {
	return;
}

// =============================================================================
// SECTION 3d — LIST PAGE HELPERS (counts, tab groups)
// =============================================================================

/**
 * Return status keys grouped by tab slug.
 * An empty array means "all statuses" (no filter).
 *
 * @param string $tab  Tab slug: all | active | ready | completed | cancelled
 * @return array<string>
 */
function dtb_repair_admin_tab_statuses( string $tab ): array {
	$groups = [
		'active'    => [ 'submitted', 'reviewed', 'awaiting_customer', 'approved', 'quoted', 'quote_accepted', 'parts_allocated', 'in_progress' ],
		'ready'     => [ 'ready_to_ship' ],
		'completed' => [ 'completed', 'closed' ],
		'cancelled' => [ 'cancelled', 'quote_declined' ],
	];
	return $groups[ $tab ] ?? [];
}

/**
 * Count all repair CPT posts grouped by `_repair_status` meta.
 *
 * @return array<string,int>  key → count
 */
function dtb_repair_admin_get_status_counts(): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS status, COUNT(*) AS cnt
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_type   = %s
			   AND p.post_status = 'publish'
			 GROUP BY pm.meta_value",
			'_repair_status',
			'dtb_repair_request'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$out = [];
	foreach ( (array) $rows as $row ) {
		$out[ $row->status ] = (int) $row->cnt;
	}
	return $out;
}

/**
 * Sum counts for a slice of statuses.
 *
 * @param array<string,int> $counts    Full counts map.
 * @param array<string>     $statuses  Keys to sum.
 * @return int
 */
function dtb_repair_admin_sum_counts( array $counts, array $statuses ): int {
	if ( empty( $statuses ) ) {
		return (int) array_sum( $counts );
	}
	return (int) array_sum( array_intersect_key( $counts, array_flip( $statuses ) ) );
}

// =============================================================================
// SECTION 3b — REPAIR HERO BANNER (edit_form_top)
// =============================================================================

add_action( 'edit_form_top', 'dtb_repair_admin_hero_banner' );

/**
 * Render the custom hero banner at the top of the repair CPT edit screen.
 *
 * @param WP_Post $post
 */
function dtb_repair_admin_hero_banner( WP_Post $post ): void {
	if ( 'dtb_repair_request' !== $post->post_type ) {
		return;
	}

	$repair_id   = $post->ID;
	$status      = dtb_get_repair_status( $repair_id );
	$status_lbl  = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $status ) : ucwords( str_replace( '_', ' ', $status ) );
	$customer    = esc_html( (string) get_post_meta( $repair_id, '_repair_customer_name', true ) );
	$email       = esc_html( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
	$phone       = esc_html( (string) get_post_meta( $repair_id, '_repair_customer_phone', true ) );
	$brand       = esc_html( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );
	$model       = esc_html( (string) get_post_meta( $repair_id, '_repair_model', true ) );
	$category    = esc_html( (string) get_post_meta( $repair_id, '_repair_tool_category', true ) );
	$tier        = esc_html( (string) get_post_meta( $repair_id, '_repair_service_tier', true ) );
	$submitted   = esc_html( (string) get_post_meta( $repair_id, '_repair_submitted_at', true ) );
	$wc_id       = (int) get_post_meta( $repair_id, '_repair_wc_order_id', true );
	$int_raw     = (string) get_post_meta( $repair_id, '_repair_integration_state', true );
	$int_state   = ( '' !== $int_raw ) ? json_decode( $int_raw, true ) : [];
	$wc_state    = $wc_id ? 'synced' : ( $int_state['woocommerce']['state'] ?? 'pending' );
	$veeqo_state = $int_state['veeqo']['state'] ?? 'pending';
	$qb_state    = $int_state['quickbooks']['state'] ?? 'pending';
	$rw_state    = $int_state['rewards']['state'] ?? 'not_eligible';
	$thread_alert_state = function_exists( 'dtb_repair_get_customer_message_alert_state' )
		? dtb_repair_get_customer_message_alert_state( $repair_id, dtb_repair_get_customer_message_thread( $repair_id, 120 ) )
		: [ 'unread_count' => 0 ];
	$unread_customer_messages = (int) ( $thread_alert_state['unread_count'] ?? 0 );

	$tool_desc   = trim( implode( ' — ', array_filter( [ $brand, $model ?: $category ] ) ) );
	$submitted_fmt = $submitted ? date_i18n( 'M j, Y g:i a', strtotime( $submitted ) ) : '';
	?>
	<div id="dtb-repair-hero">

		<!-- Left: Identity + integration badges + meta -->
		<div class="dtb-hero-left">
			<div class="dtb-hero-id">Repair #<?php echo esc_html( (string) $repair_id ); ?></div>
			<div class="dtb-hero-title"><?php echo $customer ? esc_html( $customer ) : esc_html__( '(No customer name)', 'drywall-toolbox' ); ?></div>
			<div class="dtb-hero-status-inline">
				<span class="dtb-status-badge dtb-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
			</div>

			<!-- Integration badges: inline row under name -->
			<div class="dtb-hero-int-row">
				<div class="dtb-hero-int-pill dtb-hero-int-pill--wc dtb-int-<?php echo esc_attr( $wc_state ); ?>">
					<span class="dtb-hip-name">WooCommerce</span>
					<span class="dtb-hip-dot"></span>
					<?php if ( $wc_id ) : ?>
						<a class="dtb-hip-link" href="<?php echo esc_url( admin_url( 'post.php?post=' . $wc_id . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) $wc_id ); ?> →</a>
					<?php else : ?>
						<span class="dtb-hip-state"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $wc_state ) ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="dtb-hero-int-pill dtb-int-<?php echo esc_attr( $veeqo_state ); ?>">
					<span class="dtb-hip-name">Veeqo</span>
					<span class="dtb-hip-dot"></span>
					<span class="dtb-hip-state"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $veeqo_state ) ) ); ?></span>
				</div>
				<div class="dtb-hero-int-pill dtb-int-<?php echo esc_attr( $qb_state ); ?>">
					<span class="dtb-hip-name">QuickBooks</span>
					<span class="dtb-hip-dot"></span>
					<span class="dtb-hip-state"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $qb_state ) ) ); ?></span>
				</div>
			</div>

			<div class="dtb-hero-meta">
				<?php if ( $email ) : ?>
					<span><span class="dashicons dashicons-email-alt dtb-hero-meta-icon" aria-hidden="true"></span><?php echo esc_html( $email ); ?></span>
				<?php endif; ?>
				<?php if ( $phone ) : ?>
					<span><span class="dashicons dashicons-phone dtb-hero-meta-icon" aria-hidden="true"></span><?php echo esc_html( $phone ); ?></span>
				<?php endif; ?>
				<?php if ( $tool_desc ) : ?>
					<span><span class="dashicons dashicons-hammer dtb-hero-meta-icon" aria-hidden="true"></span><?php echo esc_html( $tool_desc ); ?></span>
				<?php endif; ?>
				<?php if ( $tier ) : ?>
					<span><span class="dashicons dashicons-star-filled dtb-hero-meta-icon" aria-hidden="true"></span><?php echo esc_html( ucfirst( $tier ) ); ?></span>
				<?php endif; ?>
				<?php if ( $submitted_fmt ) : ?>
					<span><span class="dashicons dashicons-calendar-alt dtb-hero-meta-icon" aria-hidden="true"></span>Submitted <?php echo esc_html( $submitted_fmt ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Right: Inline command center (status progress + transition) -->
		<div class="dtb-hero-cc" id="dtb-hero-command-center">
			<?php if ( function_exists( 'dtb_get_repair_status' ) && function_exists( 'dtb_get_allowed_transitions' ) ) :
				$_cc_current     = dtb_get_repair_status( $repair_id );
				$_cc_current_lbl = dtb_get_repair_status_label( $_cc_current );
				$_cc_transitions = dtb_get_allowed_transitions();
				$_cc_allowed     = $_cc_transitions[ $_cc_current ] ?? [];
				$_cc_milestones  = [
					[ 'key' => 'submitted',     'label' => 'Submitted' ],
					[ 'key' => 'in_progress',   'label' => 'In Progress' ],
					[ 'key' => 'ready_to_ship', 'label' => 'Ready to Ship' ],
					[ 'key' => 'completed',     'label' => 'Completed' ],
				];
				$_cc_milestone_order = [
					'submitted' => 0, 'reviewed' => 0, 'awaiting_customer' => 0,
					'approved' => 1, 'quoted' => 1, 'quote_accepted' => 1, 'parts_allocated' => 1, 'in_progress' => 1,
					'ready_to_ship' => 2, 'completed' => 3, 'closed' => 3,
					'cancelled' => -1, 'quote_declined' => -1,
				];
				$_cc_progress_pct = [
					'submitted' => 8, 'reviewed' => 16, 'awaiting_customer' => 20,
					'approved' => 28, 'quoted' => 35, 'quote_accepted' => 42, 'quote_declined' => 100,
					'parts_allocated' => 55, 'in_progress' => 70, 'ready_to_ship' => 88,
					'completed' => 100, 'closed' => 100, 'cancelled' => 100,
				];
				$_cc_milestone_targets = [
					'submitted'     => [ 'submitted', 'reviewed' ],
					'in_progress'   => [ 'approved', 'quoted', 'quote_accepted', 'parts_allocated', 'in_progress' ],
					'ready_to_ship' => [ 'ready_to_ship' ],
					'completed'     => [ 'completed', 'closed' ],
				];
				$_cc_idx         = $_cc_milestone_order[ $_cc_current ] ?? 0;
				$_cc_negative    = in_array( $_cc_current, [ 'cancelled', 'quote_declined' ], true );
				$_cc_complete    = in_array( $_cc_current, [ 'completed', 'closed' ], true );
				$_cc_progress    = $_cc_progress_pct[ $_cc_current ] ?? 0;
			?>

			<!-- Progress track -->
			<div class="dtb-hcc-progress">
				<div class="dtb-hcc-status-row">
					<span class="dtb-hcc-kicker">Current Status</span>
					<span class="dtb-status-badge dtb-status-<?php echo esc_attr( $_cc_current ); ?>"><?php echo esc_html( $_cc_current_lbl ); ?></span>
				</div>
				<?php if ( ! $_cc_negative ) : ?>
					<div class="dtb-hcc-track">
						<div class="dtb-hcc-fill <?php echo $_cc_complete ? 'is-complete' : ''; ?>" style="width:<?php echo esc_attr( (string) $_cc_progress ); ?>%"></div>
					</div>
					<div class="dtb-hcc-milestones">
						<?php foreach ( $_cc_milestones as $_i => $_m ) :
							$_done   = $_cc_idx > $_i || $_cc_complete;
							$_active = ! $_cc_complete && $_cc_idx === $_i;
							$_cls    = $_done ? 'dtb-ms-done' : ( $_active ? 'dtb-ms-active' : 'dtb-ms-future' );
							$_dot_target = '';
							foreach ( ( $_cc_milestone_targets[ $_m['key'] ] ?? [] ) as $_candidate ) {
								if ( in_array( $_candidate, $_cc_allowed, true ) ) { $_dot_target = $_candidate; break; }
							}
						?>
							<div class="dtb-cc-ms-item">
								<button type="button"
									class="dtb-cc-ms-dot-btn <?php echo $_dot_target ? 'is-clickable' : 'is-disabled'; ?>"
									<?php if ( ! $_dot_target ) : ?>disabled<?php endif; ?>
									<?php if ( $_dot_target ) : ?>data-status="<?php echo esc_attr( $_dot_target ); ?>" data-label="<?php echo esc_attr( dtb_get_repair_status_label( $_dot_target ) ); ?>"<?php endif; ?>
								><span class="dtb-cc-ms-dot <?php echo esc_attr( $_cls ); ?>"></span></button>
								<span class="dtb-cc-ms-label <?php echo esc_attr( $_cls ); ?>"><?php echo esc_html( $_m['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Transition panel -->
			<div class="dtb-hcc-transition">
				<?php if ( ! empty( $_cc_allowed ) ) : ?>
					<?php wp_nonce_field( 'dtb_repair_transition_' . $repair_id, 'dtb_repair_transition_nonce' ); ?>
					<div class="dtb-cc-action-picker" id="dtb-cc-action-picker">
						<input type="hidden" id="dtb-repair-to-status" value="">
						<button type="button" id="dtb-cc-action-toggle" class="dtb-cc-action-toggle" aria-expanded="false" aria-controls="dtb-cc-action-menu">
							<span class="dtb-cc-action-toggle-label">Select transition action</span>
							<span class="dashicons dashicons-arrow-down-alt2 dtb-cc-action-icon" aria-hidden="true"></span>
						</button>
						<div id="dtb-cc-action-menu" class="dtb-cc-action-menu" hidden>
							<?php foreach ( $_cc_allowed as $_ts ) : ?>
								<button type="button" class="dtb-cc-action-option" data-status="<?php echo esc_attr( $_ts ); ?>"><?php echo esc_html( dtb_get_repair_status_label( $_ts ) ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<input type="text" id="dtb-repair-transition-note" class="dtb-cc-note" placeholder="Optional note…">
					<button type="button" id="dtb-repair-transition-btn" class="dtb-cc-btn" data-repair-id="<?php echo esc_attr( (string) $repair_id ); ?>">
						<span class="dashicons dashicons-update dtb-cc-action-icon" aria-hidden="true"></span> Transition
					</button>
					<span id="dtb-repair-transition-msg" class="dtb-cc-msg"></span>
				<?php else : ?>
					<p class="dtb-cc-terminal">Terminal state — no transitions available.</p>
				<?php endif; ?>
			</div>

			<?php endif; ?>
		</div><!-- .dtb-hero-cc -->

	</div><!-- #dtb-repair-hero -->

	<div id="dtb-sticky-bar">
		<span class="dtb-sb-title">
			Repair #<?php echo esc_html( (string) $repair_id ); ?>
			&nbsp;—&nbsp;<?php echo $customer ? esc_html( $customer ) : ''; ?>
			&nbsp;<span class="dtb-status-badge dtb-sticky-status dtb-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
		</span>
		<div class="dtb-sb-actions">
			<button type="button" class="button dtb-sticky-save" onclick="document.getElementById('publish').click();">
				<span class="dashicons dashicons-saved dtb-sticky-save-icon" aria-hidden="true"></span>Save Notes
			</button>
		</div>
	</div>

	<div id="dtb-repair-workspace-tabs" role="tablist" aria-label="Repair Workspace Tabs">
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="order_details" role="tab" aria-selected="false">
			Order Details
			<?php if ( $unread_customer_messages > 0 ) : ?>
				<span class="dtb-workspace-tab-badge"><?php echo esc_html( (string) $unread_customer_messages ); ?></span>
			<?php endif; ?>
		</button>
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="quote_builder" role="tab" aria-selected="false">
			Quote Builder
		</button>
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="technician" role="tab" aria-selected="false">
			Technician
		</button>
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="timeline" role="tab" aria-selected="false">
			Timeline
		</button>
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="notes" role="tab" aria-selected="false">
			Notes
		</button>
		<button type="button" class="dtb-workspace-tab" data-dtb-tab="all" role="tab" aria-selected="false">
			All
		</button>
	</div>
	<?php
}

// =============================================================================
// SECTION 3c — ADMIN FOOTER SCRIPTS (detail page enhancements)
// =============================================================================

add_action( 'admin_footer', 'dtb_repair_admin_footer_scripts' );

/**
 * Inject JS enhancements for the repair CPT edit screen.
 */
function dtb_repair_admin_footer_scripts(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'dtb_repair_request' !== $screen->post_type ) {
		return;
	}
	?>
	<script>
	(function() {
		'use strict';

		/* ── Sticky header on scroll ── */
		var hero    = document.getElementById('dtb-repair-hero');
		var stickyBar = document.getElementById('dtb-sticky-bar');
		if ( hero && stickyBar ) {
			window.addEventListener('scroll', function() {
				var heroBottom = hero.getBoundingClientRect().bottom;
				if ( heroBottom < 60 ) {
					stickyBar.classList.add('dtb-visible');
				} else {
					stickyBar.classList.remove('dtb-visible');
				}
			}, { passive: true });
		}

		/* ── Keep all postboxes open; strip WP hidden/closed/hide-if-js so tab JS owns visibility ── */
		document.querySelectorAll('#normal-sortables .postbox, #side-sortables .postbox').forEach(function(box) {
			box.classList.remove('closed', 'hidden', 'hide-if-js');
			if ( box.style.display === 'none' ) box.style.display = '';
		});
		document.querySelectorAll('.handlediv, .toggle-indicator').forEach(function(el) {
			el.style.display = 'none';
		});

		/* ── Restyle timeline visibility badges and event dots ── */
		document.querySelectorAll('.dtb-repair-timeline li').forEach(function(li) {
			var badge = li.querySelector('.dtb-status-badge');
			if ( ! badge ) return;
			var vis = badge.textContent.trim().toLowerCase();
			li.classList.add('dtb-ev-' + vis);

			// Re-render the badge as a small pill
			badge.className = 'dtb-tl-vis dtb-tl-vis-' + vis;

			// Wrap the text content in structured divs
			var text = li.innerHTML;
			// Find the event type text (between the badge and the time span)
			var typeMatch = li.childNodes;
			var eventType = '';
			typeMatch.forEach(function(node) {
				if ( node.nodeType === 3 ) {
					var t = node.textContent.trim();
					if ( t ) eventType = t;
				}
			});
			var timeEl = li.querySelector('.dtb-timeline-time');
			var timeHtml = timeEl ? timeEl.outerHTML : '';

			if ( eventType || badge ) {
				li.innerHTML =
					'<div class="dtb-tl-body">' +
						'<span class="dtb-tl-type">' + (eventType || '') + '</span>' +
						badge.outerHTML +
						timeHtml +
					'</div>';
			}
		});

		/* ── Auto-expand notes textarea ── */
		var notes = document.querySelector('textarea[name="dtb_repair_internal_notes"]');
		if ( notes ) {
			notes.addEventListener('input', function() {
				this.style.height = 'auto';
				this.style.height = ( this.scrollHeight + 2 ) + 'px';
			});
		}

		/* ── Auto-expand order log textarea ── */
		var orderLog = document.getElementById('dtb-tech-order-log');
		if ( orderLog ) {
			var resizeLog = function() {
				orderLog.style.height = 'auto';
				orderLog.style.height = ( orderLog.scrollHeight + 2 ) + 'px';
			};
			orderLog.addEventListener('input', resizeLog);
			resizeLog();
		}

		/* ── QA checkbox: live toggle is-passed / is-checked ── */
		var qaCheckbox = document.getElementById('dtb-tw-qa-checkbox');
		if ( qaCheckbox ) {
			var qaSection = qaCheckbox.closest('.dtb-tw-qa-section');
			var qaLabel   = qaCheckbox.closest('.dtb-tw-qa-check');
			var syncQa = function() {
				var checked = qaCheckbox.checked;
				if ( qaSection ) qaSection.classList.toggle('is-passed', checked);
				if ( qaLabel )   qaLabel.classList.toggle('is-checked', checked);
			};
			qaCheckbox.addEventListener('change', syncQa);
			syncQa();
		}

		/* ── Move the transition metabox to top of #side-sortables ── */
		var side = document.getElementById('side-sortables');
		var transBox = document.getElementById('dtb-repair-transition');
		if ( side && transBox ) {
			side.insertBefore( transBox, side.firstChild );
		}

		/* ── Force Custom Fields metabox into side column (user order safe) ── */
		var postBody = document.getElementById('post-body');
		if ( postBody ) {
			postBody.classList.remove('columns-1');
			postBody.classList.add('columns-2');
		}
		var customFieldsBox = document.getElementById('postcustom');
		if ( side && customFieldsBox && customFieldsBox.parentElement !== side ) {
			side.appendChild(customFieldsBox);
		}

		/* ── Restyle integration status rows ── */
		document.querySelectorAll('.dtb-integration-row').forEach(function(row) {
			var strong = row.querySelector('strong');
			if ( ! strong ) return;
			var label = strong.textContent.replace(':', '').trim();
			strong.outerHTML = '<span class="dtb-int-label">' + label + '</span>';
		});

		/* ── Add pill class to integration state text ── */
		document.querySelectorAll('.dtb-integration-row').forEach(function(row) {
			var text = row.textContent;
			var states = ['synced','pending','error','not_configured','not_eligible','stub_pending','stub_issued','issued'];
			states.forEach(function(st) {
				if ( text.indexOf(st) !== -1 ) {
					// Wrap the state word in a pill if not already wrapped
					row.innerHTML = row.innerHTML.replace(
						new RegExp('\\b(' + st + ')\\b', 'g'),
						'<span class="dtb-int-pill dtb-int-' + st + '">$1</span>'
					);
				}
			});
		});

		/* ── Workspace tabs: show/hide section groups ── */
		var tabButtons = Array.from(document.querySelectorAll('.dtb-workspace-tab'));
		var mountTopGrid = function() {
			var normal = document.getElementById('normal-sortables');
			var command = document.getElementById('dtb-repair-command-center');
			var orderDetails = document.getElementById('dtb-repair-order-details');
			if (!normal || !command || !orderDetails) return;

			var topGrid = normal.querySelector('.dtb-top-grid');
			if (!topGrid) {
				topGrid = document.createElement('div');
				topGrid.className = 'dtb-top-grid';
				normal.insertBefore(topGrid, normal.firstChild);
			}

			if (command.parentElement !== topGrid) {
				topGrid.appendChild(command);
			}
			if (orderDetails.parentElement !== topGrid) {
				topGrid.appendChild(orderDetails);
			}
		};

		mountTopGrid();
		if ( tabButtons.length ) {
			var byId = function(id) { return document.getElementById(id); };
			// Debug: log which IDs are missing from the DOM
			['dtb-repair-command-center','dtb-repair-order-details','dtb-repair-quote-builder',
			 'dtb-repair-technician','dtb-repair-timeline','dtb-repair-notes','dtb-repair-queue'].forEach(function(id) {
				var el = document.getElementById(id);
				if ( ! el ) { console.warn('[DTB Tabs] Postbox not found in DOM: #' + id); }
				else { console.log('[DTB Tabs] Found #' + id + ' classes:', el.className); }
			});
			var SESSION_KEY = 'dtb_repair_tab_' + (window.location.search.match(/[?&]post=(\d+)/) || ['',''])[1];
			var groups = {
				order_details: [
					'dtb-repair-command-center',
					'dtb-repair-order-details'
				],
				quote_builder: [
					'dtb-repair-command-center',
					'dtb-repair-quote-builder'
				],
				technician: [
					'dtb-repair-command-center',
					'dtb-repair-technician'
				],
				timeline: [
					'dtb-repair-command-center',
					'dtb-repair-timeline'
				],
				notes: [
					'dtb-repair-command-center',
					'dtb-repair-notes',
					'dtb-repair-queue'
				],
				all: []
			};

			var allKnownIds = [
				'dtb-repair-command-center',
				'dtb-repair-order-details',
				'dtb-repair-quote-builder',
				'dtb-repair-technician',
				'dtb-repair-timeline',
				'dtb-repair-notes',
				'dtb-repair-queue'
			];

			var setActiveTab = function(tabKey) {
				tabButtons.forEach(function(btn) {
					var isActive = btn.getAttribute('data-dtb-tab') === tabKey;
					btn.classList.toggle('is-active', isActive);
					btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
				});

				try { window.sessionStorage.setItem(SESSION_KEY, tabKey); } catch(e) {}

				var visibleSet = ( tabKey === 'all' )
					? new Set(allKnownIds)
					: new Set(groups[tabKey] || groups.order_details);

				allKnownIds.forEach(function(id) {
					var el = byId(id);
					if ( ! el ) return;
					var show = visibleSet.has(id);
					el.classList.remove('hidden'); // strip WP Screen Options class
					el.classList.toggle('dtb-workspace-hidden', ! show);
					el.style.display = show ? '' : 'none';
				});
			};

			tabButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					var tabKey = btn.getAttribute('data-dtb-tab') || 'order_details';
					setActiveTab(tabKey);
					mountTopGrid();
				});
			});

			var savedTab = '';
			try { savedTab = window.sessionStorage.getItem(SESSION_KEY) || ''; } catch(e) {}
			var initialTab = (savedTab && groups.hasOwnProperty(savedTab)) ? savedTab : 'order_details';
			setActiveTab(initialTab);
			mountTopGrid();
		}

		/* ── Live schematic lookup (technician workspace) ── */
		var lookupInput = document.getElementById('dtb_repair_schematic_catalog_id');
		var lookupMenu = document.getElementById('dtb-tech-schematic-lookup-menu');
		var linksInput = document.getElementById('dtb_repair_schematic_links_json');
		var selectedListEl = document.getElementById('dtb-tech-selected-schematics');
		var primaryBrandEl = document.getElementById('dtb-tech-primary-brand');
		var primaryModelEl = document.getElementById('dtb-tech-primary-model');
		var primarySkuEl = document.getElementById('dtb-tech-primary-sku');
		var lookupReq = null;
		var lookupTimer = null;
		var selectedSchematics = [];

		var hideLookupMenu = function() {
			if (!lookupMenu) return;
			lookupMenu.hidden = true;
			lookupMenu.innerHTML = '';
		};

		var renderLookupMenu = function(items) {
			if (!lookupMenu) return;
			if (!items || !items.length) {
				hideLookupMenu();
				return;
			}
			lookupMenu.innerHTML = items.map(function(item) {
				var primary = (item.schematic_id || 'Unknown ID');
				var secondary = [item.brand, item.model_number, item.model_name].filter(Boolean).join(' · ');
				return (
					'<button type="button" class="dtb-tech-lookup-option" ' +
					'data-id="' + String(item.schematic_id || '').replace(/"/g, '&quot;') + '" ' +
					'data-url="' + String(item.url || '').replace(/"/g, '&quot;') + '" ' +
					'data-brand="' + String(item.brand || '').replace(/"/g, '&quot;') + '" ' +
					'data-model="' + String(item.model_number || '').replace(/"/g, '&quot;') + '" ' +
					'data-model-name="' + String(item.model_name || '').replace(/"/g, '&quot;') + '" ' +
					'data-sku="' + String(item.sku || '').replace(/"/g, '&quot;') + '" ' +
					'data-product-name="' + String(item.product_name || '').replace(/"/g, '&quot;') + '" ' +
					'data-version="' + String(item.version || '').replace(/"/g, '&quot;') + '">' +
						'<span class="dtb-tech-lookup-primary">' + primary + '</span>' +
						'<span class="dtb-tech-lookup-secondary">' + (secondary || 'Catalog schematic') + '</span>' +
					'</button>'
				);
			}).join('');
			lookupMenu.hidden = false;
		};

		var renderSelectedSchematics = function() {
			if (!selectedListEl || !linksInput) return;
			if (!selectedSchematics.length) {
				selectedListEl.innerHTML = '<div class="dtb-tech-selected-empty">No schematic selected yet.</div>';
				linksInput.value = '[]';
				if (primaryBrandEl) primaryBrandEl.textContent = '';
				if (primaryModelEl) primaryModelEl.textContent = '';
				if (primarySkuEl) primarySkuEl.textContent = '';
				return;
			}
			selectedListEl.innerHTML = selectedSchematics.map(function(item, idx) {
				var title = item.schematic_id || 'Unknown schematic';
				var subtitle = [item.brand, item.model_number, item.model_name, item.sku ? ('SKU: ' + item.sku) : ''].filter(Boolean).join(' · ');
				var link = item.url ? '<a href="' + item.url + '" target="_blank" rel="noopener noreferrer">Open</a>' : '';
				return (
					'<div class="dtb-tech-selected-item" draggable="true" data-index="' + idx + '">' +
						'<div class="dtb-tech-selected-main">' +
							'<div class="dtb-tech-selected-title">' + title + '</div>' +
							'<div class="dtb-tech-selected-sub">' + (subtitle || 'Catalog schematic') + '</div>' +
						'</div>' +
						'<div class="dtb-tech-selected-actions">' +
							link +
							'<button type="button" class="dtb-tech-selected-remove" data-index="' + idx + '">Remove</button>' +
						'</div>' +
					'</div>'
				);
			}).join('');
			linksInput.value = JSON.stringify(selectedSchematics);
			var primary = selectedSchematics[0] || {};
			if (primaryBrandEl) primaryBrandEl.textContent = primary.brand || '';
			if (primaryModelEl) primaryModelEl.textContent = primary.model_number || primary.model_name || '';
			if (primarySkuEl) primarySkuEl.textContent = primary.sku || '';
		};

		if (lookupInput && lookupMenu && linksInput && selectedListEl && typeof ajaxurl === 'string') {
			try {
				selectedSchematics = JSON.parse(linksInput.value || '[]');
				if (!Array.isArray(selectedSchematics)) selectedSchematics = [];
			} catch (e) {
				selectedSchematics = [];
			}
			renderSelectedSchematics();

			lookupInput.addEventListener('input', function() {
				var term = lookupInput.value.trim();
				if (lookupTimer) window.clearTimeout(lookupTimer);
				if (term.length < 2) {
					hideLookupMenu();
					return;
				}
				lookupTimer = window.setTimeout(function() {
					if (lookupReq && typeof lookupReq.abort === 'function') {
						lookupReq.abort();
					}
					var body = new URLSearchParams();
					body.set('action', 'dtb_repair_schematic_lookup');
					body.set('term', term);
					body.set('nonce', lookupInput.getAttribute('data-lookup-nonce') || '');

					lookupReq = fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString(),
						credentials: 'same-origin'
					})
					.then(function(resp) { return resp.json(); })
					.then(function(payload) {
						var items = payload && payload.success && payload.data ? payload.data.items : [];
						renderLookupMenu(items || []);
					})
					.catch(function() { hideLookupMenu(); });
				}, 180);
			});

			lookupMenu.addEventListener('click', function(e) {
				var btn = e.target.closest('.dtb-tech-lookup-option');
				if (!btn) return;
				var sid = btn.getAttribute('data-id') || '';
				var surl = btn.getAttribute('data-url') || '';
				var sver = btn.getAttribute('data-version') || '';
				var existing = selectedSchematics.find(function(item) { return item.schematic_id === sid; });
				if (!existing) {
					selectedSchematics.push({
						schematic_id: sid,
						url: surl,
						version: sver,
						brand: btn.getAttribute('data-brand') || '',
						model_number: btn.getAttribute('data-model') || '',
						model_name: btn.getAttribute('data-model-name') || '',
						sku: btn.getAttribute('data-sku') || '',
						product_name: btn.getAttribute('data-product-name') || ''
					});
				}
				lookupInput.value = '';
				renderSelectedSchematics();
				hideLookupMenu();
			});

			selectedListEl.addEventListener('click', function(e) {
				var removeBtn = e.target.closest('.dtb-tech-selected-remove');
				if (!removeBtn) return;
				var index = parseInt(removeBtn.getAttribute('data-index') || '-1', 10);
				if (index < 0 || index >= selectedSchematics.length) return;
				selectedSchematics.splice(index, 1);
				renderSelectedSchematics();
			});

			var dragFromIndex = -1;
			selectedListEl.addEventListener('dragstart', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				dragFromIndex = parseInt(item.getAttribute('data-index') || '-1', 10);
				item.classList.add('is-dragging');
				if (e.dataTransfer) {
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData('text/plain', String(dragFromIndex));
				}
			});
			selectedListEl.addEventListener('dragend', function() {
				dragFromIndex = -1;
				selectedListEl.querySelectorAll('.dtb-tech-selected-item').forEach(function(el) {
					el.classList.remove('is-dragging', 'is-drop-target');
				});
			});
			selectedListEl.addEventListener('dragover', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				e.preventDefault();
				selectedListEl.querySelectorAll('.dtb-tech-selected-item').forEach(function(el) {
					el.classList.remove('is-drop-target');
				});
				item.classList.add('is-drop-target');
			});
			selectedListEl.addEventListener('drop', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				e.preventDefault();
				var toIndex = parseInt(item.getAttribute('data-index') || '-1', 10);
				if (dragFromIndex < 0 || toIndex < 0 || dragFromIndex === toIndex) return;
				var moved = selectedSchematics.splice(dragFromIndex, 1)[0];
				selectedSchematics.splice(toIndex, 0, moved);
				renderSelectedSchematics();
			});

			document.addEventListener('click', function(e) {
				if (!lookupMenu.contains(e.target) && e.target !== lookupInput) {
					hideLookupMenu();
				}
			});
		}

		/* ── Live parts lookup (technician workspace) ── */
		var partsLookupInput = document.getElementById('dtb_repair_parts_lookup');
		var partsLookupMenu = document.getElementById('dtb-tech-parts-lookup-menu');
		var partsLinksInput = document.getElementById('dtb_repair_parts_links_json');
		var selectedPartsEl = document.getElementById('dtb-tech-selected-parts');
		var recentPartsEl = document.getElementById('dtb-tech-recent-parts');
		var primaryPartSkuEl = document.getElementById('dtb-tech-primary-part-sku');
		var primaryPartNameEl = document.getElementById('dtb-tech-primary-part-name');
		var primaryPartBrandEl = document.getElementById('dtb-tech-primary-part-brand');
		var syncPartsToQuoteBtn = document.getElementById('dtb-tech-sync-parts-to-quote');
		var partsReq = null;
		var partsTimer = null;
		var selectedParts = [];
		var recentParts = [];
		var RECENT_PARTS_KEY = 'dtbRepairRecentParts.v1';

		var uniquePartKey = function(part) {
			if (!part) return '';
			var id = parseInt(part.part_id || 0, 10) || 0;
			if (id > 0) return 'id:' + id;
			var sku = (part.sku || '').toString().trim().toLowerCase();
			if (sku) return 'sku:' + sku;
			var name = (part.name || '').toString().trim().toLowerCase();
			return name ? ('name:' + name) : '';
		};

		var loadRecentParts = function() {
			try {
				var raw = window.localStorage.getItem(RECENT_PARTS_KEY);
				var parsed = raw ? JSON.parse(raw) : [];
				return Array.isArray(parsed) ? parsed : [];
			} catch (e) {
				return [];
			}
		};

		var saveRecentParts = function() {
			try {
				window.localStorage.setItem(RECENT_PARTS_KEY, JSON.stringify(recentParts.slice(0, 16)));
			} catch (e) {}
		};

		var rememberRecentPart = function(part) {
			if (!part) return;
			var key = uniquePartKey(part);
			if (!key) return;
			recentParts = recentParts.filter(function(item) {
				return uniquePartKey(item) !== key;
			});
			recentParts.unshift(Object.assign({}, part));
			recentParts = recentParts.slice(0, 16);
			saveRecentParts();
			renderRecentParts();
			document.dispatchEvent(new CustomEvent('dtb:parts:recentUpdated', {
				detail: { parts: recentParts.slice() }
			}));
		};

		var pushPartToQuoteBuilder = function(part) {
			if (!part) return;
			document.dispatchEvent(new CustomEvent('dtb:quote:addPart', {
				detail: { part: part }
			}));
		};

		var pushAllPartsToQuoteBuilder = function() {
			document.dispatchEvent(new CustomEvent('dtb:quote:syncParts', {
				detail: { parts: selectedParts.slice() }
			}));
		};

		var hidePartsLookupMenu = function() {
			if (!partsLookupMenu) return;
			partsLookupMenu.hidden = true;
			partsLookupMenu.innerHTML = '';
		};

		var renderPartsLookupMenu = function(items) {
			if (!partsLookupMenu) return;
			if (!items || !items.length) {
				hidePartsLookupMenu();
				return;
			}
			partsLookupMenu.innerHTML = items.map(function(item) {
				var primary = (item.sku || 'No SKU') + ' — ' + (item.name || 'Part');
				var price = parseFloat(item.unit_price || 0);
				var secondary = [
					item.brand_label,
					item.manufacturer_sku ? ('MFG: ' + item.manufacturer_sku) : '',
					(price > 0 ? ('USD ' + price.toFixed(2)) : '')
				].filter(Boolean).join(' · ');
				return (
					'<button type="button" class="dtb-tech-lookup-option" ' +
					'data-part-id="' + String(item.part_id || 0).replace(/"/g, '&quot;') + '" ' +
					'data-sku="' + String(item.sku || '').replace(/"/g, '&quot;') + '" ' +
					'data-name="' + String(item.name || '').replace(/"/g, '&quot;') + '" ' +
					'data-brand="' + String(item.brand_label || '').replace(/"/g, '&quot;') + '" ' +
					'data-manufacturer-sku="' + String(item.manufacturer_sku || '').replace(/"/g, '&quot;') + '" ' +
					'data-unit-price="' + String(item.unit_price || 0).replace(/"/g, '&quot;') + '">' +
						'<span class="dtb-tech-lookup-primary">' + primary + '</span>' +
						'<span class="dtb-tech-lookup-secondary">' + (secondary || 'Parts library item') + '</span>' +
					'</button>'
				);
			}).join('');
			partsLookupMenu.hidden = false;
		};

		var renderSelectedParts = function() {
			if (!selectedPartsEl || !partsLinksInput) return;
			if (!selectedParts.length) {
				selectedPartsEl.innerHTML = '<div class="dtb-tech-selected-empty">No part selected yet.</div>';
				partsLinksInput.value = '[]';
				if (primaryPartSkuEl) primaryPartSkuEl.textContent = '';
				if (primaryPartNameEl) primaryPartNameEl.textContent = '';
				if (primaryPartBrandEl) primaryPartBrandEl.textContent = '';
				return;
			}
			selectedPartsEl.innerHTML = selectedParts.map(function(item, idx) {
				var price = parseFloat(item.unit_price || 0);
				var subtitle = [item.brand_label, item.manufacturer_sku ? ('MFG: ' + item.manufacturer_sku) : '', (price > 0 ? ('USD ' + price.toFixed(2)) : '')].filter(Boolean).join(' · ');
				var qty = parseInt(item.quantity || 1, 10);
				if (!qty || qty < 1) qty = 1;
				var lineNote = item.line_note || '';
				return (
					'<div class="dtb-tech-selected-item" draggable="true" data-index="' + idx + '">' +
						'<div class="dtb-tech-selected-main">' +
							'<div class="dtb-tech-selected-title">' + (item.sku || 'No SKU') + ' — ' + (item.name || 'Part') + '</div>' +
							'<div class="dtb-tech-selected-sub">' + (subtitle || 'Parts library item') + '</div>' +
							'<div class="dtb-tech-selected-fields">' +
								'<input type="number" min="1" step="1" class="dtb-tech-part-qty" data-index="' + idx + '" value="' + qty + '" placeholder="Qty" />' +
								'<input type="text" class="dtb-tech-part-note" data-index="' + idx + '" value="' + String(lineNote).replace(/"/g, '&quot;') + '" placeholder="Line note (installed position, condition, torque, etc.)" />' +
							'</div>' +
						'</div>' +
						'<div class="dtb-tech-selected-actions">' +
							'<button type="button" class="dtb-tech-add-to-quote" data-index="' + idx + '">Add to Quote</button>' +
							'<button type="button" class="dtb-tech-selected-remove" data-index="' + idx + '">Remove</button>' +
						'</div>' +
					'</div>'
				);
			}).join('');
			partsLinksInput.value = JSON.stringify(selectedParts);
			var primary = selectedParts[0] || {};
			if (primaryPartSkuEl) primaryPartSkuEl.textContent = primary.sku || '';
			if (primaryPartNameEl) primaryPartNameEl.textContent = primary.name || '';
			if (primaryPartBrandEl) primaryPartBrandEl.textContent = primary.brand_label || '';
		};

		var renderRecentParts = function() {
			if (!recentPartsEl) return;
			if (!recentParts.length) {
				recentPartsEl.innerHTML = '<div class="dtb-tech-selected-empty">No recent parts yet.</div>';
				return;
			}
			recentPartsEl.innerHTML = recentParts.slice(0, 8).map(function(item, idx) {
				var price = parseFloat(item.unit_price || 0);
				var subtitle = [item.brand_label, item.manufacturer_sku ? ('MFG: ' + item.manufacturer_sku) : '', (price > 0 ? ('USD ' + price.toFixed(2)) : '')].filter(Boolean).join(' · ');
				return (
					'<div class="dtb-tech-selected-item">' +
						'<div class="dtb-tech-selected-main">' +
							'<div class="dtb-tech-selected-title">' + (item.sku || 'No SKU') + ' — ' + (item.name || 'Part') + '</div>' +
							'<div class="dtb-tech-selected-sub">' + (subtitle || 'Parts library item') + '</div>' +
						'</div>' +
						'<div class="dtb-tech-selected-actions">' +
							'<button type="button" class="dtb-tech-add-to-quote dtb-tech-add-recent" data-index="' + idx + '">Add</button>' +
						'</div>' +
					'</div>'
				);
			}).join('');
		};

		var upsertSelectedPart = function(part, pushToQuote) {
			if (!part) return;
			var partId = parseInt(part.part_id || 0, 10);
			var sku = (part.sku || '').toString();
			var index = selectedParts.findIndex(function(item) {
				var samePartId = partId > 0 && parseInt(item.part_id || 0, 10) === partId;
				var sameSku = sku && String(item.sku || '') === sku;
				return samePartId || sameSku;
			});
			var normalized = {
				part_id: partId,
				sku: sku,
				name: (part.name || '').toString(),
				brand_label: (part.brand_label || '').toString(),
				manufacturer_sku: (part.manufacturer_sku || '').toString(),
				unit_price: parseFloat(part.unit_price || 0) || 0,
				quantity: Math.max(1, parseInt(part.quantity || 1, 10) || 1),
				line_note: (part.line_note || '').toString()
			};
			if (index === -1) {
				selectedParts.push(normalized);
			} else {
				selectedParts[index] = Object.assign({}, selectedParts[index], normalized);
			}
			rememberRecentPart(normalized);
			renderSelectedParts();
			if (pushToQuote) {
				pushPartToQuoteBuilder(normalized);
			}
		};

		if (partsLookupInput && partsLookupMenu && partsLinksInput && selectedPartsEl && typeof ajaxurl === 'string') {
			try {
				selectedParts = JSON.parse(partsLinksInput.value || '[]');
				if (!Array.isArray(selectedParts)) selectedParts = [];
			} catch (e) {
				selectedParts = [];
			}
			renderSelectedParts();

			partsLookupInput.addEventListener('input', function() {
				var term = partsLookupInput.value.trim();
				if (partsTimer) window.clearTimeout(partsTimer);
				if (term.length < 2) {
					hidePartsLookupMenu();
					return;
				}
				partsTimer = window.setTimeout(function() {
					if (partsReq && typeof partsReq.abort === 'function') {
						partsReq.abort();
					}
					var body = new URLSearchParams();
					body.set('action', 'dtb_repair_parts_lookup');
					body.set('term', term);
					body.set('nonce', partsLookupInput.getAttribute('data-lookup-nonce') || '');

					partsReq = fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString(),
						credentials: 'same-origin'
					})
					.then(function(resp) { return resp.json(); })
					.then(function(payload) {
						var items = payload && payload.success && payload.data ? payload.data.items : [];
						renderPartsLookupMenu(items || []);
					})
					.catch(function() { hidePartsLookupMenu(); });
				}, 180);
			});

			partsLookupMenu.addEventListener('click', function(e) {
				var btn = e.target.closest('.dtb-tech-lookup-option');
				if (!btn) return;
				upsertSelectedPart({
					part_id: parseInt(btn.getAttribute('data-part-id') || '0', 10),
					sku: btn.getAttribute('data-sku') || '',
					name: btn.getAttribute('data-name') || '',
					brand_label: btn.getAttribute('data-brand') || '',
					manufacturer_sku: btn.getAttribute('data-manufacturer-sku') || '',
					unit_price: parseFloat(btn.getAttribute('data-unit-price') || '0') || 0,
					quantity: 1,
					line_note: ''
				}, true);
				partsLookupInput.value = '';
				hidePartsLookupMenu();
			});

			selectedPartsEl.addEventListener('click', function(e) {
				var addBtn = e.target.closest('.dtb-tech-add-to-quote');
				if (addBtn) {
					var addIndex = parseInt(addBtn.getAttribute('data-index') || '-1', 10);
					if (addIndex >= 0 && addIndex < selectedParts.length) {
						pushPartToQuoteBuilder(selectedParts[addIndex]);
					}
					return;
				}

				var removeBtn = e.target.closest('.dtb-tech-selected-remove');
				if (!removeBtn) return;
				var index = parseInt(removeBtn.getAttribute('data-index') || '-1', 10);
				if (index < 0 || index >= selectedParts.length) return;
				selectedParts.splice(index, 1);
				renderSelectedParts();
			});

			if (recentPartsEl) {
				recentPartsEl.addEventListener('click', function(e) {
					var addBtn = e.target.closest('.dtb-tech-add-recent');
					if (!addBtn) return;
					var idx = parseInt(addBtn.getAttribute('data-index') || '-1', 10);
					if (idx < 0 || idx >= recentParts.length) return;
					upsertSelectedPart(recentParts[idx], true);
				});
			}

			if (syncPartsToQuoteBtn) {
				syncPartsToQuoteBtn.addEventListener('click', function() {
					pushAllPartsToQuoteBuilder();
				});
			}

			selectedPartsEl.addEventListener('input', function(e) {
				var qtyEl = e.target.closest('.dtb-tech-part-qty');
				if (qtyEl) {
					var idx = parseInt(qtyEl.getAttribute('data-index') || '-1', 10);
					if (idx >= 0 && idx < selectedParts.length) {
						var qtyVal = parseInt(qtyEl.value || '1', 10);
						if (!qtyVal || qtyVal < 1) qtyVal = 1;
						selectedParts[idx].quantity = qtyVal;
						partsLinksInput.value = JSON.stringify(selectedParts);
						pushPartToQuoteBuilder(selectedParts[idx]);
					}
					return;
				}
				var noteEl = e.target.closest('.dtb-tech-part-note');
				if (noteEl) {
					var nidx = parseInt(noteEl.getAttribute('data-index') || '-1', 10);
					if (nidx >= 0 && nidx < selectedParts.length) {
						selectedParts[nidx].line_note = noteEl.value || '';
						partsLinksInput.value = JSON.stringify(selectedParts);
						pushPartToQuoteBuilder(selectedParts[nidx]);
					}
				}
			});

			var dragPartFromIndex = -1;
			selectedPartsEl.addEventListener('dragstart', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				dragPartFromIndex = parseInt(item.getAttribute('data-index') || '-1', 10);
				item.classList.add('is-dragging');
				if (e.dataTransfer) {
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData('text/plain', String(dragPartFromIndex));
				}
			});
			selectedPartsEl.addEventListener('dragend', function() {
				dragPartFromIndex = -1;
				selectedPartsEl.querySelectorAll('.dtb-tech-selected-item').forEach(function(el) {
					el.classList.remove('is-dragging', 'is-drop-target');
				});
			});
			selectedPartsEl.addEventListener('dragover', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				e.preventDefault();
				selectedPartsEl.querySelectorAll('.dtb-tech-selected-item').forEach(function(el) {
					el.classList.remove('is-drop-target');
				});
				item.classList.add('is-drop-target');
			});
			selectedPartsEl.addEventListener('drop', function(e) {
				var item = e.target.closest('.dtb-tech-selected-item');
				if (!item) return;
				e.preventDefault();
				var toIndex = parseInt(item.getAttribute('data-index') || '-1', 10);
				if (dragPartFromIndex < 0 || toIndex < 0 || dragPartFromIndex === toIndex) return;
				var moved = selectedParts.splice(dragPartFromIndex, 1)[0];
				selectedParts.splice(toIndex, 0, moved);
				renderSelectedParts();
			});

			document.addEventListener('click', function(e) {
				if (!partsLookupMenu.contains(e.target) && e.target !== partsLookupInput) {
					hidePartsLookupMenu();
				}
			});

			document.addEventListener('dtb:tech:partSelected', function(evt) {
				var detail = evt && evt.detail ? evt.detail : null;
				if (!detail || !detail.part) return;
				upsertSelectedPart(detail.part, false);
			});

			document.addEventListener('dtb:parts:recentUpdated', function(evt) {
				var detail = evt && evt.detail ? evt.detail : null;
				if (!detail || !Array.isArray(detail.parts)) return;
				recentParts = detail.parts.slice(0, 16);
				renderRecentParts();
			});

			recentParts = loadRecentParts();
			renderRecentParts();
		}

	}());
	</script>
	<?php
}

// =============================================================================
// SECTION 4 — LIST PAGE CALLBACK (WP_List_Table)
// =============================================================================

/**
 * Render the All Repairs admin page — modernized dashboard layout.
 */
function dtb_repair_admin_list_page(): void {
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'drywall-toolbox' ) );
	}

	$lookup_term = isset( $_GET['dtb_lookup'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['dtb_lookup'] ) ) : '';
	if ( '' !== $lookup_term ) {
		if ( preg_match( '/^#?(\d+)$/', trim( $lookup_term ), $match ) ) {
			$lookup_id = (int) $match[1];
			$lookup_post = get_post( $lookup_id );
			if ( $lookup_post instanceof WP_Post && 'dtb_repair_request' === $lookup_post->post_type ) {
				wp_safe_redirect( admin_url( 'post.php?post=' . $lookup_id . '&action=edit' ) );
				exit;
			}
		}
		if ( empty( $_GET['s'] ) ) {
			$_GET['s'] = $lookup_term; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	$table = new DTB_Repair_List_Table();
	$table->prepare_items();

	// ── Status counts ────────────────────────────────────────────────────────
	$counts      = dtb_repair_admin_get_status_counts();
	$n_total     = (int) array_sum( $counts );
	$n_active    = dtb_repair_admin_sum_counts( $counts, dtb_repair_admin_tab_statuses( 'active' ) );
	$n_ready     = dtb_repair_admin_sum_counts( $counts, dtb_repair_admin_tab_statuses( 'ready' ) );
	$n_completed = dtb_repair_admin_sum_counts( $counts, dtb_repair_admin_tab_statuses( 'completed' ) );
	$n_cancelled = dtb_repair_admin_sum_counts( $counts, dtb_repair_admin_tab_statuses( 'cancelled' ) );

	// ── Current tab & chip ───────────────────────────────────────────────────
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$current_tab    = isset( $_GET['tab'] )           ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) )           : 'all';
	$current_status = isset( $_GET['repair_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['repair_status'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Normalise 'all' to a known slug
	if ( ! in_array( $current_tab, [ 'all', 'active', 'ready', 'completed', 'cancelled' ], true ) ) {
		$current_tab = 'all';
	}

	$base_url    = admin_url( 'admin.php?page=dtb-repairs' );
	$tab_statuses = dtb_repair_admin_tab_statuses( $current_tab );

	// ── Ordered list of all statuses for chip bar ────────────────────────────
	$all_statuses_ordered = [
		'submitted', 'reviewed', 'awaiting_customer', 'approved',
		'quoted', 'quote_accepted', 'quote_declined',
		'parts_allocated', 'in_progress',
		'ready_to_ship', 'completed', 'closed', 'cancelled',
	];
	$chip_pool = ( 'all' === $current_tab ) ? $all_statuses_ordered : $tab_statuses;

	// Filter chip pool to statuses that have at least 1 repair (on All tab)
	if ( 'all' === $current_tab ) {
		$chip_pool = array_filter( $chip_pool, static fn( $s ) => ( $counts[ $s ] ?? 0 ) > 0 );
	}
	?>
	<div class="wrap dtb-repairs-wrap">

		<?php if ( ! empty( $_GET['dtb_bulk_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['dtb_bulk_msg'] ) ) ); ?></p>
			</div>
		<?php endif; ?>

		<!-- ── Page header ────────────────────────────────────────────────── -->
		<div class="dtb-page-header">
			<div>
				<h1><?php esc_html_e( 'Repair Requests', 'drywall-toolbox' ); ?>
					<span class="dtb-page-subtitle"><?php echo esc_html( date_i18n( 'F j, Y' ) ); ?></span>
				</h1>
			</div>
		</div>
		<div class="dtb-stats-row">
			<?php
			$stat_cards = [
				[ 'cls' => 'dtb-sc-total',     'num' => $n_total,     'label' => __( 'Total Repairs',   'drywall-toolbox' ) ],
				[ 'cls' => 'dtb-sc-active',    'num' => $n_active,    'label' => __( 'Active',           'drywall-toolbox' ) ],
				[ 'cls' => 'dtb-sc-ready',     'num' => $n_ready,     'label' => __( 'Ready to Ship',    'drywall-toolbox' ) ],
				[ 'cls' => 'dtb-sc-completed', 'num' => $n_completed, 'label' => __( 'Completed',        'drywall-toolbox' ) ],
				[ 'cls' => 'dtb-sc-cancelled', 'num' => $n_cancelled, 'label' => __( 'Cancelled',        'drywall-toolbox' ) ],
			];
			foreach ( $stat_cards as $card ) :
			?>
				<div class="dtb-stat-card <?php echo esc_attr( $card['cls'] ); ?>">
					<div class="dtb-stat-num"><?php echo esc_html( (string) $card['num'] ); ?></div>
					<div class="dtb-stat-label"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- ── List shell ─────────────────────────────────────────────────── -->
		<div class="dtb-list-shell">

			<!-- ── Tab bar ──────────────────────────────────────────────── -->
			<div class="dtb-tab-bar">
				<nav class="dtb-tabs" role="tablist">
					<?php
					$tabs = [
						'all'       => [ 'label' => __( 'All Repairs',    'drywall-toolbox' ), 'count' => $n_total     ],
						'active'    => [ 'label' => __( 'Active',          'drywall-toolbox' ), 'count' => $n_active    ],
						'ready'     => [ 'label' => __( 'Ready to Ship',   'drywall-toolbox' ), 'count' => $n_ready     ],
						'completed' => [ 'label' => __( 'Completed',       'drywall-toolbox' ), 'count' => $n_completed ],
						'cancelled' => [ 'label' => __( 'Cancelled',       'drywall-toolbox' ), 'count' => $n_cancelled ],
					];
					foreach ( $tabs as $slug => $tab_def ) :
						$is_cur  = ( $slug === $current_tab );
						$tab_url = ( 'all' === $slug )
							? esc_url( $base_url )
							: esc_url( add_query_arg( [ 'tab' => $slug ], $base_url ) );
					?>
						<a href="<?php echo $tab_url; // phpcs:ignore ?>"
						   class="dtb-tab<?php echo $is_cur ? ' dtb-tab-current' : ''; ?>"
						   role="tab"
						   aria-selected="<?php echo $is_cur ? 'true' : 'false'; ?>"
						>
							<?php echo esc_html( $tab_def['label'] ); ?>
							<span class="dtb-tab-badge"><?php echo esc_html( (string) $tab_def['count'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>
				<div class="dtb-tab-bar-right">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dtb_repair_request' ) ); ?>"
					   class="button button-primary">
						+ <?php esc_html_e( 'New Repair', 'drywall-toolbox' ); ?>
					</a>
				</div>
			</div><!-- .dtb-tab-bar -->

			<!-- ── Status chip filter bar ───────────────────────────────── -->
			<div class="dtb-chip-bar">
				<?php
				// "All Statuses" chip clears the status filter but keeps the tab.
				$clear_url = ( 'all' === $current_tab )
					? esc_url( $base_url )
					: esc_url( add_query_arg( [ 'tab' => $current_tab ], $base_url ) );
				?>
				<a href="<?php echo $clear_url; // phpcs:ignore ?>"
				   class="dtb-chip<?php echo '' === $current_status ? ' dtb-chip-active' : ''; ?>">
					<?php esc_html_e( 'All Statuses', 'drywall-toolbox' ); ?>
				</a>

				<?php foreach ( $chip_pool as $st ) :
					$cnt   = $counts[ $st ] ?? 0;
					$label = function_exists( 'dtb_get_repair_status_label' )
						? dtb_get_repair_status_label( $st )
						: ucwords( str_replace( '_', ' ', $st ) );

					$chip_args = [ 'repair_status' => $st ];
					if ( 'all' !== $current_tab ) {
						$chip_args['tab'] = $current_tab;
					}
					$chip_url = esc_url( add_query_arg( $chip_args, $base_url ) );
				?>
					<a href="<?php echo $chip_url; // phpcs:ignore ?>"
					   class="dtb-chip<?php echo ( $current_status === $st ) ? ' dtb-chip-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<span class="dtb-chip-count"><?php echo esc_html( (string) $cnt ); ?></span>
					</a>
				<?php endforeach; ?>
			</div><!-- .dtb-chip-bar -->

			<!-- ── Table ────────────────────────────────────────────────── -->
			<div class="dtb-table-wrap" id="dtb-repairs-table-wrap" data-page="dtb-repairs" data-tab="<?php echo esc_attr( $current_tab ); ?>" data-status="<?php echo esc_attr( $current_status ); ?>">
				<form method="get" action="" class="dtb-call-lookup-bar" id="dtb-call-lookup-form">
					<input type="hidden" name="page" value="dtb-repairs">
					<?php if ( 'all' !== $current_tab ) : ?>
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
					<?php endif; ?>
					<?php if ( '' !== $current_status ) : ?>
						<input type="hidden" name="repair_status" value="<?php echo esc_attr( $current_status ); ?>">
					<?php endif; ?>
					<label for="dtb_lookup_input" class="screen-reader-text"><?php esc_html_e( 'Call lookup', 'drywall-toolbox' ); ?></label>
					<input type="search" id="dtb_lookup_input" name="dtb_lookup" class="dtb-call-lookup-input" value="<?php echo esc_attr( $lookup_term ); ?>" placeholder="<?php esc_attr_e( 'Call lookup: repair #, order #, name, email, phone, serial', 'drywall-toolbox' ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Find Repair', 'drywall-toolbox' ); ?></button>
				</form>
				<form method="get" action="" id="dtb-repair-search-form">
					<input type="hidden" name="page" value="dtb-repairs">
					<?php if ( 'all' !== $current_tab ) : ?>
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
					<?php endif; ?>
					<?php if ( '' !== $current_status ) : ?>
						<input type="hidden" name="repair_status" value="<?php echo esc_attr( $current_status ); ?>">
					<?php endif; ?>
					<?php
					$table->search_box( __( 'Search repairs…', 'drywall-toolbox' ), 'dtb-repair-search' );
					$table->display();
					?>
				</form>
				<script>
				(function(){
					var debounceTimer = null;
					var fetchController = null;

					function buildUrl(term) {
						var wrap = document.getElementById('dtb-repairs-table-wrap');
						var url = new URL(window.location.href);
						url.searchParams.set('page', wrap ? (wrap.getAttribute('data-page') || 'dtb-repairs') : 'dtb-repairs');
						if (wrap && wrap.getAttribute('data-tab') && wrap.getAttribute('data-tab') !== 'all') {
							url.searchParams.set('tab', wrap.getAttribute('data-tab'));
						} else {
							url.searchParams.delete('tab');
						}
						if (wrap && wrap.getAttribute('data-status')) {
							url.searchParams.set('repair_status', wrap.getAttribute('data-status'));
						} else {
							url.searchParams.delete('repair_status');
						}
						if (term) {
							url.searchParams.set('s', term);
						} else {
							url.searchParams.delete('s');
						}
						url.searchParams.delete('dtb_lookup');
						url.searchParams.set('dtb_live_search', '1');
						return url;
					}

					function syncInputs(term) {
						var searchInput = document.getElementById('dtb-repair-search-input');
						var lookupInput = document.getElementById('dtb_lookup_input');
						if (searchInput && searchInput.value !== term) searchInput.value = term;
						if (lookupInput && lookupInput.value !== term) lookupInput.value = term;
					}

					function attachHandlers() {
						var wrap = document.getElementById('dtb-repairs-table-wrap');
						var searchInput = document.getElementById('dtb-repair-search-input');
						var lookupInput = document.getElementById('dtb_lookup_input');
						var searchForm = document.getElementById('dtb-repair-search-form');
						var lookupForm = document.getElementById('dtb-call-lookup-form');
						if (searchInput) {
							searchInput.setAttribute('placeholder', 'Search customer, email, phone, repair #, order #, serial');
						}
						if (!wrap || !searchInput || !lookupInput) return;

						var triggerLiveSearch = function(term) {
							syncInputs(term);
							if (debounceTimer) window.clearTimeout(debounceTimer);
							debounceTimer = window.setTimeout(function() {
								if (fetchController) {
									fetchController.abort();
								}
								fetchController = new AbortController();
								wrap.classList.add('is-loading');
								fetch(buildUrl(term).toString(), {
									credentials: 'same-origin',
									signal: fetchController.signal
								})
								.then(function(resp){ return resp.text(); })
								.then(function(html){
									var parser = new DOMParser();
									var doc = parser.parseFromString(html, 'text/html');
									var newWrap = doc.getElementById('dtb-repairs-table-wrap');
									if (!newWrap) return;
									wrap.replaceWith(newWrap);
									attachHandlers();
								})
								.catch(function(err){
									if (err && err.name === 'AbortError') return;
								})
								.finally(function(){
									var currentWrap = document.getElementById('dtb-repairs-table-wrap');
									if (currentWrap) currentWrap.classList.remove('is-loading');
								});
							}, 220);
						};

						var onInput = function(ev) {
							var term = (ev.target && ev.target.value ? ev.target.value : '').trim();
							triggerLiveSearch(term);
						};

						searchInput.addEventListener('input', onInput);
						lookupInput.addEventListener('input', onInput);

						if (searchForm) {
							searchForm.addEventListener('submit', function(ev){
								ev.preventDefault();
								triggerLiveSearch((searchInput.value || '').trim());
							});
						}
						if (lookupForm) {
							lookupForm.addEventListener('submit', function(ev){
								ev.preventDefault();
								triggerLiveSearch((lookupInput.value || '').trim());
							});
						}
					}

					attachHandlers();
				}());
				</script>
			</div><!-- .dtb-table-wrap -->

		</div><!-- .dtb-list-shell -->

	</div><!-- .dtb-repairs-wrap -->
	<?php
}

// =============================================================================
// SECTION 5 — WP_LIST_TABLE SUBCLASS
// =============================================================================

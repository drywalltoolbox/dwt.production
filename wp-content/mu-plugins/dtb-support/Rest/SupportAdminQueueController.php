<?php
/**
 * DTB Support — SupportAdminQueueController
 *
 * REST endpoint: GET /dtb/v1/admin/support
 *
 * Returns an HTML fragment (JSON-wrapped) consumed by liveNavigate
 * to refresh the Support live region without a full page reload.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_support_admin_register_routes' );

function dtb_support_admin_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/support', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_admin_queue_handler',
		'permission_callback' => 'dtb_support_read_permission',
		'args'                => [
			'status' => [ 'sanitize_callback' => 'sanitize_key' ],
			'tab'    => [ 'sanitize_callback' => 'sanitize_key' ],
			'queue'  => [ 'sanitize_callback' => 'sanitize_key' ],
			'type'   => [ 'sanitize_callback' => 'sanitize_key' ],
			'priority' => [ 'sanitize_callback' => 'sanitize_key' ],
			's'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'search' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'paged'  => [ 'sanitize_callback' => 'absint' ],
		],
	] );
}

/**
 * Normalize support status aliases from legacy and live controls.
 */
function dtb_support_admin_normalize_status( string $status ): string {
	$status = sanitize_key( $status );

	$status_aliases = [
		'all'         => '',
		'needs_reply' => 'needs-reply',
		'past_sla'    => 'past-sla',
	];

	return $status_aliases[ $status ] ?? $status;
}

/**
 * Resolve support admin queue/status to table-backed ticket query results.
 */
function dtb_support_admin_query_tickets( string $status, string $search, int $paged, int $per, string $queue = '', string $type = '', string $priority = '' ): array {
	$query_args = [
		'search'   => $search,
		'page'     => $paged,
		'per_page' => $per,
		'type'     => sanitize_key( $type ),
		'priority' => sanitize_key( $priority ),
		'order_by' => 'created_at',
		'order'    => 'DESC',
	];
	$queue = sanitize_key( $queue );

	if ( '' !== $queue ) {
		if ( 'closed' === $queue ) {
			$query_args['status'] = 'closed';
			return dtb_support_query_tickets( $query_args );
		}
		return dtb_support_query_queue( $queue, $query_args );
	}

	if ( 'needs-reply' === $status ) {
		return dtb_support_query_queue( 'needs_reply', $query_args );
	}

	if ( 'past-sla' === $status ) {
		return dtb_support_query_queue( 'overdue', $query_args );
	}

	if ( '' === $status || 'open' === $status ) {
		return dtb_support_query_queue( 'all_active', $query_args );
	}

	$query_args['status'] = '' !== $status ? $status : 'all';

	return dtb_support_query_tickets( $query_args );
}

/**
 * Generate 2-letter avatar initials from a display name.
 */
function dtb_support_admin_avatar_initials( string $name ): string {
	$name = trim( $name );
	if ( '' === $name || '—' === $name ) {
		return '?';
	}
	$parts = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $parts ) >= 2 ) {
		return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) );
	}
	return strtoupper( mb_substr( $parts[0], 0, 2 ) );
}

/**
 * Return a human-readable relative time string from a MySQL datetime.
 */
function dtb_support_admin_relative_time( string $datetime ): string {
	if ( '' === $datetime ) {
		return '';
	}
	$timestamp = strtotime( $datetime );
	if ( false === $timestamp ) {
		return '';
	}
	$diff = time() - $timestamp;
	if ( $diff < 90 ) {
		return 'just now';
	}
	return human_time_diff( $timestamp ) . ' ago';
}

/**
 * Map a support ticket type slug to a CSS modifier class.
 */
function dtb_support_admin_type_badge_class( string $type ): string {
	$map = [
		'repair'         => 'dtb-support-type--warning',
		'repair_service' => 'dtb-support-type--warning',
		'warranty'       => 'dtb-support-type--danger',
		'parts'          => 'dtb-support-type--danger',
		'order'          => 'dtb-support-type--primary',
		'billing'        => 'dtb-support-type--primary',
		'shipping'       => 'dtb-support-type--info',
		'general'        => 'dtb-support-type--muted',
	];
	return $map[ $type ] ?? 'dtb-support-type--muted';
}

/**
 * Calculate ticket lifecycle progress (0–100) from status + priority.
 * Used to drive the progress bar in the queue list.
 */
function dtb_support_admin_ticket_progress( string $status, string $priority ): int {
	$base = [
		'open'             => 15,
		'needs-reply'      => 25,
		'needs_reply'      => 25,
		'in_progress'      => 45,
		'pending_customer' => 60,
		'pending_staff'    => 50,
		'snoozed'          => 40,
		'resolved'         => 85,
		'closed'           => 100,
		'spam'             => 5,
	];
	$pct = $base[ $status ] ?? 20;
	// Urgent tickets that are still open get a slight boost to surface urgency visually
	if ( in_array( $priority, [ 'high', 'urgent' ], true ) && $pct < 50 ) {
		$pct = min( 50, $pct + 10 );
	}
	return $pct;
}

/**
 * Return the CSS modifier for the progress bar fill based on ticket state.
 */
function dtb_support_admin_progress_bar_class( string $status, string $priority ): string {
	if ( in_array( $status, [ 'resolved', 'closed', 'deleted' ], true ) ) {
		return 'dtb-support-progress__fill--success';
	}
	if ( 'spam' === $status ) {
		return 'dtb-support-progress__fill--muted';
	}
	if ( 'urgent' === $priority ) {
		return 'dtb-support-progress__fill--danger';
	}
	if ( 'high' === $priority ) {
		return 'dtb-support-progress__fill--warning';
	}
	return 'dtb-support-progress__fill--primary';
}

/**
 * Render support admin queue as a structured list table with progress bars.
 */
function dtb_support_admin_render_queue_markup( array $result, int $paged ): string {
	$tickets     = array_map( 'dtb_support_project_ticket', $result['tickets'] ?? [] );
	$total_pages = max( 1, (int) ( $result['page_count'] ?? 1 ) );

	ob_start();

	if ( empty( $tickets ) ) {
		echo dtb_admin_ui_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'No tickets found', 'drywall-toolbox' ),
			__( 'Try adjusting your filters.', 'drywall-toolbox' )
		);
		return (string) ob_get_clean();
	}

	echo dtb_admin_ui_update_badge( 'dtb-support-workspace' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<div class="dtb-bulk-toolbar" data-dtb-bulk-toolbar data-dtb-bulk-record="support" data-dtb-bulk-endpoint="dtb/v1/support/bulk" data-dtb-bulk-refresh="dtb-support-workspace" data-dtb-bulk-label="<?php esc_attr_e( 'tickets', 'drywall-toolbox' ); ?>" hidden>
		<div class="dtb-bulk-toolbar__summary">
			<span class="dtb-bulk-toolbar__count" data-dtb-bulk-count>0</span>
			<span><?php esc_html_e( 'selected tickets', 'drywall-toolbox' ); ?></span>
		</div>
		<div class="dtb-bulk-toolbar__actions">
			<?php
			echo dtb_admin_ui_button( __( 'Move to Trash', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'type' => 'danger',
				'size' => 'sm',
				'data' => [ 'dtb-bulk-delete' => '1' ],
			] );
			?>
		</div>
	</div>
	<div class="dtb-support-list-wrap">
		<table class="dtb-support-list-table">
			<thead>
				<tr>
					<th class="dtb-support-list-th dtb-support-list-th--check">
						<label class="dtb-support-list-check-all">
							<input type="checkbox" class="dtb-support-row__checkbox" id="dtb-support-select-all" title="Select all" data-dtb-bulk-select-all data-dtb-bulk-record="support">
						</label>
					</th>
					<th class="dtb-support-list-th"><?php esc_html_e( 'Ticket', 'drywall-toolbox' ); ?></th>
					<th class="dtb-support-list-th"><?php esc_html_e( 'Status', 'drywall-toolbox' ); ?></th>
					<th class="dtb-support-list-th"><?php esc_html_e( 'Customer', 'drywall-toolbox' ); ?></th>
					<th class="dtb-support-list-th dtb-support-list-th--progress"><?php esc_html_e( 'Progress', 'drywall-toolbox' ); ?></th>
					<th class="dtb-support-list-th dtb-support-list-th--actions"></th>
				</tr>
			</thead>
			<tbody>
	<?php
	foreach ( $tickets as $ticket ) {
		$id         = (int) ( $ticket['id'] ?? 0 );
		$ticket_ref = (string) ( $ticket['ticket_number'] ?? '' );
		if ( '' === $ticket_ref ) {
			$ticket_ref = '#' . $id;
		}

		$subject      = (string) ( $ticket['subject'] ?? '' );
		$status       = (string) ( $ticket['status'] ?? 'open' );
		$status_label = (string) ( $ticket['status_label'] ?? dtb_support_status_label( $status ) );
		$type         = sanitize_key( $ticket['ticket_type'] ?? $ticket['type'] ?? '' );
		$priority     = sanitize_key( $ticket['priority'] ?? 'normal' );
		$priority_label = ucfirst( $priority );

		$customer_name  = trim( (string) ( $ticket['customer_name'] ?? '' ) );
		$customer_email = trim( (string) ( $ticket['customer_email'] ?? '' ) );
		$customer_display = '' !== $customer_name ? $customer_name : $customer_email;
		if ( '' === $customer_display ) {
			$customer_display = '—';
		}

		$view_url    = (string) ( $ticket['edit_url'] ?? admin_url( 'admin.php?page=dtb-support&ticket_id=' . $id ) );
		$created_raw = (string) ( $ticket['created_at'] ?? '' );
		$rel_time    = dtb_support_admin_relative_time( $created_raw );

		// Progress bar
		$progress_pct   = dtb_support_admin_ticket_progress( $status, $priority );
		$progress_class = dtb_support_admin_progress_bar_class( $status, $priority );

		// Unread (needs attention) state
		$is_unread    = in_array( $status, [ 'open', 'needs-reply', 'needs_reply' ], true );
		$row_class    = 'dtb-support-row dtb-support-list-row' . ( $is_unread ? ' dtb-support-row--unread' : '' );

		// Status badge icon
		$status_icon_map = [
			'open'             => '●',
			'in_progress'      => '▶',
			'pending_customer' => '⏳',
			'pending_staff'    => '⏸',
			'needs-reply'      => '↩',
			'needs_reply'      => '↩',
			'resolved'         => '✓',
			'closed'           => '✗',
			'snoozed'          => '💤',
			'spam'             => '⊘',
		];
		$status_icon = $status_icon_map[ $status ] ?? '●';
		$badge_type  = dtb_admin_ui_status_badge_type( $status );

		// Type badge
		$type_badge_html = '';
		if ( '' !== $type ) {
			$type_label       = ucwords( str_replace( '_', ' ', $type ) );
			$type_badge_class = dtb_support_admin_type_badge_class( $type );
			$type_badge_html  = ' <span class="dtb-support-row__type ' . esc_attr( $type_badge_class ) . '">' . esc_html( $type_label ) . '</span>';
		}

		// Priority flag for high/urgent only
		$priority_html = '';
		if ( in_array( $priority, [ 'high', 'urgent' ], true ) ) {
			$p_class       = 'urgent' === $priority ? 'dtb-support-row__priority--urgent' : 'dtb-support-row__priority--high';
			$priority_html = ' <span class="dtb-support-row__priority ' . esc_attr( $p_class ) . '">' . esc_html( $priority_label ) . '</span>';
		}
		?>
		<tr class="<?php echo esc_attr( $row_class ); ?>"
			data-dtb-ticket-id="<?php echo esc_attr( (string) $id ); ?>"
			data-dtb-ticket-ref="<?php echo esc_attr( $ticket_ref ); ?>"
			data-dtb-ticket-url="<?php echo esc_attr( $view_url ); ?>"
			tabindex="0">

			<td class="dtb-support-list-td dtb-support-list-td--check">
				<label onclick="event.stopPropagation()">
					<input type="checkbox" class="dtb-support-row__checkbox" data-dtb-ticket-id="<?php echo esc_attr( (string) $id ); ?>" data-dtb-bulk-record="support" data-dtb-bulk-id="<?php echo esc_attr( (string) $id ); ?>">
				</label>
			</td>

			<td class="dtb-support-list-td dtb-support-list-td--ticket">
				<div class="dtb-support-list-ref"><?php echo esc_html( $ticket_ref ); ?></div>
				<div class="dtb-support-list-subject">
					<a class="dtb-support-open-ticket"
						data-dtb-ticket-id="<?php echo esc_attr( (string) $id ); ?>"
						data-dtb-ticket-ref="<?php echo esc_attr( $ticket_ref ); ?>"
						data-dtb-ticket-url="<?php echo esc_attr( $view_url ); ?>"
						href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( '' !== $subject ? $subject : '(no subject)' ); ?></a>
					<?php
					echo $type_badge_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $priority_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php if ( '' !== $rel_time ) : ?>
				<div class="dtb-support-list-time"><?php echo esc_html( $rel_time ); ?></div>
				<?php endif; ?>
			</td>

			<td class="dtb-support-list-td dtb-support-list-td--status">
				<?php
				echo dtb_admin_ui_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $status_icon . ' ' . $status_label ),
					$badge_type
				);
				?>
			</td>

			<td class="dtb-support-list-td dtb-support-list-td--customer">
				<div class="dtb-support-list-customer-name"><?php echo esc_html( $customer_display ); ?></div>
				<?php if ( '' !== $customer_name && '' !== $customer_email ) : ?>
				<div class="dtb-support-list-customer-email"><?php echo esc_html( $customer_email ); ?></div>
				<?php endif; ?>
			</td>

			<td class="dtb-support-list-td dtb-support-list-td--progress">
				<div class="dtb-support-list-progress-wrap">
					<div class="dtb-support-list-progress">
						<div class="dtb-support-progress__fill <?php echo esc_attr( $progress_class ); ?>"
							role="progressbar"
							style="width:<?php echo esc_attr( (string) $progress_pct ); ?>%"
							aria-valuenow="<?php echo esc_attr( (string) $progress_pct ); ?>"
							aria-valuemin="0"
							aria-valuemax="100"></div>
					</div>
					<span class="dtb-support-list-progress-pct"><?php echo esc_html( $progress_pct . '%' ); ?></span>
				</div>
			</td>

			<td class="dtb-support-list-td dtb-support-list-td--actions">
				<?php
				echo dtb_admin_ui_button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					__( 'Open', 'drywall-toolbox' ),
					[
						'href'  => $view_url,
						'size'  => 'xs',
						'type'  => 'ghost',
						'class' => 'dtb-support-open-ticket',
						'data'  => [
							'dtb-ticket-id'  => (string) $id,
							'dtb-ticket-ref' => $ticket_ref,
							'dtb-ticket-url' => $view_url,
						],
					]
				);
				?>
			</td>
		</tr>
		<?php
	}
	?>
			</tbody>
		</table>
	</div>
	<?php
	echo dtb_admin_ui_pagination( $paged, $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	return (string) ob_get_clean();
}

function dtb_support_admin_queue_handler( WP_REST_Request $request ): WP_REST_Response {
	$status = sanitize_key( $request->get_param( 'status' ) ?? '' );
	$tab    = sanitize_key( $request->get_param( 'tab' ) ?? '' );
	$queue  = sanitize_key( $request->get_param( 'queue' ) ?? '' );
	$type   = sanitize_key( $request->get_param( 'type' ) ?? '' );
	$priority = sanitize_key( $request->get_param( 'priority' ) ?? '' );
	if ( '' === $status && '' !== $tab ) {
		$status = $tab;
	}
	$status = dtb_support_admin_normalize_status( $status );

	$search = sanitize_text_field( $request->get_param( 's' ) ?? '' );
	if ( '' === $search ) {
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
	}
	$paged  = max( 1, (int) ( $request->get_param( 'paged' ) ?: 1 ) );
	$per    = (int) get_option( 'dtb_admin_items_per_page', 25 );

	$result = dtb_support_admin_query_tickets( $status, $search, $paged, $per, $queue, $type, $priority );
	$html   = dtb_support_admin_render_queue_markup( $result, $paged );

	return new WP_REST_Response( [ 'ok' => true, 'html' => $html ], 200 );
}

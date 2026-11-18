<?php
/**
 * Admin — RepairListTable: WP_List_Table subclass, CPT columns, and search filter.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// WP_List_Table is only available after wp-admin/includes/ is loaded.
// Load it eagerly so the class declaration below can extend it safely
// regardless of which hook triggered this file's require_once.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * DTB Repair List Table
 *
 * @package drywall-toolbox
 */
class DTB_Repair_List_Table extends WP_List_Table {

	/** @var int Total number of items (for pagination). */
	private int $total_items = 0;

	public function __construct() {
		parent::__construct( [
			'singular' => 'repair',
			'plural'   => 'repairs',
			'ajax'     => false,
		] );
	}

	// ---- Columns ---------------------------------------------------------------

	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox">',
			'repair_id'    => __( 'Repair ID', 'drywall-toolbox' ),
			'customer'     => __( 'Customer', 'drywall-toolbox' ),
			'tool'         => __( 'Brand / Model', 'drywall-toolbox' ),
			'status'       => __( 'Status', 'drywall-toolbox' ),
			'wc_order'     => __( 'WC Order', 'drywall-toolbox' ),
			'last_event'   => __( 'Last Update', 'drywall-toolbox' ),
			'date_created' => __( 'Date Created', 'drywall-toolbox' ),
		];
	}

	public function get_sortable_columns(): array {
		return [
			'repair_id'    => [ 'ID', false ],
			'status'       => [ 'status', false ],
			'date_created' => [ 'date', true ],
		];
	}

	// ---- Bulk actions ----------------------------------------------------------

	public function get_bulk_actions(): array {
		return [
			'bulk_cancel'   => __( 'Cancel', 'drywall-toolbox' ),
			'bulk_reviewed' => __( 'Mark Reviewed', 'drywall-toolbox' ),
		];
	}

	public function process_bulk_action(): void {
		// Verify nonce before touching any POST data; bail silently on regular page loads.
		if ( empty( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'bulk-repairs' );

		if ( empty( $_POST['repair'] ) || ! is_array( $_POST['repair'] ) ) {
			return;
		}

		$action     = $this->current_action();
		$repair_ids = array_map( 'absint', (array) $_POST['repair'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$processed  = 0;

		$action_map = [
			'bulk_cancel'   => 'cancelled',
			'bulk_reviewed' => 'reviewed',
		];

		if ( ! isset( $action_map[ $action ] ) ) {
			return;
		}

		$to_status = $action_map[ $action ];

		foreach ( $repair_ids as $rid ) {
			if ( function_exists( 'dtb_transition_repair_status' ) ) {
				$result = dtb_transition_repair_status( $rid, $to_status, [
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'source'     => 'admin_bulk',
				] );
				if ( true === $result ) {
					$processed++;
				}
			}
		}

		if ( $processed > 0 ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'         => 'dtb-repairs',
						'dtb_bulk_msg' => sprintf(
							/* translators: %d: number of repairs updated */
							__( '%d repair(s) updated.', 'drywall-toolbox' ),
							$processed
						),
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	// ---- Data query ------------------------------------------------------------

	public function prepare_items(): void {
		$this->process_bulk_action();

		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$search       = sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = [
			'post_type'      => 'dtb_repair_request',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( '' !== $search ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => '_repair_customer_email',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_customer_name',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_customer_phone',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_wc_order_id',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_model',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_tool_brand',
					'value'   => $search,
					'compare' => 'LIKE',
				],
				[
					'key'     => '_repair_serial',
					'value'   => $search,
					'compare' => 'LIKE',
				],
			];

			$search_digits = preg_replace( '/\D+/', '', $search );
			if ( ! empty( $search_digits ) ) {
				$args['meta_query'][] = [
					'key'     => '_repair_wc_order_id',
					'value'   => $search_digits,
					'compare' => '=',
				];
			}

			if ( preg_match( '/^#?(\d+)$/', trim( $search ), $m ) ) {
				$args['post__in'] = [ (int) $m[1] ];
			}
		}

		// Status filter — supports tab group (IN) and individual chip (=).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_tab    = isset( $_GET['tab'] )           ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )           : 'all';
		$chip_status    = isset( $_GET['repair_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['repair_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $chip_status ) {
			// A specific chip is selected — single-status exact match.
			$args['meta_query'][] = [
				'key'   => '_repair_status',
				'value' => $chip_status,
			];
		} elseif ( 'all' !== $current_tab && function_exists( 'dtb_repair_admin_tab_statuses' ) ) {
			// Tab group — filter to all statuses in that group.
			$tab_statuses = dtb_repair_admin_tab_statuses( $current_tab );
			if ( ! empty( $tab_statuses ) ) {
				$args['meta_query'][] = [
					'key'     => '_repair_status',
					'value'   => $tab_statuses,
					'compare' => 'IN',
				];
			}
		}

		$query             = new WP_Query( $args );
		$this->total_items = (int) $query->found_posts;

		$this->set_pagination_args( [
			'total_items' => $this->total_items,
			'per_page'    => $per_page,
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		$this->items           = $query->posts;
	}

	// ---- Column renderers ------------------------------------------------------

	protected function column_default( $item, $column_name ): string {
		return '—';
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="repair[]" value="%d">', (int) $item->ID );
	}

	protected function column_repair_id( $item ): string {
		$edit_url = admin_url( 'post.php?post=' . (int) $item->ID . '&action=edit' );
		$actions  = [
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'drywall-toolbox' )
			),
		];
		return sprintf(
			'<strong><a href="%s">#%d</a></strong>%s',
			esc_url( $edit_url ),
			(int) $item->ID,
			$this->row_actions( $actions )
		);
	}

	protected function column_customer( $item ): string {
		$name_raw  = (string) get_post_meta( $item->ID, '_repair_customer_name', true );
		$name_norm = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $name_raw ) : $name_raw;
		$name  = esc_html( $name_norm );
		$email = esc_html( (string) get_post_meta( $item->ID, '_repair_customer_email', true ) );
		return $name . ( $email ? '<span class="dtb-cell-sub">' . $email . '</span>' : '' );
	}

	protected function column_tool( $item ): string {
		$brand_raw = (string) get_post_meta( $item->ID, '_repair_tool_brand', true );
		$model_raw = (string) get_post_meta( $item->ID, '_repair_model', true );
		$brand  = esc_html( function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $brand_raw ) : $brand_raw );
		$model  = esc_html( function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $model_raw ) : $model_raw );
		$serial = esc_html( (string) get_post_meta( $item->ID, '_repair_serial', true ) );
		$main   = trim( $brand . ' ' . $model );
		return $main . ( $serial ? '<span class="dtb-cell-sub">S/N: ' . $serial . '</span>' : '' );
	}

	protected function column_status( $item ): string {
		$status = (string) get_post_meta( $item->ID, '_repair_status', true );
		$label  = function_exists( 'dtb_get_repair_status_label' )
			? dtb_get_repair_status_label( $status )
			: esc_html( $status );

		$css_class = 'dtb-status-' . esc_attr( $status );
		return sprintf(
			'<span class="dtb-status-badge %s">%s</span>',
			esc_attr( $css_class ),
			esc_html( $label )
		);
	}

	protected function column_wc_order( $item ): string {
		$order_id = (int) get_post_meta( $item->ID, '_repair_wc_order_id', true );
		if ( ! $order_id ) {
			return '<span class="dtb-empty-dash">—</span>';
		}
		$order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		return sprintf(
			'<a href="%s" class="dtb-strong-link">#%d</a>',
			esc_url( $order_url ),
			$order_id
		);
	}

	protected function column_last_event( $item ): string {
		if ( ! function_exists( 'dtb_repair_get_last_event' ) ) {
			return '—';
		}
		$ev = dtb_repair_get_last_event( $item->ID );
		if ( ! $ev ) {
			return '—';
		}

		if ( 'internal' === (string) ( $ev->visibility ?? '' ) && function_exists( 'dtb_repair_get_events' ) ) {
			$recent_events = array_reverse( (array) dtb_repair_get_events( $item->ID, null, 12 ) );
			foreach ( $recent_events as $candidate ) {
				if ( 'internal' !== (string) ( $candidate->visibility ?? '' ) ) {
					$ev = $candidate;
					break;
				}
			}
		}

		$type_label = function_exists( 'dtb_repair_event_label' )
			? dtb_repair_event_label( (string) $ev->event_type )
			: ucwords( str_replace( [ 'repair.', '.', '_' ], [ '', ' ', ' ' ], (string) $ev->event_type ) );
		$ts         = strtotime( $ev->created_at );
		$ago        = $ts ? human_time_diff( $ts, time() ) . ' ago' : esc_html( $ev->created_at );

		return '<span class="dtb-last-event-type">' . $type_label . '</span>'
			. '<span class="dtb-last-event-time">' . esc_html( $ago ) . '</span>';
	}

	protected function column_date_created( $item ): string {
		$submitted = (string) get_post_meta( $item->ID, '_repair_submitted_at', true );
		$ts        = $submitted ? strtotime( $submitted ) : strtotime( $item->post_date );

		if ( ! $ts ) {
			return '—';
		}

		$date = date_i18n( 'M j, Y', $ts );
		$time = date_i18n( 'g:i a', $ts );

		// Age coloring: warn ≥7 days, critical ≥14 days.
		$days = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
		if ( $days >= 14 ) {
			$age_cls = 'dtb-age-critical';
		} elseif ( $days >= 7 ) {
			$age_cls = 'dtb-age-warn';
		} else {
			$age_cls = 'dtb-age-ok';
		}

		$age_label = $days === 0 ? 'Today' : ( $days === 1 ? '1 day ago' : $days . 'd ago' );

		return '<span class="dtb-date-primary">' . esc_html( $date ) . '</span>'
			. '<span class="dtb-cell-sub">' . esc_html( $time ) . '</span>'
			. '<span class="dtb-cell-sub ' . esc_attr( $age_cls ) . '">' . esc_html( $age_label ) . '</span>';
	}
}

add_filter( 'manage_dtb_repair_request_posts_columns', 'dtb_repair_admin_cpt_columns' );

/**
 * Customize columns for the native WP all-posts screen (fallback, rarely used).
 *
 * @param array $columns
 * @return array
 */
function dtb_repair_admin_cpt_columns( array $columns ): array {
	$new = [
		'cb'          => $columns['cb'],
		'title'       => __( 'Repair', 'drywall-toolbox' ),
		'dtb_status'  => __( 'Status', 'drywall-toolbox' ),
		'dtb_customer'=> __( 'Customer', 'drywall-toolbox' ),
		'dtb_tool'    => __( 'Tool', 'drywall-toolbox' ),
		'date'        => $columns['date'],
	];
	return $new;
}

add_action( 'manage_dtb_repair_request_posts_custom_column', 'dtb_repair_admin_cpt_column_content', 10, 2 );

/**
 * Render custom column content on the native WP post list.
 *
 * @param string $column
 * @param int    $post_id
 */
function dtb_repair_admin_cpt_column_content( string $column, int $post_id ): void {
	switch ( $column ) {
		case 'dtb_status':
			$status = (string) get_post_meta( $post_id, '_repair_status', true );
			$label  = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $status ) : $status;
			echo '<span class="dtb-status-badge dtb-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
			break;
		case 'dtb_customer':
			echo esc_html( (string) get_post_meta( $post_id, '_repair_customer_name', true ) );
			break;
		case 'dtb_tool':
			$brand = (string) get_post_meta( $post_id, '_repair_tool_brand', true );
			$model = (string) get_post_meta( $post_id, '_repair_model', true );
			echo esc_html( $brand . ' ' . $model );
			break;
	}
}

// =============================================================================
// SECTION 10 — ADMIN SEARCH FILTER
// =============================================================================

add_action( 'pre_get_posts', 'dtb_repair_admin_search_filter' );

/**
 * Extend the default WP search to query customer email/name meta for repair CPT.
 *
 * @param WP_Query $query
 */
function dtb_repair_admin_search_filter( WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'dtb_repair_request' !== $query->get( 'post_type' ) ) {
		return;
	}
	$search = (string) $query->get( 's' );
	if ( '' === $search ) {
		return;
	}

	$query->set( 's', '' ); // Clear default title search.
	$query->set(
		'meta_query',
		[
			'relation' => 'OR',
			[
				'key'     => '_repair_customer_email',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_customer_name',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_customer_phone',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_wc_order_id',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_model',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_tool_brand',
				'value'   => $search,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_repair_serial',
				'value'   => $search,
				'compare' => 'LIKE',
			],
		]
	);

	if ( preg_match( '/^#?(\d+)$/', trim( $search ), $m ) ) {
		$query->set( 'post__in', [ (int) $m[1] ] );
	}
}

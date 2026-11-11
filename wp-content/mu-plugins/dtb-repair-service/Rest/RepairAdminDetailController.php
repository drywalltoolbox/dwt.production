<?php
/**
 * DTB Repair Service — RepairAdminDetailController
 *
 * REST endpoint: GET /dtb/v1/admin/repairs/{id}/detail
 *
 * Returns the full workbench contract payload consumed by the
 * dtb-repairs-page.js full-screen modal.  Every field is authoritative
 * (read from the DB / WooCommerce at request time) and includes linked-record
 * data, customer 360 context, audit events, integration state, and workload
 * intelligence from the shared platform Services.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_admin_detail_register_routes' );

// ── Route registration ────────────────────────────────────────────────────────

function dtb_repair_admin_detail_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/repairs/(?P<id>\d+)/detail', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_repair_admin_detail_handler',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
		'args'                => [
			'id' => [
				'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
				'sanitize_callback' => 'absint',
			],
		],
	] );
}

/**
 * Build structured admin workbench actions for repair requests.
 *
 * @param array  $allowed_next Allowed workflow destinations.
 * @param array  $workflow_def Workflow definition.
 * @param array  $perms        Permission flags.
 * @return array<int,array<string,mixed>>
 */
function dtb_repair_admin_build_actions( array $allowed_next, array $workflow_def, array $perms ): array {
	$labels  = (array) ( $workflow_def['labels'] ?? [] );
	$actions = [];

	if ( ! empty( $perms['can_transition'] ) ) {
		foreach ( $allowed_next as $target ) {
			$target = sanitize_key( (string) $target );
			if ( '' === $target ) {
				continue;
			}
			$actions[] = [
				'id'            => 'repair_transition_' . $target,
				'type'          => 'transition',
				'action_type'   => 'transition',
				'target_status' => $target,
				'group'         => 'Workflow',
				'label'         => sprintf(
					/* translators: %s: destination status label. */
					__( 'Move to %s', 'drywall-toolbox' ),
					(string) ( $labels[ $target ] ?? ucwords( str_replace( '_', ' ', $target ) ) )
				),
				'description'   => __( 'Update the repair workflow status and log the operator transition.', 'drywall-toolbox' ),
				'confirm'       => in_array( $target, [ 'cancelled', 'quote_declined', 'closed' ], true ),
			];
		}
	}

	if ( ! empty( $perms['can_assign_technician'] ) ) {
		$actions[] = [
			'id'          => 'technician_assign',
			'type'        => 'form_action',
			'action_type' => 'technician_assign',
			'group'       => 'Production',
			'label'       => __( 'Assign Technician', 'drywall-toolbox' ),
			'description' => __( 'Assign or reassign bench ownership with an internal note.', 'drywall-toolbox' ),
		];
	}
	if ( ! empty( $perms['can_edit_quote'] ) ) {
		$actions[] = [
			'id'          => 'quote_save',
			'type'        => 'form_action',
			'action_type' => 'quote_save',
			'group'       => 'Quote',
			'label'       => __( 'Build / Save Quote', 'drywall-toolbox' ),
			'description' => __( 'Edit labor, parts, shipping, customer note, and internal quote note.', 'drywall-toolbox' ),
		];
		$actions[] = [
			'id'          => 'quote_send',
			'type'        => 'server_action',
			'action_type' => 'quote_send',
			'group'       => 'Quote',
			'label'       => __( 'Send Quote', 'drywall-toolbox' ),
			'description' => __( 'Send the current quote to the customer for approval.', 'drywall-toolbox' ),
			'confirm'     => true,
		];
	}
	if ( ! empty( $perms['can_allocate_parts'] ) ) {
		$actions[] = [
			'id'          => 'parts_allocate',
			'type'        => 'form_action',
			'action_type' => 'parts_allocate',
			'group'       => 'Production',
			'label'       => __( 'Allocate Parts', 'drywall-toolbox' ),
			'description' => __( 'Reserve repair parts, quantities, and fitment notes for the bench.', 'drywall-toolbox' ),
		];
	}
	if ( ! empty( $perms['can_message'] ) ) {
		$actions[] = [
			'id'          => 'customer_message',
			'type'        => 'form_action',
			'action_type' => 'customer_message',
			'group'       => 'Communication',
			'label'       => __( 'Message Customer', 'drywall-toolbox' ),
			'description' => __( 'Send a customer update or request more information.', 'drywall-toolbox' ),
		];
	}
	if ( ! empty( $perms['can_note'] ) ) {
		$actions[] = [
			'id'          => 'internal_note',
			'type'        => 'form_action',
			'action_type' => 'internal_note',
			'group'       => 'Communication',
			'label'       => __( 'Add Internal Note', 'drywall-toolbox' ),
			'description' => __( 'Record operator-only repair context.', 'drywall-toolbox' ),
		];
	}
	if ( ! empty( $perms['can_transition'] ) ) {
		$actions[] = [
			'id'          => 'ready_to_ship',
			'type'        => 'server_action',
			'action_type' => 'ready_to_ship',
			'group'       => 'Shipping',
			'label'       => __( 'Ready to Ship', 'drywall-toolbox' ),
			'description' => __( 'Mark repair completion and move the record into outbound shipping prep.', 'drywall-toolbox' ),
		];
	}
	if ( ! empty( $perms['can_close'] ) ) {
		$actions[] = [
			'id'          => 'close',
			'type'        => 'server_action',
			'action_type' => 'close',
			'group'       => 'Workflow',
			'label'       => __( 'Close Repair', 'drywall-toolbox' ),
			'description' => __( 'Close the repair after completion, cancellation, or final disposition.', 'drywall-toolbox' ),
			'confirm'     => true,
		];
	}

	return $actions;
}

// ── Handler ───────────────────────────────────────────────────────────────────

function dtb_repair_admin_detail_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );

	$post = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return new WP_Error( 'not_found', __( 'Repair not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	// ── Core projection ──
	$proj = function_exists( 'dtb_build_repair_status_projection' )
		? dtb_build_repair_status_projection( $repair_id )
		: [];

	// ── Status / workflow ──
	$status         = (string) get_post_meta( $repair_id, '_repair_status', true ) ?: $post->post_status;
	$allowed_next   = function_exists( 'dtb_admin_get_allowed_workflow_transitions' )
		? dtb_admin_get_allowed_workflow_transitions( 'repair', $status )
		: ( function_exists( 'dtb_get_allowed_transitions' )
			? ( dtb_get_allowed_transitions()[ $status ] ?? [] )
			: [] );
	$workflow_def = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( 'repair' )
		: [];
	$is_terminal    = empty( $allowed_next );

	// ── Quote ──
	$quote = function_exists( 'dtb_repair_get_quote' )
		? dtb_repair_get_quote( $repair_id )
		: [];

	// ── Integration state ──
	$integration = function_exists( 'dtb_admin_get_integration_state' )
		? dtb_admin_get_integration_state( 'repair', $repair_id )
		: ( function_exists( 'dtb_get_repair_integration_state' )
			? dtb_get_repair_integration_state( $repair_id )
			: [] );

	// ── Comments / conversation ──
	$comments_raw = get_comments( [
		'post_id' => $repair_id,
		'status'  => 'approve',
		'orderby' => 'comment_date',
		'order'   => 'ASC',
		'number'  => 200,
	] );

	$conversation = array_values( array_map( fn( $c ) => [
		'id'         => (int) $c->comment_ID,
		'type'       => (string) get_comment_meta( (int) $c->comment_ID, '_dtb_comment_type', true ) ?: 'customer',
		'body'       => wp_kses_post( $c->comment_content ),
		'author'     => esc_html( $c->comment_author ),
		'user_label' => esc_html( $c->comment_author ),
		'created_at' => get_comment_date( 'c', $c ),
		'user_id'    => (int) $c->user_id,
	], $comments_raw ) );

	// ── Customer context ──
	$customer_email = sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
	$customer_user_id = absint( get_post_meta( $repair_id, '_repair_customer_user_id', true ) );
	$wc_order_id = absint( get_post_meta( $repair_id, '_repair_wc_order_id', true ) );
	if ( ! $wc_order_id ) {
		$wc_order_id = absint( get_post_meta( $repair_id, '_repair_order_id', true ) );
	}
	if ( $wc_order_id && function_exists( 'wc_get_order' ) ) {
		$wc_order = wc_get_order( $wc_order_id );
		if ( $wc_order instanceof WC_Order ) {
			if ( ! $customer_email ) {
				$customer_email = sanitize_email( $wc_order->get_billing_email() );
			}
			if ( ! $customer_user_id ) {
				$customer_user_id = absint( $wc_order->get_customer_id() );
			}
		}
	}
	$customer_ctx   = [];
	if ( function_exists( 'dtb_admin_get_customer_context' ) ) {
		$customer_ctx = dtb_admin_get_customer_context( [
			'customer_email'   => $customer_email,
			'customer_user_id' => $customer_user_id,
			'order_id'         => $wc_order_id,
			'exclude_module'   => 'repair',
		] );
		if ( isset( $customer_ctx['lifetime_spend'] ) && ! isset( $customer_ctx['lifetime_value'] ) ) {
			// TODO: remove when all admin JS reads lifetime_spend.
			$customer_ctx['lifetime_value'] = $customer_ctx['lifetime_spend'];
		}
	}

	// ── Linked records ──
	$linked = [];
	if ( function_exists( 'dtb_admin_get_linked_records' ) ) {
		$linked = dtb_admin_get_linked_records( 'repair', $repair_id );
	}

	// ── Workload intelligence ──
	$intel = [];
	if ( function_exists( 'dtb_admin_compute_workload_score' ) ) {
		// Build a record snapshot for the intelligence helpers.
		$intel_record = array_merge( $proj, [
			'id'     => $repair_id,
			'status' => $status,
		] );

		// Collect free-text for sentiment / intent analysis.
		$intel_text = implode( ' ', array_filter( [
			(string) get_post_meta( $repair_id, '_repair_issue', true ),
			(string) get_post_meta( $repair_id, '_repair_customer_name', true ),
		] ) );

		$intel = [
			'age_bucket'      => function_exists( 'dtb_admin_compute_age_bucket' )
				? dtb_admin_compute_age_bucket( $post->post_date ) : '',
			'sla_state'       => function_exists( 'dtb_admin_compute_sla_state' )
				? dtb_admin_compute_sla_state( $post->post_date, $status, 'repair' ) : '',
			'intent_flags'    => function_exists( 'dtb_admin_detect_intent_flags' )
				? dtb_admin_detect_intent_flags( $intel_text ) : [],
			'sentiment_flags' => function_exists( 'dtb_admin_detect_customer_sentiment_flags' )
				? dtb_admin_detect_customer_sentiment_flags( $intel_text ) : [],
			'next_best_action' => function_exists( 'dtb_admin_compute_next_best_action' )
				? dtb_admin_compute_next_best_action( 'repair', $intel_record ) : '',
			'blockers'        => function_exists( 'dtb_admin_compute_blockers' )
				? dtb_admin_compute_blockers( 'repair', $intel_record ) : [],
			'workload_score'  => dtb_admin_compute_workload_score( 'repair', $intel_record ),
		];
	}

	// ── Audit events ──
	$audit_events = [];
	if ( function_exists( 'dtb_admin_audit_get_events' ) ) {
		$audit_events = dtb_admin_audit_get_events( 'repair', $repair_id, 50 );
	}
	$timeline = function_exists( 'dtb_admin_get_timeline' )
		? dtb_admin_get_timeline( 'repair', $repair_id, [ 'events' => $audit_events ] )
		: $audit_events;

	// ── Permissions for this operator ──
	$perms = [
		'can_transition'      => current_user_can( 'dtb_manage_repairs' ) && ! $is_terminal,
		'can_note'            => current_user_can( 'dtb_manage_repairs' ),
		'can_message'         => current_user_can( 'dtb_manage_repairs' ),
		'can_edit_quote'      => current_user_can( 'dtb_manage_repairs' ) && in_array( $status, [ 'reviewed', 'approved', 'quoted' ], true ),
		'can_allocate_parts'  => current_user_can( 'dtb_manage_repairs' ) && in_array( $status, [ 'approved', 'quote_accepted' ], true ),
		'can_assign_technician' => current_user_can( 'dtb_manage_repairs' ) && ! $is_terminal,
		'can_close'           => current_user_can( 'dtb_manage_repairs' ),
	];

	// ── Shipping ──
	$tracking_number = (string) get_post_meta( $repair_id, '_repair_veeqo_tracking', true );
	$shipping = [
		'return_address' => [
			'line1'    => (string) get_post_meta( $repair_id, '_repair_return_address_1', true ),
			'city'     => (string) get_post_meta( $repair_id, '_repair_return_city', true ),
			'state'    => (string) get_post_meta( $repair_id, '_repair_return_state', true ),
			'postcode' => (string) get_post_meta( $repair_id, '_repair_return_postcode', true ),
			'country'  => (string) get_post_meta( $repair_id, '_repair_return_country', true ),
		],
		'rate_name'       => (string) get_post_meta( $repair_id, '_repair_shipping_rate_name', true ),
		'rate_price'      => (float) get_post_meta( $repair_id, '_repair_shipping_rate_price', true ),
		'inbound_method'  => (string) get_post_meta( $repair_id, '_repair_inbound_shipping_method', true ),
		'return_preference' => (string) get_post_meta( $repair_id, '_repair_return_shipping_preference', true ),
		'rate_id'           => (string) get_post_meta( $repair_id, '_repair_shipping_rate_id', true ),
		'tracking_number' => $tracking_number,
		'veeqo_order_id'  => (string) get_post_meta( $repair_id, '_repair_veeqo_order_id', true ),
	];

	$media_refs = [];
	foreach ( [ '_repair_images', '_repair_media_ids', '_repair_attachments', '_repair_uploads', '_repair_files' ] as $media_meta_key ) {
		$raw_values = get_post_meta( $repair_id, $media_meta_key, false );
		foreach ( $raw_values as $raw_value ) {
			$decoded = is_string( $raw_value ) ? json_decode( $raw_value, true ) : null;
			$value   = is_array( $decoded ) ? $decoded : $raw_value;
			if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
				$value = array_map( 'trim', explode( ',', $value ) );
			}
			foreach ( (array) $value as $item ) {
				if ( is_array( $item ) ) {
					$item = $item['id'] ?? $item['attachment_id'] ?? $item['url'] ?? $item['file'] ?? '';
				}
				if ( is_numeric( $item ) || filter_var( (string) $item, FILTER_VALIDATE_URL ) ) {
					$media_refs[] = $item;
				}
			}
		}
	}
	$child_attachment_ids = get_posts( [
		'post_type'      => 'attachment',
		'post_parent'    => $repair_id,
		'post_status'    => 'inherit',
		'fields'         => 'ids',
		'posts_per_page' => 50,
	] );
	$media_refs = array_values( array_unique( array_merge( $media_refs, array_map( 'absint', (array) $child_attachment_ids ) ) ) );
	$media = array_values( array_filter( array_map( static function ( $media_ref ): ?array {
		$attachment_id = absint( $media_ref );
		$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : esc_url_raw( (string) $media_ref );
		if ( ! $url ) {
			return null;
		}

		$thumb = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'thumbnail' ) : null;
		$full  = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'full' ) : null;
		$file  = $attachment_id ? get_attached_file( $attachment_id ) : '';

		return [
			'id'        => $attachment_id,
			'url'       => esc_url_raw( $url ),
			'thumbnail' => esc_url_raw( is_array( $thumb ) && ! empty( $thumb[0] ) ? $thumb[0] : $url ),
			'full'      => esc_url_raw( is_array( $full ) && ! empty( $full[0] ) ? $full[0] : $url ),
			'alt'       => $attachment_id ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '',
			'title'     => $attachment_id ? get_the_title( $attachment_id ) : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ),
			'mime_type' => $attachment_id ? (string) get_post_mime_type( $attachment_id ) : '',
			'filename'  => $file ? wp_basename( $file ) : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ),
		];
	}, $media_refs ) ) );

	$allocated_parts_raw = (string) get_post_meta( $repair_id, '_repair_parts_allocated', true );
	$allocated_parts     = json_decode( $allocated_parts_raw, true );
	if ( ! is_array( $allocated_parts ) ) {
		$allocated_parts = [];
	}
	$allocated_parts = array_values( array_map( static function ( $part ): array {
		return [
			'sku'  => sanitize_text_field( (string) ( $part['sku'] ?? '' ) ),
			'qty'  => absint( $part['qty'] ?? 1 ),
			'note' => sanitize_text_field( (string) ( $part['note'] ?? '' ) ),
		];
	}, $allocated_parts ) );

	// ── Assemble response ──
	$payload = [
		'ok'       => true,
		'record'   => [
			'id'                 => $repair_id,
			'status'             => $status,
			'allowed_next'       => $allowed_next,
			'is_terminal'        => $is_terminal,
			'created_at'         => get_the_date( 'c', $post ),
			'updated_at'         => get_the_modified_date( 'c', $post ),
			'submitted_at'       => (string) get_post_meta( $repair_id, '_repair_submitted_at', true ),
			'source'             => (string) get_post_meta( $repair_id, '_repair_source', true ),
			'submission_ip'      => (string) get_post_meta( $repair_id, '_repair_submission_ip', true ),
			'customer_name'      => (string) get_post_meta( $repair_id, '_repair_customer_name', true ),
			'customer_email'     => $customer_email,
			'customer_phone'     => (string) get_post_meta( $repair_id, '_repair_customer_phone', true ),
			'company'            => (string) get_post_meta( $repair_id, '_repair_company', true ),
			'tool_brand'         => (string) get_post_meta( $repair_id, '_repair_tool_brand', true ),
			'tool_category'      => (string) get_post_meta( $repair_id, '_repair_tool_category', true ),
			'tool_model'         => (string) get_post_meta( $repair_id, '_repair_model', true ),
			'serial_number'      => (string) get_post_meta( $repair_id, '_repair_serial', true ),
			'tool_age'           => (string) get_post_meta( $repair_id, '_repair_tool_age', true ),
			'service_tier'       => (string) get_post_meta( $repair_id, '_repair_service_tier', true ),
			'package_id'         => (string) get_post_meta( $repair_id, '_repair_package_id', true ),
			'approval_mode'      => (string) get_post_meta( $repair_id, '_repair_approval_mode', true ),
			'preapproval_limit'  => (float) get_post_meta( $repair_id, '_repair_preapproval_limit', true ),
			'warranty_requested' => (string) get_post_meta( $repair_id, '_repair_warranty_requested', true ),
			'purchase_date'      => (string) get_post_meta( $repair_id, '_repair_purchase_date', true ),
			'old_parts_return'   => (string) get_post_meta( $repair_id, '_repair_old_parts_return', true ),
			'priority'           => (string) get_post_meta( $repair_id, '_repair_priority', true ),
			'issue_start'        => (string) get_post_meta( $repair_id, '_repair_issue_start', true ),
			'issue_description'  => (string) get_post_meta( $repair_id, '_repair_issue', true ),
			'contact_preference' => (string) get_post_meta( $repair_id, '_repair_contact_preference', true ),
			'wc_order_id'        => $wc_order_id,
			'technician_id'      => (int) get_post_meta( $repair_id, '_repair_technician_id', true ),
		],
		'quote'        => $quote,
		'parts'        => [
			'allocated' => $allocated_parts,
			'count'     => count( $allocated_parts ),
			'source'    => 'repair_meta',
		],
		'shipping'     => $shipping,
		'media'        => [
			'items' => $media,
			'count' => count( $media ),
		],
		'conversation' => $conversation,
		'workflow'     => [
			'key'                 => 'repair',
			'status'              => $status,
			'label'               => (string) ( $workflow_def['labels'][ $status ] ?? ( function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $status ) : $status ) ),
			'all_statuses'        => array_values( (array) ( $workflow_def['statuses'] ?? [] ) ),
			'labels'              => (array) ( $workflow_def['labels'] ?? [] ),
			'terminal_statuses'   => array_values( (array) ( $workflow_def['terminal_statuses'] ?? [] ) ),
			'allowed_transitions' => $allowed_next,
		],
		'linked_records' => $linked,
		'linked'       => $linked, // TODO: remove after repairs JS reads linked_records only.
		'customer'     => $customer_ctx,
		'intelligence' => $intel,
		'intel'        => $intel, // TODO: remove after repairs JS reads intelligence only.
		'integrations' => $integration,
		'integration'  => $integration, // TODO: remove after repairs JS reads integrations only.
		'communication' => [
			'conversation' => $conversation,
			'unread_customer_messages' => (int) get_post_meta( $repair_id, '_repair_customer_unread', true ),
		],
		'timeline'     => $timeline,
		'audit'        => $audit_events, // TODO: remove after repairs JS reads timeline only.
		'actions'      => dtb_repair_admin_build_actions( (array) $allowed_next, $workflow_def, $perms ),
		'permissions'  => $perms,
		'meta'         => [
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'rest_url'    => esc_url_raw( rest_url( 'dtb/v1/admin/repairs/' . $repair_id ) ),
			'fetched_at'  => gmdate( 'c' ),
		],
	];

	if ( function_exists( 'dtb_admin_prepare_workbench_payload' ) ) {
		$payload = dtb_admin_prepare_workbench_payload( $payload );
	}

	return new WP_REST_Response( $payload, 200 );
}

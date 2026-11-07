<?php
/**
 * Services — TicketMacroService: macro rendering and default macro seeding.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the macros table name.
 */
function dtb_support_macros_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'dtb_support_macros';
}

/**
 * Return all active macros ordered by sort_order.
 *
 * @return object[]
 */
function dtb_support_get_macros(): array {
	global $wpdb;
	$table = dtb_support_macros_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, macro_name ASC" );
	return $rows ?: [];
}

/**
 * Return a single macro by ID, or null.
 */
function dtb_support_get_macro( int $macro_id ): ?object {
	global $wpdb;
	$table = dtb_support_macros_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $macro_id ) );
}

/**
 * Build canonical macro token replacements from a support ticket.
 *
 * @return array<string,string>
 */
function dtb_support_macro_replacements( object $ticket ): array {
	$customer_name = function_exists( 'dtb_str_normalize_display' )
		? dtb_str_normalize_display( (string) ( $ticket->customer_name ?? '' ) )
		: trim( (string) ( $ticket->customer_name ?? '' ) );

	$ticket_number = function_exists( 'dtb_str_normalize_display' )
		? dtb_str_normalize_display( (string) ( $ticket->ticket_number ?? '' ) )
		: trim( (string) ( $ticket->ticket_number ?? '' ) );

	$site_name = function_exists( 'dtb_str_normalize_display' )
		? dtb_str_normalize_display( (string) get_bloginfo( 'name' ) )
		: trim( (string) get_bloginfo( 'name' ) );

	return [
		'customer'         => $customer_name,
		'customer_name'    => $customer_name,
		'ticket'           => $ticket_number,
		'ticket_number'    => $ticket_number,
		'order'            => ! empty( $ticket->order_id ) ? '#' . (string) $ticket->order_id : '',
		'order_id'         => ! empty( $ticket->order_id ) ? (string) $ticket->order_id : '',
		'support_email'    => function_exists( 'dtb_support_email_from' ) ? dtb_support_email_from() : (string) get_option( 'admin_email', '' ),
		'site_name'        => $site_name ?: 'Drywall Toolbox',
		'ticket_url'       => function_exists( 'dtb_support_public_status_url' ) ? dtb_support_public_status_url( $ticket ) : home_url( '/support/status/' . (int) ( $ticket->id ?? 0 ) ),
		'admin_ticket_url' => admin_url( 'admin.php?page=dtb-support&ticket_id=' . (int) ( $ticket->id ?? 0 ) ),
	];
}

/**
 * Replace known {{token}} and legacy {token} placeholders.
 */
function dtb_support_replace_macro_tokens( string $template, object $ticket ): string {
	$replacements = dtb_support_macro_replacements( $ticket );
	$replace = static function ( array $matches ) use ( $replacements ): string {
		$key = strtolower( trim( (string) ( $matches[1] ?? '' ) ) );
		return array_key_exists( $key, $replacements ) ? (string) $replacements[ $key ] : '';
	};

	$rendered = preg_replace_callback( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $replace, $template );
	$rendered = preg_replace_callback( '/(?<!\{)\{\s*([a-z0-9_]+)\s*\}(?!\})/i', $replace, (string) $rendered );

	// Clean accidental single-brace wrappers left by legacy display normalisation.
	$rendered = preg_replace( '/\{\s*([^{}\r\n]{2,120})\s*\}/u', '$1', (string) $rendered );
	return (string) $rendered;
}

/**
 * Remove unresolved macro residue and empty boilerplate lines from a rendered macro.
 */
function dtb_support_clean_rendered_macro( string $body ): string {
	$body = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $body, true ) : trim( $body );
	$body = preg_replace( '/[ \t]+([,.!?;:])/u', '$1', (string) $body );
	$body = preg_replace( '/\bYour order\s+is\b/i', 'Your order is', (string) $body );

	$lines = preg_split( '/\R/u', (string) $body ) ?: [];
	$clean = [];
	foreach ( $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			$clean[] = '';
			continue;
		}
		if ( preg_match( '/\{\{.+?\}\}|\{\s*[a-z0-9_]+\s*\}/i', $line ) ) {
			continue;
		}
		if ( preg_match( '/^(you can also review|review the latest details here|ticket:)\s*:?[\s]*$/i', $line ) ) {
			continue;
		}
		$clean[] = $line;
	}

	return trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $clean ) ) );
}

/**
 * Render a macro template, interpolating variables from ticket/order context.
 */
function dtb_support_render_macro( string $template, object $ticket ): string {
	$rendered = dtb_support_replace_macro_tokens( $template, $ticket );
	return dtb_support_clean_rendered_macro( $rendered );
}

/**
 * Create or update a macro.
 *
 * @param array $data Fields: macro_name, category, subject_template, body_template, is_active, sort_order.
 * @param int   $macro_id 0 for new, >0 for update.
 * @return int|WP_Error
 */
function dtb_support_save_macro( array $data, int $macro_id = 0 ): int|WP_Error {
	global $wpdb;
	$table = dtb_support_macros_table();
	$now   = gmdate( 'Y-m-d H:i:s' );
	$existing = $macro_id > 0 ? dtb_support_get_macro( $macro_id ) : null;

	$macro_name_raw = sanitize_text_field( $data['macro_name'] ?? ( $existing->macro_name ?? '' ) );
	$macro_name = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $macro_name_raw ) : $macro_name_raw;
	$body_raw   = wp_kses_post( $data['body_template'] ?? ( $existing->body_template ?? '' ) );
	$body       = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $body_raw, true ) : $body_raw;
	if ( '' === $macro_name || '' === trim( wp_strip_all_tags( $body ) ) ) {
		return new WP_Error( 'dtb_support_invalid_macro', __( 'Macro name and body template are required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$subject_raw = sanitize_text_field( $data['subject_template'] ?? ( $existing->subject_template ?? '' ) );
	$subject_template = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $subject_raw ) : $subject_raw;
	preg_match_all( '/\{\{\s*([a-z0-9_]+)\s*\}\}|(?<!\{)\{\s*([a-z0-9_]+)\s*\}(?!\})/i', $subject_template . "\n" . $body, $matches );
	$variables = array_values( array_unique( array_filter( array_map( 'strtolower', array_merge( $matches[1] ?? [], $matches[2] ?? [] ) ) ) ) );

	$row = [
		'macro_name'       => $macro_name,
		'category'         => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( $data['category'] ?? ( $existing->category ?? 'general' ) ) ) : sanitize_text_field( $data['category'] ?? ( $existing->category ?? 'general' ) ),
		'subject_template' => $subject_template,
		'body_template'    => $body,
		'variables'        => wp_json_encode( $variables ),
		'is_active'        => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : ( isset( $existing->is_active ) ? (int) $existing->is_active : 1 ),
		'sort_order'       => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : ( isset( $existing->sort_order ) ? (int) $existing->sort_order : 0 ),
		'updated_at'       => $now,
	];

	if ( $macro_id > 0 ) {
		$result = $wpdb->update( $table, $row, [ 'id' => $macro_id ] );
		if ( false === $result ) {
			return new WP_Error( 'dtb_support_macro_save_failed', __( 'Could not update macro.', 'drywall-toolbox' ) );
		}
		return $macro_id;
	}

	$row['created_by'] = get_current_user_id() ?: null;
	$row['created_at'] = $now;
	$result            = $wpdb->insert( $table, $row );
	if ( false === $result ) {
		return new WP_Error( 'dtb_support_macro_save_failed', __( 'Could not create macro.', 'drywall-toolbox' ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Deactivate a macro.
 */
function dtb_support_delete_macro( int $macro_id ): bool|WP_Error {
	global $wpdb;
	$table = dtb_support_macros_table();

	if ( ! dtb_support_get_macro( $macro_id ) ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Macro not found.', 'drywall-toolbox' ) );
	}

	$result = $wpdb->update(
		$table,
		[
			'is_active'  => 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		],
		[ 'id' => $macro_id ]
	);

	if ( false === $result ) {
		return new WP_Error( 'dtb_support_macro_delete_failed', __( 'Could not delete macro.', 'drywall-toolbox' ) );
	}

	return true;
}

/**
 * Seed default macros on first install.
 */
function dtb_support_seed_default_macros(): void {
	if ( get_option( 'dtb_support_macros_seeded', false ) ) {
		return;
	}

	$defaults = [
		[ 'shipping', 'Shipping Delay Update', 'Update on your support ticket', "Hi {{customer_name}},\n\nWe wanted to share a quick shipping update. Your order is still in transit, and we are actively monitoring it for you.\n\nThank you,\nDrywall Toolbox Support" ],
		[ 'shipping', 'Order Tracking Update', 'Tracking update for your order', "Hi {{customer_name}},\n\nHere is the latest tracking update for your order. If you need anything else, reply here and our team will help.\n\nRegards,\nDrywall Toolbox Support" ],
		[ 'orders', 'Missing Item', 'We are reviewing the missing item report', "Hi {{customer_name}},\n\nThanks for letting us know about the missing item. We are reviewing the shipment details now and will follow up as soon as we have the next step.\n\nDrywall Toolbox Support" ],
		[ 'orders', 'Wrong Item Received', 'Help with the wrong item received', "Hi {{customer_name}},\n\nWe are sorry the wrong item arrived. We are reviewing the replacement options and will update this support ticket shortly.\n\nDrywall Toolbox Support" ],
		[ 'orders', 'Order Cancellation', 'Order cancellation request received', "Hi {{customer_name}},\n\nWe received your cancellation request. Our team will confirm the next steps shortly.\n\nDrywall Toolbox Support" ],
		[ 'returns', 'Return Request Instructions', 'Return instructions', "Hi {{customer_name}},\n\nWe have logged your return request. Please keep the item and packaging available while we confirm the return steps.\n\nDrywall Toolbox Support" ],
		[ 'returns', 'Refund Request', 'Refund request update', "Hi {{customer_name}},\n\nYour refund request is being reviewed. We will keep this support ticket updated as soon as we verify the order status.\n\nDrywall Toolbox Support" ],
		[ 'product', 'Damaged Product', 'We are reviewing your damaged product report', "Hi {{customer_name}},\n\nWe are sorry to hear the product arrived damaged. This support ticket is now with our team, and we will review the best replacement or resolution option.\n\nDrywall Toolbox Support" ],
		[ 'product', 'Warranty Question', 'Warranty support update', "Hi {{customer_name}},\n\nThanks for contacting Drywall Toolbox about your warranty question. We are reviewing the details and will follow up with the applicable coverage information.\n\nDrywall Toolbox Support" ],
		[ 'product', 'Product Compatibility', 'Compatibility request received', "Hi {{customer_name}},\n\nWe have received your compatibility question. We will review the details and respond with guidance shortly.\n\nDrywall Toolbox Support" ],
		[ 'general', 'General Follow-up', 'Following up', "Hi {{customer_name}},\n\nWe are following up to keep things moving. If you have any additional details to share, reply here and our team will jump back in.\n\nDrywall Toolbox Support" ],
		[ 'general', 'Repair Service Status Update', 'Repair service update', "Hi {{customer_name}},\n\nWe wanted to share a quick repair status update. We are still working through the details and will keep you posted with the next milestone.\n\nDrywall Toolbox Support" ],
	];

	$sort = 10;
	foreach ( $defaults as $row ) {
		dtb_support_save_macro( [
			'category'         => $row[0],
			'macro_name'       => $row[1],
			'subject_template' => $row[2],
			'body_template'    => $row[3],
			'is_active'        => 1,
			'sort_order'       => $sort,
		] );
		$sort += 10;
	}

	update_option( 'dtb_support_macros_seeded', 1, false );
}
add_action( 'plugins_loaded', 'dtb_support_seed_default_macros', 20 );

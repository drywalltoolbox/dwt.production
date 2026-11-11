<?php
/**
 * Admin — RepairMetaBoxes: meta box registration, render callbacks, and AJAX transition handler.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'dtb_repair_admin_add_metaboxes' );

/**
 * Register all metaboxes for the dtb_repair_request CPT edit screen.
 */
function dtb_repair_admin_add_metaboxes(): void {
	$boxes = [
		'dtb-repair-command-center' => [ __( 'Repair Command Center', 'drywall-toolbox' ), 'dtb_repair_metabox_command_center', 'normal', 'high' ],
		'dtb-repair-order-details'  => [ __( 'Repair Order Details', 'drywall-toolbox' ), 'dtb_repair_metabox_order_details', 'normal', 'high' ],
		'dtb-repair-quote-builder'  => [ __( 'Quote Builder', 'drywall-toolbox' ), 'dtb_repair_metabox_quote_builder', 'normal', 'high' ],
		'dtb-repair-technician'     => [ __( 'Technician Workspace', 'drywall-toolbox' ), 'dtb_repair_metabox_technician', 'normal', 'high' ],
		'dtb-repair-timeline'       => [ __( 'Repair Timeline', 'drywall-toolbox' ), 'dtb_repair_metabox_timeline', 'normal', 'default' ],
		'dtb-repair-notes'          => [ __( 'Internal Notes', 'drywall-toolbox' ), 'dtb_repair_metabox_notes', 'normal', 'default' ],
		'dtb-repair-queue'          => [ __( 'Queue Jobs', 'drywall-toolbox' ), 'dtb_repair_metabox_queue', 'side', 'low' ],
	];

	foreach ( $boxes as $id => $args ) {
		add_meta_box(
			$id,
			$args[0],
			$args[1],
			'dtb_repair_request',
			$args[2],
			$args[3]
		);
	}

	// Move WP's native Custom Fields box to the side column.
	remove_meta_box( 'postcustom', 'dtb_repair_request', 'normal' );
	add_meta_box( 'postcustom', __( 'Custom Fields', 'drywall-toolbox' ), 'post_custom_meta_box', 'dtb_repair_request', 'side', 'low' );
}

// ---- Metabox: Customer Details -----------------------------------------------

function dtb_repair_metabox_customer( WP_Post $post ): void {
	$fields = [
		'_repair_customer_name'  => __( 'Name', 'drywall-toolbox' ),
		'_repair_customer_email' => __( 'Email', 'drywall-toolbox' ),
		'_repair_customer_phone' => __( 'Phone', 'drywall-toolbox' ),
	];
	echo '<div class="dtb-repair-metabox"><table>';
	foreach ( $fields as $key => $label ) {
		$value = esc_html( (string) get_post_meta( $post->ID, $key, true ) );
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
	}
	echo '</table></div>';
}

// ---- Metabox: Tool Details ---------------------------------------------------

function dtb_repair_metabox_tool( WP_Post $post ): void {
	$fields = [
		'_repair_tool_brand'   => __( 'Brand', 'drywall-toolbox' ),
		'_repair_model'        => __( 'Model', 'drywall-toolbox' ),
		'_repair_serial'       => __( 'Serial Number', 'drywall-toolbox' ),
		'_repair_service_tier' => __( 'Service Tier', 'drywall-toolbox' ),
	];
	echo '<div class="dtb-repair-metabox"><table>';
	foreach ( $fields as $key => $label ) {
		$value = esc_html( (string) get_post_meta( $post->ID, $key, true ) );
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
	}
	echo '</table></div>';
}

// ---- Metabox: Issue Description ---------------------------------------------

function dtb_repair_metabox_issue( WP_Post $post ): void {
	$issue  = wp_kses_post( (string) get_post_meta( $post->ID, '_repair_issue', true ) );
	$images = json_decode( (string) get_post_meta( $post->ID, '_repair_images', true ), true );

	echo '<div class="dtb-repair-metabox">';
	echo '<p>' . wp_kses_post( $issue ) . '</p>';

	if ( ! empty( $images ) && is_array( $images ) ) {
		echo '<p class="dtb-attachment-label"><strong>' . esc_html__( 'Attached Images:', 'drywall-toolbox' ) . '</strong></p><div class="dtb-attachment-grid">';
		foreach ( $images as $att_id ) {
			$att_id = absint( $att_id );
			$thumb  = wp_get_attachment_image( $att_id, [ 80, 80 ] );
			$url    = wp_get_attachment_url( $att_id );
			if ( $thumb && $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank">' . $thumb . '</a>';
			}
		}
		echo '</div>';
	}
	echo '</div>';
}

// ---- Metabox: Repair Order Details (Unified) -------------------------------

function dtb_repair_metabox_order_details( WP_Post $post ): void {
	$fields = [
		'_repair_tool_brand'   => __( 'Brand', 'drywall-toolbox' ),
		'_repair_model'        => __( 'Model', 'drywall-toolbox' ),
		'_repair_serial'       => __( 'Serial Number', 'drywall-toolbox' ),
		'_repair_service_tier' => __( 'Service Tier', 'drywall-toolbox' ),
		'_repair_wc_order_id'  => __( 'Woo Order ID', 'drywall-toolbox' ),
	];
	$issue  = wp_kses_post( (string) get_post_meta( $post->ID, '_repair_issue', true ) );
	$images = json_decode( (string) get_post_meta( $post->ID, '_repair_images', true ), true );
	$thread_events = dtb_repair_get_customer_message_thread( $post->ID, 120 );
	$thread_alert  = dtb_repair_get_customer_message_alert_state( $post->ID, $thread_events );

	echo '<div class="dtb-repair-metabox"><table>';
	foreach ( $fields as $key => $label ) {
		$value = esc_html( (string) get_post_meta( $post->ID, $key, true ) );
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
	}
	echo '</table>';

	echo '<div class="dtb-issue-summary">';
	echo '<div class="dtb-issue-summary__title">'
		. esc_html__( 'Issue Description', 'drywall-toolbox' ) . '</div>';
	echo '<p class="dtb-issue-summary__text">' . wp_kses_post( $issue ) . '</p>';

	if ( ! empty( $images ) && is_array( $images ) ) {
		echo '<p class="dtb-attachment-label"><strong>' . esc_html__( 'Attached Images:', 'drywall-toolbox' ) . '</strong></p><div class="dtb-attachment-grid">';
		foreach ( $images as $att_id ) {
			$att_id = absint( $att_id );
			$thumb  = wp_get_attachment_image( $att_id, [ 80, 80 ] );
			$url    = wp_get_attachment_url( $att_id );
			if ( $thumb && $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $thumb . '</a>';
			}
		}
		echo '</div>';
	}
	echo '</div>';

	wp_nonce_field( 'dtb_repair_thread_' . $post->ID, 'dtb_repair_thread_nonce' );

	echo '<div class="dtb-repair-chat-card">';
	echo '<div class="dtb-repair-chat-head">';
	echo '<div>';
	echo '<div class="dtb-repair-chat-title">' . esc_html__( 'Customer Conversation', 'drywall-toolbox' ) . '</div>';
	echo '<div class="dtb-repair-chat-subtitle">' . esc_html__( 'Two-way updates shared with the customer status page.', 'drywall-toolbox' ) . '</div>';
	echo '</div>';
	if ( (int) $thread_alert['unread_count'] > 0 ) {
		echo '<span class="dtb-repair-chat-unread-badge">' . esc_html( (string) $thread_alert['unread_count'] ) . ' ' . esc_html__( 'new', 'drywall-toolbox' ) . '</span>';
	}
	echo '</div>';

	if ( (int) $thread_alert['unread_count'] > 0 ) {
		echo '<div class="dtb-repair-chat-alert">'
			. esc_html__( 'Customer sent new message(s). Review and mark as read when handled.', 'drywall-toolbox' )
			. '</div>';
	}

	echo '<div class="dtb-repair-chat-thread" data-repair-id="' . esc_attr( (string) $post->ID ) . '">';
	echo '<div id="dtb-repair-chat-list" class="dtb-repair-chat-list">';
	if ( empty( $thread_events ) ) {
		echo '<p class="dtb-repair-chat-empty">' . esc_html__( 'No customer-facing messages yet.', 'drywall-toolbox' ) . '</p>';
	} else {
		foreach ( $thread_events as $event ) {
			echo dtb_repair_render_customer_message_item( $event, (int) $thread_alert['last_seen_customer_note_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	echo '</div>';

	echo '<div class="dtb-repair-chat-compose">';
	echo '<textarea id="dtb-repair-chat-input" class="dtb-repair-chat-input" maxlength="600" placeholder="'
		. esc_attr__( 'Send an update to the customer…', 'drywall-toolbox' )
		. '"></textarea>';
	echo '<span class="dtb-repair-chat-charcount" id="dtb-repair-chat-charcount" aria-live="polite">0 / 600</span>';
	echo '<div class="dtb-repair-chat-actions">';
	echo '<span id="dtb-repair-chat-msg" class="dtb-repair-chat-msg"></span>';
	if ( (int) $thread_alert['unread_count'] > 0 ) {
		echo '<button type="button" id="dtb-repair-chat-mark-read" class="button">'
			. esc_html__( 'Mark customer messages read', 'drywall-toolbox' )
			. '</button>';
	}
	echo '<button type="button" id="dtb-repair-chat-send" class="button button-primary">'
		. esc_html__( 'Send Update', 'drywall-toolbox' )
		. '</button>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '</div>';

	?>
	<script>
	(function($){
		var $root = $('#dtb-repair-chat-list').closest('.dtb-repair-chat-thread');
		if (!$root.length) return;

		var repairId = Number($root.data('repair-id') || 0);
		var nonce = $('input[name="dtb_repair_thread_nonce"]').val();
		var $list = $('#dtb-repair-chat-list');
		var $input = $('#dtb-repair-chat-input');
		var $msg = $('#dtb-repair-chat-msg');
		var $send = $('#dtb-repair-chat-send');
		var $markRead = $('#dtb-repair-chat-mark-read');

		var MAX_CHARS = 600;
		var $charCount = $('#dtb-repair-chat-charcount');
		$input.on('input', function() {
			var len = ($input.val() || '').length;
			$charCount.text(len + ' / ' + MAX_CHARS);
			$charCount.removeClass('is-near is-limit');
			if (len >= MAX_CHARS) {
				$charCount.addClass('is-limit');
			} else if (len >= MAX_CHARS * 0.8) {
				$charCount.addClass('is-near');
			}
		});

		var esc = function(str){
			return $('<div>').text(String(str || '')).html();
		};

		var setMsg = function(text, cls){
			$msg.removeClass('is-ok is-err').addClass(cls || '').text(text || '');
		};

		$send.on('click', function(){
			var comment = ($input.val() || '').trim();
			if (!comment) {
				setMsg('Please enter a message.', 'is-err');
				return;
			}
			$send.prop('disabled', true);
			setMsg('Sending…', '');

			$.post(ajaxurl, {
				action: 'dtb_repair_send_customer_update',
				repair_id: repairId,
				comment: comment,
				nonce: nonce
			}, function(res){
				$send.prop('disabled', false);
				if (!res || !res.success) {
					setMsg((res && res.data && res.data.message) ? res.data.message : 'Could not send message.', 'is-err');
					return;
				}

				var html = res.data && res.data.html ? String(res.data.html) : '';
				if (html) {
					$list.find('.dtb-repair-chat-empty').remove();
					$list.append(html);
					$list.scrollTop($list.prop('scrollHeight'));
				}

				$input.val('');
				setMsg((res.data && res.data.message) ? res.data.message : 'Update sent.', 'is-ok');
			});
		});

		$markRead.on('click', function(){
			$markRead.prop('disabled', true);
			$.post(ajaxurl, {
				action: 'dtb_repair_mark_customer_messages_read',
				repair_id: repairId,
				nonce: nonce
			}, function(res){
				if (res && res.success) {
					$list.find('.dtb-repair-chat-item.is-unread').removeClass('is-unread');
					$('.dtb-repair-chat-unread-badge').remove();
					$('.dtb-repair-chat-alert').remove();
					$markRead.remove();
					setMsg('Marked as read.', 'is-ok');
				} else {
					$markRead.prop('disabled', false);
					setMsg((res && res.data && res.data.message) ? res.data.message : 'Unable to mark read.', 'is-err');
				}
			});
		});
	})(jQuery);
	</script>
	<?php

	echo '</div>';
}

/**
 * Return customer-visible message-thread events for admin conversation UI.
 *
 * @param int $repair_id
 * @param int $limit
 * @return array<int, object>
 */
function dtb_repair_get_customer_message_thread( int $repair_id, int $limit = 120 ): array {
	if ( ! function_exists( 'dtb_repair_get_events' ) ) {
		return [];
	}

	$events = dtb_repair_get_events( $repair_id, null, $limit );
	$thread = [];

	foreach ( $events as $event ) {
		$event_type = (string) ( $event->event_type ?? '' );
		$visibility = (string) ( $event->visibility ?? '' );
		if ( 'repair.note_added' !== $event_type ) {
			continue;
		}
		if ( ! in_array( $visibility, [ 'customer', 'public' ], true ) ) {
			continue;
		}

		$payload = is_string( $event->payload_json ?? null )
			? json_decode( (string) $event->payload_json, true )
			: [];
		$message = trim( wp_strip_all_tags( (string) ( $payload['note'] ?? '' ) ) );
		$message = function_exists( 'dtb_str_normalize_display' )
			? dtb_str_normalize_display( $message, true )
			: $message;
		if ( '' === $message ) {
			continue;
		}

		$thread[] = $event;
	}

	return $thread;
}

/**
 * Build unread-state metadata for customer messages in the admin thread.
 *
 * @param int               $repair_id
 * @param array<int,object> $thread_events
 * @return array{unread_count:int,last_seen_customer_note_id:int,last_customer_note_id:int}
 */
function dtb_repair_get_customer_message_alert_state( int $repair_id, array $thread_events ): array {
	$last_seen = (int) get_post_meta( $repair_id, '_repair_admin_last_seen_customer_note_id', true );
	$latest_customer_note_id = 0;
	$unread_count = 0;

	foreach ( $thread_events as $event ) {
		$event_id = (int) ( $event->id ?? 0 );
		$actor_type = (string) ( $event->actor_type ?? '' );
		if ( 'customer' !== $actor_type ) {
			continue;
		}
		$latest_customer_note_id = max( $latest_customer_note_id, $event_id );
		if ( $event_id > $last_seen ) {
			$unread_count++;
		}
	}

	return [
		'unread_count'                => $unread_count,
		'last_seen_customer_note_id'  => $last_seen,
		'last_customer_note_id'       => $latest_customer_note_id,
	];
}

/**
 * Render a single message row for the customer conversation thread.
 *
 * @param object $event
 * @param int    $last_seen_customer_note_id
 * @return string
 */
function dtb_repair_render_customer_message_item( object $event, int $last_seen_customer_note_id = 0 ): string {
	$payload = is_string( $event->payload_json ?? null )
		? json_decode( (string) $event->payload_json, true )
		: [];
	$message = trim( wp_strip_all_tags( (string) ( $payload['note'] ?? '' ) ) );
	$message = function_exists( 'dtb_str_normalize_display' )
		? dtb_str_normalize_display( $message, true )
		: $message;
	if ( '' === $message ) {
		return '';
	}

	$event_id = (int) ( $event->id ?? 0 );
	$actor_type = (string) ( $event->actor_type ?? 'system' );
	$is_customer = 'customer' === $actor_type;
	$is_unread = $is_customer && $event_id > $last_seen_customer_note_id;
	$author = $is_customer ? __( 'Customer', 'drywall-toolbox' ) : __( 'DTB Team', 'drywall-toolbox' );
	$created_at = (string) ( $event->created_at ?? '' );
	$time_fmt = $created_at ? date_i18n( 'M j, Y g:i a', strtotime( $created_at ) ) : '';

	$classes = [ 'dtb-repair-chat-item', $is_customer ? 'from-customer' : 'from-admin' ];
	if ( $is_unread ) {
		$classes[] = 'is-unread';
	}

	$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-event-id="' . esc_attr( (string) $event_id ) . '">';
	$html .= '<div class="dtb-repair-chat-bubble">';
	$html .= '<div class="dtb-repair-chat-meta">';
	$html .= '<span class="dtb-repair-chat-author">' . esc_html( $author ) . '</span>';
	if ( $is_unread ) {
		$html .= '<span class="dtb-repair-chat-pill">' . esc_html__( 'new', 'drywall-toolbox' ) . '</span>';
	}
	if ( '' !== $time_fmt ) {
		$html .= '<span class="dtb-repair-chat-time">' . esc_html( $time_fmt ) . '</span>';
	}
	$html .= '</div>';
	$html .= '<div class="dtb-repair-chat-text">' . esc_html( $message ) . '</div>';
	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

// ---- Metabox: Quote Builder --------------------------------------------------

function dtb_repair_metabox_quote_builder( WP_Post $post ): void {
	if ( ! function_exists( 'dtb_repair_get_quote' ) ) {
		echo '<p class="dtb-muted-message">' . esc_html__( 'Quote service unavailable.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	$quote          = dtb_repair_get_quote( $post->ID );
	$current_status = function_exists( 'dtb_get_repair_status' ) ? dtb_get_repair_status( $post->ID ) : '';
	$allowed        = function_exists( 'dtb_get_allowed_transitions' ) ? ( dtb_get_allowed_transitions()[ $current_status ] ?? [] ) : [];
	$can_send       = in_array( 'quoted', $allowed, true ) || 'quoted' === $current_status;
	$can_accept     = in_array( 'quote_accepted', $allowed, true ) || 'quote_accepted' === $current_status;
	$can_decline    = in_array( 'quote_declined', $allowed, true ) || 'quote_declined' === $current_status;
	$status_label   = function_exists( 'dtb_repair_quote_status_label' )
		? dtb_repair_quote_status_label( (string) ( $quote['status'] ?? 'draft' ) )
		: __( 'Draft', 'drywall-toolbox' );
	$parts_lookup_nonce = wp_create_nonce( 'dtb_repair_parts_lookup' );
	$tech_parts = get_post_meta( $post->ID, '_repair_parts_links', true );
	$tech_parts = is_array( $tech_parts ) ? $tech_parts : [];

	wp_nonce_field( 'dtb_repair_quote_' . $post->ID, 'dtb_repair_quote_nonce' );
	?>
	<div id="dtb-repair-quote-builder" class="dtb-quote-builder" data-repair-id="<?php echo esc_attr( (string) $post->ID ); ?>">
		<div class="dtb-quote-head">
			<div class="dtb-quote-head-main">
				<div class="dtb-quote-title"><?php esc_html_e( 'Repair Quote', 'drywall-toolbox' ); ?></div>
				<div class="dtb-quote-subtitle"><?php esc_html_e( 'Build line items, calculate totals, and send to customer.', 'drywall-toolbox' ); ?></div>
			</div>
			<div class="dtb-quote-head-status">
				<span class="dtb-quote-pill"><?php echo esc_html( $status_label ); ?></span>
			</div>
		</div>

		<div class="dtb-quote-controls">
			<label>
				<span><?php esc_html_e( 'Currency', 'drywall-toolbox' ); ?></span>
				<input type="text" id="dtb-quote-currency" maxlength="6" value="<?php echo esc_attr( (string) ( $quote['currency'] ?? dtb_repair_quote_default_currency() ) ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Expires', 'drywall-toolbox' ); ?></span>
				<input type="datetime-local" id="dtb-quote-expires-at" value="<?php echo esc_attr( ! empty( $quote['expires_at'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( (string) $quote['expires_at'] ) ) : '' ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Discount %', 'drywall-toolbox' ); ?></span>
				<input type="number" min="0" max="100" step="0.01" id="dtb-quote-discount-percent" value="<?php echo esc_attr( (string) ( $quote['totals']['discount_percent'] ?? 0 ) ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Tax %', 'drywall-toolbox' ); ?></span>
				<input type="number" min="0" max="100" step="0.01" id="dtb-quote-tax-percent" value="<?php echo esc_attr( (string) ( $quote['totals']['tax_percent'] ?? 0 ) ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Shipping', 'drywall-toolbox' ); ?></span>
				<input type="number" min="0" step="0.01" id="dtb-quote-shipping" value="<?php echo esc_attr( (string) ( $quote['totals']['shipping_amount'] ?? 0 ) ); ?>" />
			</label>
		</div>

		<div class="dtb-quote-workspace">
			<div class="dtb-quote-table-wrap">
				<table class="dtb-quote-table">
					<thead>
						<tr><th><?php esc_html_e( 'Line Items', 'drywall-toolbox' ); ?></th></tr>
					</thead>
					<tbody id="dtb-quote-lines"></tbody>
				</table>
				<button type="button" id="dtb-quote-add-line" class="button"><?php esc_html_e( 'Add Line Item', 'drywall-toolbox' ); ?></button>
			</div>
			<aside class="dtb-quote-parts-panel">
				<div class="dtb-quote-parts-panel__title"><?php esc_html_e( 'Parts Lookup', 'drywall-toolbox' ); ?></div>
				<p class="dtb-quote-parts-panel__help"><?php esc_html_e( 'Search your parts catalog and add items directly into quote lines.', 'drywall-toolbox' ); ?></p>
				<label class="dtb-quote-parts-label" for="dtb-quote-parts-lookup"><?php esc_html_e( 'Search Parts', 'drywall-toolbox' ); ?></label>
				<input id="dtb-quote-parts-lookup" type="text" class="dtb-tech-input" value="" placeholder="<?php esc_attr_e( 'Search by SKU, title, or brand...', 'drywall-toolbox' ); ?>" autocomplete="off" data-lookup-nonce="<?php echo esc_attr( $parts_lookup_nonce ); ?>" />
				<div id="dtb-quote-parts-menu" class="dtb-tech-lookup-menu dtb-quote-parts-menu" role="listbox" aria-label="<?php esc_attr_e( 'Quote parts lookup results', 'drywall-toolbox' ); ?>" hidden></div>
				<div class="dtb-quote-parts-selected-head"><?php esc_html_e( 'Technician Selected Parts', 'drywall-toolbox' ); ?></div>
				<div id="dtb-quote-parts-selected" class="dtb-quote-parts-selected"></div>
				<div class="dtb-quote-parts-selected-head"><?php esc_html_e( 'Recently Used Parts', 'drywall-toolbox' ); ?></div>
				<div id="dtb-quote-parts-recent" class="dtb-quote-parts-selected"></div>
			</aside>
		</div>

		<div class="dtb-quote-notes-grid">
			<label>
				<span><?php esc_html_e( 'Customer Note', 'drywall-toolbox' ); ?></span>
				<textarea id="dtb-quote-customer-note" placeholder="<?php esc_attr_e( 'Included in quote email…', 'drywall-toolbox' ); ?>"><?php echo esc_textarea( (string) ( $quote['customer_note'] ?? '' ) ); ?></textarea>
			</label>
			<label>
				<span><?php esc_html_e( 'Internal Quote Note', 'drywall-toolbox' ); ?></span>
				<textarea id="dtb-quote-internal-note" placeholder="<?php esc_attr_e( 'Internal context for your team…', 'drywall-toolbox' ); ?>"><?php echo esc_textarea( (string) ( $quote['internal_note'] ?? '' ) ); ?></textarea>
			</label>
		</div>

		<div class="dtb-quote-footer">
			<div class="dtb-quote-totals" id="dtb-quote-totals"></div>
			<div class="dtb-quote-actions">
				<span id="dtb-quote-msg" class="dtb-quote-msg"></span>
				<button type="button" id="dtb-quote-save" class="button"><?php esc_html_e( 'Save Draft', 'drywall-toolbox' ); ?></button>
				<button type="button" id="dtb-quote-send" class="button button-primary" <?php disabled( ! $can_send ); ?>><?php esc_html_e( 'Send Quote', 'drywall-toolbox' ); ?></button>
				<button type="button" id="dtb-quote-accept" class="button" <?php disabled( ! $can_accept ); ?>><?php esc_html_e( 'Mark Accepted', 'drywall-toolbox' ); ?></button>
				<button type="button" id="dtb-quote-decline" class="button" <?php disabled( ! $can_decline ); ?>><?php esc_html_e( 'Mark Declined', 'drywall-toolbox' ); ?></button>
			</div>
		</div>
	</div>
	<script>
	(function($){
		var $root = $('#dtb-repair-quote-builder');
		if (!$root.length) return;

		var repairId = Number($root.data('repair-id') || 0);
		var nonce = $('input[name="dtb_repair_quote_nonce"]').val();
		var quote = <?php echo wp_json_encode( $quote ); ?> || {};
		var technicianParts = <?php echo wp_json_encode( $tech_parts ); ?> || [];
		var lines = Array.isArray(quote.lines) ? quote.lines.slice() : [];

		var $msg = $('#dtb-quote-msg');
		var $lineTbody = $('#dtb-quote-lines');
		var $totals = $('#dtb-quote-totals');
		var $partsLookupInput = $('#dtb-quote-parts-lookup');
		var $partsLookupMenu = $('#dtb-quote-parts-menu');
		var $partsSelected = $('#dtb-quote-parts-selected');
		var $partsRecent = $('#dtb-quote-parts-recent');
		var lookupTimer = null;
		var lookupReq = null;
		var autosaveTimer = null;
		var autosaveReq = null;
		var autosaveInFlight = false;
		var pendingAutosave = false;
		var lastSavedHash = '';
		var RECENT_PARTS_KEY = 'dtbRepairRecentParts.v1';
		var recentParts = [];

		var esc = function(v){ return $('<div>').text(String(v || '')).html(); };
		var toNum = function(v){ var n = parseFloat(v); return isNaN(n) ? 0 : n; };
		var money = function(n){ return (Math.round((toNum(n) + Number.EPSILON) * 100) / 100).toFixed(2); };
		var currency = function(){ return ($('#dtb-quote-currency').val() || 'USD').toString().toUpperCase(); };

		var setMsg = function(text, cls){
			$msg.removeClass('is-ok is-err').text(text || '');
			if (cls) $msg.addClass(cls);
		};

		var uniquePartKey = function(part){
			if (!part) return '';
			var id = parseInt(part.part_id || 0, 10) || 0;
			if (id > 0) return 'id:' + id;
			var sku = (part.sku || '').toString().trim().toLowerCase();
			if (sku) return 'sku:' + sku;
			var name = (part.name || '').toString().trim().toLowerCase();
			return name ? ('name:' + name) : '';
		};

		var loadRecentParts = function(){
			try {
				var raw = window.localStorage.getItem(RECENT_PARTS_KEY);
				var parsed = raw ? JSON.parse(raw) : [];
				return Array.isArray(parsed) ? parsed : [];
			} catch (e) {
				return [];
			}
		};

		var saveRecentParts = function(){
			try {
				window.localStorage.setItem(RECENT_PARTS_KEY, JSON.stringify(recentParts.slice(0, 16)));
			} catch (e) {}
		};

		var normalizePart = function(part){
			if (!part) return null;
			var qty = Math.max(1, parseInt(part.quantity || 1, 10) || 1);
			var price = Math.max(0, toNum(part.unit_price || part.price || 0));
			return {
				part_id: parseInt(part.part_id || 0, 10) || 0,
				sku: (part.sku || '').toString(),
				name: (part.name || '').toString(),
				brand_label: (part.brand_label || '').toString(),
				manufacturer_sku: (part.manufacturer_sku || '').toString(),
				line_note: (part.line_note || '').toString(),
				quantity: qty,
				unit_price: price
			};
		};

		var rememberRecentPart = function(part){
			var normalized = normalizePart(part);
			if (!normalized) return;
			var key = uniquePartKey(normalized);
			if (!key) return;
			recentParts = recentParts.filter(function(item){
				return uniquePartKey(item) !== key;
			});
			recentParts.unshift(normalized);
			recentParts = recentParts.slice(0, 16);
			saveRecentParts();
			renderRecentParts();
			document.dispatchEvent(new CustomEvent('dtb:parts:recentUpdated', {
				detail: { parts: recentParts.slice() }
			}));
		};

		var mergeTechnicianPart = function(part){
			var normalized = normalizePart(part);
			if (!normalized) return;
			var foundIndex = -1;
			technicianParts.forEach(function(item, idx){
				if (foundIndex !== -1) return;
				var samePartId = normalized.part_id > 0 && parseInt(item.part_id || 0, 10) === normalized.part_id;
				var sameSku = normalized.sku && String(item.sku || '') === normalized.sku;
				if (samePartId || sameSku) foundIndex = idx;
			});
			if (foundIndex === -1) {
				technicianParts.push(normalized);
			} else {
				technicianParts[foundIndex] = $.extend({}, technicianParts[foundIndex], normalized);
			}
		};

		var partSecondaryText = function(part){
			var chunks = [];
			if (part.brand_label) chunks.push(part.brand_label);
			if (part.manufacturer_sku) chunks.push('MFG: ' + part.manufacturer_sku);
			if (toNum(part.unit_price) > 0) chunks.push(currency() + ' ' + money(part.unit_price));
			return chunks.join(' · ');
		};

		var renderPartButton = function(item, className){
			var title = (item.sku || 'No SKU') + ' — ' + (item.name || 'Part');
			return '<button type="button" class="' + className + '" data-part-id="' + esc(item.part_id || '') + '" data-sku="' + esc(item.sku || '') + '" data-name="' + esc(item.name || '') + '" data-brand="' + esc(item.brand_label || '') + '" data-manufacturer-sku="' + esc(item.manufacturer_sku || '') + '" data-qty="' + esc(item.quantity || 1) + '" data-unit-price="' + esc(item.unit_price || 0) + '" data-line-note="' + esc(item.line_note || '') + '"><span class="dtb-quote-selected-part__title">' + esc(title) + '</span><span class="dtb-quote-selected-part__sub">' + esc(partSecondaryText(item) || 'Parts library item') + '</span></button>';
		};

		var renderSelectedParts = function(){
			if (!$partsSelected.length) return;
			if (!Array.isArray(technicianParts) || !technicianParts.length) {
				$partsSelected.html('<div class="dtb-tech-selected-empty">No parts selected yet in technician workspace.</div>');
				return;
			}
			$partsSelected.html(technicianParts.slice(0, 12).map(function(item){
				return renderPartButton(item, 'dtb-quote-selected-part');
			}).join(''));
		};

		var renderRecentParts = function(){
			if (!$partsRecent.length) return;
			if (!Array.isArray(recentParts) || !recentParts.length) {
				$partsRecent.html('<div class="dtb-tech-selected-empty">No recently used parts yet.</div>');
				return;
			}
			$partsRecent.html(recentParts.slice(0, 12).map(function(item){
				return renderPartButton(item, 'dtb-quote-selected-part dtb-quote-selected-part-recent');
			}).join(''));
		};

		var upsertPartLine = function(part){
			var normalized = normalizePart(part);
			if (!normalized) return;
			var keySku = normalized.sku;
			var partNote = normalized.line_note.trim();
			var partDescription = '';
			if (keySku) partDescription = 'SKU: ' + keySku;
			if (normalized.manufacturer_sku) {
				partDescription = partDescription ? (partDescription + ' | MFG: ' + normalized.manufacturer_sku) : ('MFG: ' + normalized.manufacturer_sku);
			}
			if (partNote) partDescription = partDescription ? (partDescription + ' | ' + partNote) : partNote;
			var foundIndex = -1;
			lines.forEach(function(line, idx){
				if (foundIndex !== -1) return;
				var desc = String(line.description || '');
				if (keySku && desc.indexOf('SKU: ' + keySku) !== -1) foundIndex = idx;
			});

			if (foundIndex === -1) {
				lines.push({
					label: normalized.name || normalized.sku || 'Part',
					description: partDescription,
					type: 'part',
					quantity: normalized.quantity,
					unit_price: normalized.unit_price
				});
			} else {
				var q = normalized.quantity;
				lines[foundIndex].quantity = q;
				if (!lines[foundIndex].label && normalized.name) lines[foundIndex].label = normalized.name;
				if (toNum(lines[foundIndex].unit_price) <= 0 && normalized.unit_price > 0) {
					lines[foundIndex].unit_price = normalized.unit_price;
				}
				if ((!lines[foundIndex].description || String(lines[foundIndex].description).indexOf('SKU: ' + keySku) !== -1) && partDescription) {
					lines[foundIndex].description = partDescription;
				}
			}
			mergeTechnicianPart(normalized);
			rememberRecentPart(normalized);
			render();
			renderSelectedParts();
			scheduleAutosave(true);
		};

		var hidePartsLookupMenu = function(){
			if (!$partsLookupMenu.length) return;
			$partsLookupMenu.prop('hidden', true).html('');
		};

		var renderPartsLookupMenu = function(items){
			if (!$partsLookupMenu.length) return;
			if (!items || !items.length) {
				hidePartsLookupMenu();
				return;
			}
			$partsLookupMenu.html(items.map(function(item){
				var primary = (item.sku || 'No SKU') + ' — ' + (item.name || 'Part');
				var chunks = [];
				if (item.brand_label) chunks.push(item.brand_label);
				if (item.manufacturer_sku) chunks.push('MFG: ' + item.manufacturer_sku);
				if (toNum(item.unit_price) > 0) chunks.push(currency() + ' ' + money(item.unit_price));
				var secondary = chunks.join(' · ');
				return (
					'<button type="button" class="dtb-tech-lookup-option dtb-quote-parts-option" ' +
					'data-part-id="' + esc(item.part_id || 0) + '" ' +
					'data-sku="' + esc(item.sku || '') + '" ' +
					'data-name="' + esc(item.name || '') + '" ' +
					'data-brand="' + esc(item.brand_label || '') + '" ' +
					'data-manufacturer-sku="' + esc(item.manufacturer_sku || '') + '" ' +
					'data-unit-price="' + esc(item.unit_price || 0) + '">' +
						'<span class="dtb-tech-lookup-primary">' + esc(primary) + '</span>' +
						'<span class="dtb-tech-lookup-secondary">' + esc(secondary || 'Parts library item') + '</span>' +
					'</button>'
				);
			}).join('')).prop('hidden', false);
		};

		var TYPE_CONFIG = {
			service: {
				defaultLabel: 'Service',
				labelPlaceholder: 'Service name',
				descPlaceholder: 'Service scope and what is included',
				qtyLabel: 'Qty',
				unitLabel: 'Flat Fee',
				qtyStep: 1,
				qtyMin: 1,
				lockQtyToOne: true,
				presets: [{label: 'Diagnostic Service', qty: 1}, {label: 'Bench Inspection', qty: 1}]
			},
			labor: {
				defaultLabel: 'Labor',
				labelPlaceholder: 'Labor task',
				descPlaceholder: 'Labor notes (time breakdown, complexity)',
				qtyLabel: 'Hours',
				unitLabel: 'Rate / Hr',
				qtyStep: 0.25,
				qtyMin: 0.25,
				lockQtyToOne: false,
				presets: [{label: 'Repair Labor', qty: 1}, {label: 'Advanced Diagnostic', qty: 1.5}]
			},
			part: {
				defaultLabel: 'Part',
				labelPlaceholder: 'Part name or SKU',
				descPlaceholder: 'Auto-filled SKU, fitment, and install notes',
				qtyLabel: 'Units',
				unitLabel: 'Unit Cost',
				qtyStep: 1,
				qtyMin: 1,
				lockQtyToOne: false,
				presets: []
			},
			shipping: {
				defaultLabel: 'Shipping',
				labelPlaceholder: 'Shipping method',
				descPlaceholder: 'Carrier/service notes',
				qtyLabel: 'Qty',
				unitLabel: 'Shipping Cost',
				qtyStep: 1,
				qtyMin: 1,
				lockQtyToOne: true,
				presets: [{label: 'Ground Shipping', qty: 1}, {label: 'Expedited Shipping', qty: 1}]
			},
			misc: {
				defaultLabel: 'Misc',
				labelPlaceholder: 'Miscellaneous charge',
				descPlaceholder: 'Reason for this charge',
				qtyLabel: 'Qty',
				unitLabel: 'Unit Cost',
				qtyStep: 1,
				qtyMin: 1,
				lockQtyToOne: false,
				presets: [{label: 'Shop Supplies', qty: 1}, {label: 'Disposal / Handling', qty: 1}]
			}
		};

		var typeConfig = function(type){
			return TYPE_CONFIG[type] || TYPE_CONFIG.service;
		};

		var rowHtml = function(line, idx){
			var type = (line.type || 'service').toString();
			var cfg = typeConfig(type);
			var qty = Math.max(0.001, toNum(line.quantity || 1));
			if (cfg.lockQtyToOne) qty = 1;
			var unit = toNum(line.unit_price || 0);
			var total = qty * unit;
			return '' +
				'<tr data-index="' + idx + '" data-type="' + esc(type) + '">' +
					'<td class="dtb-ql-item-cell">' +
						'<div class="dtb-ql-card">' +
							'<div class="dtb-ql-card-top">' +
								'<div class="dtb-ql-card-fields">' +
									'<input type="text" class="dtb-quote-line-label dtb-ql-label" value="' + esc(line.label || '') + '" placeholder="' + esc(cfg.labelPlaceholder) + '" />' +
									'<input type="text" class="dtb-quote-line-desc dtb-ql-desc" value="' + esc(line.description || '') + '" placeholder="' + esc(cfg.descPlaceholder) + '" />' +
								'</div>' +
								'<select class="dtb-quote-line-type dtb-ql-type">' +
									'<option value="service"' + (type === 'service' ? ' selected' : '') + '>Service</option>' +
									'<option value="labor"' + (type === 'labor' ? ' selected' : '') + '>Labor</option>' +
									'<option value="part"' + (type === 'part' ? ' selected' : '') + '>Part</option>' +
									'<option value="shipping"' + (type === 'shipping' ? ' selected' : '') + '>Shipping</option>' +
									'<option value="misc"' + (type === 'misc' ? ' selected' : '') + '>Misc</option>' +
								'</select>' +
								'<button type="button" class="dtb-ql-remove dtb-quote-line-remove" aria-label="Remove line">×</button>' +
							'</div>' +
							'<div class="dtb-ql-card-bottom">' +
								'<div class="dtb-ql-num-field">' +
									'<span class="dtb-quote-field-caption">' + esc(cfg.qtyLabel) + '</span>' +
									'<input type="number" min="' + esc(cfg.qtyMin) + '" step="' + esc(cfg.qtyStep) + '" class="dtb-quote-line-qty dtb-ql-num" value="' + esc(qty) + '" />' +
								'</div>' +
								'<div class="dtb-ql-num-field">' +
									'<span class="dtb-quote-field-caption">' + esc(cfg.unitLabel) + '</span>' +
									'<input type="number" min="0" step="0.01" class="dtb-quote-line-unit dtb-ql-num" value="' + esc(unit) + '" />' +
								'</div>' +
								'<div class="dtb-ql-total">' +
									'<span class="dtb-quote-field-caption">Line Total</span>' +
									'<span class="dtb-quote-line-total">' + esc(currency() + ' ' + money(total)) + '</span>' +
								'</div>' +
								'<div class="dtb-ql-presets"><div class="dtb-quote-line-presets"></div></div>' +
							'</div>' +
						'</div>' +
					'</td>' +
				'</tr>';
		};

		var applyTypePresentation = function($tr){
			if (!$tr || !$tr.length) return;
			var type = ($tr.find('.dtb-quote-line-type').val() || 'service').toString();
			var cfg = typeConfig(type);
			var $label = $tr.find('.dtb-quote-line-label');
			var $desc = $tr.find('.dtb-quote-line-desc');
			var $qty = $tr.find('.dtb-quote-line-qty');
			var $unit = $tr.find('.dtb-quote-line-unit');
			var $caps = $tr.find('.dtb-quote-field-caption');
			var $presetWrap = $tr.find('.dtb-quote-line-presets');
			var labelVal = ($label.val() || '').toString().trim();

			$tr.attr('data-type', type);
			$label.attr('placeholder', cfg.labelPlaceholder || 'Item name');
			$desc.attr('placeholder', cfg.descPlaceholder || 'Description (optional)');
			$qty.attr('min', cfg.qtyMin || 0.001).attr('step', cfg.qtyStep || 0.001);
			$qty.prop('readonly', !!cfg.lockQtyToOne);
			$qty.toggleClass('is-readonly', !!cfg.lockQtyToOne);
			if (cfg.lockQtyToOne && toNum($qty.val()) !== 1) {
				$qty.val('1');
			}

			if ($caps.length >= 2) {
				$tr.find('.dtb-ql-num-field').eq(0).find('.dtb-quote-field-caption').text(cfg.qtyLabel || 'Qty');
				$tr.find('.dtb-ql-num-field').eq(1).find('.dtb-quote-field-caption').text(cfg.unitLabel || 'Unit');
			}

			if (!labelVal && cfg.defaultLabel) {
				$label.val(cfg.defaultLabel);
			}

			if ($presetWrap.length) {
				if (!Array.isArray(cfg.presets) || !cfg.presets.length) {
					$presetWrap.html('');
				} else {
					$presetWrap.html(cfg.presets.map(function(preset){
						return '<button type="button" class="dtb-quote-preset" data-preset-label="' + esc(preset.label || '') + '" data-preset-qty="' + esc(preset.qty || '') + '">' + esc(preset.label || 'Preset') + '</button>';
					}).join(''));
				}
			}
		};

		var collectLines = function(){
			var rows = [];
			$lineTbody.find('tr').each(function(){
				var $tr = $(this);
				var label = ($tr.find('.dtb-quote-line-label').val() || '').toString().trim();
				var desc = ($tr.find('.dtb-quote-line-desc').val() || '').toString().trim();
				var type = ($tr.find('.dtb-quote-line-type').val() || 'service').toString();
				var qty = toNum($tr.find('.dtb-quote-line-qty').val() || 1);
				var unit = toNum($tr.find('.dtb-quote-line-unit').val() || 0);
				if (!label && !desc && unit <= 0) return;
				var cfg = typeConfig(type);
				if (cfg.lockQtyToOne) qty = 1;
				rows.push({
					label: label,
					description: desc,
					type: type,
					quantity: qty > 0 ? qty : 1,
					unit_price: unit > 0 ? unit : 0
				});
			});
			lines = rows;
			return rows;
		};

		var calcTotals = function(rows){
			var subtotal = 0;
			rows.forEach(function(line){
				subtotal += Math.max(0, toNum(line.quantity)) * Math.max(0, toNum(line.unit_price));
			});
			var discountPct = Math.max(0, Math.min(100, toNum($('#dtb-quote-discount-percent').val() || 0)));
			var taxPct = Math.max(0, Math.min(100, toNum($('#dtb-quote-tax-percent').val() || 0)));
			var shipping = Math.max(0, toNum($('#dtb-quote-shipping').val() || 0));
			var discount = subtotal * (discountPct / 100);
			var net = Math.max(0, subtotal - discount);
			var tax = net * (taxPct / 100);
			var total = net + tax + shipping;
			return {
				subtotal: subtotal,
				discount_percent: discountPct,
				discount_amount: discount,
				net_subtotal: net,
				tax_percent: taxPct,
				tax_amount: tax,
				shipping_amount: shipping,
				total: total
			};
		};

		var renderTotals = function(t){
			var cur = currency();
			$totals.html('' +
				'<div><span>Subtotal</span><strong>' + esc(cur + ' ' + money(t.subtotal)) + '</strong></div>' +
				'<div><span>Discount</span><strong>-' + esc(cur + ' ' + money(t.discount_amount)) + '</strong></div>' +
				'<div><span>Tax</span><strong>' + esc(cur + ' ' + money(t.tax_amount)) + '</strong></div>' +
				'<div><span>Shipping</span><strong>' + esc(cur + ' ' + money(t.shipping_amount)) + '</strong></div>' +
				'<div class="is-total"><span>Total</span><strong>' + esc(cur + ' ' + money(t.total)) + '</strong></div>'
			);
		};

		var render = function(){
			if (!lines.length) {
				lines = [{ label: '', description: '', type: 'service', quantity: 1, unit_price: 0 }];
			}
			$lineTbody.html(lines.map(rowHtml).join(''));
			$lineTbody.find('tr').each(function(){
				applyTypePresentation($(this));
			});
			renderTotals(calcTotals(lines));
		};

		var collectPayload = function(status){
			var payloadLines = collectLines();
			var totals = calcTotals(payloadLines);
			return {
				status: status || 'draft',
				currency: currency(),
				expires_at: $('#dtb-quote-expires-at').val() || '',
				discount_percent: totals.discount_percent,
				tax_percent: totals.tax_percent,
				shipping_amount: totals.shipping_amount,
				customer_note: ($('#dtb-quote-customer-note').val() || '').toString(),
				internal_note: ($('#dtb-quote-internal-note').val() || '').toString(),
				lines: payloadLines
			};
		};

		var toLocalDateTime = function(zDateString){
			if (!zDateString) return '';
			var dt = new Date(zDateString);
			if (isNaN(dt.getTime())) return '';
			var offset = dt.getTimezoneOffset() * 60000;
			var local = new Date(dt.getTime() - offset);
			return local.toISOString().slice(0, 16);
		};

		var payloadHash = function(payload){
			var clone = $.extend(true, {}, payload || {});
			clone.status = 'draft';
			return JSON.stringify(clone);
		};

		var applyServerQuote = function(serverQuote){
			if (!serverQuote || !Array.isArray(serverQuote.lines)) return;
			quote = serverQuote;
			lines = quote.lines.slice();
			if (quote.totals) {
				$('#dtb-quote-discount-percent').val(quote.totals.discount_percent || 0);
				$('#dtb-quote-tax-percent').val(quote.totals.tax_percent || 0);
				$('#dtb-quote-shipping').val(quote.totals.shipping_amount || 0);
			}
			$('#dtb-quote-expires-at').val(toLocalDateTime(quote.expires_at || ''));
			render();
		};

		var runAction = function(action, status, opts){
			opts = opts || {};
			var payload = collectPayload(status);
			var isSilent = !!opts.silent;
			if (!isSilent) {
				setMsg('Saving…', '');
				$root.find('button').each(function(){
					var $btn = $(this);
					if ($btn.prop('disabled')) $btn.attr('data-was-disabled', '1');
					$btn.prop('disabled', true);
				});
			}

			return $.post(ajaxurl, {
				action: 'dtb_repair_quote_action',
				repair_id: repairId,
				nonce: nonce,
				quote_action: action,
				autosave: isSilent ? '1' : '',
				quote_json: JSON.stringify(payload)
			}).done(function(res){
				if (!isSilent) {
					$root.find('button').each(function(){
						var $btn = $(this);
						$btn.prop('disabled', false);
						if ($btn.attr('data-was-disabled') === '1') {
							$btn.prop('disabled', true).removeAttr('data-was-disabled');
						}
					});
				}
				if (!res || !res.success) {
					setMsg((res && res.data && res.data.message) ? res.data.message : 'Unable to save quote.', 'is-err');
					return;
				}
				if (res.data && res.data.quote) {
					applyServerQuote(res.data.quote);
				}
				lastSavedHash = payloadHash(collectPayload('draft'));
				if (!isSilent) {
					setMsg((res.data && res.data.message) ? res.data.message : 'Quote saved.', 'is-ok');
				} else {
					setMsg('Draft auto-saved.', 'is-ok');
				}
				if (res.data && res.data.reload) {
					window.setTimeout(function(){ window.location.reload(); }, 900);
				}
			}).fail(function(){
				if (!isSilent) {
					$root.find('button').each(function(){
						var $btn = $(this);
						$btn.prop('disabled', false);
						if ($btn.attr('data-was-disabled') === '1') {
							$btn.prop('disabled', true).removeAttr('data-was-disabled');
						}
					});
				}
				if (!isSilent) {
					setMsg('Unable to save quote.', 'is-err');
				}
			});
		};

		var runAutosave = function(){
			if (autosaveInFlight) {
				pendingAutosave = true;
				return;
			}
			var payload = collectPayload('draft');
			var nextHash = payloadHash(payload);
			if (nextHash === lastSavedHash) return;
			autosaveInFlight = true;
			autosaveReq = runAction('save', 'draft', { silent: true });
			$.when(autosaveReq).always(function(){
				autosaveInFlight = false;
				if (pendingAutosave) {
					pendingAutosave = false;
					runAutosave();
				}
			});
		};

		var scheduleAutosave = function(markDirty){
			if (markDirty) {
				setMsg('Unsaved changes…', '');
			}
			if (autosaveTimer) window.clearTimeout(autosaveTimer);
			autosaveTimer = window.setTimeout(function(){
				runAutosave();
			}, 800);
		};

		$('#dtb-quote-add-line').on('click', function(){
			collectLines();
			lines.push({ label: '', description: '', type: 'service', quantity: 1, unit_price: 0 });
			render();
			scheduleAutosave(true);
		});
		$lineTbody.on('click', '.dtb-quote-line-remove', function(){
			var idx = Number($(this).closest('tr').data('index'));
			if (idx >= 0 && idx < lines.length) {
				lines.splice(idx, 1);
				render();
				scheduleAutosave(true);
			}
		});

		$lineTbody.on('change', '.dtb-quote-line-type', function(){
			var $tr = $(this).closest('tr');
			applyTypePresentation($tr);
			collectLines();
			renderTotals(calcTotals(lines));
			scheduleAutosave(true);
		});

		$lineTbody.on('click', '.dtb-quote-preset', function(){
			var $btn = $(this);
			var $tr = $btn.closest('tr');
			if (!$tr.length) return;
			var label = ($btn.attr('data-preset-label') || '').toString();
			var qty = toNum($btn.attr('data-preset-qty') || 1);
			if (label) {
				$tr.find('.dtb-quote-line-label').val(label);
			}
			if (qty > 0) {
				$tr.find('.dtb-quote-line-qty').val(qty);
			}
			collectLines();
			renderTotals(calcTotals(lines));
			scheduleAutosave(true);
		});

		$root.on('input change', '.dtb-quote-line-label, .dtb-quote-line-desc, .dtb-quote-line-qty, .dtb-quote-line-unit, #dtb-quote-discount-percent, #dtb-quote-tax-percent, #dtb-quote-shipping, #dtb-quote-currency, #dtb-quote-customer-note, #dtb-quote-internal-note, #dtb-quote-expires-at', function(){
			collectLines();
			renderTotals(calcTotals(lines));
			scheduleAutosave(true);
		});

		$('#dtb-quote-save').on('click', function(){ runAction('save', 'draft'); });
		$('#dtb-quote-send').on('click', function(){ runAction('send', 'sent'); });
		$('#dtb-quote-accept').on('click', function(){ runAction('accept', 'accepted'); });
		$('#dtb-quote-decline').on('click', function(){ runAction('decline', 'declined'); });

		if ($partsLookupInput.length && typeof ajaxurl === 'string') {
			$partsLookupInput.on('input', function(){
				var term = ($partsLookupInput.val() || '').toString().trim();
				if (lookupTimer) window.clearTimeout(lookupTimer);
				if (term.length < 2) {
					hidePartsLookupMenu();
					return;
				}
				lookupTimer = window.setTimeout(function(){
					if (lookupReq && typeof lookupReq.abort === 'function') {
						lookupReq.abort();
					}
					lookupReq = $.post(ajaxurl, {
						action: 'dtb_repair_parts_lookup',
						term: term,
						nonce: $partsLookupInput.attr('data-lookup-nonce') || ''
					}).done(function(payload){
						var items = payload && payload.success && payload.data ? payload.data.items : [];
						renderPartsLookupMenu(items || []);
					}).fail(function(){
						hidePartsLookupMenu();
					});
				}, 180);
			});

			$partsLookupMenu.on('click', '.dtb-quote-parts-option', function(){
				var $btn = $(this);
				var part = {
					part_id: parseInt($btn.attr('data-part-id') || '0', 10),
					sku: $btn.attr('data-sku') || '',
					name: $btn.attr('data-name') || '',
					brand_label: $btn.attr('data-brand') || '',
					manufacturer_sku: $btn.attr('data-manufacturer-sku') || '',
					quantity: 1,
					unit_price: toNum($btn.attr('data-unit-price') || 0)
				};
				upsertPartLine(part);
				document.dispatchEvent(new CustomEvent('dtb:tech:partSelected', { detail: { part: part } }));
				setMsg('Part added to quote.', 'is-ok');
				$partsLookupInput.val('');
				hidePartsLookupMenu();
			});

			$partsSelected.on('click', '.dtb-quote-selected-part', function(){
				var $btn = $(this);
				var part = {
					part_id: parseInt($btn.attr('data-part-id') || '0', 10),
					sku: $btn.attr('data-sku') || '',
					name: $btn.attr('data-name') || '',
					brand_label: $btn.attr('data-brand') || '',
					manufacturer_sku: $btn.attr('data-manufacturer-sku') || '',
					quantity: parseInt($btn.attr('data-qty') || '1', 10),
					unit_price: toNum($btn.attr('data-unit-price') || 0),
					line_note: $btn.attr('data-line-note') || ''
				};
				upsertPartLine(part);
				document.dispatchEvent(new CustomEvent('dtb:tech:partSelected', { detail: { part: part } }));
				setMsg('Part added to quote.', 'is-ok');
			});
			$partsRecent.on('click', '.dtb-quote-selected-part', function(){
				var $btn = $(this);
				var part = {
					part_id: parseInt($btn.attr('data-part-id') || '0', 10),
					sku: $btn.attr('data-sku') || '',
					name: $btn.attr('data-name') || '',
					brand_label: $btn.attr('data-brand') || '',
					manufacturer_sku: $btn.attr('data-manufacturer-sku') || '',
					quantity: parseInt($btn.attr('data-qty') || '1', 10),
					unit_price: toNum($btn.attr('data-unit-price') || 0),
					line_note: $btn.attr('data-line-note') || ''
				};
				upsertPartLine(part);
				document.dispatchEvent(new CustomEvent('dtb:tech:partSelected', { detail: { part: part } }));
				setMsg('Part added to quote.', 'is-ok');
			});

			$(document).on('click', function(e){
				if (!$partsLookupMenu.length) return;
				if ($(e.target).closest('#dtb-quote-parts-menu').length) return;
				if (e.target === $partsLookupInput[0]) return;
				hidePartsLookupMenu();
			});
		}

		document.addEventListener('dtb:quote:addPart', function(evt){
			var detail = evt && evt.detail ? evt.detail : null;
			if (!detail || !detail.part) return;
			upsertPartLine(detail.part);
			setMsg('Part added to quote.', 'is-ok');
		});

		document.addEventListener('dtb:quote:syncParts', function(evt){
			var detail = evt && evt.detail ? evt.detail : null;
			var parts = detail && Array.isArray(detail.parts) ? detail.parts : [];
			if (!parts.length) {
				setMsg('No parts to sync.', 'is-err');
				return;
			}
			parts.forEach(function(part){ upsertPartLine(part); });
			setMsg('Parts synced to quote.', 'is-ok');
		});

		document.addEventListener('dtb:parts:recentUpdated', function(evt){
			var detail = evt && evt.detail ? evt.detail : null;
			if (!detail || !Array.isArray(detail.parts)) return;
			recentParts = detail.parts.slice(0, 16);
			renderRecentParts();
		});

		recentParts = loadRecentParts();
		render();
		renderSelectedParts();
		renderRecentParts();
		lastSavedHash = payloadHash(collectPayload('draft'));
	}(jQuery));
	</script>
	<?php
}

// ---- Metabox: Timeline -------------------------------------------------------

function dtb_repair_metabox_timeline( WP_Post $post ): void {
	if ( ! function_exists( 'dtb_repair_get_events' ) ) {
		echo '<p>' . esc_html__( 'Event log unavailable.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	$events = dtb_repair_get_events( $post->ID, null, 50 );
	if ( empty( $events ) ) {
		echo '<p class="dtb-muted-message">' . esc_html__( 'No events recorded yet.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	echo '<ul class="dtb-repair-timeline">';
	foreach ( array_reverse( $events ) as $ev ) {
		$vis_raw   = sanitize_text_field( (string) $ev->visibility );
		$vis       = esc_attr( $vis_raw );
		$type_raw  = (string) $ev->event_type;
		$type_label = function_exists( 'dtb_repair_event_label' )
			? dtb_repair_event_label( $type_raw )
			: ucwords( str_replace( [ '.', '_' ], ' ', $type_raw ) );
		$payload   = is_string( $ev->payload_json ?? null ) ? json_decode( (string) $ev->payload_json, true ) : [];
		$summary   = '';
		if ( is_array( $payload ) ) {
			if ( ! empty( $payload['note'] ) ) {
				$summary = wp_strip_all_tags( (string) $payload['note'] );
			} elseif ( ! empty( $payload['reason'] ) ) {
				$summary = wp_strip_all_tags( (string) $payload['reason'] );
			}
		}
		$vis_label = ucwords( str_replace( '_', ' ', $vis_raw ) );
		if ( in_array( $vis_raw, [ 'customer', 'public' ], true ) ) {
			$vis_label = __( 'Customer Visible', 'drywall-toolbox' );
		} elseif ( 'operator' === $vis_raw ) {
			$vis_label = __( 'Operator', 'drywall-toolbox' );
		} elseif ( 'internal' === $vis_raw ) {
			$vis_label = __( 'Internal', 'drywall-toolbox' );
		}
		$time_fmt  = $ev->created_at ? date_i18n( 'M j, Y g:i a', strtotime( (string) $ev->created_at ) ) : '';
		echo '<li class="dtb-ev-' . $vis . '">'
			. '<div class="dtb-tl-body">'
			. '<span class="dtb-tl-type">' . esc_html( $type_label ) . '</span>'
			. '<span class="dtb-tl-vis dtb-tl-vis-' . $vis . '">' . esc_html( $vis_label ) . '</span>'
			. ( '' !== $summary ? '<span class="dtb-cell-sub">' . esc_html( wp_html_excerpt( $summary, 140, '…' ) ) . '</span>' : '' )
			. '<span class="dtb-timeline-time">' . esc_html( $time_fmt ) . '</span>'
			. '</div>'
			. '</li>';
	}
	echo '</ul>';
}

// ---- Metabox: Internal Notes ------------------------------------------------

function dtb_repair_metabox_notes( WP_Post $post ): void {
	wp_nonce_field( 'dtb_repair_save_notes_' . $post->ID, 'dtb_repair_notes_nonce' );
	$notes = wp_kses_post( (string) get_post_meta( $post->ID, '_repair_internal_notes', true ) );
	echo '<div class="dtb-repair-metabox">';
	echo '<textarea name="dtb_repair_internal_notes" class="dtb-notes-textarea" placeholder="'
		. esc_attr__( 'Internal notes (not visible to customers)…', 'drywall-toolbox' )
		. '">' . esc_textarea( $notes ) . '</textarea>';
	echo '</div>';
}

add_action( 'save_post_dtb_repair_request', 'dtb_repair_save_notes_meta' );

/**
 * Save internal notes metabox.
 *
 * @param int $post_id
 */
function dtb_repair_save_notes_meta( int $post_id ): void {
	if ( ! isset( $_POST['dtb_repair_notes_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['dtb_repair_notes_nonce'] ) ), 'dtb_repair_save_notes_' . $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$notes = isset( $_POST['dtb_repair_internal_notes'] )
		? wp_kses_post( wp_unslash( (string) $_POST['dtb_repair_internal_notes'] ) )
		: '';

	update_post_meta( $post_id, '_repair_internal_notes', $notes );

	if ( ! empty( $notes ) && function_exists( 'dtb_repair_append_event' ) ) {
		dtb_repair_append_event( $post_id, 'repair.note_added', [
			'actor_type' => 'admin',
			'actor_id'   => get_current_user_id(),
			'source'     => 'admin',
			'visibility' => 'operator',
			'payload'    => [ 'note_length' => strlen( $notes ) ],
		] );
	}
}

// ---- Metabox: Technician Workspace ------------------------------------------

function dtb_repair_metabox_technician( WP_Post $post ): void {
	wp_nonce_field( 'dtb_repair_save_technician_' . $post->ID, 'dtb_repair_technician_nonce' );

	$diag      = (string) get_post_meta( $post->ID, '_repair_diag_notes', true );
	$parts     = (string) get_post_meta( $post->ID, '_repair_parts_worklog', true );
	$order_log = trim( $diag . ( '' !== trim( $parts ) ? "\n\n" . $parts : '' ) );
	$qa_notes = (string) get_post_meta( $post->ID, '_repair_qa_notes', true );
	$qa_ok    = (string) get_post_meta( $post->ID, '_repair_qa_passed', true );
	$qa_by    = (string) get_post_meta( $post->ID, '_repair_qa_signed_by', true );
	$qa_at    = (string) get_post_meta( $post->ID, '_repair_qa_signed_at', true );

	$sch_cat  = (string) get_post_meta( $post->ID, '_repair_schematic_catalog_id', true );
	$sch_meta = function_exists( 'dtb_repair_get_schematic_sync_snapshot' ) ? dtb_repair_get_schematic_sync_snapshot( $post->ID ) : [];
	$sch_list = get_post_meta( $post->ID, '_repair_schematic_links', true );
	$sch_list = is_array( $sch_list ) ? $sch_list : [];
	if ( empty( $sch_list ) && '' !== trim( $sch_cat ) ) {
		$fallback_url = (string) get_post_meta( $post->ID, '_repair_schematic_url', true );
		$fallback_rev = (string) get_post_meta( $post->ID, '_repair_schematic_revision', true );
		$sch_list[]   = [
			'schematic_id' => $sch_cat,
			'url'          => $fallback_url,
			'version'      => $fallback_rev,
			'brand'        => '',
			'model_number' => '',
			'model_name'   => '',
		];
	}

	$model_hint = sanitize_text_field( (string) get_post_meta( $post->ID, '_repair_model', true ) );
	$brand_hint = sanitize_text_field( (string) get_post_meta( $post->ID, '_repair_tool_brand', true ) );
	$catalog_seed = get_posts(
		[
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'relation' => 'OR',
					[
						'key'     => '_dtb_is_schematic',
						'value'   => '1',
						'compare' => '=',
					],
					[
						'key'     => '_dtb_schematic_id',
						'value'   => '',
						'compare' => '!=',
					],
				],
				[
					'relation' => 'OR',
					[
						'key'     => '_dtb_schematic_model_number',
						'value'   => $model_hint,
						'compare' => '=',
					],
					[
						'key'     => '_dtb_schematic_brand',
						'value'   => $brand_hint,
						'compare' => '=',
					],
					[
						'key'     => '_dtb_schematic_id',
						'value'   => '',
						'compare' => '!=',
					],
				],
			],
		]
	);
	$parts_list = get_post_meta( $post->ID, '_repair_parts_links', true );
	$parts_list = is_array( $parts_list ) ? $parts_list : [];

	?>
	<div class="dtb-tech-workspace">

		<!-- ── Two-column upper layout ─────────────────────────────────── -->
		<div class="dtb-tw-layout">

			<!-- Left primary: Log -->
			<div class="dtb-tw-primary">
				<section class="dtb-tw-section">
					<div class="dtb-tw-section-hd">
						<span class="dtb-tw-step">1</span>
						<div>
							<div class="dtb-tw-section-title">Repair Order Log</div>
							<div class="dtb-tw-section-sub">Diagnostics, labor notes, and parts work log — saved with this repair.</div>
						</div>
					</div>
					<textarea
						name="dtb_repair_diag_notes"
						id="dtb-tech-order-log"
						class="dtb-tw-log-textarea"
						placeholder="e.g. Motor draws 4.2A at idle. Replaced PN-AX21 bearing x2. Threadlocked fasteners and validated runout at 0.003″ TIR."
					><?php echo esc_textarea( $order_log ); ?></textarea>
					<input type="hidden" name="dtb_repair_parts_worklog" value="">
				</section>
			</div>

			<!-- Right secondary: Schematics + Parts -->
			<div class="dtb-tw-secondary">

				<section class="dtb-tw-section">
					<div class="dtb-tw-section-hd">
						<span class="dtb-tw-step">2</span>
						<div>
							<div class="dtb-tw-section-title">Schematic Reference</div>
							<div class="dtb-tw-section-sub">Synced to the schematics catalog.</div>
						</div>
					</div>
					<div class="dtb-tw-lookup-wrap">
						<input
							id="dtb_repair_schematic_catalog_id"
							name="dtb_repair_schematic_catalog_id"
							type="text"
							class="dtb-tw-input"
							value=""
							placeholder="Search by schematic ID, brand, or model…"
							autocomplete="off"
							data-lookup-nonce="<?php echo esc_attr( wp_create_nonce( 'dtb_repair_schematic_lookup' ) ); ?>"
						/>
						<div id="dtb-tech-schematic-lookup-menu" class="dtb-tech-lookup-menu" role="listbox" aria-label="Schematic lookup results" hidden></div>
					</div>
					<input type="hidden" id="dtb_repair_schematic_links_json" name="dtb_repair_schematic_links_json" value="<?php echo esc_attr( wp_json_encode( $sch_list ) ); ?>" />
					<datalist id="dtb_repair_schematic_catalog_list">
						<?php foreach ( (array) $catalog_seed as $att_id ) :
							$sid = (string) get_post_meta( (int) $att_id, '_dtb_schematic_id', true );
							if ( '' === trim( $sid ) ) { continue; }
							$sbrand = (string) get_post_meta( (int) $att_id, '_dtb_schematic_brand', true );
							$smodel = (string) get_post_meta( (int) $att_id, '_dtb_schematic_model_number', true );
						?>
							<option value="<?php echo esc_attr( $sid ); ?>"><?php echo esc_html( trim( $sbrand . ' ' . $smodel ) ); ?></option>
						<?php endforeach; ?>
					</datalist>

					<div class="dtb-tw-field-label">Selected Schematics</div>
					<div id="dtb-tech-selected-schematics" class="dtb-tech-selected-list"></div>

					<div class="dtb-tw-meta-row" id="dtb-tech-primary-details">
						<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Brand</span><span id="dtb-tech-primary-brand"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_schematic_tool_brand', true ) ); ?></span></div>
						<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Model</span><span id="dtb-tech-primary-model"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_schematic_tool_model', true ) ); ?></span></div>
						<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">SKU</span><span id="dtb-tech-primary-sku"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_schematic_tool_sku', true ) ); ?></span></div>
					</div>

					<?php if ( ! empty( $sch_meta ) ) : ?>
						<details class="dtb-tw-sync-details">
							<summary>Catalog sync details</summary>
							<div class="dtb-tw-meta-row">
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Catalog ID</span><?php echo esc_html( (string) ( $sch_meta['catalog_id'] ?? 'Unresolved' ) ); ?></div>
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Source</span><?php echo esc_html( (string) ( $sch_meta['source_host'] ?? 'n/a' ) ); ?></div>
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Synced</span><?php echo esc_html( (string) ( $sch_meta['synced_at_gmt'] ?? '' ) ); ?> UTC</div>
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Version</span><?php echo esc_html( (string) ( $sch_meta['catalog_version'] ?? 'n/a' ) ); ?></div>
							</div>
						</details>
					<?php endif; ?>
				</section>

				<!-- Parts Reference (moved to right column, step 2) -->
				<section class="dtb-tw-section dtb-tw-parts-section">
					<div class="dtb-tw-parts-header">
						<div class="dtb-tw-section-hd">
							<span class="dtb-tw-step">2</span>
							<div>
								<div class="dtb-tw-section-title">Parts Reference</div>
								<div class="dtb-tw-section-sub">Search your parts catalog, build the selected list, then push to the Quote Builder.</div>
							</div>
						</div>
						<button type="button" id="dtb-tech-sync-parts-to-quote" class="dtb-tw-sync-btn">
							<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
							Add All to Quote
						</button>
					</div>

					<div class="dtb-tw-lookup-wrap">
						<input
							id="dtb_repair_parts_lookup"
							type="text"
							class="dtb-tw-input dtb-tw-parts-search"
							value=""
							placeholder="Search by SKU, part title, or brand…"
							autocomplete="off"
							data-lookup-nonce="<?php echo esc_attr( wp_create_nonce( 'dtb_repair_parts_lookup' ) ); ?>"
						/>
						<div id="dtb-tech-parts-lookup-menu" class="dtb-tech-lookup-menu" role="listbox" aria-label="Parts lookup results" hidden></div>
					</div>
					<input type="hidden" id="dtb_repair_parts_links_json" name="dtb_repair_parts_links_json" value="<?php echo esc_attr( wp_json_encode( $parts_list ) ); ?>" />

					<div class="dtb-tw-parts-cols">
						<div class="dtb-tw-parts-col">
							<div class="dtb-tw-parts-col-hd">
								<span class="dtb-tw-parts-col-title">Selected Parts</span>
								<span class="dtb-tw-parts-col-hint">Drag to reorder · top item = primary</span>
							</div>
							<div id="dtb-tech-selected-parts" class="dtb-tech-selected-list"></div>
							<div class="dtb-tw-meta-row" id="dtb-tech-primary-part-details">
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Primary SKU</span><span id="dtb-tech-primary-part-sku"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_parts_primary_sku', true ) ); ?></span></div>
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Name</span><span id="dtb-tech-primary-part-name"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_parts_primary_name', true ) ); ?></span></div>
								<div class="dtb-tw-meta-item"><span class="dtb-tw-meta-key">Brand</span><span id="dtb-tech-primary-part-brand"><?php echo esc_html( (string) get_post_meta( $post->ID, '_repair_parts_primary_brand', true ) ); ?></span></div>
							</div>
						</div>
						<div class="dtb-tw-parts-col dtb-tw-parts-col--recent">
							<div class="dtb-tw-parts-col-hd">
								<span class="dtb-tw-parts-col-title">Recently Used</span>
								<span class="dtb-tw-parts-col-hint">Click Add to insert into selected list</span>
							</div>
							<div id="dtb-tech-recent-parts" class="dtb-tech-selected-list"></div>
						</div>
					</div>
				</section>

			</div><!-- /.dtb-tw-secondary -->
		</div><!-- /.dtb-tw-layout -->

		<!-- ── Full-width QA Sign-off (step 3) ──────────────────────────── -->
		<section class="dtb-tw-section dtb-tw-qa-section <?php echo $qa_ok === '1' ? 'is-passed' : ''; ?>">
			<div class="dtb-tw-section-hd">
				<span class="dtb-tw-step dtb-tw-step--qa">3</span>
				<div>
					<div class="dtb-tw-section-title">Final QA & Sign-off</div>
					<div class="dtb-tw-section-sub">Complete before ready-to-ship transition.</div>
				</div>
			</div>

			<label class="dtb-tw-qa-check <?php echo $qa_ok === '1' ? 'is-checked' : ''; ?>">
				<input type="checkbox" name="dtb_repair_qa_passed" value="1" id="dtb-tw-qa-checkbox" <?php checked( $qa_ok, '1' ); ?> />
				<span class="dtb-tw-qa-check-inner">
					<span class="dtb-tw-qa-check-icon">✓</span>
					<span class="dtb-tw-qa-check-label">QA Passed</span>
				</span>
			</label>

			<div class="dtb-tw-row-2col">
				<div>
					<label class="dtb-tw-field-label" for="dtb_repair_qa_signed_by">Signed By</label>
					<input id="dtb_repair_qa_signed_by" name="dtb_repair_qa_signed_by" type="text" class="dtb-tw-input" value="<?php echo esc_attr( $qa_by ); ?>" placeholder="Technician name" />
				</div>
				<div>
					<label class="dtb-tw-field-label" for="dtb_repair_qa_signed_at">Signed At</label>
					<input id="dtb_repair_qa_signed_at" name="dtb_repair_qa_signed_at" type="datetime-local" class="dtb-tw-input" value="<?php echo esc_attr( $qa_at ); ?>" />
				</div>
			</div>
			<label class="dtb-tw-field-label" for="dtb_repair_qa_notes">QA Notes</label>
			<textarea id="dtb_repair_qa_notes" name="dtb_repair_qa_notes" class="dtb-tw-input dtb-tw-qa-notes" placeholder="Final validation summary, test results, torque specs…"><?php echo esc_textarea( $qa_notes ); ?></textarea>
		</section>

	</div><!-- /.dtb-tech-workspace -->
	<?php
}

add_action( 'save_post_dtb_repair_request', 'dtb_repair_save_technician_meta' );
add_action( 'wp_ajax_dtb_repair_schematic_lookup', 'dtb_repair_ajax_schematic_lookup' );
add_action( 'wp_ajax_dtb_repair_parts_lookup', 'dtb_repair_ajax_parts_lookup' );
add_action( 'wp_ajax_dtb_repair_send_customer_update', 'dtb_repair_ajax_send_customer_update' );
add_action( 'wp_ajax_dtb_repair_mark_customer_messages_read', 'dtb_repair_ajax_mark_customer_messages_read' );
add_action( 'wp_ajax_dtb_repair_quote_action', 'dtb_repair_ajax_quote_action' );

/**
 * Send a customer-visible admin update from the Order Details conversation card.
 */
function dtb_repair_ajax_send_customer_update(): void {
	$repair_id = (int) ( $_POST['repair_id'] ?? 0 );
	$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );
	$comment = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['comment'] ?? '' ) ) );

	if ( ! $repair_id || ! wp_verify_nonce( $nonce, 'dtb_repair_thread_' . $repair_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'drywall-toolbox' ) ], 403 );
	}

	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drywall-toolbox' ) ], 403 );
	}

	if ( '' === $comment ) {
		wp_send_json_error( [ 'message' => __( 'Please enter a message.', 'drywall-toolbox' ) ], 422 );
	}

	if ( strlen( $comment ) > 600 ) {
		wp_send_json_error( [ 'message' => __( 'Message is too long.', 'drywall-toolbox' ) ], 422 );
	}

	$status = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( in_array( $status, [ 'closed', 'completed', 'cancelled', 'quote_declined' ], true ) ) {
		wp_send_json_error( [ 'message' => __( 'Messaging is disabled for this repair status.', 'drywall-toolbox' ) ], 409 );
	}

	if ( ! function_exists( 'dtb_repair_append_event' ) ) {
		wp_send_json_error( [ 'message' => __( 'Event service unavailable.', 'drywall-toolbox' ) ], 500 );
	}

	$event_id = dtb_repair_append_event(
		$repair_id,
		'repair.note_added',
		[
			'actor_type' => 'admin',
			'actor_id'   => get_current_user_id(),
			'source'     => 'admin_order_details',
			'visibility' => 'customer',
			'payload'    => [ 'note' => $comment ],
		]
	);

	if ( false === $event_id ) {
		wp_send_json_error( [ 'message' => __( 'Could not store message.', 'drywall-toolbox' ) ], 500 );
	}

	$event = null;
	if ( function_exists( 'dtb_repair_get_events' ) ) {
		$rows = dtb_repair_get_events( $repair_id, null, 1, max( 0, (int) $event_id - 1 ) );
		$event = ! empty( $rows ) ? $rows[0] : null;
	}

	wp_send_json_success( [
		'message' => __( 'Update sent to customer timeline.', 'drywall-toolbox' ),
		'html'    => $event ? dtb_repair_render_customer_message_item( $event ) : '',
	] );
}

/**
 * Mark customer messages as read for this repair's admin conversation card.
 */
function dtb_repair_ajax_mark_customer_messages_read(): void {
	$repair_id = (int) ( $_POST['repair_id'] ?? 0 );
	$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );

	if ( ! $repair_id || ! wp_verify_nonce( $nonce, 'dtb_repair_thread_' . $repair_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'drywall-toolbox' ) ], 403 );
	}

	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drywall-toolbox' ) ], 403 );
	}

	$thread = dtb_repair_get_customer_message_thread( $repair_id, 200 );
	$alert  = dtb_repair_get_customer_message_alert_state( $repair_id, $thread );

	update_post_meta( $repair_id, '_repair_admin_last_seen_customer_note_id', (int) $alert['last_customer_note_id'] );

	wp_send_json_success( [ 'message' => __( 'Customer messages marked read.', 'drywall-toolbox' ) ] );
}

/**
 * Save/send/transition repair quotes from the quote builder panel.
 */
function dtb_repair_ajax_quote_action(): void {
	$repair_id = (int) ( $_POST['repair_id'] ?? 0 );
	$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );
	$action = sanitize_key( (string) ( $_POST['quote_action'] ?? 'save' ) );
	$is_autosave = ! empty( $_POST['autosave'] );
	$quote_json = wp_unslash( (string) ( $_POST['quote_json'] ?? '' ) );

	if ( ! $repair_id || ! wp_verify_nonce( $nonce, 'dtb_repair_quote_' . $repair_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'drywall-toolbox' ) ], 403 );
	}
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drywall-toolbox' ) ], 403 );
	}
	if ( ! function_exists( 'dtb_repair_save_quote' ) || ! function_exists( 'dtb_repair_get_quote' ) ) {
		wp_send_json_error( [ 'message' => __( 'Quote service unavailable.', 'drywall-toolbox' ) ], 500 );
	}

	$input = json_decode( $quote_json, true );
	if ( ! is_array( $input ) ) {
		$input = [];
	}

	if ( ! in_array( $action, [ 'save', 'send', 'accept', 'decline' ], true ) ) {
		$action = 'save';
	}

	if ( 'save' === $action ) {
		$input['status'] = 'draft';
	} elseif ( 'send' === $action ) {
		$input['status'] = 'sent';
	} elseif ( 'accept' === $action ) {
		$input['status'] = 'accepted';
	} elseif ( 'decline' === $action ) {
		$input['status'] = 'declined';
	}

	if ( 'send' === $action && function_exists( 'dtb_repair_quote_normalize_payload' ) ) {
		$preview = dtb_repair_quote_normalize_payload( $input, dtb_repair_get_quote( $repair_id ) );
		if ( empty( $preview['lines'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Add at least one quote line item before sending.', 'drywall-toolbox' ) ], 422 );
		}
		$preview_total = (float) ( $preview['totals']['total'] ?? 0 );
		if ( $preview_total <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Quote total must be greater than zero before sending.', 'drywall-toolbox' ) ], 422 );
		}
		$current = function_exists( 'dtb_get_repair_status' ) ? dtb_get_repair_status( $repair_id ) : '';
		if ( 'quoted' !== $current ) {
			$allowed = function_exists( 'dtb_get_allowed_transitions' ) ? ( dtb_get_allowed_transitions()[ $current ] ?? [] ) : [];
			if ( ! in_array( 'quoted', $allowed, true ) ) {
				$label = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $current ) : $current;
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: %s: status label */
							__( 'Cannot send quote from current status: %s', 'drywall-toolbox' ),
							$label
						),
					],
					409
				);
			}
		}
	}

	$quote = dtb_repair_save_quote(
		$repair_id,
		$input,
		[
			'actor_type' => 'admin',
			'actor_id'   => get_current_user_id(),
			'source'     => $is_autosave ? 'admin_quote_autosave' : 'admin_quote_builder',
			'suppress_event' => $is_autosave,
		]
	);

	$message = __( 'Quote saved.', 'drywall-toolbox' );
	$reload  = false;

	if ( 'send' === $action ) {
		if ( empty( $quote['lines'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Add at least one quote line item before sending.', 'drywall-toolbox' ) ], 422 );
		}
		$total = (float) ( $quote['totals']['total'] ?? 0 );
		if ( $total <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Quote total must be greater than zero before sending.', 'drywall-toolbox' ) ], 422 );
		}

		$current = function_exists( 'dtb_get_repair_status' ) ? dtb_get_repair_status( $repair_id ) : '';
		$payload = [
			'quote_total'    => $total,
			'quote_currency' => (string) ( $quote['currency'] ?? dtb_repair_quote_default_currency() ),
			'line_count'     => count( (array) $quote['lines'] ),
		];
		$context = array_merge(
			[
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'source'     => 'admin_quote_builder',
				'note'       => __( 'Quote sent to customer.', 'drywall-toolbox' ),
				'payload'    => $payload,
			],
			function_exists( 'dtb_repair_quote_to_notification_context' )
				? dtb_repair_quote_to_notification_context( $quote )
				: []
		);

		if ( 'quoted' === $current ) {
			if ( function_exists( 'dtb_repair_dispatch_notification' ) ) {
				dtb_repair_dispatch_notification(
					$repair_id,
					'repair-quote-created',
					function_exists( 'dtb_repair_quote_to_notification_context' ) ? dtb_repair_quote_to_notification_context( $quote ) : []
				);
			}
			if ( function_exists( 'dtb_repair_append_event' ) ) {
				dtb_repair_append_event(
					$repair_id,
					'repair.quote_resent',
					[
						'actor_type' => 'admin',
						'actor_id'   => get_current_user_id(),
						'source'     => 'admin_quote_builder',
						'visibility' => 'customer',
						'payload'    => $payload,
					]
				);
			}
			$message = __( 'Quote resent to customer.', 'drywall-toolbox' );
		} else {
			$allowed = function_exists( 'dtb_get_allowed_transitions' ) ? ( dtb_get_allowed_transitions()[ $current ] ?? [] ) : [];
			if ( ! in_array( 'quoted', $allowed, true ) ) {
				$label = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $current ) : $current;
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: %s: status label */
							__( 'Cannot send quote from current status: %s', 'drywall-toolbox' ),
							$label
						),
					],
					409
				);
			}

			if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
				wp_send_json_error( [ 'message' => __( 'Workflow module unavailable.', 'drywall-toolbox' ) ], 500 );
			}

			$result = dtb_transition_repair_status( $repair_id, 'quoted', $context );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 409 );
			}
			$reload = true;
			$message = __( 'Quote sent and repair status moved to Quote Sent.', 'drywall-toolbox' );
		}
	}

	if ( 'accept' === $action || 'decline' === $action ) {
		$target = 'accept' === $action ? 'quote_accepted' : 'quote_declined';
		$current = function_exists( 'dtb_get_repair_status' ) ? dtb_get_repair_status( $repair_id ) : '';
		if ( $current !== $target ) {
			$allowed = function_exists( 'dtb_get_allowed_transitions' ) ? ( dtb_get_allowed_transitions()[ $current ] ?? [] ) : [];
			if ( ! in_array( $target, $allowed, true ) ) {
				$label = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $current ) : $current;
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: %s: status label */
							__( 'Cannot update quote decision from current status: %s', 'drywall-toolbox' ),
							$label
						),
					],
					409
				);
			}

			$result = function_exists( 'dtb_transition_repair_status' )
				? dtb_transition_repair_status(
					$repair_id,
					$target,
					[
						'actor_type' => 'admin',
						'actor_id'   => get_current_user_id(),
						'source'     => 'admin_quote_builder',
						'note'       => 'accept' === $action
							? __( 'Quote marked accepted by admin.', 'drywall-toolbox' )
							: __( 'Quote marked declined by admin.', 'drywall-toolbox' ),
					]
				)
				: new WP_Error( 'dtb_repair_workflow_unavailable', __( 'Workflow module unavailable.', 'drywall-toolbox' ) );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 409 );
			}
			$reload = true;
		}

		$message = 'accept' === $action
			? __( 'Quote marked accepted.', 'drywall-toolbox' )
			: __( 'Quote marked declined.', 'drywall-toolbox' );
	}

	wp_send_json_success(
		[
			'message' => $message,
			'quote'   => dtb_repair_get_quote( $repair_id ),
			'reload'  => $reload,
		]
	);
}

function dtb_repair_save_technician_meta( int $post_id ): void {
	if ( ! isset( $_POST['dtb_repair_technician_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['dtb_repair_technician_nonce'] ) ), 'dtb_repair_save_technician_' . $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$diag     = isset( $_POST['dtb_repair_diag_notes'] ) ? wp_kses_post( wp_unslash( (string) $_POST['dtb_repair_diag_notes'] ) ) : '';
	$parts    = isset( $_POST['dtb_repair_parts_worklog'] ) ? wp_kses_post( wp_unslash( (string) $_POST['dtb_repair_parts_worklog'] ) ) : '';
	$qa_notes = isset( $_POST['dtb_repair_qa_notes'] ) ? wp_kses_post( wp_unslash( (string) $_POST['dtb_repair_qa_notes'] ) ) : '';
	$qa_ok    = isset( $_POST['dtb_repair_qa_passed'] ) ? '1' : '0';
	$qa_by    = isset( $_POST['dtb_repair_qa_signed_by'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['dtb_repair_qa_signed_by'] ) ) : '';
	$qa_at    = isset( $_POST['dtb_repair_qa_signed_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['dtb_repair_qa_signed_at'] ) ) : '';

	$parts_json = isset( $_POST['dtb_repair_parts_links_json'] ) ? wp_unslash( (string) $_POST['dtb_repair_parts_links_json'] ) : '[]';
	$sch_cat  = isset( $_POST['dtb_repair_schematic_catalog_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['dtb_repair_schematic_catalog_id'] ) ) : '';
	$sch_json = isset( $_POST['dtb_repair_schematic_links_json'] ) ? wp_unslash( (string) $_POST['dtb_repair_schematic_links_json'] ) : '[]';
	$sch_raw  = json_decode( $sch_json, true );
	$sch_list = [];
	if ( is_array( $sch_raw ) ) {
		foreach ( $sch_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sid_raw = sanitize_text_field( (string) ( $row['schematic_id'] ?? '' ) );
			$sid = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $sid_raw ) : $sid_raw;
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			if ( '' === $sid && '' === $url ) {
				continue;
			}
			$sch_list[] = [
				'schematic_id' => $sid,
				'url'          => $url,
				'version'      => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['version'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['version'] ?? '' ) ),
				'brand'        => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['brand'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['brand'] ?? '' ) ),
				'model_number' => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['model_number'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['model_number'] ?? '' ) ),
				'model_name'   => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['model_name'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['model_name'] ?? '' ) ),
				'sku'          => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['sku'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['sku'] ?? '' ) ),
				'product_name' => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['product_name'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['product_name'] ?? '' ) ),
			];
		}
	}
	$sch_list = array_slice( $sch_list, 0, 20 );
	$parts_raw  = json_decode( $parts_json, true );
	$parts_list = [];
	if ( is_array( $parts_raw ) ) {
		foreach ( $parts_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$part_id = absint( $row['part_id'] ?? 0 );
			$sku_raw = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
			$sku = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $sku_raw ) : $sku_raw;
			if ( $part_id <= 0 && '' === $sku ) {
				continue;
			}
			$parts_list[] = [
				'part_id'           => $part_id,
				'sku'               => $sku,
				'name'              => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'brand_label'       => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['brand_label'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['brand_label'] ?? '' ) ),
				'manufacturer_sku'  => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_text_field( (string) ( $row['manufacturer_sku'] ?? '' ) ) ) : sanitize_text_field( (string) ( $row['manufacturer_sku'] ?? '' ) ),
				'unit_price'        => max( 0, (float) ( $row['unit_price'] ?? 0 ) ),
				'quantity'          => max( 1, absint( $row['quantity'] ?? 1 ) ),
				'line_note'         => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( sanitize_textarea_field( (string) ( $row['line_note'] ?? '' ) ), true ) : sanitize_textarea_field( (string) ( $row['line_note'] ?? '' ) ),
			];
		}
	}
	$parts_list = array_slice( $parts_list, 0, 40 );

	$primary  = $sch_list[0] ?? [
		'schematic_id' => $sch_cat,
		'url'          => '',
		'version'      => '',
	];
	$sch_ref = sanitize_text_field( (string) ( $primary['schematic_id'] ?? '' ) );
	$sch_url = esc_url_raw( (string) ( $primary['url'] ?? '' ) );
	$sch_rev = sanitize_text_field( (string) ( $primary['version'] ?? '' ) );
	$sch_brand = sanitize_text_field( (string) ( $primary['brand'] ?? '' ) );
	$sch_model = sanitize_text_field( (string) ( $primary['model_number'] ?? $primary['model_name'] ?? '' ) );
	$sch_sku   = sanitize_text_field( (string) ( $primary['sku'] ?? '' ) );
	if ( '' === $sch_cat ) {
		$sch_cat = $sch_ref;
	}

	update_post_meta( $post_id, '_repair_diag_notes', $diag );
	update_post_meta( $post_id, '_repair_parts_worklog', '' );
	update_post_meta( $post_id, '_repair_qa_notes', $qa_notes );
	update_post_meta( $post_id, '_repair_qa_passed', $qa_ok );
	update_post_meta( $post_id, '_repair_qa_signed_by', $qa_by );
	update_post_meta( $post_id, '_repair_qa_signed_at', $qa_at );
	update_post_meta( $post_id, '_repair_schematic_links', $sch_list );
	update_post_meta( $post_id, '_repair_schematic_url', $sch_url );
	update_post_meta( $post_id, '_repair_schematic_revision', $sch_rev );
	update_post_meta( $post_id, '_repair_schematic_ref', $sch_ref );
	update_post_meta( $post_id, '_repair_schematic_catalog_id', $sch_cat );
	update_post_meta( $post_id, '_repair_schematic_tool_brand', $sch_brand );
	update_post_meta( $post_id, '_repair_schematic_tool_model', $sch_model );
	update_post_meta( $post_id, '_repair_schematic_tool_sku', $sch_sku );
	update_post_meta( $post_id, '_repair_parts_links', $parts_list );
	$primary_part = $parts_list[0] ?? [];
	update_post_meta( $post_id, '_repair_parts_primary_sku', sanitize_text_field( (string) ( $primary_part['sku'] ?? '' ) ) );
	update_post_meta( $post_id, '_repair_parts_primary_name', sanitize_text_field( (string) ( $primary_part['name'] ?? '' ) ) );
	update_post_meta( $post_id, '_repair_parts_primary_brand', sanitize_text_field( (string) ( $primary_part['brand_label'] ?? '' ) ) );
	if ( '' !== $sch_brand && '' === trim( (string) get_post_meta( $post_id, '_repair_tool_brand', true ) ) ) {
		update_post_meta( $post_id, '_repair_tool_brand', $sch_brand );
	}
	if ( '' !== $sch_model && '' === trim( (string) get_post_meta( $post_id, '_repair_model', true ) ) ) {
		update_post_meta( $post_id, '_repair_model', $sch_model );
	}

	if ( function_exists( 'dtb_repair_sync_schematic_metadata' ) ) {
		$snapshot = dtb_repair_sync_schematic_metadata( $post_id, $sch_url, $sch_ref, $sch_rev, $sch_cat );
		if ( ! empty( $snapshot['catalog_url'] ) ) {
			update_post_meta( $post_id, '_repair_schematic_url', (string) $snapshot['catalog_url'] );
		}
		if ( ! empty( $snapshot['catalog_id'] ) ) {
			update_post_meta( $post_id, '_repair_schematic_ref', (string) $snapshot['catalog_id'] );
		}
		if ( ! empty( $snapshot['catalog_version'] ) && '' === trim( $sch_rev ) ) {
			update_post_meta( $post_id, '_repair_schematic_revision', (string) $snapshot['catalog_version'] );
		}
	}
}

/**
 * AJAX lookup for technician schematic search.
 */
function dtb_repair_ajax_schematic_lookup(): void {
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	}
	check_ajax_referer( 'dtb_repair_schematic_lookup', 'nonce' );

	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['term'] ) ) : '';
	$term = trim( $term );
	if ( strlen( $term ) < 2 ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$query = new WP_Query(
		[
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 15,
			'fields'         => 'ids',
			's'              => $term,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					[
						'key'     => '_dtb_is_schematic',
						'value'   => '1',
						'compare' => '=',
					],
					[
						'key'     => '_dtb_schematic_id',
						'value'   => '',
						'compare' => '!=',
					],
				],
				[
					'relation' => 'OR',
					[
						'key'     => '_dtb_schematic_id',
						'value'   => $term,
						'compare' => 'LIKE',
					],
					[
						'key'     => '_dtb_schematic_brand',
						'value'   => $term,
						'compare' => 'LIKE',
					],
					[
						'key'     => '_dtb_schematic_model_number',
						'value'   => $term,
						'compare' => 'LIKE',
					],
					[
						'key'     => '_dtb_schematic_model_name',
						'value'   => $term,
						'compare' => 'LIKE',
					],
				],
			],
		]
	);

	$items = [];
	foreach ( (array) $query->posts as $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$sid_raw          = (string) get_post_meta( $attachment_id, '_dtb_schematic_id', true );
		$brand_raw        = (string) get_post_meta( $attachment_id, '_dtb_schematic_brand', true );
		$model_number_raw = (string) get_post_meta( $attachment_id, '_dtb_schematic_model_number', true );
		$model_name_raw   = (string) get_post_meta( $attachment_id, '_dtb_schematic_model_name', true );
		$sid          = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $sid_raw ) : $sid_raw;
		$brand        = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $brand_raw ) : $brand_raw;
		$model_number = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $model_number_raw ) : $model_number_raw;
		$model_name   = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $model_name_raw ) : $model_name_raw;
		$url           = (string) wp_get_attachment_url( $attachment_id );
		$version       = get_post_modified_time( 'Y-m-d\TH:i:s\Z', true, $attachment_id );
		$product_ids   = get_post_meta( $attachment_id, '_dtb_schematic_product_ids', true );
		if ( function_exists( 'dtb_schematic_normalize_product_ids' ) ) {
			$product_ids = dtb_schematic_normalize_product_ids( $product_ids );
		}
		$product_ids = is_array( $product_ids ) ? array_map( 'intval', $product_ids ) : [];
		$product_sku = '';
		$product_name = '';
		if ( ! empty( $product_ids ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product_ids[0] );
			if ( $product ) {
				$product_sku_raw  = (string) $product->get_sku();
				$product_name_raw = (string) $product->get_name();
				$product_sku  = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $product_sku_raw ) : $product_sku_raw;
				$product_name = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $product_name_raw ) : $product_name_raw;
			}
		}

		$haystack = strtolower( trim( $sid . ' ' . $brand . ' ' . $model_number . ' ' . $model_name ) );
		if ( false === strpos( $haystack, strtolower( $term ) ) ) {
			continue;
		}

		$items[] = [
			'attachment_id' => $attachment_id,
			'schematic_id'  => $sid,
			'brand'         => $brand,
			'model_number'  => $model_number,
			'model_name'    => $model_name,
			'url'           => $url,
			'version'       => $version,
			'sku'           => $product_sku,
			'product_name'  => $product_name,
		];
	}

	wp_send_json_success( [ 'items' => array_values( $items ) ] );
}

/**
 * AJAX lookup for technician parts search.
 */
function dtb_repair_ajax_parts_lookup(): void {
	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	}
	check_ajax_referer( 'dtb_repair_parts_lookup', 'nonce' );

	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['term'] ) ) : '';
	$term = trim( $term );
	if ( '' === $term ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	// First, do an exact SKU/manufacturer SKU lookup so exact part-code searches
	// are not blocked by WP text search behavior (which does not search _sku meta).
	$exact_ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_dtb_is_parts',
					'value'   => '1',
					'compare' => '=',
				],
				[
					'relation' => 'OR',
					[
						'key'     => '_sku',
						'value'   => $term,
						'compare' => '=',
					],
					[
						'key'     => '_dtb_manufacturer_sku',
						'value'   => $term,
						'compare' => '=',
					],
				],
			],
		]
	);

	$search_ids = [];
	if ( strlen( $term ) >= 2 ) {
		$query = new WP_Query(
			[
				'post_type'      => 'product',
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				'posts_per_page' => 20,
				'fields'         => 'ids',
				's'              => $term,
				'meta_query'     => [
					[
						'key'     => '_dtb_is_parts',
						'value'   => '1',
						'compare' => '=',
					],
				],
			]
		);
		$search_ids = (array) $query->posts;
	}

	$product_ids = array_values( array_unique( array_map( 'intval', array_merge( (array) $exact_ids, (array) $search_ids ) ) ) );

	$items = [];
	foreach ( $product_ids as $product_id ) {
		$product_id = (int) $product_id;
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$sku_raw  = $product ? (string) $product->get_sku() : (string) get_post_meta( $product_id, '_sku', true );
		$name_raw = get_the_title( $product_id );
		$brand_raw = (string) get_post_meta( $product_id, '_dtb_brand_label', true );
		$manu_sku_raw = (string) get_post_meta( $product_id, '_dtb_manufacturer_sku', true );
		$sku      = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $sku_raw ) : $sku_raw;
		$name     = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( (string) $name_raw ) : (string) $name_raw;
		$brand    = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $brand_raw ) : $brand_raw;
		$manu_sku = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $manu_sku_raw ) : $manu_sku_raw;
		$unit_price = $product ? (float) $product->get_price() : 0.0;
		$stock_qty  = $product && method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$stock_text = $product && method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
		$haystack   = strtolower( trim( $sku . ' ' . $name . ' ' . $brand . ' ' . $manu_sku ) );

		if ( false === strpos( $haystack, strtolower( $term ) ) ) {
			continue;
		}

		$items[] = [
			'part_id'           => $product_id,
			'sku'               => $sku,
			'name'              => $name,
			'brand_label'       => $brand,
			'manufacturer_sku'  => $manu_sku,
			'unit_price'        => $unit_price,
			'stock_quantity'    => null === $stock_qty ? null : (int) $stock_qty,
			'stock_status'      => $stock_text,
		];
	}

	wp_send_json_success( [ 'items' => array_values( $items ) ] );
}

// ---- Metabox: Repair Command Center (Status Transition) ----------------------

/**
 * Unified command center — status transition controls.
 */
function dtb_repair_metabox_command_center( WP_Post $post ): void {
	if ( ! function_exists( 'dtb_get_repair_status' ) || ! function_exists( 'dtb_get_allowed_transitions' ) ) {
		echo '<p class="dtb-muted-message dtb-muted-message--padded">' . esc_html__( 'Workflow module unavailable.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	// ── Status data ──────────────────────────────────────────────────────────
	$current     = dtb_get_repair_status( $post->ID );
	$current_lbl = dtb_get_repair_status_label( $current );
	$transitions = dtb_get_allowed_transitions();
	$allowed     = $transitions[ $current ] ?? [];
	$milestones  = [
		[ 'key' => 'submitted',     'label' => __( 'Submitted', 'drywall-toolbox' ) ],
		[ 'key' => 'in_progress',   'label' => __( 'In Progress', 'drywall-toolbox' ) ],
		[ 'key' => 'ready_to_ship', 'label' => __( 'Ready to Ship', 'drywall-toolbox' ) ],
		[ 'key' => 'completed',     'label' => __( 'Completed', 'drywall-toolbox' ) ],
	];
	$milestone_targets = [
		'submitted'     => [ 'submitted', 'reviewed' ],
		'in_progress'   => [ 'approved', 'quoted', 'quote_accepted', 'parts_allocated', 'in_progress' ],
		'ready_to_ship' => [ 'ready_to_ship' ],
		'completed'     => [ 'completed', 'closed' ],
	];
	$milestone_order = [
		'submitted'         => 0,
		'reviewed'          => 0,
		'awaiting_customer' => 0,
		'approved'          => 1,
		'quoted'            => 1,
		'quote_accepted'    => 1,
		'parts_allocated'   => 1,
		'in_progress'       => 1,
		'ready_to_ship'     => 2,
		'completed'         => 3,
		'closed'            => 3,
		'cancelled'         => -1,
		'quote_declined'    => -1,
	];
	$progress_pct = [
		'submitted'         => 8,
		'reviewed'          => 16,
		'awaiting_customer' => 20,
		'approved'          => 28,
		'quoted'            => 35,
		'quote_accepted'    => 42,
		'quote_declined'    => 100,
		'parts_allocated'   => 55,
		'in_progress'       => 70,
		'ready_to_ship'     => 88,
		'completed'         => 100,
		'closed'            => 100,
		'cancelled'         => 100,
	];
	$milestone_idx = $milestone_order[ $current ] ?? 0;
	$is_negative   = in_array( $current, [ 'cancelled', 'quote_declined' ], true );
	$is_complete   = in_array( $current, [ 'completed', 'closed' ], true );
	$progress      = $progress_pct[ $current ] ?? 0;

	// HTML is rendered inline in the hero banner — only emit the JS here.
	?>
	<script>
	(function($) {
		var $picker = $('#dtb-cc-action-picker');
		var $toggle = $('#dtb-cc-action-toggle');
		var $menu   = $('#dtb-cc-action-menu');
		var $target = $('#dtb-repair-to-status');
		var $label  = $('.dtb-cc-action-toggle-label');

		$toggle.on('click', function() {
			var open = ! $menu.prop('hidden');
			$menu.prop('hidden', open);
			$toggle.attr('aria-expanded', open ? 'false' : 'true');
		});

		$menu.on('click', '.dtb-cc-action-option', function() {
			var status = $(this).data('status');
			var text   = $(this).text().trim();
			$target.val(status);
			$label.text(text);
			$menu.prop('hidden', true);
			$toggle.attr('aria-expanded', 'false');
		});

		$(document).on('click', function(e) {
			if ( ! $picker.length ) return;
			if ( $(e.target).closest('#dtb-cc-action-picker').length ) return;
			$menu.prop('hidden', true);
			$toggle.attr('aria-expanded', 'false');
		});

		var runTransition = function(toStatus) {
			var repairId = $('#dtb-repair-transition-btn').data('repair-id');
			var note     = $('#dtb-repair-transition-note').val();
			var nonce    = $('input[name="dtb_repair_transition_nonce"]').val();
			var $msg     = $('#dtb-repair-transition-msg');
			var $btn     = $('#dtb-repair-transition-btn');

			if ( ! toStatus ) {
				$msg.text('Please select a target status.').attr('class', 'dtb-cc-msg dtb-cc-msg-err');
				return;
			}

			$btn.prop('disabled', true);
			$msg.text('Transitioning…').attr('class', 'dtb-cc-msg');

			$.post(ajaxurl, {
				action:    'dtb_repair_transition',
				repair_id: repairId,
				to_status: toStatus,
				note:      note,
				nonce:     nonce
			}, function(response) {
				if (response.success) {
					$msg.text(response.data.message).attr('class', 'dtb-cc-msg dtb-cc-msg-ok');
					setTimeout(function() { location.reload(); }, 900);
				} else {
					$msg.text(response.data.message || 'Error.').attr('class', 'dtb-cc-msg dtb-cc-msg-err');
					$btn.prop('disabled', false);
				}
			});
		};

		$('.dtb-hcc-milestones').on('click', '.dtb-cc-ms-dot-btn.is-clickable', function() {
			var toStatus = $(this).data('status');
			var labelTxt = $(this).data('label');
			$('#dtb-repair-to-status').val(toStatus);
			if ( labelTxt ) {
				$('.dtb-cc-action-toggle-label').text(labelTxt);
			}
			runTransition(toStatus);
		});

		$('#dtb-repair-transition-btn').on('click', function() {
			var toStatus = $('#dtb-repair-to-status').val();
			runTransition(toStatus);
		});
	}(jQuery));
	</script>
	<?php
}

// ---- Metabox: Queue Jobs ----------------------------------------------------

function dtb_repair_metabox_queue( WP_Post $post ): void {
	echo '<div class="dtb-repair-metabox">';

	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		echo '<p><em>' . esc_html__( 'Action Scheduler not active.', 'drywall-toolbox' ) . '</em></p>';
		echo '</div>';
		return;
	}

	// function_exists('as_get_scheduled_actions') passed above — AS is active, so the class exists.
	$as_status = \ActionScheduler_Store::STATUS_PENDING;

	$actions = as_get_scheduled_actions(
		[
			'group'    => 'dtb-repairs',
			'search'   => (string) $post->ID,
			'status'   => $as_status,
			'per_page' => 20,
		]
	);

	if ( empty( $actions ) ) {
		echo '<p><em>' . esc_html__( 'No pending jobs.', 'drywall-toolbox' ) . '</em></p>';
		echo '</div>';
		return;
	}

	echo '<ul class="dtb-queue-job-list">';
	foreach ( $actions as $action ) {
		echo '<li class="dtb-queue-job-list__item">'
			. esc_html( $action->get_hook() )
			. '</li>';
	}
	echo '</ul>';
	echo '</div>';
}

// =============================================================================
// SECTION 8 — AJAX: STATUS TRANSITION
// =============================================================================

add_action( 'wp_ajax_dtb_repair_transition', 'dtb_repair_ajax_transition' );

/**
 * Handle the AJAX status transition request from the metabox.
 */
function dtb_repair_ajax_transition(): void {
	// Nonce verification.
	$nonce     = sanitize_text_field( wp_unslash( (string) ( $_POST['nonce'] ?? '' ) ) );
	$repair_id = (int) ( $_POST['repair_id'] ?? 0 );

	if ( ! $repair_id || ! wp_verify_nonce( $nonce, 'dtb_repair_transition_' . $repair_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'drywall-toolbox' ) ], 403 );
	}

	if ( ! current_user_can( 'dtb_manage_repairs' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drywall-toolbox' ) ], 403 );
	}

	$to_status = sanitize_text_field( wp_unslash( (string) ( $_POST['to_status'] ?? '' ) ) );
	$note      = sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) );

	if ( '' === $to_status ) {
		wp_send_json_error( [ 'message' => __( 'No target status provided.', 'drywall-toolbox' ) ] );
	}

	if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
		wp_send_json_error( [ 'message' => __( 'Workflow module unavailable.', 'drywall-toolbox' ) ] );
	}

	$result = dtb_transition_repair_status(
		$repair_id,
		$to_status,
		[
			'actor_type' => 'admin',
			'actor_id'   => get_current_user_id(),
			'source'     => 'admin',
			'note'       => $note,
		]
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message' => sprintf(
			/* translators: %s: new status label */
			__( 'Status updated to: %s', 'drywall-toolbox' ),
			function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $to_status ) : $to_status
		),
	] );
}

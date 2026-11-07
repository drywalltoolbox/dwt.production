<?php
/**
 * Services — RepairQuoteService: quote normalization, totals, and persistence.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the default quote currency code.
 */
function dtb_repair_quote_default_currency(): string {
	if ( function_exists( 'get_woocommerce_currency' ) ) {
		$currency = sanitize_text_field( (string) get_woocommerce_currency() );
		if ( '' !== $currency ) {
			return strtoupper( $currency );
		}
	}

	return 'USD';
}

/**
 * Return the UI label for a quote status.
 */
function dtb_repair_quote_status_label( string $status ): string {
	$map = [
		'draft'    => __( 'Draft', 'drywall-toolbox' ),
		'sent'     => __( 'Sent', 'drywall-toolbox' ),
		'accepted' => __( 'Accepted', 'drywall-toolbox' ),
		'declined' => __( 'Declined', 'drywall-toolbox' ),
	];

	return $map[ $status ] ?? __( 'Draft', 'drywall-toolbox' );
}

/**
 * Normalize a decimal value to a currency-safe precision.
 */
function dtb_repair_quote_money( mixed $value ): float {
	$number = round( (float) $value, 2 );
	return abs( $number ) < 0.005 ? 0.0 : $number;
}

/**
 * Clamp a percentage value to 0..100.
 */
function dtb_repair_quote_percent( mixed $value ): float {
	$number = (float) $value;
	if ( $number < 0 ) {
		$number = 0;
	}
	if ( $number > 100 ) {
		$number = 100;
	}
	return round( $number, 3 );
}

/**
 * Normalize a list of quote line items.
 *
 * @param mixed $raw_lines Raw line items.
 * @return array<int,array<string,mixed>>
 */
function dtb_repair_quote_normalize_lines( mixed $raw_lines ): array {
	if ( ! is_array( $raw_lines ) ) {
		return [];
	}

	$lines = [];
	foreach ( $raw_lines as $line ) {
		if ( ! is_array( $line ) ) {
			continue;
		}

		$label_raw = sanitize_text_field( (string) ( $line['label'] ?? '' ) );
		$desc_raw  = sanitize_textarea_field( (string) ( $line['description'] ?? '' ) );
		$label = function_exists( 'dtb_str_normalize_display' )
			? dtb_str_normalize_display( $label_raw )
			: $label_raw;
		$description = function_exists( 'dtb_str_normalize_display' )
			? dtb_str_normalize_display( $desc_raw, true )
			: $desc_raw;
		$type = sanitize_key( (string) ( $line['type'] ?? 'service' ) );
		if ( ! in_array( $type, [ 'service', 'labor', 'part', 'shipping', 'misc' ], true ) ) {
			$type = 'service';
		}

		$quantity = (float) ( $line['quantity'] ?? 1 );
		if ( $quantity <= 0 ) {
			$quantity = 1;
		}
		$quantity = min( 9999, round( $quantity, 3 ) );

		$unit_price = dtb_repair_quote_money( $line['unit_price'] ?? $line['price'] ?? 0 );
		if ( '' === $label && '' === $description && $unit_price <= 0 ) {
			continue;
		}

		$lines[] = [
			'label'       => '' !== $label ? $label : __( 'Repair Item', 'drywall-toolbox' ),
			'description' => $description,
			'type'        => $type,
			'quantity'    => $quantity,
			'unit_price'  => $unit_price,
			'line_total'  => dtb_repair_quote_money( $quantity * $unit_price ),
		];
	}

	return array_slice( $lines, 0, 80 );
}

/**
 * Calculate normalized quote totals.
 *
 * @param array<int,array<string,mixed>> $lines      Normalized quote lines.
 * @param array<string,mixed>            $adjustment Adjustment inputs.
 * @return array<string,mixed>
 */
function dtb_repair_quote_calculate_totals( array $lines, array $adjustment = [] ): array {
	$subtotal = 0.0;
	foreach ( $lines as $line ) {
		$subtotal += dtb_repair_quote_money( (float) ( $line['line_total'] ?? 0 ) );
	}
	$subtotal = dtb_repair_quote_money( $subtotal );

	$discount_percent = dtb_repair_quote_percent( $adjustment['discount_percent'] ?? 0 );
	$discount_amount = dtb_repair_quote_money( $adjustment['discount_amount'] ?? 0 );
	if ( $discount_percent > 0 ) {
		$discount_amount = dtb_repair_quote_money( $subtotal * ( $discount_percent / 100 ) );
	}
	$discount_amount = min( $discount_amount, $subtotal );

	$net_subtotal = dtb_repair_quote_money( $subtotal - $discount_amount );
	$tax_percent = dtb_repair_quote_percent( $adjustment['tax_percent'] ?? 0 );
	$tax_amount = dtb_repair_quote_money( $adjustment['tax_amount'] ?? 0 );
	if ( $tax_percent > 0 ) {
		$tax_amount = dtb_repair_quote_money( $net_subtotal * ( $tax_percent / 100 ) );
	}

	$shipping_amount = dtb_repair_quote_money( $adjustment['shipping_amount'] ?? 0 );
	if ( $shipping_amount < 0 ) {
		$shipping_amount = 0;
	}

	$total = dtb_repair_quote_money( $net_subtotal + $tax_amount + $shipping_amount );

	return [
		'subtotal'         => $subtotal,
		'discount_percent' => $discount_percent,
		'discount_amount'  => $discount_amount,
		'net_subtotal'     => $net_subtotal,
		'tax_percent'      => $tax_percent,
		'tax_amount'       => $tax_amount,
		'shipping_amount'  => $shipping_amount,
		'total'            => $total,
	];
}

/**
 * Compatibility wrapper used by Woo integration code.
 *
 * @param array<int,array<string,mixed>> $lines Quote lines.
 * @return array<string,mixed>
 */
function dtb_repair_calculate_totals( array $lines ): array {
	$normalized = dtb_repair_quote_normalize_lines( $lines );
	$totals     = dtb_repair_quote_calculate_totals( $normalized );

	return [
		'subtotal' => (float) $totals['subtotal'],
		'total'    => (float) $totals['total'],
		'currency' => dtb_repair_quote_default_currency(),
	];
}

/**
 * Normalize an ISO-ish datetime string.
 */
function dtb_repair_quote_normalize_datetime( string $value ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	$ts = strtotime( $value );
	if ( false === $ts ) {
		return '';
	}

	return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
}

/**
 * Normalize an incoming quote payload.
 *
 * @param array<string,mixed> $input Raw quote payload.
 * @param array<string,mixed> $base  Existing quote payload.
 * @return array<string,mixed>
 */
function dtb_repair_quote_normalize_payload( array $input, array $base = [] ): array {
	$status = sanitize_key( (string) ( $input['status'] ?? $base['status'] ?? 'draft' ) );
	if ( ! in_array( $status, [ 'draft', 'sent', 'accepted', 'declined' ], true ) ) {
		$status = 'draft';
	}

	$currency = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? $base['currency'] ?? dtb_repair_quote_default_currency() ) ) );
	if ( '' === $currency ) {
		$currency = dtb_repair_quote_default_currency();
	}

	$lines = dtb_repair_quote_normalize_lines( $input['lines'] ?? $base['lines'] ?? [] );
	$totals = dtb_repair_quote_calculate_totals(
		$lines,
		[
			'discount_percent' => $input['discount_percent'] ?? $base['totals']['discount_percent'] ?? 0,
			'discount_amount'  => $input['discount_amount'] ?? $base['totals']['discount_amount'] ?? 0,
			'tax_percent'      => $input['tax_percent'] ?? $base['totals']['tax_percent'] ?? 0,
			'tax_amount'       => $input['tax_amount'] ?? $base['totals']['tax_amount'] ?? 0,
			'shipping_amount'  => $input['shipping_amount'] ?? $base['totals']['shipping_amount'] ?? 0,
		]
	);

	$expires_at = dtb_repair_quote_normalize_datetime( (string) ( $input['expires_at'] ?? $base['expires_at'] ?? '' ) );
	$sent_at = dtb_repair_quote_normalize_datetime( (string) ( $input['sent_at'] ?? $base['sent_at'] ?? '' ) );
	if ( 'sent' !== $status && in_array( $status, [ 'draft', 'accepted', 'declined' ], true ) && '' === $sent_at ) {
		$sent_at = (string) ( $base['sent_at'] ?? '' );
	}

	return [
		'version'         => 1,
		'status'          => $status,
		'currency'        => $currency,
		'lines'           => $lines,
		'totals'          => $totals,
		'expires_at'      => $expires_at,
		'sent_at'         => $sent_at,
		'customer_note'   => sanitize_textarea_field( (string) ( $input['customer_note'] ?? $base['customer_note'] ?? '' ) ),
		'internal_note'   => sanitize_textarea_field( (string) ( $input['internal_note'] ?? $base['internal_note'] ?? '' ) ),
		'updated_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
	];
}

/**
 * Return the current quote payload for a repair.
 *
 * @param int $repair_id Repair post ID.
 * @return array<string,mixed>
 */
function dtb_repair_get_quote( int $repair_id ): array {
	$raw = (string) get_post_meta( $repair_id, '_repair_quote_payload', true );
	$base = [];
	if ( '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$base = $decoded;
		}
	}

	if ( empty( $base['lines'] ) ) {
		$parts = get_post_meta( $repair_id, '_repair_parts_links', true );
		if ( is_array( $parts ) ) {
			$seed_lines = [];
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				$name = sanitize_text_field( (string) ( $part['name'] ?? '' ) );
				$sku = sanitize_text_field( (string) ( $part['sku'] ?? '' ) );
				if ( '' === $name && '' === $sku ) {
					continue;
				}
				$seed_lines[] = [
					'label'       => '' !== $name ? $name : $sku,
					'description' => '' !== $sku ? sprintf( 'SKU: %s', $sku ) : '',
					'type'        => 'part',
					'quantity'    => max( 1, (float) ( $part['quantity'] ?? 1 ) ),
					'unit_price'  => 0,
				];
			}
			if ( ! empty( $seed_lines ) ) {
				$base['lines'] = $seed_lines;
			}
		}
	}

	return dtb_repair_quote_normalize_payload( $base );
}

/**
 * Compare two normalized quote payloads, ignoring volatile metadata keys.
 *
 * @param array<string,mixed> $left
 * @param array<string,mixed> $right
 * @return bool
 */
function dtb_repair_quote_payload_equals( array $left, array $right ): bool {
	unset( $left['updated_at'], $right['updated_at'] );
	return wp_json_encode( $left ) === wp_json_encode( $right );
}

/**
 * Save quote payload for a repair and return the normalized payload.
 *
 * @param int                 $repair_id Repair post ID.
 * @param array<string,mixed> $input     Input quote payload.
 * @param array<string,mixed> $context   Optional event context.
 * @return array<string,mixed>
 */
function dtb_repair_save_quote( int $repair_id, array $input, array $context = [] ): array {
	$existing = dtb_repair_get_quote( $repair_id );
	$quote    = dtb_repair_quote_normalize_payload( $input, $existing );

	if ( 'sent' === $quote['status'] && '' === $quote['sent_at'] ) {
		$quote['sent_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	$payload_changed = ! dtb_repair_quote_payload_equals( $existing, $quote );
	if ( ! $payload_changed ) {
		return $existing;
	}

	update_post_meta( $repair_id, '_repair_quote_payload', wp_json_encode( $quote ) );
	update_post_meta( $repair_id, '_repair_quote_status', sanitize_text_field( (string) $quote['status'] ) );
	update_post_meta( $repair_id, '_repair_quote_currency', sanitize_text_field( (string) $quote['currency'] ) );
	update_post_meta( $repair_id, '_repair_quote_expires_at', sanitize_text_field( (string) $quote['expires_at'] ) );
	update_post_meta( $repair_id, '_repair_quote_sent_at', sanitize_text_field( (string) $quote['sent_at'] ) );
	update_post_meta( $repair_id, '_repair_quote_updated_at', sanitize_text_field( (string) $quote['updated_at'] ) );

	if ( empty( $context['suppress_event'] ) && function_exists( 'dtb_repair_append_event' ) ) {
		dtb_repair_append_event(
			$repair_id,
			'repair.quote_updated',
			[
				'actor_type' => sanitize_text_field( (string) ( $context['actor_type'] ?? 'admin' ) ),
				'actor_id'   => absint( (int) ( $context['actor_id'] ?? get_current_user_id() ) ) ?: null,
				'source'     => sanitize_text_field( (string) ( $context['source'] ?? 'admin_quote_builder' ) ),
				'visibility' => 'operator',
				'payload'    => [
					'line_count' => count( (array) $quote['lines'] ),
					'total'      => (float) ( $quote['totals']['total'] ?? 0 ),
					'currency'   => (string) ( $quote['currency'] ?? dtb_repair_quote_default_currency() ),
					'status'     => (string) ( $quote['status'] ?? 'draft' ),
				],
			]
		);
	}

	return $quote;
}

/**
 * Convert quote payload to a notification context.
 *
 * @param array<string,mixed> $quote Quote payload.
 * @return array<string,mixed>
 */
function dtb_repair_quote_to_notification_context( array $quote ): array {
	return [
		'quote_lines'       => $quote['lines'] ?? [],
		'quote_totals'      => $quote['totals'] ?? [],
		'quote_currency'    => $quote['currency'] ?? dtb_repair_quote_default_currency(),
		'quote_expires_at'  => $quote['expires_at'] ?? '',
		'quote_customer_note' => $quote['customer_note'] ?? '',
	];
}

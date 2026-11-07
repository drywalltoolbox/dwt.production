<?php
/**
 * DTB Returns — PublicReturnAccessService
 *
 * Public order verification, return-line projection, eligibility and lookup-token
 * helpers. WooCommerce remains the order authority; this service only projects
 * verified order data into the returns domain.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_policy_window_days(): int {
	return max( 1, (int) apply_filters( 'dtb_returns_policy_window_days', 45 ) );
}

function dtb_returns_public_policy(): array {
	return [
		'window_days'        => dtb_returns_policy_window_days(),
		'condition'          => __( 'Unused, like-new items with original packaging, accessories and documentation.', 'drywall-toolbox' ),
		'approval_required'  => __( 'Wait for an approved Return ID before shipping anything back.', 'drywall-toolbox' ),
		'refund_method'      => __( 'Approved refunds are returned to the original payment method after inspection.', 'drywall-toolbox' ),
		'damaged_order_help' => __( 'Damaged, incorrect and defective-item requests are reviewed separately from standard buyer-remorse returns.', 'drywall-toolbox' ),
	];
}

/**
 * Resolve one WooCommerce order from order number + checkout email.
 * A bounded email query supports stores where the presentation order number
 * differs from the numeric WooCommerce order ID.
 */
function dtb_returns_resolve_verified_order( string $order_number, string $customer_email ) {
	$order_number   = ltrim( trim( sanitize_text_field( $order_number ) ), '#' );
	$customer_email = strtolower( sanitize_email( $customer_email ) );

	if ( '' === $order_number || ! is_email( $customer_email ) || ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_returns_order_not_found', __( 'We could not verify that order. Check the order number and checkout email and try again.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$candidates = [];
	if ( ctype_digit( $order_number ) ) {
		$order = wc_get_order( (int) $order_number );
		if ( $order ) {
			$candidates[ (int) $order->get_id() ] = $order;
		}
	}

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( [
			'limit'         => 20,
			'billing_email' => $customer_email,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'return'        => 'objects',
		] );
		foreach ( (array) $orders as $candidate ) {
			if ( $candidate ) {
				$candidates[ (int) $candidate->get_id() ] = $candidate;
			}
		}
	}

	foreach ( $candidates as $candidate ) {
		$billing_email = strtolower( sanitize_email( (string) $candidate->get_billing_email() ) );
		$display_num   = ltrim( (string) $candidate->get_order_number(), '#' );
		if ( hash_equals( $billing_email, $customer_email ) && hash_equals( $display_num, $order_number ) ) {
			return $candidate;
		}
	}

	return new WP_Error( 'dtb_returns_order_not_found', __( 'We could not verify that order. Check the order number and checkout email and try again.', 'drywall-toolbox' ), [ 'status' => 404 ] );
}

function dtb_returns_generate_lookup_token( $order, ?int $ttl = null ): string {
	if ( null === $ttl ) {
		$ttl = (int) apply_filters( 'dtb_returns_lookup_token_ttl', 30 * MINUTE_IN_SECONDS );
	}
	$expires = time() + max( 60, $ttl );
	$email   = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
	$payload = (int) $order->get_id() . ':' . $email . ':' . $expires;
	$hmac    = hash_hmac( 'sha256', $payload, AUTH_KEY );
	return $expires . ':' . $hmac;
}

function dtb_returns_validate_lookup_token( string $token ) {
	$parts = explode( ':', sanitize_text_field( $token ), 2 );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || strlen( $parts[1] ) !== 64 || ! ctype_xdigit( $parts[1] ) ) {
		return new WP_Error( 'dtb_returns_invalid_lookup', __( 'Your order verification has expired. Find the order again.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$expires = (int) $parts[0];
	if ( $expires < time() ) {
		return new WP_Error( 'dtb_returns_invalid_lookup', __( 'Your order verification has expired. Find the order again.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$order_id = (int) get_transient( 'dtb_return_lookup_' . hash( 'sha256', $token ) );
	if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_returns_invalid_lookup', __( 'Your order verification has expired. Find the order again.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'dtb_returns_invalid_lookup', __( 'Your order verification has expired. Find the order again.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$email    = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
	$expected = hash_hmac( 'sha256', $order_id . ':' . $email . ':' . $expires, AUTH_KEY );
	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return new WP_Error( 'dtb_returns_invalid_lookup', __( 'Your order verification has expired. Find the order again.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	return $order;
}

function dtb_returns_store_lookup_token( string $token, int $order_id ): void {
	$expires = (int) strtok( $token, ':' );
	$ttl     = max( 60, $expires - time() );
	set_transient( 'dtb_return_lookup_' . hash( 'sha256', $token ), $order_id, $ttl );
}

/**
 * Project one order line into the public return contract.
 *
 * `eligible_for_request` means the line may be submitted for review. The
 * separate `standard_return_eligible` flag applies the ordinary return window;
 * order/product problems remain reviewable without pretending they are ordinary
 * buyer-remorse returns.
 */
function dtb_returns_project_order_item( $order, int $item_id, $item ): array {
	$product          = $item->get_product();
	$ordered_quantity = max( 0, (int) $item->get_quantity() );
	$refunded_qty     = method_exists( $order, 'get_qty_refunded_for_item' ) ? abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) : 0;
	$returnable_qty   = max( 0, $ordered_quantity - $refunded_qty );
	$date_created     = $order->get_date_created();
	$window_days      = dtb_returns_policy_window_days();
	$within_window    = $date_created ? ( time() <= ( $date_created->getTimestamp() + ( $window_days * DAY_IN_SECONDS ) ) ) : false;
	$requestable      = $returnable_qty > 0;
	$standard_eligible = $requestable && $within_window;
	$note             = '';

	if ( $returnable_qty < 1 ) {
		$note = __( 'No remaining quantity is available for another return request.', 'drywall-toolbox' );
	} elseif ( ! $within_window ) {
		$note = sprintf( __( 'Outside the standard %d-day return window. A damaged, incorrect, or defective-item problem can still be submitted for review.', 'drywall-toolbox' ), $window_days );
	}

	$projection = [
		'item_id'                  => $item_id,
		'product_id'               => (int) $item->get_product_id(),
		'variation_id'             => (int) $item->get_variation_id(),
		'name'                     => wp_strip_all_tags( (string) $item->get_name() ),
		'sku'                      => $product ? (string) $product->get_sku() : '',
		'quantity'                 => $ordered_quantity,
		'returnable_quantity'      => $returnable_qty,
		'eligible_for_request'     => $requestable,
		'standard_return_eligible' => $standard_eligible,
		'eligibility_note'         => $note,
		'image_url'                => '',
	];

	if ( $product && $product->get_image_id() ) {
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
		$projection['image_url'] = $image ? esc_url_raw( $image ) : '';
	}

	/**
	 * Product/policy integrations may narrow either eligibility flag without
	 * duplicating those rules in the React storefront.
	 */
	$projection = (array) apply_filters( 'dtb_returns_public_item_projection', $projection, $item, $order );
	$projection['item_id'] = $item_id;
	$projection['returnable_quantity'] = max( 0, (int) ( $projection['returnable_quantity'] ?? 0 ) );
	$projection['eligible_for_request'] = ! empty( $projection['eligible_for_request'] );
	$projection['standard_return_eligible'] = ! empty( $projection['standard_return_eligible'] ) && $projection['eligible_for_request'];
	$projection['eligibility_note'] = sanitize_text_field( (string) ( $projection['eligibility_note'] ?? '' ) );

	return $projection;
}

function dtb_returns_project_verified_order( $order, string $lookup_token ): array {
	$items = [];
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$items[] = dtb_returns_project_order_item( $order, (int) $item_id, $item );
	}

	$date_created = $order->get_date_created();
	return [
		'order_id'     => (int) $order->get_id(),
		'order_number' => (string) $order->get_order_number(),
		'date'         => $date_created ? $date_created->date_i18n( get_option( 'date_format' ) ) : '',
		'status'       => wc_get_order_status_name( $order->get_status() ),
		'item_count'   => count( $items ),
		'items'        => $items,
		'lookup_token' => $lookup_token,
		'policy'       => dtb_returns_public_policy(),
	];
}

/**
 * Re-validate client-submitted line identity and quantity against WooCommerce.
 */
function dtb_returns_validate_requested_items( $order, array $requested_items, string $request_type ) {
	$available = [];
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$available[ (int) $item_id ] = dtb_returns_project_order_item( $order, (int) $item_id, $item );
	}

	$validated = [];
	$seen      = [];
	foreach ( $requested_items as $requested ) {
		$item_id  = (int) ( $requested['item_id'] ?? 0 );
		$quantity = (int) ( $requested['quantity'] ?? 0 );
		if ( $item_id < 1 || isset( $seen[ $item_id ] ) || ! isset( $available[ $item_id ] ) ) {
			return new WP_Error( 'dtb_returns_invalid_item', __( 'One of the selected return items is invalid. Refresh the order and try again.', 'drywall-toolbox' ), [ 'status' => 400 ] );
		}

		$projection = $available[ $item_id ];
		$eligible   = ! empty( $projection['eligible_for_request'] );
		if ( 'standard_return' === $request_type ) {
			$eligible = ! empty( $projection['standard_return_eligible'] );
		}

		if ( ! $eligible || $quantity < 1 || $quantity > (int) $projection['returnable_quantity'] ) {
			return new WP_Error( 'dtb_returns_ineligible_item', __( 'One of the selected items is not eligible for this request type or quantity.', 'drywall-toolbox' ), [ 'status' => 409 ] );
		}

		$seen[ $item_id ] = true;
		$validated[] = [
			'item_id'      => $item_id,
			'product_id'   => (int) $projection['product_id'],
			'variation_id' => (int) $projection['variation_id'],
			'name'         => sanitize_text_field( (string) $projection['name'] ),
			'sku'          => sanitize_text_field( (string) $projection['sku'] ),
			'quantity'     => $quantity,
		];
	}

	if ( empty( $validated ) ) {
		return new WP_Error( 'dtb_returns_missing_items', __( 'Select at least one eligible item to return.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}
	return $validated;
}

function dtb_returns_find_by_idempotency_key( int $order_id, string $idempotency_key ): int {
	$query = new WP_Query( [
		'post_type'      => 'dtb_return',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'AND',
			[ 'key' => '_dtb_return_order_id', 'value' => (string) $order_id, 'compare' => '=' ],
			[ 'key' => '_dtb_return_idempotency_key', 'value' => $idempotency_key, 'compare' => '=' ],
		],
	] );
	return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
}

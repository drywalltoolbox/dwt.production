<?php
/**
 * DTB Order Customer Timeline — customer-facing event timeline and order formatters.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_get_customer_timeline( int $order_id ): array {
	$events = dtb_order_get_events( $order_id, [ 'visibility' => 'customer', 'order' => 'ASC' ] );

	$timeline = [];
	foreach ( $events as $row ) {
		$timeline[] = [
			'type'        => (string) $row->event_type,
			'label'       => dtb_order_event_customer_label( (string) $row->event_type ),
			'occurred_at' => (string) $row->created_at,
		];
	}
	return $timeline;
}

function dtb_order_format_summary( WC_Order $order ): array {
	$status_proj = dtb_order_build_status_projection( (int) $order->get_id() );
	$order_type  = function_exists( 'dtb_order_resolve_type' )
		? dtb_order_resolve_type( $order )
		: 'product';

	return [
		'id'                 => (int) $order->get_id(),
		'order_type'         => $order_type,
		'status'             => $status_proj['status'],
		'status_label'       => $status_proj['label'],
		'total'              => $order->get_total(),
		'currency'           => $order->get_currency(),
		'date_created'       => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
		'date_modified'      => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
		'items_count'        => count( $order->get_items() ),
		'payment_method'     => $order->get_payment_method(),
		'payment_method_title' => $order->get_payment_method_title(),
		'fulfillment_substate' => dtb_order_get_fulfillment_substate( (int) $order->get_id() ),
	];
}

function dtb_order_format_detail( WC_Order $order ): array {
	$order_id    = (int) $order->get_id();
	$summary     = dtb_order_format_summary( $order );
	$tracking    = dtb_order_get_tracking_projection( $order_id );

	$items = [];
	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$items[] = function_exists( 'dtb_order_format_product_item' )
			? dtb_order_format_product_item( $item )
			: [
				'id'           => (int) $item->get_id(),
				'name'         => wp_strip_all_tags( $item->get_name() ),
				'quantity'     => (int) $item->get_quantity(),
				'total'        => $item->get_total(),
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
			];
	}

	$billing = [
		'first_name' => $order->get_billing_first_name(),
		'last_name'  => $order->get_billing_last_name(),
		'address_1'  => $order->get_billing_address_1(),
		'address_2'  => $order->get_billing_address_2(),
		'city'       => $order->get_billing_city(),
		'state'      => $order->get_billing_state(),
		'postcode'   => $order->get_billing_postcode(),
		'country'    => $order->get_billing_country(),
		'email'      => $order->get_billing_email(),
		'phone'      => $order->get_billing_phone(),
	];

	$shipping = [
		'first_name' => $order->get_shipping_first_name(),
		'last_name'  => $order->get_shipping_last_name(),
		'address_1'  => $order->get_shipping_address_1(),
		'address_2'  => $order->get_shipping_address_2(),
		'city'       => $order->get_shipping_city(),
		'state'      => $order->get_shipping_state(),
		'postcode'   => $order->get_shipping_postcode(),
		'country'    => $order->get_shipping_country(),
	];

	return array_merge( $summary, [
		'line_items'          => $items,
		'billing'             => $billing,
		'shipping'            => $shipping,
		'subtotal'            => $order->get_subtotal(),
		'shipping_total'      => $order->get_shipping_total(),
		'total_tax'           => $order->get_total_tax(),
		'discount_total'      => $order->get_discount_total(),
		'customer_note'       => wp_strip_all_tags( $order->get_customer_note() ),
		'tracking'            => $tracking,
		'timeline'            => $tracking['timeline'] ?? [],
	] );
}

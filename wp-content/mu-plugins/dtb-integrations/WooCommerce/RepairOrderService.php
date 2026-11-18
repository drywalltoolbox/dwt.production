<?php
/**
 * WooCommerce repair-order integration helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_WooRepairOrderService' ) ) {
	return;
}

final class DTB_WooRepairOrderService {
	/**
	 * Calculate repair quote totals using the repair module when available.
	 *
	 * @param array<int,array<string,mixed>> $lines Quote lines.
	 * @return array<string,mixed>
	 */
	public static function quote_totals( array $lines ): array {
		if ( function_exists( 'dtb_repair_calculate_totals' ) ) {
			return (array) dtb_repair_calculate_totals( $lines );
		}

		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			$qty      = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$price    = (float) ( $line['price'] ?? 0 );
			$subtotal += $qty * $price;
		}

		return [
			'subtotal' => round( $subtotal, 2 ),
			'total'    => round( $subtotal, 2 ),
			'currency' => get_woocommerce_currency(),
		];
	}

	/**
	 * Find the WooCommerce order linked to a repair request.
	 *
	 * @param int $repair_id Repair request post ID.
	 * @return WC_Order|null
	 */
	public static function find_order_for_repair( int $repair_id ): ?WC_Order {
		if ( $repair_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order_id = (int) get_post_meta( $repair_id, '_repair_wc_order_id', true );
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}
}

/**
 * Backward-compatible repair quote total wrapper.
 *
 * @param array<int,array<string,mixed>> $lines Quote lines.
 * @return array<string,mixed>
 */
function dtb_integrations_woo_repair_quote_totals( array $lines ): array {
	return DTB_WooRepairOrderService::quote_totals( $lines );
}

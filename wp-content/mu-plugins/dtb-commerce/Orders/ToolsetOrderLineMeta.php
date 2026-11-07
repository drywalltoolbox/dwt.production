<?php
/**
 * Toolset order line metadata persistence.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetOrderLineMeta {
	/** @var string[] */
	private const ALLOWLIST_KEYS = [
		'_dtb_toolset_id',
		'_dtb_toolset_instance_id',
		'_dtb_toolset_slot',
		'_dtb_toolset_slot_label',
		'_dtb_toolset_brand',
		'_dtb_toolset_scope',
		'_dtb_included_item',
	];

	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'persist_order_line_meta' ], 20, 4 );
	}

	/**
	 * Persist allowed DTB toolset metadata onto order line items.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $_cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $_order
	 * @return void
	 */
	public static function persist_order_line_meta( WC_Order_Item_Product $item, string $_cart_item_key, array $values, WC_Order $_order ): void {
		$meta = $values['dtb_toolset_meta'] ?? null;
		if ( ! is_array( $meta ) || [] === $meta ) {
			return;
		}

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! in_array( $key, self::ALLOWLIST_KEYS, true ) ) {
				continue;
			}
			$val = sanitize_text_field( (string) $value );
			if ( '' === $val ) {
				continue;
			}
			$item->add_meta_data( $key, $val, true );
		}
	}
}

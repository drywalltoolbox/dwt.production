<?php
/**
 * Toolset cart item data bridge.
 *
 * Accepts a constrained DTB metadata payload from Store API extensions and
 * stores sanitized values in Woo cart item data for later order persistence.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetCartItemData {
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

	/** @var string[] */
	private const REQUIRED_KEYS = [
		'_dtb_toolset_id',
		'_dtb_toolset_instance_id',
		'_dtb_toolset_slot',
	];

	public static function register(): void {
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ self::class, 'filter_store_api_add_to_cart_data' ], 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'filter_add_cart_item_data' ], 20, 3 );
	}

	/**
	 * Move sanitized Store API extension metadata into cart item data.
	 *
	 * @param array           $add_to_cart_data
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function filter_store_api_add_to_cart_data( array $add_to_cart_data, WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return $add_to_cart_data;
		}

		$incoming = $params['extensions']['dtb']['metadata'] ?? null;
		$meta     = self::sanitize_metadata_pairs( $incoming );
		if ( [] === $meta ) {
			return $add_to_cart_data;
		}

		$product_id   = absint( $params['id'] ?? 0 );
		$variation_id = 0;

		if ( ! self::validate_toolset_metadata( $meta, $product_id, $variation_id ) ) {
			return $add_to_cart_data;
		}

		$add_to_cart_data['dtb_toolset_meta'] = $meta;
		return $add_to_cart_data;
	}

	/**
	 * Ensure cart item data carries sanitized metadata in non-Store-API flows too.
	 *
	 * @param array $cart_item_data
	 * @param int   $_product_id
	 * @param int   $_variation_id
	 * @return array
	 */
	public static function filter_add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		$incoming = $cart_item_data['dtb_toolset_meta'] ?? null;
		$meta     = self::sanitize_metadata_assoc( $incoming );

		if ( [] === $meta ) {
			unset( $cart_item_data['dtb_toolset_meta'] );
			return $cart_item_data;
		}

		if ( ! self::validate_toolset_metadata( $meta, $product_id, $variation_id ) ) {
			unset( $cart_item_data['dtb_toolset_meta'] );
			return $cart_item_data;
		}

		$cart_item_data['dtb_toolset_meta'] = $meta;
		return $cart_item_data;
	}

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private static function sanitize_metadata_assoc( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $key => $val ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, self::ALLOWLIST_KEYS, true ) ) {
				continue;
			}
			$normalized = self::normalize_value( $key, $val );
			if ( null === $normalized ) {
				continue;
			}
			$out[ $key ] = $normalized;
		}

		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private static function sanitize_metadata_pairs( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $entry['key'] ?? '' ) );
			if ( ! in_array( $key, self::ALLOWLIST_KEYS, true ) ) {
				continue;
			}
			$normalized = self::normalize_value( $key, $entry['value'] ?? null );
			if ( null === $normalized ) {
				continue;
			}
			$out[ $key ] = $normalized;
		}

		return $out;
	}

	private static function normalize_value( string $key, mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$raw = wp_strip_all_tags( (string) $value );
		if ( '' === $raw ) {
			return null;
		}

		if ( '_dtb_included_item' === $key ) {
			return in_array( strtolower( $raw ), [ '1', 'true', 'yes' ], true ) ? '1' : '0';
		}

		return sanitize_text_field( $raw );
	}

	/**
	 * Validate toolset metadata against template + product eligibility.
	 *
	 * @param array<string,string> $meta
	 */
	private static function validate_toolset_metadata( array $meta, int $product_id, int $variation_id ): bool {
		foreach ( self::REQUIRED_KEYS as $required_key ) {
			if ( '' === ( $meta[ $required_key ] ?? '' ) ) {
				return false;
			}
		}

		if ( ! class_exists( 'DTB_ToolsetData' ) ) {
			return false;
		}

		$template_id = $meta['_dtb_toolset_id'];
		$slot_id     = $meta['_dtb_toolset_slot'];
		$instance_id = $meta['_dtb_toolset_instance_id'];

		if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $template_id ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $slot_id ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-z0-9._-]{1,120}$/i', $instance_id ) ) {
			return false;
		}

		$template = DTB_ToolsetData::get_by_id( $template_id );
		if ( ! is_array( $template ) || [] === $template ) {
			return false;
		}

		$template_slots = [];
		foreach ( $template['slots'] ?? [] as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $slot['id'] ?? '' ) );
			if ( '' !== $id ) {
				$template_slots[] = $id;
			}
		}

		if ( ! in_array( $slot_id, $template_slots, true ) ) {
			return false;
		}

		$meta_brand = sanitize_title( (string) ( $meta['_dtb_toolset_brand'] ?? '' ) );
		$meta_scope = sanitize_key( (string) ( $meta['_dtb_toolset_scope'] ?? '' ) );

		$template_brand = sanitize_title( (string) ( $template['brandKey'] ?? '' ) );
		$template_scope = sanitize_key( (string) ( $template['scope'] ?? '' ) );

		if ( '' !== $meta_brand && '' !== $template_brand && $meta_brand !== $template_brand ) {
			return false;
		}
		if ( '' !== $meta_scope && '' !== $template_scope && $meta_scope !== $template_scope ) {
			return false;
		}

		$purchasable_id = $variation_id > 0 ? $variation_id : $product_id;
		if ( $purchasable_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $purchasable_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return false;
		}

		if ( $variation_id > 0 ) {
			$resolved_parent = method_exists( $product, 'get_parent_id' ) ? absint( $product->get_parent_id() ) : 0;
			if ( $resolved_parent <= 0 || ( $product_id > 0 && $resolved_parent !== $product_id ) ) {
				return false;
			}
			$product_id = $resolved_parent;
		}

		$brand_meta_key = class_exists( 'DTB_ProductMeta' ) ? DTB_ProductMeta::BRAND_KEY : '_dtb_brand_key';
		$slots_meta_key = class_exists( 'DTB_ProductMeta' ) ? DTB_ProductMeta::BUILDER_SLOTS : '_dtb_builder_slots';

		$product_brand = sanitize_title( (string) get_post_meta( $product_id, $brand_meta_key, true ) );
		if ( '' !== $template_brand && '' !== $product_brand && $template_brand !== $product_brand ) {
			return false;
		}

		$eligible_slots = self::decode_slots( get_post_meta( $purchasable_id, $slots_meta_key, true ) );
		if ( [] === $eligible_slots && $variation_id > 0 ) {
			$eligible_slots = self::decode_slots( get_post_meta( $product_id, $slots_meta_key, true ) );
		}

		return in_array( $slot_id, $eligible_slots, true );
	}

	/**
	 * Normalize builder slot metadata into a clean string list.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function decode_slots( mixed $raw ): array {
		if ( class_exists( 'DTB_CatalogProductNormalizer' ) ) {
			$decoded = DTB_CatalogProductNormalizer::decode_csv_or_array( $raw );
			$decoded = is_array( $decoded ) ? $decoded : [];
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = array_map( 'trim', explode( ',', (string) $raw ) );
		}

		$out = [];
		foreach ( $decoded as $slot ) {
			$slot = sanitize_key( (string) $slot );
			if ( '' !== $slot ) {
				$out[] = $slot;
			}
		}

		return array_values( array_unique( $out ) );
	}
}

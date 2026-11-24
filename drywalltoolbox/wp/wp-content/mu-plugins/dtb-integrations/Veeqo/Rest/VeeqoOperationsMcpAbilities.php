<?php
/**
 * Veeqo administrative MCP abilities backed by the existing DTB operator controller.
 *
 * This is deliberately not an arbitrary Veeqo API proxy. WooCommerce order authority,
 * provider ownership, DTB runtime gates, preview/apply validation and audit remain intact.
 *
 * @package drywalltoolbox
 */
defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Operations_MCP_Abilities {
	private const CATEGORY = 'dtb-veeqo-operations';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register' ] );
	}

	public static function category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( self::CATEGORY, [
				'label' => 'DTB Veeqo Operations',
				'description' => 'Existing authenticated Veeqo control-center reads and guarded operations.',
			] );
		}
	}

	public static function allowed(): bool {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	private static function object( array $properties = [], array $required = [] ): array {
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => false,
		];
	}

	private static function positive_id(): array {
		return [ 'type' => 'integer', 'minimum' => 1 ];
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'DTB_Veeqo_Admin_Controller' ) ) {
			return;
		}

		$pagination = [
			'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 10, 'maximum' => 100 ],
			'search' => [ 'type' => 'string', 'maxLength' => 120 ],
		];
		$specs = [
			'overview' => [ 'Read Veeqo control center overview', 'overview', self::object(), true ],
			'settings' => [ 'Read Veeqo operational configuration (redacted)', 'settings', self::object(), true ],
			'inventory' => [ 'Read bounded DTB Veeqo inventory projection', 'inventory', self::object( $pagination + [
				'mapping' => [ 'type' => 'string', 'enum' => [ '', 'mapped', 'unmapped', 'mismatch' ] ],
				'stock_status' => [ 'type' => 'string', 'enum' => [ '', 'instock', 'lowstock', 'outofstock', 'onbackorder', 'negative', 'committed_exceeds_on_hand' ] ],
			] ), true ],
			'inventory-item' => [ 'Read exact WooCommerce product inventory projection', 'inventory_item', self::object( [ 'product_id' => self::positive_id() ], [ 'product_id' ] ), true ],
			'orders' => [ 'Read bounded Veeqo order projection', 'orders', self::object( $pagination + [ 'status' => [ 'type' => 'string', 'maxLength' => 40 ] ] ), true ],
			'fulfillment' => [ 'Read bounded Veeqo fulfillment projection', 'fulfillment', self::object( $pagination + [ 'status' => [ 'type' => 'string', 'maxLength' => 40 ] ] ), true ],
			'operations' => [ 'Read DTB Veeqo operations status', 'operations', self::object(), true ],
			'operation' => [ 'Read exact DTB Veeqo operation status', 'operation', self::object( [ 'operation_id' => [ 'type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'maxLength' => 100 ] ], [ 'operation_id' ] ), true ],
			'sku' => [ 'Resolve exact SKU in DTB Veeqo projection', 'sku', self::object( [ 'sku' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ] ], [ 'sku' ] ), true ],
			'mapping-diagnostics' => [ 'Read last inventory mapping exceptions and classify current WooCommerce SKU matches without changing stock', 'mapping_diagnostics', self::object(), true ],
			'inventory-preview' => [ 'Preview guarded WooCommerce inventory workspace product metadata changes', 'bulk_preview', self::object( [
				'product_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => self::positive_id() ],
				'changes' => [ 'type' => 'object', 'properties' => [
					'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private', 'pending' ] ],
					'catalog_visibility' => [ 'type' => 'string', 'enum' => [ 'visible', 'catalog', 'search', 'hidden' ] ],
					'backorders' => [ 'type' => 'string', 'enum' => [ 'no', 'notify', 'yes' ] ],
					'low_stock_amount' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ],
				], 'additionalProperties' => false ],
			], [ 'product_ids', 'changes' ] ), false ],
			'inventory-apply' => [ 'Apply previewed WooCommerce product metadata; preview hash bound to operator and exact changes', 'bulk_apply', self::object( [
				'product_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => self::positive_id() ],
				'changes' => [ 'type' => 'object' ],
				'preview_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			], [ 'product_ids', 'changes', 'preview_hash' ] ), false ],
		];

		foreach ( $specs as $slug => $spec ) {
			[ $label, $callback, $schema, $readonly ] = $spec;
			wp_register_ability( self::CATEGORY . '/' . $slug, [
				'label' => $label,
				'description' => $label . '. DTB-owned scope and provider gates remain authoritative.',
				'category' => self::CATEGORY,
				'input_schema' => $schema,
				'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],
				'permission_callback' => [ __CLASS__, 'allowed' ],
				'execute_callback' => static function ( $input ) use ( $callback ) {
					if ( ! self::allowed() ) {
						return new WP_Error( 'dtb_veeqo_forbidden', 'Permission denied.', [ 'status' => 403 ] );
					}
					if ( 'mapping_diagnostics' === $callback ) {
						return self::mapping_diagnostics();
					}
					$params = is_array( $input ) ? $input : [];
					$request = new WP_REST_Request( 'GET', '/dtb/v1/veeqo/admin/control-center' );
					$request->set_header( 'Content-Type', 'application/json' );
					$request->set_body( wp_json_encode( $params ) );
					foreach ( $params as $key => $value ) {
						$request->set_param( $key, $value );
					}
					$result = DTB_Veeqo_Admin_Controller::$callback( $request );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return $result instanceof WP_REST_Response ? $result->get_data() : $result;
				},
				'meta' => [
					'public' => true,
					'show_in_rest' => true,
					'annotations' => [
						'readonly' => $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					],
				],
			] );
		}
	}
	/**
	 * Reclassify the previous sync's unmapped SKUs against today's WooCommerce
	 * product index. This does not call Veeqo or alter stock/order state.
	 */
	public static function mapping_diagnostics(): array {
		$option = defined( 'DTB_VEEQO_INVENTORY_DIAGNOSTICS' ) ? DTB_VEEQO_INVENTORY_DIAGNOSTICS : 'dtb_veeqo_inventory_reconciliation_diagnostics';
		$report = (array) get_option( $option, [] );
		$skus = array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) ( $report['unmapped_skus'] ?? [] ) ) ) ) ), 0, 250 );
		$groups = [ 'now_simple_or_variation' => [], 'now_variable_parent' => [], 'still_missing' => [], 'unrecognized_product_type' => [] ];
		foreach ( $skus as $sku ) {
			$id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			$product = $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			if ( ! $product instanceof WC_Product ) {
				$groups['still_missing'][] = $sku;
				continue;
			}
			$type = $product->get_type();
			$entry = [ 'sku' => $sku, 'woo_product_id' => $id, 'type' => $type, 'status' => $product->get_status() ];
			if ( in_array( $type, [ 'simple', 'variation' ], true ) ) {
				$groups['now_simple_or_variation'][] = $entry;
			} elseif ( 'variable' === $type ) {
				$groups['now_variable_parent'][] = $entry;
			} else {
				$groups['unrecognized_product_type'][] = $entry;
			}
		}
		return [
			'last_reconciliation_at' => sanitize_text_field( (string) ( $report['completed_at'] ?? '' ) ),
			'historical_unmapped_count' => count( (array) ( $report['unmapped_skus'] ?? [] ) ),
			'checked_count' => count( $skus ),
			'groups' => $groups,
			'requires_fresh_provider_reconciliation' => true,
		];
	}

}
DTB_Veeqo_Operations_MCP_Abilities::init();

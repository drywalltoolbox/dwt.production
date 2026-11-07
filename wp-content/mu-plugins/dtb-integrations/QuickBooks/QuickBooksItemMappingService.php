<?php
/**
 * QuickBooks accounting item discovery and environment-scoped mapping.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksItemMappingService {
	/**
	 * Canonical QuickBooks service items used by the accounting projection.
	 *
	 * @return array<string, array{name:string,label:string,description:string}>
	 */
	public static function definitions(): array {
		return [
			'product'  => [
				'name'        => 'DTB Product Sales',
				'label'       => __( 'Product sales', 'drywall-toolbox' ),
				'description' => __( 'Merchandise and parts sold through WooCommerce.', 'drywall-toolbox' ),
			],
			'shipping' => [
				'name'        => 'DTB Shipping',
				'label'       => __( 'Shipping', 'drywall-toolbox' ),
				'description' => __( 'Customer shipping and delivery charges.', 'drywall-toolbox' ),
			],
			'fee'      => [
				'name'        => 'DTB Order Fees',
				'label'       => __( 'Order fees', 'drywall-toolbox' ),
				'description' => __( 'WooCommerce surcharges and other customer-facing order fees.', 'drywall-toolbox' ),
			],
			'discount' => [
				'name'        => 'DTB Discount',
				'label'       => __( 'Discounts', 'drywall-toolbox' ),
				'description' => __( 'Order-level coupons and promotional reductions.', 'drywall-toolbox' ),
			],
			'refund'   => [
				'name'        => 'DTB Refund',
				'label'       => __( 'Refunds', 'drywall-toolbox' ),
				'description' => __( 'Concrete WooCommerce refund projections.', 'drywall-toolbox' ),
			],
		];
	}

	/**
	 * Return the effective local mapping for all required item roles.
	 *
	 * Constants remain supported as an explicit operator override. Managed
	 * mappings are isolated by active QuickBooks environment and are only ready
	 * when they were verified against the currently connected company realm.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function status(): array {
		$items        = [];
		$verification = self::verification_state();

		foreach ( self::definitions() as $key => $definition ) {
			$id_constant   = 'DTB_QBO_ITEM_' . strtoupper( $key ) . '_ID';
			$name_constant = 'DTB_QBO_ITEM_' . strtoupper( $key ) . '_NAME';
			$option_id     = dtb_qbo_option_name( 'item_' . $key . '_id' );
			$option_name   = dtb_qbo_option_name( 'item_' . $key . '_name' );
			$legacy_id     = 'dtb_qbo_item_' . $key . '_id';
			$legacy_name   = 'dtb_qbo_item_' . $key . '_name';

			$source = 'unconfigured';
			$id     = '';
			$name   = $definition['name'];

			if ( defined( $id_constant ) ) {
				$id     = sanitize_text_field( (string) constant( $id_constant ) );
				$name   = defined( $name_constant ) ? sanitize_text_field( (string) constant( $name_constant ) ) : $name;
				$source = 'constant';
			} else {
				$id   = sanitize_text_field( (string) get_option( $option_id, '' ) );
				$name = sanitize_text_field( (string) get_option( $option_name, $name ) );

				if ( '' !== $id ) {
					$source = 'managed';
				} else {
					$id   = sanitize_text_field( (string) get_option( $legacy_id, '' ) );
					$name = sanitize_text_field( (string) get_option( $legacy_name, $name ) );
					if ( '' !== $id ) {
						$source = 'legacy';
					}
				}
			}

			$configured = '' !== $id;
			$verified   = $configured && $verification['verified'];

			$items[ $key ] = [
				'key'         => $key,
				'label'       => $definition['label'],
				'description' => $definition['description'],
				'expected'    => $definition['name'],
				'id'          => $id,
				'name'        => $name,
				'configured'  => $configured,
				'verified'    => $verified,
				'stale'       => $configured && ! $verified,
				'verified_at' => $verified ? $verification['verified_at'] : '',
				'source'      => $source,
				'locked'      => 'constant' === $source,
			];
		}

		return $items;
	}

	/** Determine whether every required accounting item is verified. */
	public static function ready(): bool {
		foreach ( self::status() as $item ) {
			if ( empty( $item['verified'] ) ) {
				return false;
			}
		}

		return true;
	}

	/** Determine whether one required accounting role is verified. */
	public static function item_ready( string $key ): bool {
		$key   = sanitize_key( $key );
		$items = self::status();
		return ! empty( $items[ $key ]['verified'] );
	}

	/**
	 * Discover exact-name service items in the connected QuickBooks company.
	 *
	 * This operation is idempotent and does not create or mutate remote records.
	 * It persists only a complete verified mapping set in environment-scoped
	 * WordPress options. Constant-backed mappings remain immutable.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function discover_and_store(): array|WP_Error {
		if ( ! dtb_qbo_enabled() ) {
			return new WP_Error(
				'qbo_not_connected',
				__( 'QuickBooks must be connected before accounting items can be discovered.', 'drywall-toolbox' ),
				[ 'status' => 409 ]
			);
		}

		$realm_hash = self::current_realm_hash();
		if ( '' === $realm_hash ) {
			return new WP_Error(
				'qbo_realm_unavailable',
				__( 'The connected QuickBooks company realm could not be verified.', 'drywall-toolbox' ),
				[ 'status' => 409 ]
			);
		}

		$current     = self::status();
		$definitions = self::definitions();
		$name_to_key = [];
		$quoted      = [];

		foreach ( $definitions as $key => $definition ) {
			$name                = (string) $definition['name'];
			$name_to_key[ $name ] = $key;
			$escaped             = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $name );
			$quoted[]            = "'{$escaped}'";
		}

		// QuickBooks query projections are unsupported. One bounded IN query
		// returns complete Item entities and avoids four sequential network calls.
		$response = dtb_qbo_request(
			'GET',
			'/query',
			[
				'query' => 'SELECT * FROM Item WHERE Name IN (' . implode( ',', $quoted ) . ') AND Active IN (true, false) MAXRESULTS 10',
			]
		);

		if ( empty( $response['ok'] ) ) {
			return new WP_Error(
				'qbo_item_discovery_failed',
				sanitize_text_field( (string) ( $response['error'] ?? __( 'QuickBooks item discovery failed.', 'drywall-toolbox' ) ) ),
				[
					'status'     => 502,
					'retryable'  => (bool) ( $response['retryable'] ?? false ),
					'intuit_tid' => sanitize_text_field( (string) ( $response['intuit_tid'] ?? '' ) ),
				]
			);
		}

		$matches_by_name = [];
		foreach ( (array) ( $response['data']['QueryResponse']['Item'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $item['Name'] ?? '' ) );
			if ( ! isset( $name_to_key[ $name ] ) ) {
				continue;
			}

			$matches_by_name[ $name ][] = $item;
		}

		$results = [];
		$resolved = [];
		$ready    = true;

		foreach ( $definitions as $key => $definition ) {
			$name    = (string) $definition['name'];
			$matches = (array) ( $matches_by_name[ $name ] ?? [] );
			$result  = [
				'key'      => $key,
				'expected' => $name,
				'status'   => 'missing',
				'id'       => '',
				'name'     => '',
				'type'     => '',
				'active'   => false,
				'source'   => $current[ $key ]['source'],
			];

			if ( count( $matches ) > 1 ) {
				$result['status'] = 'ambiguous';
				$results[ $key ]  = $result;
				$ready            = false;
				continue;
			}

			$item = $matches[0] ?? null;
			if ( ! is_array( $item ) ) {
				$results[ $key ] = $result;
				$ready           = false;
				continue;
			}

			$result['id']     = sanitize_text_field( (string) ( $item['Id'] ?? '' ) );
			$result['name']   = sanitize_text_field( (string) ( $item['Name'] ?? '' ) );
			$result['type']   = sanitize_text_field( (string) ( $item['Type'] ?? '' ) );
			$result['active'] = ! array_key_exists( 'Active', $item ) || rest_sanitize_boolean( $item['Active'] );

			if ( '' === $result['id'] || $name !== $result['name'] ) {
				$result['status'] = 'invalid';
				$results[ $key ]  = $result;
				$ready            = false;
				continue;
			}

			if ( 'Service' !== $result['type'] || ! $result['active'] ) {
				$result['status'] = 'incompatible';
				$results[ $key ]  = $result;
				$ready            = false;
				continue;
			}

			if ( ! empty( $current[ $key ]['locked'] ) ) {
				$result['status'] = hash_equals( (string) $current[ $key ]['id'], $result['id'] ) ? 'verified' : 'constant_conflict';
				if ( 'verified' !== $result['status'] ) {
					$ready = false;
				}
			} else {
				$result['status'] = 'mapped';
				$result['source'] = 'managed';
				$resolved[ $key ] = [
					'id'   => $result['id'],
					'name' => $result['name'],
				];
			}

			$results[ $key ] = $result;
		}

		if ( $ready ) {
			foreach ( $resolved as $key => $mapping ) {
				update_option( dtb_qbo_option_name( 'item_' . $key . '_id' ), $mapping['id'], false );
				update_option( dtb_qbo_option_name( 'item_' . $key . '_name' ), $mapping['name'], false );
			}

			foreach ( $resolved as $key => $mapping ) {
				$stored_id   = sanitize_text_field( (string) get_option( dtb_qbo_option_name( 'item_' . $key . '_id' ), '' ) );
				$stored_name = sanitize_text_field( (string) get_option( dtb_qbo_option_name( 'item_' . $key . '_name' ), '' ) );
				if ( ! hash_equals( $mapping['id'], $stored_id ) || ! hash_equals( $mapping['name'], $stored_name ) ) {
					$ready = false;
					break;
				}
			}
		}

		if ( $ready ) {
			update_option( dtb_qbo_option_name( 'item_mapping_realm_hash' ), $realm_hash, false );
			update_option( dtb_qbo_option_name( 'item_mapping_verified_at' ), gmdate( 'c' ), false );
		} else {
			delete_option( dtb_qbo_option_name( 'item_mapping_realm_hash' ) );
			delete_option( dtb_qbo_option_name( 'item_mapping_verified_at' ) );
		}

		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log(
				'qbo_item_mapping_discovered',
				[
					'environment' => dtb_qbo_environment(),
					'ready'       => $ready,
					'statuses'    => array_map( static fn( array $item ): string => (string) $item['status'], $results ),
				]
			);
		}

		return [
			'ok'           => true,
			'environment'  => dtb_qbo_environment(),
			'ready'        => $ready,
			'items'        => $results,
			'mappings'     => self::status(),
			'verified_at'  => $ready ? (string) get_option( dtb_qbo_option_name( 'item_mapping_verified_at' ), '' ) : '',
		];
	}

	/**
	 * Return realm-bound mapping verification state without exposing the realm.
	 *
	 * @return array{verified:bool,verified_at:string}
	 */
	private static function verification_state(): array {
		$current_hash = self::current_realm_hash();
		$stored_hash  = sanitize_text_field( (string) get_option( dtb_qbo_option_name( 'item_mapping_realm_hash' ), '' ) );
		$verified     = '' !== $current_hash && '' !== $stored_hash && hash_equals( $stored_hash, $current_hash );

		return [
			'verified'    => $verified,
			'verified_at' => $verified ? sanitize_text_field( (string) get_option( dtb_qbo_option_name( 'item_mapping_verified_at' ), '' ) ) : '',
		];
	}

	/** Build an environment-bound hash for the currently connected company. */
	private static function current_realm_hash(): string {
		$config = dtb_qbo_config();
		$realm  = preg_replace( '/[^0-9]/', '', (string) ( $config['realm_id'] ?? '' ) );
		if ( '' === $realm ) {
			return '';
		}

		return hash( 'sha256', dtb_qbo_environment() . '|' . $realm );
	}
}

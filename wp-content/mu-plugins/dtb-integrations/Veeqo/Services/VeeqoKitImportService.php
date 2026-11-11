<?php
/**
 * Guarded native-kit conversion. Veeqo alone owns kit/inventory state.
 *
 * Deliberately requires the separately reviewed DTB_VEEQO_KIT_WRITES_ENABLED
 * configuration, and never retries an ambiguous upstream mutation.
 *
 * @package drywalltoolbox
 */
defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Kit_Import_Service {
	private const OPTION_PREFIX = 'dtb_veeqo_kit_import_v1_';

	public static function enabled(): bool {
		return defined( 'DTB_VEEQO_KIT_WRITES_ENABLED' ) && true === DTB_VEEQO_KIT_WRITES_ENABLED;
	}

	public static function validate( array $payload ) {
		$parent = $payload['parent_sellable_id'] ?? null;
		$sku    = $payload['parent_sku'] ?? null;
		$rows   = $payload['components'] ?? null;
		if ( ! is_int( $parent ) || $parent < 1 || ! is_string( $sku ) || ! preg_match( '/^[A-Za-z0-9._\/ -]{1,64}$/D', $sku )
			|| ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) < 1 || count( $rows ) > 25 ) {
			return new WP_Error( 'dtb_kit_invalid_payload', 'Invalid kit parent, SKU or component list.', [ 'status' => 400 ] );
		}
		$components = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['sellable_id'], $row['quantity'], $row['sku'] )
				|| ! is_int( $row['sellable_id'] ) || $row['sellable_id'] < 1 || $row['sellable_id'] === $parent
				|| ! is_int( $row['quantity'] ) || $row['quantity'] < 1 || $row['quantity'] > 10000
				|| ! is_string( $row['sku'] ) || ! preg_match( '/^[A-Za-z0-9._\/ -]{1,64}$/D', $row['sku'] )
				|| isset( $components[ $row['sellable_id'] ] ) ) {
				return new WP_Error( 'dtb_kit_invalid_component', 'Duplicate, self-referencing or invalid component.', [ 'status' => 400 ] );
			}
			$components[ $row['sellable_id'] ] = [ 'product_variant_id' => $row['sellable_id'], 'sku' => $row['sku'], 'quantity' => $row['quantity'] ];
		}
		ksort( $components, SORT_NUMERIC );
		return [ 'parent_sellable_id' => $parent, 'parent_sku' => $sku, 'components' => array_values( $components ) ];
	}

	private static function record_name( int $parent ): string {
		return self::OPTION_PREFIX . $parent;
	}

	/**
	 * Verify ID and exact SKU against Veeqo before any mutation.
	 * Requires native Veeqo sellable to be a ProductVariant, not an existing Kit.
	 */
	private static function sellable( int $id, string $sku ) {
		$result = dtb_veeqo_request( 'GET', '/sellables/' . $id );
		if ( empty( $result['ok'] ) || ! is_array( $result['data'] ?? null ) ) {
			return new WP_Error( 'dtb_kit_sellable_unresolved', 'Veeqo sellable lookup failed.', [ 'status' => 409 ] );
		}
		$actual = $result['data'];
		if ( (int) ( $actual['id'] ?? 0 ) !== $id || (string) ( $actual['sku_code'] ?? '' ) !== $sku
			|| (string) ( $actual['type'] ?? '' ) !== 'ProductVariant' ) {
			return new WP_Error( 'dtb_kit_sellable_mismatch', 'Sellable identity, SKU, or type differs from expected.', [ 'status' => 409 ] );
		}
		return $actual;
	}

	public static function preview( array $payload ) {
		$desired = self::validate( $payload );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}
		$parent = $desired['parent_sellable_id'];
		$existing = get_option( self::record_name( $parent ), null );
		if ( null !== $existing ) {
			return [ 'ready' => false, 'reason' => 'previous_operation_present', 'status' => $existing['status'] ?? 'unknown', 'write_enabled' => self::enabled() ];
		}
		if ( ! function_exists( 'dtb_veeqo_request' ) ) {
			return new WP_Error( 'dtb_veeqo_client_unavailable', 'Veeqo API client is unavailable.', [ 'status' => 503 ] );
		}
		$check = self::sellable( $parent, $desired['parent_sku'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		foreach ( $desired['components'] as $part ) {
			$check = self::sellable( $part['product_variant_id'], $part['sku'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		return [ 'ready' => true, 'write_enabled' => self::enabled(), 'parent_sellable_id' => $parent, 'component_count' => count( $desired['components'] ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $desired ) ) ];
	}

	/**
	 * Synchronous one-shot operation; never executed from a retrying queue.
	 * Persist intent before POST. Unknown results remain blocked for review.
	 */
	public static function convert( array $payload, string $approved_fingerprint ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'dtb_kit_writes_disabled', 'Native kit writes are disabled.', [ 'status' => 503 ] );
		}
		$desired = self::validate( $payload );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}
		$preflight = self::preview( $payload );
		if ( is_wp_error( $preflight ) || empty( $preflight['ready'] ) ) {
			return is_wp_error( $preflight ) ? $preflight : new WP_Error( 'dtb_kit_preflight_blocked', 'Kit preflight is blocked.', [ 'status' => 409 ] );
		}
		if ( ! hash_equals( (string) $preflight['fingerprint'], $approved_fingerprint ) ) {
			return new WP_Error( 'dtb_kit_approval_mismatch', 'Approved BOM fingerprint does not match.', [ 'status' => 409 ] );
		}
		$parent = $desired['parent_sellable_id'];
		$option = self::record_name( $parent );
		$record = [ 'status' => 'uncertain', 'parent_sellable_id' => $parent, 'fingerprint' => $approved_fingerprint,
			'created_at' => gmdate( 'c' ), 'operator_id' => get_current_user_id() ];
		if ( ! add_option( $option, $record, '', false ) ) {
			return new WP_Error( 'dtb_kit_duplicate_operation', 'A kit operation already exists for this parent. Reconcile it before proceeding.', [ 'status' => 409 ] );
		}
		$contents = array_map( static fn( array $part ): array => [
			'product_variant_id' => $part['product_variant_id'], 'quantity' => $part['quantity'],
		], $desired['components'] );
		$response = dtb_veeqo_request( 'POST', '/kits', [], [ 'product_variant_id' => $parent, 'contents' => $contents ] );
		if ( empty( $response['ok'] ) || ! is_array( $response['data'] ?? null ) ) {
			return new WP_Error( 'dtb_kit_outcome_uncertain', 'Conversion may have reached Veeqo. No automatic retry is permitted.', [ 'status' => 409 ] );
		}
		$kit_id = absint( $response['data']['id'] ?? 0 );
		if ( ! $kit_id ) {
			return new WP_Error( 'dtb_kit_outcome_uncertain', 'Conversion response lacked a verified kit ID. No retry permitted.', [ 'status' => 409 ] );
		}
		$readback = dtb_veeqo_request( 'GET', '/kits/' . $kit_id );
		if ( empty( $readback['ok'] ) || ! is_array( $readback['data'] ?? null ) ) {
			return new WP_Error( 'dtb_kit_readback_failed', 'Veeqo kit created but readback failed; manual reconciliation required.', [ 'status' => 409 ] );
		}
		$actual = $readback['data'];
		$found = [];
		foreach ( (array) ( $actual['contents'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['product_variant_id'], $row['quantity'] ) ) {
				continue;
			}
			$id = absint( $row['product_variant_id'] );
			if ( isset( $found[ $id ] ) ) {
				return new WP_Error( 'dtb_kit_readback_mismatch', 'Repeated component in readback; manual reconciliation required.', [ 'status' => 409 ] );
			}
			$found[ $id ] = absint( $row['quantity'] );
		}
		$expected = [];
		foreach ( $contents as $row ) {
			$expected[ $row['product_variant_id'] ] = $row['quantity'];
		}
		ksort( $found, SORT_NUMERIC );
		ksort( $expected, SORT_NUMERIC );
		if ( (int) ( $actual['id'] ?? 0 ) !== $kit_id || (string) ( $actual['sku_code'] ?? '' ) !== $desired['parent_sku']
			|| (string) ( $actual['type'] ?? '' ) !== 'Kit' || $found !== $expected ) {
			return new WP_Error( 'dtb_kit_readback_mismatch', 'Kit identity or BOM differs after conversion; manual reconciliation required.', [ 'status' => 409 ] );
		}
		$record['status'] = 'verified';
		$record['kit_id'] = $kit_id;
		$record['verified_at'] = gmdate( 'c' );
		update_option( $option, $record, false );
		return [ 'success' => true, 'kit_id' => $kit_id, 'parent_sellable_id' => $parent, 'component_count' => count( $expected ) ];
	}
}

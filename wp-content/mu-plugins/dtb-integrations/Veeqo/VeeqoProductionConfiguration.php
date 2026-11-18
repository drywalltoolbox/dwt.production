<?php
/**
 * Veeqo production configuration service.
 *
 * The Veeqo Control Center is the single wp-admin owner. This file contains
 * server-side readiness, discovery, and validation only. Credentials are never
 * accepted from or rendered into wp-admin.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_CONFIGURATION_DIAGNOSTICS_OPTION = 'dtb_veeqo_configuration_diagnostics';

function dtb_veeqo_production_api_key_configured(): bool {
	return defined( 'DTB_VEEQO_API_KEY' ) && '' !== trim( (string) DTB_VEEQO_API_KEY );
}

function dtb_veeqo_production_readiness( ?array $verified_channel_ids = null ): array {
	if ( null === $verified_channel_ids ) {
		$diagnostics = (array) get_option( DTB_VEEQO_CONFIGURATION_DIAGNOSTICS_OPTION, [] );
		$verified_channel_ids = [];
		foreach ( (array) ( $diagnostics['channel_candidates'] ?? [] ) as $candidate ) {
			if ( is_array( $candidate ) && 'direct' === (string) ( $candidate['type_code'] ?? '' ) ) {
				$verified_channel_ids[] = absint( $candidate['id'] ?? 0 );
			}
		}
	}

	$config  = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
	$missing = [];
	if ( ! dtb_veeqo_production_api_key_configured() ) {
		$missing[] = 'api_key';
	}
	if ( absint( $config['channel_id'] ?? 0 ) <= 0 ) {
		$missing[] = 'channel_id';
	}
	if ( absint( $config['channel_id'] ?? 0 ) > 0 && ! in_array( absint( $config['channel_id'] ), $verified_channel_ids, true ) ) {
		$missing[] = 'verified_direct_channel';
	}
	if ( absint( $config['warehouse_id'] ?? 0 ) <= 0 ) {
		$missing[] = 'warehouse_id';
	}
	if ( absint( $config['delivery_method_id'] ?? 0 ) <= 0 ) {
		$missing[] = 'delivery_method_id';
	}
	return [
		'ready'                => empty( $missing ),
		'missing'              => $missing,
		'api_key_source'       => dtb_veeqo_production_api_key_configured() ? 'server_constant' : 'missing',
		'channel_id'           => absint( $config['channel_id'] ?? 0 ),
		'warehouse_id'         => absint( $config['warehouse_id'] ?? 0 ),
		'delivery_method_id'   => absint( $config['delivery_method_id'] ?? 0 ),
		'webhook_verification' => function_exists( 'dtb_veeqo_verified_webhooks_enabled' ) && dtb_veeqo_verified_webhooks_enabled() ? 'verified_enabled' : 'disabled',
	];
}

/**
 * Discover selectable non-secret Veeqo resources.
 *
 * @return array{channels:array<int,array<string,mixed>>,warehouses:array<int,array<string,mixed>>,delivery_methods:array<int,array<string,mixed>>,errors:string[]}
 */
function dtb_veeqo_production_discover_resources(): array {
	$result = [ 'channels' => [], 'warehouses' => [], 'delivery_methods' => [], 'errors' => [], 'channels_discovered' => false, 'direct_channel_ids' => [] ];
	if ( ! dtb_veeqo_production_api_key_configured() || ! function_exists( 'dtb_veeqo_request' ) ) {
		$result['errors'][] = 'Veeqo API credential is not configured server-side.';
		return $result;
	}

	$channels = dtb_veeqo_request( 'GET', '/channels', [ 'page' => '1', 'page_size' => '100' ] );
	if ( empty( $channels['ok'] ) || ! is_array( $channels['data'] ?? null ) ) {
		$result['errors'][] = dtb_veeqo_production_discovery_error( 'Direct channels', $channels );
	} else {
		foreach ( $channels['data'] as $channel ) {
			if ( ! is_array( $channel ) || absint( $channel['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$type = sanitize_key( (string) ( $channel['type_code'] ?? '' ) );
			if ( 'direct' === $type ) {
				$result['direct_channel_ids'][] = absint( $channel['id'] );
			}
			$result['channels'][] = [
				'id'       => absint( $channel['id'] ),
				'name'     => sanitize_text_field( (string) ( $channel['name'] ?? 'Veeqo channel' ) ),
				'type_code'=> $type,
				'order_export_eligible' => 'direct' === $type,
				'currency' => sanitize_text_field( (string) ( $channel['currency_code'] ?? '' ) ),
				'state'    => sanitize_key( (string) ( $channel['state'] ?? '' ) ),
			];
		}
		$result['channels_discovered'] = ! empty( $result['channels'] );
		if ( empty( $result['direct_channel_ids'] ) ) {
			$result['errors'][] = 'Veeqo channels were discovered, but no verified Direct channel is available for DTB API order creation. Existing WooCommerce channels are visible but cannot be selected for order export.';
		}
	}

	$warehouses = dtb_veeqo_request( 'GET', '/warehouses', [ 'page_size' => '100', 'page' => '1' ] );
	if ( empty( $warehouses['ok'] ) || ! is_array( $warehouses['data'] ?? null ) ) {
		$result['errors'][] = dtb_veeqo_production_discovery_error( 'warehouses', $warehouses );
	} else {
		foreach ( $warehouses['data'] as $warehouse ) {
			if ( ! is_array( $warehouse ) || absint( $warehouse['id'] ?? 0 ) <= 0 || ! empty( $warehouse['deleted_at'] ) ) {
				continue;
			}
			$result['warehouses'][] = [
				'id'   => absint( $warehouse['id'] ),
				'name' => sanitize_text_field( (string) ( $warehouse['name'] ?? 'Warehouse' ) ),
			];
		}
	}

	$methods = dtb_veeqo_request( 'GET', '/delivery_methods', [ 'page_size' => '100', 'page' => '1' ] );
	if ( empty( $methods['ok'] ) || ! is_array( $methods['data'] ?? null ) ) {
		$result['errors'][] = dtb_veeqo_production_discovery_error( 'delivery methods', $methods );
	} else {
		foreach ( $methods['data'] as $method ) {
			if ( ! is_array( $method ) || absint( $method['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$result['delivery_methods'][] = [
				'id'   => absint( $method['id'] ),
				'name' => sanitize_text_field( (string) ( $method['name'] ?? 'Delivery method' ) ),
			];
		}
	}
	return $result;
}

/**
 * Build an actionable, secret-free resource-discovery error for the admin UI.
 *
 * @param string               $resource Human-readable Veeqo resource name.
 * @param array<string, mixed> $response Normalized Veeqo client response.
 * @return string Secret-free diagnostic suitable for the admin UI.
 */
function dtb_veeqo_production_discovery_error( string $resource, array $response ): string {
	$status = absint( $response['status'] ?? 0 );

	if ( 401 === $status ) {
		return sprintf( 'Veeqo rejected the configured API key while loading %s (HTTP 401). Install a valid server-side key.', $resource );
	}
	if ( 403 === $status ) {
		return sprintf( 'Veeqo denied access to %s (HTTP 403). Check the API key account permissions.', $resource );
	}
	if ( 429 === $status ) {
		return sprintf( 'Veeqo rate-limited %s discovery (HTTP 429). Wait before retrying.', $resource );
	}
	if ( $status >= 500 ) {
		return sprintf( 'Veeqo could not serve %s discovery (HTTP %d). Retry after the provider recovers.', $resource, $status );
	}
	if ( $status >= 200 && $status < 300 ) {
		return sprintf( 'Veeqo returned an unexpected response while loading %s (HTTP %d).', $resource, $status );
	}
	if ( $status > 0 ) {
		return sprintf( 'Unable to load Veeqo %s (HTTP %d).', $resource, $status );
	}

	return sprintf( 'Unable to reach Veeqo while loading %s. Check server outbound HTTPS connectivity and retry.', $resource );
}

/**
 * Validate configured resource IDs. Discovery never selects an arbitrary first
 * result: a blank value is auto-filled only when exactly one candidate exists.
 */
function dtb_veeqo_production_validate_configuration( bool $persist = true ): array {
	$settings  = (array) get_option( 'woocommerce_dtb_veeqo_settings', [] );
	$resources = dtb_veeqo_production_discover_resources();
	$errors    = (array) $resources['errors'];
	$definitions = [
		'channel_id'         => 'channels',
		'warehouse_id'       => 'warehouses',
		'delivery_method_id' => 'delivery_methods',
	];

	foreach ( $definitions as $field => $resource_key ) {
		$constant_name = 'DTB_VEEQO_' . strtoupper( $field );
		$constant_id   = defined( $constant_name ) ? absint( constant( $constant_name ) ) : 0;
		$candidates    = (array) $resources[ $resource_key ];
		$valid_ids     = 'channel_id' === $field ? (array) $resources['direct_channel_ids'] : array_values( array_filter( array_map( static fn( array $item ): int => absint( $item['id'] ?? 0 ), $candidates ) ) );
		$current_id    = $constant_id > 0 ? $constant_id : absint( $settings[ $field ] ?? 0 );
		// A failed or empty discovery is not evidence that an existing ID is invalid.
		// Preserve the ID for a later validation rather than overwriting configuration.
		if ( empty( $valid_ids ) ) {
			if ( $current_id > 0 ) {
				$errors[] = sprintf( 'Cannot verify configured %s because no eligible resources were returned.', $field );
			}
			continue;
		}

		if ( $current_id > 0 && ! in_array( $current_id, $valid_ids, true ) ) {
			$errors[] = sprintf( 'Configured %s %d was not returned by Veeqo.', $field, $current_id );
			if ( 0 === $constant_id ) {
				$settings[ $field ] = 0;
			}
			$current_id = 0;
		}
		if ( 0 === $constant_id && 0 === $current_id && 1 === count( $valid_ids ) ) {
			$settings[ $field ] = $valid_ids[0];
		} elseif ( 0 === $current_id && count( $valid_ids ) > 1 ) {
			$errors[] = sprintf( 'Multiple Veeqo %s candidates exist; select the intended resource explicitly.', $resource_key );
		}
	}

	unset( $settings['api_key'], $settings['webhook_secret'] );
	if ( $persist ) {
		update_option( 'woocommerce_dtb_veeqo_settings', $settings, false );
		unset( $GLOBALS['_dtb_veeqo_config'] );
	}
	$readiness = dtb_veeqo_production_readiness( (array) $resources['direct_channel_ids'] );
	$diagnostics = [
		'checked_at'           => gmdate( 'c' ),
		'ready'                => empty( $errors ) && ! empty( $readiness['ready'] ),
		'errors'               => array_values( array_unique( array_map( 'sanitize_text_field', $errors ) ) ),
		'channel_candidates'   => $resources['channels'],
		'channels_discovered' => $resources['channels_discovered'],
		'eligible_order_channels' => count( $resources['direct_channel_ids'] ),
		'warehouse_candidates' => $resources['warehouses'],
		'delivery_candidates'  => $resources['delivery_methods'],
		'readiness'            => $readiness,
	];
	update_option( DTB_VEEQO_CONFIGURATION_DIAGNOSTICS_OPTION, $diagnostics, false );
	return $diagnostics;
}

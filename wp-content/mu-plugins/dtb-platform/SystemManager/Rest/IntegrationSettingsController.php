<?php
/**
 * DTB Platform — Integration Settings REST controller
 *
 * Single write endpoint for the System Manager -> Integration Settings tab
 * (IntegrationSettingsTab.php): POST /dtb/v1/admin/system/integration-settings
 * with { target: 'quickbooks'|'veeqo'|'amazon'|'ebay', fields: {...} }.
 *
 * This is the only place any of these four integrations' credentials are
 * written from an admin UI. Each target keeps using whatever storage
 * mechanism that integration already had (plaintext wp_options for
 * QuickBooks/Veeqo, matching the existing dtb_veeqo_config() precedent; the
 * already-built AES-256-CBC DTB_MarketplaceCredentialFacade for
 * Amazon/eBay) — this controller does not introduce a new storage pattern,
 * it just gives each existing one a UI.
 *
 * Blank-field convention: an empty string submitted for a secret-typed field
 * means "leave the stored value unchanged," not "clear it" (matches the
 * password-field UX Veeqo's previous WC_Integration form already had).
 * Non-secret fields always overwrite on submit.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_integration_settings_register_routes' );

function dtb_integration_settings_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/system/integration-settings', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_integration_settings_route_save',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_system' ),
		'args'                => [
			'target' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
		],
	] );
}

/**
 * Merge submitted fields onto a stored bag per a field spec, honoring the
 * blank-means-keep convention for fields marked 'secret'.
 *
 * @param array<string,string> $spec   field => 'text'|'secret'|'bool'
 * @param array<string,mixed>  $stored Existing values.
 * @param array<string,mixed>  $submitted Raw submitted values.
 * @return array<string,mixed>
 */
function dtb_integration_settings_merge( array $spec, array $stored, array $submitted ): array {
	$merged = $stored;

	foreach ( $spec as $field => $type ) {
		if ( 'bool' === $type ) {
			$merged[ $field ] = ! empty( $submitted[ $field ] ) ? '1' : '0';
			continue;
		}

		$value = trim( sanitize_text_field( (string) ( $submitted[ $field ] ?? '' ) ) );

		if ( 'secret' === $type && '' === $value ) {
			continue; // Blank secret field: keep whatever was already stored.
		}

		$merged[ $field ] = $value;
	}

	return $merged;
}

function dtb_integration_settings_save_quickbooks( array $fields ): array {
	$managed_constants = [
		'client_id'                         => [ 'DTB_QBO_CLIENT_ID' ],
		'client_secret'                     => [ 'DTB_QBO_CLIENT_SECRET' ],
		'environment'                       => [ 'DTB_QBO_ENVIRONMENT' ],
		'sandbox_webhook_verifier_token'    => [ 'DTB_QBO_SANDBOX_WEBHOOK_VERIFIER_TOKEN', 'DTB_QBO_WEBHOOK_VERIFIER_TOKEN' ],
		'production_webhook_verifier_token' => [ 'DTB_QBO_PRODUCTION_WEBHOOK_VERIFIER_TOKEN', 'DTB_QBO_WEBHOOK_VERIFIER_TOKEN' ],
		'stripe_restricted_key'             => [ 'DTB_STRIPE_ACCOUNTING_RESTRICTED_KEY' ],
	];
	$blocked = [];
	foreach ( $managed_constants as $field => $constants ) {
		if ( ! array_key_exists( $field, $fields ) ) {
			continue;
		}
		$constant = '';
		foreach ( $constants as $candidate ) {
			if ( defined( $candidate ) ) {
				$constant = $candidate;
				break;
			}
		}
		if ( '' === $constant ) {
			continue;
		}
		if ( ! is_scalar( $fields[ $field ] ) ) {
			$blocked[] = $field;
			unset( $fields[ $field ] );
			continue;
		}
		$submitted = trim( (string) $fields[ $field ] );
		$effective  = trim( (string) constant( $constant ) );
		$is_secret = ! in_array( $field, [ 'client_id', 'environment' ], true );
		if ( $is_secret && '' === $submitted ) {
			unset( $fields[ $field ] );
			continue;
		}
		if ( ! hash_equals( $effective, $submitted ) ) {
			$blocked[] = $field;
		}
		unset( $fields[ $field ] );
	}
	if ( $blocked ) {
		return [
			'ok'      => false,
			'status'  => 409,
			'message' => 'These settings are managed by server wp-config.php and were not changed: ' . implode( ', ', $blocked ) . '. Update the protected server configuration, then refresh this page.',
		];
	}

	$spec = [
		'client_id'                       => 'text',
		'client_secret'                   => 'secret',
		'environment'                     => 'text',
		'sandbox_webhook_verifier_token'  => 'secret',
		'production_webhook_verifier_token' => 'secret',
		'stripe_restricted_key'           => 'secret',
	];

	$stored = (array) get_option( 'dtb_qbo_settings', [] );
	$merged = dtb_integration_settings_merge( $spec, $stored, $fields );

	if ( ! in_array( $merged['environment'] ?? '', [ 'sandbox', 'production' ], true ) ) {
		$merged['environment'] = $stored['environment'] ?? 'production';
	}

	update_option( 'dtb_qbo_settings', $merged, false );

	$status = function_exists( 'dtb_qbo_status' ) ? dtb_qbo_status() : [];
	return [
		'ok'              => true,
		'reload_required' => true,
		'message'         => 'QuickBooks settings saved. The page will refresh to show effective settings. If credentials or environment changed, reconnect QuickBooks before accounting sync resumes.',
		'effective'       => [
			'environment'            => function_exists( 'dtb_qbo_environment' ) ? dtb_qbo_environment() : '',
			'credentials_configured' => ! empty( $status['credentials_configured'] ),
			'connected'              => ! empty( $status['connected'] ),
			'webhook_verifier_set'   => class_exists( 'DTB_QuickBooksWebhookController' ) && DTB_QuickBooksWebhookController::verifier_configured(),
		],
	];
}

function dtb_integration_settings_save_veeqo( array $fields ): array {
	$spec = [
		'api_key'            => 'secret',
		'webhook_secret'     => 'secret',
		'delivery_method_id' => 'text',
	];

	$stored = (array) get_option( 'woocommerce_dtb_veeqo_settings', [] );
	$merged = dtb_integration_settings_merge( $spec, $stored, $fields );
	update_option( 'woocommerce_dtb_veeqo_settings', $merged, false );

	if ( isset( $GLOBALS['_dtb_veeqo_config'] ) ) {
		unset( $GLOBALS['_dtb_veeqo_config'] );
	}

	if ( function_exists( 'dtb_veeqo_enabled' ) && dtb_veeqo_enabled() && function_exists( 'dtb_veeqo_discover_ids' ) ) {
		$result = dtb_veeqo_discover_ids();
		if ( '' !== ( $result['error'] ?? '' ) ) {
			return [ 'ok' => true, 'message' => 'Veeqo settings saved. ID auto-discovery issue: ' . sanitize_text_field( (string) $result['error'] ) ];
		}
		return [
			'ok'      => true,
			'message' => sprintf(
				'Veeqo connected. Channel ID: %d — Warehouse ID: %d — Delivery Method ID: %d.',
				(int) $result['channel_id'],
				(int) $result['warehouse_id'],
				(int) $result['delivery_method_id']
			),
		];
	}

	return [ 'ok' => true, 'message' => 'Veeqo settings saved.' ];
}

function dtb_integration_settings_save_amazon( array $fields ): array {
	if ( ! class_exists( 'DTB_MarketplaceCredentialFacade' ) || ! defined( 'DTB_CHANNEL_AMAZON' ) ) {
		return [ 'ok' => false, 'message' => 'Marketplace credential storage is unavailable.' ];
	}

	$spec = [
		'client_id'             => 'text',
		'client_secret'         => 'secret',
		'refresh_token'         => 'secret',
		'marketplace_id'        => 'text',
		'seller_id'             => 'text',
		'sandbox'               => 'bool',
		'notification_endpoint' => 'text',
	];

	$stored = DTB_MarketplaceCredentialFacade::get( DTB_CHANNEL_AMAZON );
	$merged = dtb_integration_settings_merge( $spec, $stored, $fields );
	DTB_MarketplaceCredentialFacade::store( DTB_CHANNEL_AMAZON, $merged );

	if ( class_exists( 'DTB_AmazonConfig' ) ) {
		DTB_AmazonConfig::flush_cache();
	}

	return [ 'ok' => true, 'message' => 'Amazon settings saved.' ];
}

function dtb_integration_settings_save_ebay( array $fields ): array {
	if ( ! class_exists( 'DTB_MarketplaceCredentialFacade' ) || ! defined( 'DTB_CHANNEL_EBAY' ) ) {
		return [ 'ok' => false, 'message' => 'Marketplace credential storage is unavailable.' ];
	}

	$spec = [
		'client_id'             => 'text',
		'client_secret'         => 'secret',
		'redirect_uri'          => 'text',
		'marketplace_id'        => 'text',
		'sandbox'               => 'bool',
		'deletion_verify_token' => 'secret',
	];

	$stored = DTB_MarketplaceCredentialFacade::get( DTB_CHANNEL_EBAY );
	$merged = dtb_integration_settings_merge( $spec, $stored, $fields );
	DTB_MarketplaceCredentialFacade::store( DTB_CHANNEL_EBAY, $merged );

	if ( class_exists( 'DTB_EbayConfig' ) ) {
		DTB_EbayConfig::flush_cache();
	}

	$message      = 'eBay settings saved.';
	$refresh_raw  = trim( (string) ( $fields['refresh_token'] ?? '' ) );
	if ( '' !== $refresh_raw && class_exists( 'DTB_EbayOAuthTokenService' ) ) {
		$stored_ok = DTB_EbayOAuthTokenService::store_refresh_token( $refresh_raw );
		$message  .= $stored_ok ? ' Refresh token stored.' : ' Refresh token could not be stored.';
	}

	return [ 'ok' => true, 'message' => $message ];
}

function dtb_integration_settings_route_save( WP_REST_Request $request ): WP_REST_Response {
	$target = sanitize_key( (string) $request->get_param( 'target' ) );
	$fields = (array) ( $request->get_json_params()['fields'] ?? [] );

	$handlers = [
		'quickbooks' => 'dtb_integration_settings_save_quickbooks',
		'veeqo'      => 'dtb_integration_settings_save_veeqo',
		'amazon'     => 'dtb_integration_settings_save_amazon',
		'ebay'       => 'dtb_integration_settings_save_ebay',
	];

	if ( ! isset( $handlers[ $target ] ) ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'Unknown settings target.' ], 400 );
	}

	$result = $handlers[ $target ]( $fields );

	if ( function_exists( 'dtb_audit_log_write' ) ) {
		dtb_audit_log_write( 'integration_settings.saved', [
			'target' => $target,
			'ok'     => ! empty( $result['ok'] ),
		] );
	}

	$status = ! empty( $result['ok'] ) ? 200 : (int) ( $result['status'] ?? 500 );
	return new WP_REST_Response( $result, $status );
}

<?php
/**
 * Protected WordPress Abilities API adapter for native Veeqo kit import.
 *
 * Never calls the provider directly: all mutations are delegated to the
 * existing feature-gated, parent-reserved DTB kit import service.
 *
 * @package drywalltoolbox
 */
defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Kit_MCP_Abilities {
	private const CATEGORY = 'dtb-veeqo-kits';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register' ] );
	}

	public static function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( self::CATEGORY, [
				'label' => 'DTB Veeqo Native Kits',
				'description' => 'Authenticated native-kit inspection and guarded import operations.',
			] );
		}
	}

	public static function allowed(): bool {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	private static function bom_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'parent_sellable_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'parent_sku' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
				'components' => [
					'type' => 'array', 'minItems' => 1, 'maxItems' => 25,
					'items' => [
						'type' => 'object',
						'properties' => [
							'sellable_id' => [ 'type' => 'integer', 'minimum' => 1 ],
							'sku' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
							'quantity' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
						],
						'required' => [ 'sellable_id', 'sku', 'quantity' ],
						'additionalProperties' => false,
					],
				],
			],
			'required' => [ 'parent_sellable_id', 'parent_sku', 'components' ],
			'additionalProperties' => false,
		];
	}

	private static function register_one( string $name, string $label, string $description, array $schema, string $callback, bool $readonly ): void {
		wp_register_ability( self::CATEGORY . '/' . $name, [
			'label' => $label,
			'description' => $description,
			'category' => self::CATEGORY,
			'input_schema' => $schema,
			'output_schema' => [ 'type' => 'object', 'additionalProperties' => true ],
			'permission_callback' => [ __CLASS__, 'allowed' ],
			'execute_callback' => [ __CLASS__, $callback ],
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

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'DTB_Veeqo_Kit_Import_Service' ) || ! class_exists( 'DTB_Veeqo_Kit_Read_Controller' ) ) {
			return;
		}
		self::register_one( 'import-preview', 'Preview Native Kit Import', 'Verify exact live parent/component identities and return the BOM fingerprint. No mutation.', self::bom_schema(), 'preview', true );
		$convert = self::bom_schema();
		$convert['properties']['approved_fingerprint'] = [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ];
		$convert['properties']['confirm_conversion'] = [ 'type' => 'boolean', 'enum' => [ true ] ];
		$convert['required'][] = 'approved_fingerprint';
		$convert['required'][] = 'confirm_conversion';
		self::register_one( 'import-convert', 'Convert Native Veeqo Kit', 'Destructive one-shot import. Requires explicit operator confirmation and exact preview fingerprint; never retry an uncertain outcome.', $convert, 'convert', false );
		self::register_one( 'integration-health', 'Read DTB Veeqo Integration Health', 'Read-only redacted DTB integration readiness, inventory freshness, and module diagnostics. Does not verify every provider operation.', [
			'type' => 'object', 'properties' => [], 'additionalProperties' => false,
		], 'health', true );
		self::register_one( 'read', 'Read Native Veeqo Kit', 'Inspect the native Veeqo kit and component quantities after conversion.', [
			'type' => 'object',
			'properties' => [ 'kit_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
			'required' => [ 'kit_id' ],
			'additionalProperties' => false,
		], 'read', true );
	}

	public static function preview( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_kit_forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}
		return DTB_Veeqo_Kit_Import_Service::preview( is_array( $input ) ? $input : [] );
	}

	public static function convert( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_kit_forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}
		if ( ! is_array( $input ) || true !== ( $input['confirm_conversion'] ?? null ) || ! is_string( $input['approved_fingerprint'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $input['approved_fingerprint'] ) ) {
			return new WP_Error( 'dtb_kit_approval_required', 'Explicit confirmation and exact fingerprint are required.', [ 'status' => 400 ] );
		}
		$fingerprint = $input['approved_fingerprint'];
		unset( $input['approved_fingerprint'], $input['confirm_conversion'] );
		return DTB_Veeqo_Kit_Import_Service::convert( $input, $fingerprint );
	}

	public static function read( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_kit_forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}
		$id = is_array( $input ) ? ( $input['kit_id'] ?? null ) : null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return new WP_Error( 'dtb_kit_invalid_id', 'Positive kit_id is required.', [ 'status' => 400 ] );
		}
		$request = new WP_REST_Request( 'GET', '/dtb/v1/veeqo/admin/kits/' . $id );
		$request->set_param( 'kit_id', $id );
		$response = DTB_Veeqo_Kit_Read_Controller::read( $request );
		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	}
	public static function health( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_kit_forbidden', 'Permission denied.', [ 'status' => 403 ] );
		}
		if ( ! class_exists( 'DTB_VeeqoHealthCheck' ) ) {
			return new WP_Error( 'dtb_veeqo_health_unavailable', 'Veeqo health service is unavailable.', [ 'status' => 503 ] );
		}
		return DTB_VeeqoHealthCheck::run();
	}

}
DTB_Veeqo_Kit_MCP_Abilities::init();

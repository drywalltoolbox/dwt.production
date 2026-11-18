<?php
/**
 * Native Veeqo kit inspection for DTB operators.
 *
 * This is intentionally read-only. Kit conversion and component writes must
 * not be exposed until durable approval, retry recovery and upstream identity
 * semantics are verified.
 *
 * @package drywalltoolbox
 */
defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Kit_Read_Controller {
	private const NS = 'dtb/v1';

	public static function register(): void {
		register_rest_route( self::NS, '/veeqo/admin/kits/(?P<kit_id>[0-9]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ __CLASS__, 'authorize' ],
			'callback'            => [ __CLASS__, 'read' ],
			'args'                => [
				'kit_id' => [
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
			],
		] );
		register_rest_route( self::NS, '/veeqo/admin/kits/validate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'authorize' ],
			'callback'            => [ __CLASS__, 'validate' ],
		] );
		register_rest_route( self::NS, '/veeqo/admin/kits/import/preview', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'authorize' ],
			'callback'            => [ __CLASS__, 'import_preview' ],
		] );
		register_rest_route( self::NS, '/veeqo/admin/kits/import/convert', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'authorize' ],
			'callback'            => [ __CLASS__, 'import_convert' ],
		] );
	}

	public static function import_preview( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return DTB_Veeqo_Kit_Import_Service::preview( is_array( $data ) ? $data : [] );
	}

	public static function import_convert( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || ! is_string( $data['approved_fingerprint'] ?? null ) ) {
			return new WP_Error( 'dtb_kit_approval_required', 'Exact approved BOM fingerprint is required.', [ 'status' => 400 ] );
		}
		$fingerprint = $data['approved_fingerprint'];
		unset( $data['approved_fingerprint'] );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			return new WP_Error( 'dtb_kit_approval_invalid', 'Invalid BOM fingerprint.', [ 'status' => 400 ] );
		}
		return DTB_Veeqo_Kit_Import_Service::convert( $data, $fingerprint );
	}

	public static function authorize(): bool {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	public static function read( WP_REST_Request $request ) {
		$id = absint( $request['kit_id'] );
		if ( ! $id || ! function_exists( 'dtb_veeqo_request' ) ) {
			return new WP_Error( 'dtb_kit_unavailable', 'Kit inspection is unavailable.', [ 'status' => 503 ] );
		}
		$result = dtb_veeqo_request( 'GET', '/kits/' . $id );
		if ( empty( $result['ok'] ) || ! is_array( $result['data'] ?? null ) ) {
			return new WP_Error( 'dtb_kit_lookup_failed', 'Veeqo could not resolve this kit.', [ 'status' => 502 ] );
		}
		$data = $result['data'];
		$contents = [];
		foreach ( (array) ( $data['contents'] ?? [] ) as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			$contents[] = [
				'content_id' => absint( $part['id'] ?? 0 ),
				'sellable_id' => absint( $part['product_variant_id'] ?? 0 ),
				'sku'         => sanitize_text_field( (string) ( $part['sku_code'] ?? '' ) ),
				'quantity'    => absint( $part['quantity'] ?? 0 ),
			];
		}
		return rest_ensure_response( [
			'kit_id'    => absint( $data['id'] ?? 0 ),
			'type'      => sanitize_text_field( (string) ( $data['type'] ?? '' ) ),
			'sku'       => sanitize_text_field( (string) ( $data['sku_code'] ?? '' ) ),
			'contents'  => $contents,
		] );
	}

	/** Validate only structural constraints; never claim live-ID verification. */
	public static function validate( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || ! isset( $input['parent_sellable_id'], $input['components'] )
			|| ! is_int( $input['parent_sellable_id'] ) || $input['parent_sellable_id'] < 1
			|| ! is_array( $input['components'] ) || ! array_is_list( $input['components'] )
			|| count( $input['components'] ) < 1 || count( $input['components'] ) > 25 ) {
			return new WP_Error( 'dtb_kit_invalid_bom', 'Expected positive parent_sellable_id and 1–25 components.', [ 'status' => 400 ] );
		}
		$seen = [];
		foreach ( $input['components'] as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['sellable_id'], $component['quantity'] )
				|| ! is_int( $component['sellable_id'] ) || $component['sellable_id'] < 1
				|| ! is_int( $component['quantity'] ) || $component['quantity'] < 1
				|| $component['quantity'] > 10000
				|| $component['sellable_id'] === $input['parent_sellable_id']
				|| isset( $seen[ $component['sellable_id'] ] ) ) {
				return new WP_Error( 'dtb_kit_invalid_component', 'Invalid, repeated, or self-referencing component.', [ 'status' => 400 ] );
			}
			$seen[ $component['sellable_id'] ] = true;
		}
		return rest_ensure_response( [
			'structure_valid' => true,
			'live_verified'   => false,
			'write_enabled'   => false,
			'component_count' => count( $seen ),
			'message'         => 'Structural validation only. Live identity, existing kits, open orders and stock behavior remain unverified.',
		] );
	}
}
add_action( 'rest_api_init', [ 'DTB_Veeqo_Kit_Read_Controller', 'register' ], 50 );

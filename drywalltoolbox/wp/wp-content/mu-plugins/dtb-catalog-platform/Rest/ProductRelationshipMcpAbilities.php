<?php
/**
 * MCP-facing operations for exact, approved product fitment releases.
 *
 * The adapter stages exact artifact bytes in the projector's private storage,
 * then delegates all product identity, approval, before-state, conflict,
 * locking, rollback, and public-route checks to the existing projector.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Product_Relationship_MCP_Abilities {
	private const CATEGORY = 'dtb-product-relationships';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register' ] );
	}

	public static function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( self::CATEGORY, [
				'label' => 'DTB Product Relationships',
				'description' => 'Stage, preview, apply, inspect, and roll back digest-bound catalog fitment releases.',
			] );
		}
	}

	public static function allowed(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	private static function register_one( string $name, string $label, string $description, array $schema, string $callback, bool $readonly, bool $destructive, bool $idempotent ): void {
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
					'destructive' => $destructive,
					'idempotent' => $idempotent,
				],
			],
		] );
	}

	private static function stage_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'artifact_json' => [ 'type' => 'string', 'minLength' => 2, 'maxLength' => DTB_ProductRelationshipProjectionService::MAX_ARTIFACT_BYTES ],
				'expected_sha256' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			],
			'required' => [ 'artifact_json', 'expected_sha256' ],
			'additionalProperties' => false,
		];
	}

	private static function release_batch_schema( bool $apply ): array {
		$schema = [
			'type' => 'object',
			'properties' => [
				'release_id' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{24}$' ],
				'batch_index' => [ 'type' => 'integer', 'minimum' => 1 ],
				'artifact_sha256' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			],
			'required' => [ 'release_id', 'batch_index', 'artifact_sha256' ],
			'additionalProperties' => false,
		];
		if ( $apply ) {
			$schema['properties']['confirm_release_id'] = [ 'type' => 'string', 'pattern' => '^[a-f0-9]{24}$' ];
			$schema['properties']['confirm_apply'] = [ 'type' => 'boolean', 'enum' => [ true ] ];
			$schema['required'][] = 'confirm_release_id';
			$schema['required'][] = 'confirm_apply';
		}
		return $schema;
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'DTB_ProductRelationshipProjectionService' ) ) {
			return;
		}
		self::register_one( 'projection-stage', 'Stage Fitment Release Artifact', 'Store exact artifact bytes in private projector storage after size and SHA-256 verification. Does not change product data.', self::stage_schema(), 'stage', false, false, true );
		self::register_one( 'projection-preview', 'Preview Fitment Release', 'Validate one staged release batch against current WooCommerce identities and before-state. No product data is written.', self::release_batch_schema( false ), 'preview', true, false, true );
		self::register_one( 'projection-apply', 'Apply Fitment Release', 'Apply one bounded approved release batch after explicit exact release-ID confirmation. The projector performs conflict checks, idempotency, rollback recording, and public-route read-back.', self::release_batch_schema( true ), 'apply', false, true, true );
		self::register_one( 'projection-status', 'Read Fitment Release Status', 'Read the private reconciliation status for a release without exposing server filesystem paths.', [
			'type' => 'object',
			'properties' => [ 'release_id' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{24}$' ] ],
			'required' => [ 'release_id' ],
			'additionalProperties' => false,
		], 'status', true, false, true );
		self::register_one( 'projection-rollback', 'Roll Back Fitment Release Batch', 'Roll back one applied release batch only when its exact current private report digest and explicit confirmation match.', [
			'type' => 'object',
			'properties' => [
				'release_id' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{24}$' ],
				'batch_index' => [ 'type' => 'integer', 'minimum' => 1 ],
				'rollback_report_sha256' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'confirm_rollback' => [ 'type' => 'boolean', 'enum' => [ true ] ],
			],
			'required' => [ 'release_id', 'batch_index', 'rollback_report_sha256', 'confirm_rollback' ],
			'additionalProperties' => false,
		], 'rollback', false, true, false );
	}

	private static function hide_private_paths( array $result ): array {
		foreach ( $result as $key => $value ) {
			if ( 'report_path' === $key ) {
				unset( $result[ $key ] );
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = self::hide_private_paths( $value );
			}
		}
		return $result;
	}

	private static function safe_recovery_error( Throwable $error, array $data, string $code ): WP_Error {
		$message = sanitize_text_field( $error->getMessage() );
		if ( preg_match( '/SHA-256:\s*([a-f0-9]{64})/i', $error->getMessage(), $matches ) ) {
			$data['rollback_report_sha256'] = strtolower( $matches[1] );
			$data['reconciliation_required'] = true;
			$message = preg_replace( '/\s*(?:Rollback report:|rollback report if needed:|Updated rollback report:).*$/is', ' Recovery report is stored in private reconciliation storage.', $message );
		}
		return new WP_Error( $code, $message, $data );
	}

	public static function stage( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_relationship_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		if ( ! is_array( $input ) || ! is_string( $input['artifact_json'] ?? null ) || ! is_string( $input['expected_sha256'] ?? null ) ) {
			return new WP_Error( 'dtb_relationship_artifact_invalid', 'Provide the exact release artifact JSON and its SHA-256 digest.', [ 'status' => 400 ] );
		}
		try {
			return DTB_ProductRelationshipProjectionService::stage_artifact_payload( $input['artifact_json'], strtolower( $input['expected_sha256'] ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'dtb_relationship_stage_failed', sanitize_text_field( $error->getMessage() ), [ 'status' => 400 ] );
		}
	}

	public static function preview( $input ) {
		return self::run_staged_artifact( $input, false );
	}

	public static function apply( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_relationship_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		if ( ! is_array( $input ) || true !== ( $input['confirm_apply'] ?? null ) || ! is_string( $input['confirm_release_id'] ?? null ) ) {
			return new WP_Error( 'dtb_relationship_confirmation_required', 'Confirm apply with the exact release ID shown by preview.', [ 'status' => 400 ] );
		}
		if ( strtolower( $input['confirm_release_id'] ) !== strtolower( (string) ( $input['release_id'] ?? '' ) ) ) {
			return new WP_Error( 'dtb_relationship_confirmation_mismatch', 'Confirm apply with the exact release ID shown by preview.', [ 'status' => 400 ] );
		}
		return self::run_staged_artifact( $input, true );
	}

	private static function run_staged_artifact( $input, bool $apply ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_relationship_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'dtb_relationship_artifact_invalid', 'Provide the exact release ID, batch index, and artifact SHA-256.', [ 'status' => 400 ] );
		}
		$release_id = strtolower( (string) ( $input['release_id'] ?? '' ) );
		$batch_index = absint( $input['batch_index'] ?? 0 );
		$artifact_sha256 = strtolower( (string) ( $input['artifact_sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', $release_id ) || $batch_index < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $artifact_sha256 ) ) {
			return new WP_Error( 'dtb_relationship_artifact_identity_invalid', 'Release ID, batch index, or artifact SHA-256 is invalid.', [ 'status' => 400 ] );
		}
		try {
			$path = DTB_ProductRelationshipProjectionService::get_staged_artifact_path( $release_id, $batch_index, $artifact_sha256 );
		} catch ( Throwable $error ) {
			return new WP_Error( 'dtb_relationship_staged_artifact_unavailable', sanitize_text_field( $error->getMessage() ), [ 'status' => 404, 'release_id' => $release_id, 'batch_index' => $batch_index ] );
		}
		try {
			$result = DTB_ProductRelationshipProjectionService::run_artifact( $path, $artifact_sha256, $apply );
			return is_array( $result ) ? self::hide_private_paths( $result ) : $result;
		} catch ( Throwable $error ) {
			return self::safe_recovery_error( $error, [ 'status' => 409, 'release_id' => $release_id, 'batch_index' => $batch_index ], 'dtb_relationship_projection_failed' );
		}
	}

	public static function status( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_relationship_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		$release_id = is_array( $input ) ? strtolower( (string) ( $input['release_id'] ?? '' ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', $release_id ) ) {
			return new WP_Error( 'dtb_relationship_release_id_invalid', 'A 24-character release ID is required.', [ 'status' => 400 ] );
		}
		try {
			return self::hide_private_paths( DTB_ProductRelationshipProjectionService::read_release_status( $release_id ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'dtb_relationship_status_unavailable', sanitize_text_field( $error->getMessage() ), [ 'status' => 404 ] );
		}
	}

	public static function rollback( $input ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'dtb_relationship_forbidden', 'Administrator access is required.', [ 'status' => 403 ] );
		}
		if ( ! is_array( $input ) || true !== ( $input['confirm_rollback'] ?? null ) ) {
			return new WP_Error( 'dtb_relationship_rollback_confirmation_required', 'Explicit rollback confirmation is required.', [ 'status' => 400 ] );
		}
		$release_id = strtolower( (string) ( $input['release_id'] ?? '' ) );
		$batch_index = absint( $input['batch_index'] ?? 0 );
		$expected_sha256 = strtolower( (string) ( $input['rollback_report_sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', $release_id ) || $batch_index < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) ) {
			return new WP_Error( 'dtb_relationship_rollback_input_invalid', 'Provide a valid release ID, batch index, and rollback-report SHA-256.', [ 'status' => 400 ] );
		}
		try {
			$release = DTB_ProductRelationshipProjectionService::read_release_status( $release_id );
			$batch = $release['batches'][ (string) $batch_index ] ?? null;
			if ( ! is_array( $batch ) || ! in_array( $batch['status'] ?? '', [ 'applied', 'failed', 'rollback_failed' ], true ) || ! is_string( $batch['report_path'] ?? null ) || ! is_string( $batch['current_report_sha256'] ?? null ) || ! hash_equals( strtolower( $batch['current_report_sha256'] ), $expected_sha256 ) ) {
				return new WP_Error( 'dtb_relationship_rollback_conflict', 'The batch is not eligible or its current rollback report digest changed.', [ 'status' => 409 ] );
			}
			$result = DTB_ProductRelationshipProjectionService::rollback_report( $batch['report_path'], $expected_sha256 );
			return is_array( $result ) ? self::hide_private_paths( $result ) : $result;
		} catch ( Throwable $error ) {
			return self::safe_recovery_error( $error, [ 'status' => 409, 'release_id' => $release_id, 'batch_index' => $batch_index ], 'dtb_relationship_rollback_failed' );
		}
	}
}

DTB_Product_Relationship_MCP_Abilities::init();

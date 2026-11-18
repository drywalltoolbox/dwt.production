<?php
/**
 * DTB_ToolsetTemplatesController
 *
 * Handles:
 *   GET /wp-json/dtb/v1/toolsets           — list all templates
 *   GET /wp-json/dtb/v1/toolsets/:id        — get template by ID
 *
 * Templates are stored in DTB_ToolsetData (seeded from SEED_TEMPLATES).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetTemplatesController {

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/toolsets', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_list' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'dtb/v1', '/toolsets/(?P<id>[a-zA-Z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_single' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** GET /dtb/v1/toolsets */
	public static function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$brand_key = sanitize_text_field( (string) ( $request->get_param( 'brand' ) ?? '' ) );

		$templates = DTB_ToolsetData::get_all();

		if ( '' !== $brand_key ) {
			$templates = array_values( array_filter( $templates, static fn( $t ) =>
				( $t['brandKey'] ?? '' ) === $brand_key
			) );
		}

		// Strip slot allowedFamilies from public list response to keep payload lean.
		$public_templates = array_map( [ self::class, 'to_public' ], $templates );

		return new WP_REST_Response( [
			'templates' => $public_templates,
			'count'     => count( $public_templates ),
		], 200 );
	}

	/** GET /dtb/v1/toolsets/:id */
	public static function handle_single( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( (string) ( $request->get_param( 'id' ) ?? '' ) );

		$template = DTB_ToolsetData::get_by_id( $id );
		if ( null === $template ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'not_found', sprintf( 'Template "%s" not found.', $id ), 404 ),
				404
			);
		}

		return new WP_REST_Response( $template, 200 );
	}

	/** Strip internal-only fields for the public list response. */
	private static function to_public( array $template ): array {
		$slots = array_map( static function ( array $slot ): array {
			// allowedFamilies is internal — frontend uses slotId to request options.
			unset( $slot['allowedFamilies'] );
			return $slot;
		}, $template['slots'] ?? [] );

		return array_merge( $template, [ 'slots' => $slots ] );
	}
}

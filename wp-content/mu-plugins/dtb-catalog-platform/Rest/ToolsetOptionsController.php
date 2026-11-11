<?php
/**
 * DTB_ToolsetOptionsController
 *
 * Handles:
 *   GET /wp-json/dtb/v1/toolsets/:id/options
 *
 * Returns all eligible product options grouped by slot ID for a template.
 * Uses DTB_ToolsetEligibilityService which queries by _dtb_builder_slots meta,
 * eliminating keyword-based product name matching.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetOptionsController {

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/toolsets/(?P<id>[a-zA-Z0-9_-]+)/options', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( (string) ( $request->get_param( 'id' ) ?? '' ) );

		$template = DTB_ToolsetData::get_by_id( $id );
		if ( null === $template ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'not_found', sprintf( 'Template "%s" not found.', $id ), 404 ),
				404
			);
		}

		$options_by_slot = DTB_ToolsetEligibilityService::get_options_for_template( $template );

		return new WP_REST_Response( [
			'templateId'   => $id,
			'optionsBySlot'=> $options_by_slot,
		], 200 );
	}
}

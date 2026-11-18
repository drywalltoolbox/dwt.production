<?php
/**
 * DTB_ToolsetValidationController
 *
 * Handles:
 *   POST /wp-json/dtb/v1/toolsets/validate
 *
 * Validates Toolset Builder slot selections server-side before the
 * frontend submits them to the cart.  Returns blocking errors and
 * non-blocking warnings.
 *
 * Request body:
 *   {
 *     "templateId": "tapetech-full",
 *     "selections": {
 *       "taper":   { "productId": 101, "variationId": 0 },
 *       "flatBox": { "productId": 205, "variationId": 310 }
 *     }
 *   }
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetValidationController {

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/toolsets/validate', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$template_id = sanitize_text_field( (string) ( $request->get_param( 'templateId' ) ?? '' ) );
		$selections  = $request->get_param( 'selections' );

		if ( '' === $template_id ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'missing_template_id', 'templateId is required.', 400 ),
				400
			);
		}

		$template = DTB_ToolsetData::get_by_id( $template_id );
		if ( null === $template ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'not_found', sprintf( 'Template "%s" not found.', $template_id ), 404 ),
				404
			);
		}

		if ( ! is_array( $selections ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'invalid_selections', 'selections must be an object.', 400 ),
				400
			);
		}

		$result = DTB_ToolsetValidationService::validate( $template, $selections );

		return new WP_REST_Response( array_merge( $result, [
			'templateId' => $template_id,
		] ), $result['valid'] ? 200 : 422 );
	}
}

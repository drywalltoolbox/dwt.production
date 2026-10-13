<?php
/**
 * Operator-only REST endpoints for the Visual Designer Email Studio.
 *
 * The studio delegates all rendering to dtb-commerce's canonical preview
 * service. It never sends email or mutates orders.
 *
 * @package drywall-toolbox
 */

use DTB\Commerce\Email\Preview\EmailPreviewService;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/email-studio', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_email_studio_manifest',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/email-studio/render', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_email_studio_render',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'emailId' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'orderId' => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool => absint( $value ) >= 0,
			],
		],
	] );
} );

function dtb_vd_rest_email_studio_manifest( WP_REST_Request $request ) {
	unset( $request );

	if ( ! class_exists( EmailPreviewService::class ) ) {
		return new WP_Error( 'dtb_email_studio_unavailable', __( 'The canonical email preview service is unavailable.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	return rest_ensure_response( [
		'emails' => EmailPreviewService::supported_emails(),
		'orders' => EmailPreviewService::recent_orders( 30 ),
		'limits' => [
			'orderCount' => 0,
			'htmlBytes'  => 750000,
		],
		'notice' => __( 'Preview renders the actual registered WooCommerce HTML email without sending it. Refund, fulfillment, account, and password-reset emails are omitted until their required lifecycle objects are available.', 'drywall-toolbox' ),
	] );
}

function dtb_vd_rest_email_studio_render( WP_REST_Request $request ) {
	if ( ! class_exists( EmailPreviewService::class ) ) {
		return new WP_Error( 'dtb_email_studio_unavailable', __( 'The canonical email preview service is unavailable.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$result = EmailPreviewService::render(
		absint( $request->get_param( 'orderId' ) ),
		sanitize_key( (string) $request->get_param( 'emailId' ) )
	);

	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

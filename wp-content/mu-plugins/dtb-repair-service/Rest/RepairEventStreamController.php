<?php
/**
 * Rest — RepairEventStreamController: GET /wp-json/dtb/v1/repairs/{id}/stream
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_stream_route' );

function dtb_repair_register_stream_route(): void {
	$routes = [
		'/repairs/(?P<id>\d+)/stream',
		'/repairs/(?P<id>\d+)/events/stream',
	];

	foreach ( $routes as $route ) {
		register_rest_route(
			'dtb/v1',
			$route,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dtb_repair_rest_events_stream',
				'permission_callback' => '__return_true',
				'args'                => [
					'id'            => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'token'         => [ 'type' => 'string', 'required' => false, 'default' => '' ],
					'last_event_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0, 'minimum' => 0 ],
				],
			]
		);
	}
}

function dtb_repair_rest_events_stream( WP_REST_Request $request ): void {
	$repair_id     = (int) $request->get_param( 'id' );
	$public_token  = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$last_event_id = (int) $request->get_param( 'last_event_id' );

	$access = function_exists( 'dtb_validate_repair_access' )
		? dtb_validate_repair_access( $repair_id, $public_token )
		: true;
	if ( is_wp_error( $access ) ) {
		$status = (int) ( $access->get_error_data()['status'] ?? 403 );
		header( 'Content-Type: application/json', true, $status );
		echo wp_json_encode( [
			'code'    => $access->get_error_code(),
			'message' => $access->get_error_message(),
		] );
		exit;
	}

	// Disable output buffering and set SSE headers.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	header( 'Content-Type: text/event-stream; charset=UTF-8' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'X-Accel-Buffering: no' );
	header( 'Connection: keep-alive' );

	if ( ! function_exists( 'dtb_repair_get_events' ) ) {
		echo "data: []\n\n";
		flush();
		exit;
	}

	// Fetch customer-visible events since last_event_id.
	global $wpdb;
	$table  = $wpdb->prefix . 'dtb_repair_events';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix (trusted); esc_sql() applied for defense-in-depth.
	$events = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, event_type, from_status, to_status, payload_json, created_at
			 FROM `" . esc_sql( $table ) . "`
			 WHERE repair_id = %d
			   AND id > %d
			   AND visibility IN ('customer', 'operator')
			 ORDER BY id ASC
			 LIMIT 100",
			$repair_id,
			$last_event_id
		)
	);

	if ( empty( $events ) ) {
		echo ": heartbeat\n\n";
		flush();
		exit;
	}

	foreach ( $events as $ev ) {
		$payload = ! empty( $ev->payload_json ) ? json_decode( $ev->payload_json, true ) : [];
		$data    = wp_json_encode(
			[
				'id'          => (int) $ev->id,
				'type'        => $ev->event_type,
				'from_status' => $ev->from_status,
				'to_status'   => $ev->to_status,
				'occurred_at' => $ev->created_at,
				'payload'     => $payload,
			]
		);
		echo "id: {$ev->id}\n";
		echo "event: {$ev->event_type}\n";
		echo "data: {$data}\n\n";
	}

	flush();
	exit;
}

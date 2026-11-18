<?php
/**
 * Public, non-cached shipping cutoff information for the React storefront.
 *
 * WooCommerce and Veeqo remain the authorities for commerce and fulfillment.
 * This endpoint exposes only the configured site timezone and the daily order
 * cutoff used by the PDP presentation.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_StorefrontShippingCutoffController {
	private const CUTOFF_HOUR   = 14;
	private const CUTOFF_MINUTE = 0;
	private const WORK_DAYS     = [ 1, 2, 3, 4, 5 ];

	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/storefront/shipping-cutoff',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'read' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function read(): WP_REST_Response {
		$timezone   = wp_timezone();
		$site_now   = new DateTimeImmutable( 'now', $timezone );
		$today_cutoff = $site_now->setTime( self::CUTOFF_HOUR, self::CUTOFF_MINUTE, 0 );
		$cutoff_open  = self::is_work_day( (int) $site_now->format( 'N' ) ) && $site_now < $today_cutoff;
		$cutoff       = $today_cutoff;

		if ( ! $cutoff_open ) {
			$cutoff = $today_cutoff->modify( '+1 day' );
			while ( ! self::is_work_day( (int) $cutoff->format( 'N' ) ) ) {
				$cutoff = $cutoff->modify( '+1 day' );
			}
		}

		$utc = new DateTimeZone( 'UTC' );
		$response = new WP_REST_Response(
			[
				'server_now_utc'       => $site_now->setTimezone( $utc )->format( DateTimeInterface::ATOM ),
				'cutoff_at_utc'        => $cutoff->setTimezone( $utc )->format( DateTimeInterface::ATOM ),
				'timezone'             => $timezone->getName(),
				'cutoff_local'         => '14:00',
				'same_day_cutoff_open' => $cutoff_open,
			]
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	private static function is_work_day( int $weekday ): bool {
		return in_array( $weekday, self::WORK_DAYS, true );
	}
}

DTB_StorefrontShippingCutoffController::register();

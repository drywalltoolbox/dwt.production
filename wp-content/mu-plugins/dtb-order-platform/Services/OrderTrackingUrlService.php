<?php
/**
 * DTB Order Tracking URL Service — carrier tracking URL builder.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_build_tracking_url( string $carrier, string $tracking_number ): ?string {
	$tn = rawurlencode( $tracking_number );

	$map = [
		'ups'     => "https://www.ups.com/track?tracknum={$tn}",
		'fedex'   => "https://www.fedex.com/fedextrack/?tracknumbers={$tn}",
		'usps'    => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$tn}",
		'dhl'     => "https://www.dhl.com/us-en/home/tracking/tracking-global-forwarding.html?submit=1&tracking-id={$tn}",
		'ontrac'  => "https://www.ontrac.com/trackingres.asp?tracking_number={$tn}",
	];

	$key = strtolower( $carrier );
	return $map[ $key ] ?? null;
}

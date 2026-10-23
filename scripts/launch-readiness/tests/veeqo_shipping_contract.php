<?php
/**
 * Offline shipping-rate contract checks. No WordPress bootstrap, API calls,
 * orders, payments, shipments, or purchased labels.
 */
define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $value ) { return trim( (string) $value ); }
final class DTB_Test_Product {
	private array $meta;
	public function __construct( array $meta ) { $this->meta = $meta; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
}
require __DIR__ . '/../../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-integrations/Veeqo/VeeqoShippingService.php';

function dtb_shipping_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$valid = [
	'_dtb_shipping_box_verified'  => 'yes',
	'_dtb_shipping_box_weight_lb' => '55',
	'_dtb_shipping_box_length_in'=> '54',
	'_dtb_shipping_box_width_in' => '21',
	'_dtb_shipping_box_height_in'=> '16',
];
$single = [ 'contents' => [ 'key1' => [ 'quantity' => 1, 'data' => new DTB_Test_Product( $valid ) ] ] ];
$parcel = DTB_VeeqoShippingService::verified_single_carton( $single );
dtb_shipping_assert( is_array( $parcel ) && 55.0 === $parcel['weight'] && 54.0 === $parcel['length'], 'Valid single-carton package rejected.' );
dtb_shipping_assert( 'lb' === $parcel['weight_unit'] && 'in' === $parcel['dimension_unit'], 'Wrong parcel units.' );
$multiple = $single;
$multiple['contents']['key1']['quantity'] = 2;
dtb_shipping_assert( null === DTB_VeeqoShippingService::verified_single_carton( $multiple ), 'Multiple cartons quoted as one.' );
$unverified = $valid;
$unverified['_dtb_shipping_box_verified'] = 'no';
dtb_shipping_assert( null === DTB_VeeqoShippingService::verified_single_carton( [ 'contents' => [ 'x' => [ 'quantity' => 1, 'data' => new DTB_Test_Product( $unverified ) ] ] ] ), 'Unverified carton accepted.' );
$missing = $valid;
$missing['_dtb_shipping_box_height_in'] = '';
dtb_shipping_assert( null === DTB_VeeqoShippingService::verified_single_carton( [ 'contents' => [ 'x' => [ 'quantity' => 1, 'data' => new DTB_Test_Product( $missing ) ] ] ] ), 'Missing dimensions accepted.' );
$oversize = $valid;
$oversize['_dtb_shipping_box_length_in'] = 109;
dtb_shipping_assert( null === DTB_VeeqoShippingService::verified_single_carton( [ 'contents' => [ 'x' => [ 'quantity' => 1, 'data' => new DTB_Test_Product( $oversize ) ] ] ] ), 'Oversize carton accepted without freight policy.' );
$rates = [
	[ 'carrier_nice_name' => 'USPS', 'service_name' => 'Bound Printed Matter', 'total_charge' => '0.95' ],
	[ 'carrier_nice_name' => 'USPS', 'service_name' => 'Media Mail', 'total_charge' => '1.25' ],
	[ 'carrier_nice_name' => 'USPS', 'service_name' => 'Priority Mail Flat Rate Envelope', 'total_charge' => '2.00' ],
	[ 'carrier_nice_name' => 'USPS', 'service_name' => 'Ground Advantage Cubic', 'total_charge' => '3.00' ],
	[ 'carrier_nice_name' => 'USPS', 'service_name' => 'Ground Advantage (1 - 70 lb)', 'total_charge' => '45.00' ],
	[ 'carrier_nice_name' => 'UPS', 'service_name' => '2nd Day Air', 'total_charge' => '85.00' ],
	[ 'carrier_nice_name' => 'FedEx', 'service_name' => 'Priority Overnight', 'total_charge' => '130.00' ],
];
$reflect = new ReflectionMethod( DTB_VeeqoShippingService::class, 'normalize_quotes' );
$quotes = $reflect->invoke( null, $rates, $parcel );
dtb_shipping_assert( 3 === count( $quotes ), 'Ineligible media/cubic/envelope rates not excluded.' );
dtb_shipping_assert( [ 'standard', 'express', 'overnight' ] === array_column( $quotes, 'tier' ), 'Carrier service tiers incorrect.' );
dtb_shipping_assert( 3 === count( array_unique( array_column( $quotes, 'id' ) ) ), 'Unstable/duplicate carrier rate IDs.' );
dtb_shipping_assert( 45.0 === $quotes[0]['cost'], 'Unexpected rate normalization.' );
$requoted = $rates;
$requoted[4]['rate_id'] = 'short-lived-token-value-changed';
$new_quotes = $reflect->invoke( null, $requoted, $parcel );
dtb_shipping_assert( array_column( $quotes, 'id' ) === array_column( $new_quotes, 'id' ), 'Woo shipping method identity varies with expiring Veeqo quote tokens.' );
$heavy_parcel = $parcel;
$heavy_parcel['weight'] = 71.0;
$heavy_quotes = $reflect->invoke( null, $rates, $heavy_parcel );
dtb_shipping_assert( 2 === count( $heavy_quotes ), 'USPS parcel weight limit not enforced.' );
echo "Veeqo verified-carton shipping contract: PASS\n";

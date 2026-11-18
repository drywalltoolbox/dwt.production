<?php
/**
 * Server-side eligibility for DTB new-account first-order coupons.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_SignupCouponPolicy {
	private const CAMPAIGN_ID     = 'welcome-10-first-order';
	private const USER_COUPON_KEY = '_dtb_signup_coupon_id';
	private const OWNER_META_KEY  = '_dtb_signup_coupon_user_id';
	private const CAMP_META_KEY   = '_dtb_signup_coupon_campaign_id';

	public static function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', [ self::class, 'validate_campaign_coupon' ], 20, 3 );
		add_filter( 'woocommerce_coupon_error', [ self::class, 'campaign_error_message' ], 20, 3 );
	}

	/**
	 * Coupon codes are not authorization. Require the matching logged-in account
	 * and reject the coupon after that account has placed its first order.
	 * WooCommerce remains responsible for validating the email restriction and
	 * tracking the coupon redemption on the order.
	 *
	 * @param bool         $valid     Coupon validity before DTB campaign policy.
	 * @param WC_Coupon    $coupon    Coupon to validate.
	 * @param WC_Discounts $discounts Current WooCommerce discount calculation.
	 */
	public static function validate_campaign_coupon( bool $valid, WC_Coupon $coupon, $discounts ): bool {
		$campaign_id = (string) $coupon->get_meta( self::CAMP_META_KEY, true );
		if ( self::CAMPAIGN_ID !== $campaign_id ) {
			return $valid;
		}
		if ( ! $valid ) {
			return false;
		}

		$user_id = get_current_user_id();
		$owner   = absint( $coupon->get_meta( self::OWNER_META_KEY, true ) );
		if ( $user_id <= 0 || $owner !== $user_id || absint( get_user_meta( $user_id, self::USER_COUPON_KEY, true ) ) !== $coupon->get_id() ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return false;
		}

		$prior_orders = wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
				'limit'       => 1,
				'return'      => 'ids',
			]
		);

		return empty( $prior_orders );
	}

	/** Keep invalid personal-code failures clear without exposing another account's identity. */
	public static function campaign_error_message( string $message, int $error_code, WC_Coupon $coupon ): string {
		if ( self::CAMPAIGN_ID !== (string) $coupon->get_meta( self::CAMP_META_KEY, true ) ) {
			return $message;
		}

		$user_id = get_current_user_id();
		$owner   = absint( $coupon->get_meta( self::OWNER_META_KEY, true ) );
		if ( $user_id <= 0 || $owner !== $user_id ) {
			return __( 'This personal signup coupon must be used while signed in to the account that received it.', 'drywall-toolbox' );
		}

		$prior_orders = wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
				'limit'       => 1,
				'return'      => 'ids',
			]
		);
		if ( ! empty( $prior_orders ) ) {
			return __( 'This personal offer is available on your first order only.', 'drywall-toolbox' );
		}

		return $message;
	}
}

DTB_SignupCouponPolicy::register();

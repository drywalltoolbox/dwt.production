<?php
/**
 * Canonical WooCommerce checkout-field policy.
 *
 * WooCommerce's own `CheckoutFields.php` hardcodes the `contact` location's
 * allowed fields to `['email']` only — Woo does not support a name field at
 * that location by design. First/last name are collected once, on the
 * canonical `address` location (WooCommerce's native `first_name`/
 * `last_name` billing/shipping inputs, rendered on the Shipping step), never
 * duplicated as a separate Additional Checkout Field group.
 *
 * Design history this file must not regress: an earlier version of this
 * class (Redesign v3, 2026-07-29 through v15, 2026-07-31) registered
 * `dtb/first_name`/`dtb/last_name` as Additional Checkout Fields at the
 * `contact` location, hid the native name inputs on the address forms via
 * `woocommerce_get_country_locale`, and synced the Contact-step value onto
 * the canonical billing/shipping name — both server-side here and, because
 * WooCommerce Blocks' *client-side* `wc/store/cart` state (which the payment provider's elements read
 * `billing_details`/shipping name from) was never populated by that
 * workaround, via a second, hand-written client-side sync in checkout.js.
 * That dual-field design directly caused a checkout-blocking production
 * incident (Redesign v15): a provider's "Missing required param:
 * payment_method_data[billing_details][name]" on every typed/card checkout,
 * because the name lived in a state tree neither provider element read.
 * The rebuild documented in checkout-ui-architecture.md removes the
 * duplicate field group and its sync workaround entirely; name now reaches
 * WooCommerce's canonical address state directly, the same state the provider
 * already reads, with no sync required.
 *
 * Only phone remains a Contact-location Additional Checkout Field
 * (`dtb/phone`) — WooCommerce's native phone field lives on the address
 * form only, and collecting it once on Contact instead avoids asking twice
 * on a store that also enables the Checkout block's native Phone address
 * field. Phone is unaffected by the name-field removal above; see the
 * "Required one-time admin action" note in checkout-ui-architecture.md for
 * disabling the address-step Phone field in the block editor.
 *
 * Historical orders may still contain legacy dtb-checkout/contact-*
 * additional-field metadata from a prior, unrelated experiment, and legacy
 * `dtb/first_name`/`dtb/last_name` additional-field metadata from Redesign
 * v3–v16. That data is retained for compatibility and auditability only and
 * is never bulk-rewritten.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutFieldPolicy {
	private const PHONE_FIELD = 'dtb/phone';

	public static function register(): void {
		add_filter( 'option_woocommerce_checkout_phone_field', [ __CLASS__, 'optional_phone' ] );
		add_filter( 'default_option_woocommerce_checkout_phone_field', [ __CLASS__, 'optional_phone' ] );

		add_action( 'woocommerce_init', [ __CLASS__, 'register_contact_fields' ] );
		add_action( 'woocommerce_set_additional_field_value', [ __CLASS__, 'sync_field_value' ], 10, 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'sync_from_order' ], 5 );
	}

	/** Keep WooCommerce's canonical phone field available but optional. */
	public static function optional_phone( $value ): string {
		return 'optional';
	}

	/**
	 * Register the Contact-step phone field via the Additional Checkout
	 * Fields API. Not required at the API level, so an Apple Pay / Google
	 * Pay / Link order (which never touches it) is never blocked.
	 */
	public static function register_contact_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			[
				'id'         => self::PHONE_FIELD,
				'label'      => __( 'Phone', 'drywall-toolbox' ), // WooCommerce appends "(optional)" itself for non-required fields.
				'location'   => 'contact',
				'type'       => 'text',
				'required'   => false,
				'attributes' => [ 'autocomplete' => 'tel' ],
			]
		);
	}

	/**
	 * Fires the moment WooCommerce persists a Contact-step field value onto
	 * an order or customer object. In-memory, immediate sync.
	 *
	 * @param string               $key       Registered field id.
	 * @param mixed                $value     Field value.
	 * @param string               $group     shipping|billing|other.
	 * @param WC_Order|WC_Customer $wc_object Object the value was saved to.
	 */
	public static function sync_field_value( string $key, $value, string $group, $wc_object ): void {
		unset( $group );
		if ( self::PHONE_FIELD !== $key ) {
			return;
		}
		self::apply_phone( $wc_object, is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Durable pass at order-processed time. Re-reads the Contact-step phone
	 * value straight from the order and re-applies it, so the sync survives
	 * regardless of exactly when, relative to Woo's own order save, the
	 * `woocommerce_set_additional_field_value` hook above fired.
	 *
	 * @param mixed $order
	 */
	public static function sync_from_order( $order ): void {
		$service = self::checkout_fields_service();
		if ( ! $order instanceof WC_Order || ! $service ) {
			return;
		}

		try {
			$value = $service->get_field_from_object( self::PHONE_FIELD, $order, 'other' );
		} catch ( Throwable $e ) {
			return;
		}

		if ( is_scalar( $value ) && '' !== trim( (string) $value ) && self::apply_phone( $order, (string) $value ) ) {
			$order->save();
		}
	}

	/**
	 * @param WC_Order|WC_Customer|mixed $wc_object
	 */
	private static function apply_phone( $wc_object, string $value ): bool {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return false; // Never overwrite a wallet-supplied phone with a blank value.
		}
		if ( ! $wc_object instanceof WC_Order && ! $wc_object instanceof WC_Customer ) {
			return false;
		}

		$changed = false;
		if ( '' === trim( (string) $wc_object->get_billing_phone() ) ) {
			$wc_object->set_billing_phone( $value );
			$changed = true;
		}
		if ( method_exists( $wc_object, 'set_shipping_phone' ) && '' === trim( (string) $wc_object->get_shipping_phone() ) ) {
			$wc_object->set_shipping_phone( $value );
			$changed = true;
		}
		return $changed;
	}

	/** @return object|null Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields instance. */
	private static function checkout_fields_service() {
		if (
			! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ||
			! class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' )
		) {
			return null;
		}
		try {
			return \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		} catch ( Throwable $e ) {
			return null;
		}
	}
}

DTB_CheckoutFieldPolicy::register();

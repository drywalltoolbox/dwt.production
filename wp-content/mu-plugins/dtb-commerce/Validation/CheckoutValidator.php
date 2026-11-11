<?php

defined( 'ABSPATH' ) || exit;

/**
 * Server-authoritative checkout validation and WooCommerce cart projection.
 *
 * Browser cart values are display data only. Every value used for a quote or
 * order is read from the current WooCommerce cart/session in this boundary.
 *
 * This validator must never decode an unverified Cart-Token payload or query
 * woocommerce_sessions directly to recover another session. WooCommerce owns
 * Cart-Token validation, session resolution, and cart hydration.
 */
final class DTB_CheckoutValidator {

	// =========================================================================
	// CART / SESSION BOOTSTRAP
	// =========================================================================

	/**
	 * Ensure WooCommerce cart, customer, and session are loaded and non-empty.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_cart(): true|WP_Error {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'dtb_checkout_wc_unavailable', 'WooCommerce is not available.', [ 'status' => 503 ] );
		}

		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->customer || ! WC()->session ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart || ! WC()->customer || ! WC()->session ) {
			return new WP_Error( 'dtb_checkout_cart_unavailable', 'The checkout cart session is unavailable.', [ 'status' => 503 ] );
		}

		if ( WC()->cart->is_empty() ) {
			return new WP_Error( 'dtb_checkout_empty_cart', 'Your cart is empty.', [ 'status' => 422 ] );
		}

		return true;
	}

	// =========================================================================
	// ADDRESS NORMALISATION AND VALIDATION
	// =========================================================================

	public static function normalize_address( $address, bool $include_contact = false ): array {
		$address = is_array( $address ) ? $address : [];
		$country = strtoupper( sanitize_text_field( (string) ( $address['country'] ?? 'US' ) ) );
		if ( 2 !== strlen( $country ) && function_exists( 'WC' ) && WC()->countries ) {
			foreach ( (array) WC()->countries->get_countries() as $code => $name ) {
				if ( 0 === strcasecmp( (string) $name, $country ) ) {
					$country = strtoupper( (string) $code );
					break;
				}
			}
		}

		$normalized = [
			'first_name' => sanitize_text_field( (string) ( $address['first_name'] ?? $address['firstName'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $address['last_name'] ?? $address['lastName'] ?? '' ) ),
			'company'    => sanitize_text_field( (string) ( $address['company'] ?? '' ) ),
			'address_1'  => sanitize_text_field( (string) ( $address['address_1'] ?? $address['address'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $address['address_2'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
			'state'      => strtoupper( sanitize_text_field( (string) ( $address['state'] ?? '' ) ) ),
			'postcode'   => sanitize_text_field( (string) ( $address['postcode'] ?? $address['zip'] ?? '' ) ),
			'country'    => $country ?: 'US',
		];

		if ( $include_contact ) {
			$normalized['email'] = sanitize_email( (string) ( $address['email'] ?? '' ) );
			$normalized['phone'] = sanitize_text_field( (string) ( $address['phone'] ?? '' ) );
		}

		return $normalized;
	}

	public static function validate_addresses( array $billing, array $shipping ): true|WP_Error {
		$required_billing = [ 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email' ];
		foreach ( $required_billing as $field ) {
			if ( '' === trim( (string) ( $billing[ $field ] ?? '' ) ) ) {
				return new WP_Error( 'dtb_checkout_invalid_address', sprintf( 'Billing field "%s" is required.', $field ), [ 'status' => 422 ] );
			}
		}

		if ( ! is_email( (string) $billing['email'] ) ) {
			return new WP_Error( 'dtb_checkout_invalid_email', 'A valid billing email address is required.', [ 'status' => 422 ] );
		}

		foreach ( [ 'address_1', 'city', 'state', 'postcode', 'country' ] as $field ) {
			if ( '' === trim( (string) ( $shipping[ $field ] ?? '' ) ) ) {
				return new WP_Error( 'dtb_checkout_invalid_address', sprintf( 'Shipping field "%s" is required.', $field ), [ 'status' => 422 ] );
			}
		}

		return true;
	}

	// =========================================================================
	// COUPON NORMALISATION
	// =========================================================================

	public static function normalize_coupon_codes( $codes ): array {
		$normalized = [];
		foreach ( is_array( $codes ) ? $codes : [] as $code ) {
			$code = strtolower( sanitize_text_field( (string) $code ) );
			if ( '' !== $code && strlen( $code ) <= 100 ) {
				$normalized[] = $code;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	// =========================================================================
	// CUSTOMER IDENTITY
	// =========================================================================

	public static function customer_identity(): array {
		$customer_id = get_current_user_id();
		$session_id  = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';

		if ( '' === $session_id ) {
			$session_id = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( 'dtb_checkout_identity_nonce' ) : '';
			if ( '' === $session_id && function_exists( 'WC' ) && WC()->session ) {
				$session_id = 'guest-' . wp_generate_uuid4();
				WC()->session->set( 'dtb_checkout_identity_nonce', $session_id );
			}
		}
		if ( '' === $session_id ) {
			$session_id = 'user-' . ( $customer_id > 0 ? $customer_id : 'unbound' );
		}

		return [
			'customer_id'           => (int) $customer_id,
			'customer_session_hash' => hash( 'sha256', $session_id ),
		];
	}

	// =========================================================================
	// CUSTOMER CONTEXT — SET DESTINATION ON WC CUSTOMER, FLUSH PACKAGE CACHE
	// =========================================================================

	/**
	 * Apply billing/shipping address and coupons to the WC customer object.
	 *
	 * @return true|WP_Error
	 */
	public static function apply_customer_context( array $billing, array $shipping, array $coupon_codes ): true|WP_Error {
		$validated = self::validate_addresses( $billing, $shipping );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$customer       = WC()->customer;
		$billing_fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];
		foreach ( $billing_fields as $field ) {
			$method = 'set_billing_' . $field;
			if ( method_exists( $customer, $method ) ) {
				$customer->{$method}( $billing[ $field ] ?? '' );
			}
		}

		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $field ) {
			$method = 'set_shipping_' . $field;
			if ( method_exists( $customer, $method ) ) {
				$customer->{$method}( $shipping[ $field ] ?? '' );
			}
		}
		if ( method_exists( $customer, 'set_calculated_shipping' ) ) {
			$customer->set_calculated_shipping( true );
		}
		if ( method_exists( $customer, 'save' ) ) {
			$customer->save();
		}

		$packages = (array) WC()->cart->get_shipping_packages();
		if ( function_exists( 'dtb_commerce_invalidate_shipping_package_cache' ) ) {
			dtb_commerce_invalidate_shipping_package_cache( $packages );
		}

		$requested = self::normalize_coupon_codes( $coupon_codes );
		$current   = self::normalize_coupon_codes( WC()->cart->get_applied_coupons() );
		foreach ( array_values( array_unique( array_merge( $current, $requested ) ) ) as $code ) {
			if ( WC()->cart->has_discount( $code ) ) {
				continue;
			}
			$applied = WC()->cart->apply_coupon( $code );
			if ( false === $applied && ! WC()->cart->has_discount( $code ) ) {
				return new WP_Error( 'dtb_checkout_invalid_coupon', sprintf( 'Coupon "%s" could not be applied.', $code ), [ 'status' => 422 ] );
			}
		}

		return true;
	}

	// =========================================================================
	// CART SNAPSHOT
	// =========================================================================

	public static function cart_snapshot(): array|WP_Error {
		$items = [];
		foreach ( (array) WC()->cart->get_cart() as $cart_item ) {
			$product  = $cart_item['data'] ?? null;
			$quantity = absint( $cart_item['quantity'] ?? 0 );
			if ( ! $product instanceof WC_Product || $quantity < 1 ) {
				continue;
			}
			if ( ! $product->is_purchasable() || ( $product->managing_stock() && ! $product->has_enough_stock( $quantity ) ) ) {
				return new WP_Error( 'dtb_checkout_stock_changed', sprintf( 'The product "%s" is no longer available in the requested quantity.', $product->get_name() ), [ 'status' => 409 ] );
			}

			$items[] = [
				'product_id'    => absint( $cart_item['product_id'] ?? ( $product->get_parent_id() ?: $product->get_id() ) ),
				'variation_id'  => absint( $cart_item['variation_id'] ?? ( $product->is_type( 'variation' ) ? $product->get_id() : 0 ) ),
				'quantity'      => $quantity,
				'sku'           => sanitize_text_field( (string) $product->get_sku() ),
				'name'          => sanitize_text_field( (string) $product->get_name() ),
				'line_subtotal' => wc_format_decimal( (string) ( $cart_item['line_subtotal'] ?? 0 ), 2 ),
				'line_total'    => wc_format_decimal( (string) ( $cart_item['line_total'] ?? 0 ), 2 ),
				'product'       => $product,
			];
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'dtb_checkout_empty_cart', 'Your cart is empty.', [ 'status' => 422 ] );
		}

		$cart_hash = method_exists( WC()->cart, 'get_cart_hash' ) ? (string) WC()->cart->get_cart_hash() : '';
		if ( '' === $cart_hash ) {
			$cart_hash = hash( 'sha256', wp_json_encode( array_map( static function ( array $item ): array {
				return array_intersect_key( $item, array_flip( [ 'product_id', 'variation_id', 'quantity', 'line_total' ] ) );
			}, $items ) ) ?: '' );
		}

		return [
			'cart_hash' => $cart_hash,
			'items'     => $items,
			'coupons'   => self::normalize_coupon_codes( WC()->cart->get_applied_coupons() ),
			'totals'    => [
				'subtotal' => (float) WC()->cart->get_subtotal(),
				'discount' => (float) WC()->cart->get_discount_total(),
				'shipping' => (float) WC()->cart->get_shipping_total(),
				'tax'      => (float) WC()->cart->get_total_tax(),
				'total'    => (float) WC()->cart->get_total( 'edit' ),
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			],
		];
	}

	// =========================================================================
	// SHIPPING RATES
	// =========================================================================

	/**
	 * @return array<int,array{id:string,method_id:string,instance_id:int,name:string,price:float,tax:float,total:float,currency:string}>
	 */
	public static function shipping_rates(): array {
		WC()->cart->calculate_shipping();

		$rates    = [];
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$packages = WC()->shipping() ? (array) WC()->shipping()->get_packages() : [];

		foreach ( $packages as $package ) {
			foreach ( (array) ( $package['rates'] ?? [] ) as $rate ) {
				if ( ! ( $rate instanceof WC_Shipping_Rate ) ) {
					continue;
				}
				$cost    = (float) $rate->get_cost();
				$taxes   = array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) );
				$rates[] = [
					'id'          => (string) $rate->get_id(),
					'method_id'   => sanitize_key( (string) $rate->get_method_id() ),
					'instance_id' => absint( $rate->get_instance_id() ),
					'name'        => sanitize_text_field( (string) $rate->get_label() ),
					'price'       => $cost,
					'tax'         => (float) $taxes,
					'total'       => $cost + (float) $taxes,
					'currency'    => $currency,
				];
			}
		}

		return $rates;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function shipping_rates_for_current_cart( array $address ): array|WP_Error {
		$ready = self::ensure_cart();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$shipping = self::normalize_address( $address );
		foreach ( [ 'address_1', 'city', 'state', 'postcode', 'country' ] as $field ) {
			if ( '' === trim( (string) ( $shipping[ $field ] ?? '' ) ) ) {
				return new WP_Error( 'dtb_checkout_invalid_address', 'A complete shipping address is required.', [ 'status' => 422 ] );
			}
		}
		$customer = WC()->customer;
		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $field ) {
			$method = 'set_shipping_' . $field;
			if ( method_exists( $customer, $method ) ) {
				$customer->{$method}( $shipping[ $field ] ?? '' );
			}
		}
		if ( method_exists( $customer, 'set_calculated_shipping' ) ) {
			$customer->set_calculated_shipping( true );
		}
		if ( method_exists( $customer, 'save' ) ) {
			$customer->save();
		}
		$packages = (array) WC()->cart->get_shipping_packages();
		if ( function_exists( 'dtb_commerce_invalidate_shipping_package_cache' ) ) {
			dtb_commerce_invalidate_shipping_package_cache( $packages );
		}
		$rates = self::shipping_rates();
		return empty( $rates )
			? new WP_Error( 'dtb_checkout_shipping_unavailable', 'No shipping method is available for this destination.', [ 'status' => 422 ] )
			: $rates;
	}

	// =========================================================================
	// FULL EVALUATION (quote / session / confirm path)
	// =========================================================================

	/**
	 * @return array|WP_Error
	 */
	public static function evaluate( array $payload ): array|WP_Error {
		$ready = self::ensure_cart();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$billing  = self::normalize_address( $payload['billing'] ?? [], true );
		$shipping = self::normalize_address( $payload['shipping'] ?? $billing );
		$coupons  = self::normalize_coupon_codes( $payload['coupon_codes'] ?? [] );
		$applied  = self::apply_customer_context( $billing, $shipping, $coupons );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$rates = self::shipping_rates();
		if ( empty( $rates ) ) {
			return new WP_Error( 'dtb_checkout_shipping_unavailable', 'No shipping method is available for this destination.', [ 'status' => 422 ] );
		}

		$requested_rate = sanitize_text_field( (string) ( $payload['shipping_rate_id'] ?? '' ) );
		$selected       = null;
		foreach ( $rates as $rate ) {
			if ( '' !== $requested_rate && hash_equals( (string) $rate['id'], $requested_rate ) ) {
				$selected = $rate;
				break;
			}
		}

		if ( '' !== $requested_rate && null === $selected ) {
			return new WP_Error(
				'dtb_checkout_shipping_rate_changed',
				'The selected shipping method is no longer available. Refresh shipping options and try again.',
				[ 'status' => 409 ]
			);
		}
		if ( null === $selected ) {
			$selected = $rates[0];
		}

		$chosen    = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		$chosen[0] = $selected['id'];
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		WC()->cart->calculate_totals();

		$snapshot = self::cart_snapshot();
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return [
			'billing'          => $billing,
			'shipping'         => $shipping,
			'coupon_codes'     => $snapshot['coupons'],
			'shipping_rate_id' => $selected['id'],
			'shipping_rate'    => $selected,
			'cart_hash'        => $snapshot['cart_hash'],
			'items'            => $snapshot['items'],
			'rates'            => $rates,
			'totals'           => $snapshot['totals'],
		];
	}

	// =========================================================================
	// FINGERPRINT + PUBLIC QUOTE SHAPE
	// =========================================================================

	public static function fingerprint( array $context, string $payment_method = '' ): string {
		$items = [];
		foreach ( (array) ( $context['items'] ?? [] ) as $item ) {
			$items[] = array_intersect_key( $item, array_flip( [ 'product_id', 'variation_id', 'quantity', 'line_total' ] ) );
		}

		return hash( 'sha256', wp_json_encode( [
			'cart_hash'        => (string) ( $context['cart_hash'] ?? '' ),
			'payment_method'   => sanitize_key( $payment_method ),
			'billing'          => $context['billing'] ?? [],
			'shipping'         => $context['shipping'] ?? [],
			'coupon_codes'     => $context['coupon_codes'] ?? [],
			'shipping_rate_id' => (string) ( $context['shipping_rate_id'] ?? '' ),
			'items'            => $items,
			'totals'           => $context['totals'] ?? [],
		] ) ?: '' );
	}

	public static function public_quote( array $quote, string $quote_id, string $expires_at ): array {
		return [
			'quote_id'         => $quote_id,
			'cart_hash'        => $quote['cart_hash'],
			'expires_at'       => $expires_at,
			'rates'            => $quote['rates'],
			'selected_rate_id' => $quote['shipping_rate_id'],
			'totals'           => $quote['totals'],
		];
	}
}

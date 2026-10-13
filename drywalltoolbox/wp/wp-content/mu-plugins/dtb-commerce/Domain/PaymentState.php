<?php

defined( 'ABSPATH' ) || exit;

/** @return array<int,string> */
function dtb_checkout_handoff_supported_contracts(): array {
	return [
		'payment-plugins-stripe-v1',
		'woopayments-v1',
		'woo-stripe-v1', // Historical orders created before the provider migration.
	];
}

/** @return array<int,string> */
function dtb_checkout_handoff_supported_stripe_providers(): array {
	return [
		'payment_plugins_stripe',
		'woocommerce_stripe', // Historical paid-order evidence remains valid.
	];
}

function dtb_checkout_handoff_is_order( $order ): bool {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$gateway  = sanitize_key( (string) $order->get_meta( '_dtb_checkout_gateway', true ) );
	$contract = sanitize_key( (string) $order->get_meta( '_dtb_checkout_contract_version', true ) );

	return ( 'woo_native_stripe' === $gateway && in_array( $contract, [ 'payment-plugins-stripe-v1', 'woo-stripe-v1' ], true ) )
		|| ( 'woo_native_woopayments' === $gateway && 'woopayments-v1' === $contract );
}

function dtb_checkout_handoff_has_gateway_reference( WC_Order $order ): bool {
	if ( '' !== trim( (string) $order->get_transaction_id() ) ) {
		return true;
	}

	foreach ( [ '_dtb_payment_ref', '_stripe_intent_id', '_stripe_charge_id', '_payment_intent_id' ] as $meta_key ) {
		if ( '' !== trim( (string) $order->get_meta( $meta_key, true ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Verify provider evidence written only after a WooCommerce paid lifecycle hook.
 * Raw gateway-ID prefixes are intentionally insufficient because unrelated Stripe
 * extensions may register overlapping IDs.
 */
function dtb_checkout_handoff_uses_verified_stripe_provider( WC_Order $order ): bool {
	$provider = sanitize_key( (string) $order->get_meta( '_dtb_payment_provider', true ) );
	return in_array( $provider, dtb_checkout_handoff_supported_stripe_providers(), true );
}

/** Historical compatibility name retained for integrations outside this module. */
function dtb_checkout_handoff_uses_official_stripe_gateway( WC_Order $order ): bool {
	return dtb_checkout_handoff_uses_verified_stripe_provider( $order );
}

function dtb_checkout_handoff_has_provider_verified_payment( WC_Order $order ): bool {
	$provider = sanitize_key( (string) $order->get_meta( '_dtb_payment_provider', true ) );
	$verified_provider = 'woo_native_woopayments' === sanitize_key( (string) $order->get_meta( '_dtb_checkout_gateway', true ) )
		? ( 'woopayments' === $provider && 'woocommerce_payments' === substr( sanitize_key( (string) $order->get_payment_method() ), 0, 20 ) && in_array( sanitize_key( (string) $order->get_meta( '_wcpay_mode', true ) ), [ 'test', 'prod' ], true )
			&& 'succeeded' === sanitize_key( (string) $order->get_meta( '_intention_status', true ) )
			&& '' !== trim( (string) $order->get_meta( '_intent_id', true ) ) )
		: dtb_checkout_handoff_uses_verified_stripe_provider( $order );
	return dtb_checkout_handoff_is_order( $order )
		&& $verified_provider
		&& null !== $order->get_date_paid()
		&& dtb_checkout_handoff_has_gateway_reference( $order );
}

function dtb_checkout_handoff_has_captured_payment( WC_Order $order ): bool {
	return dtb_checkout_handoff_has_provider_verified_payment( $order );
}

function dtb_checkout_handoff_is_order_unpaid( WC_Order $order ): bool {
	return dtb_checkout_handoff_is_order( $order )
		&& (float) $order->get_total() > 0
		&& ! dtb_checkout_handoff_has_captured_payment( $order )
		&& ! in_array( (string) $order->get_status(), [ 'shipped', 'completed', 'cancelled', 'refunded', 'trash' ], true )
		&& ! in_array( sanitize_key( (string) $order->get_payment_method() ), [ 'cod', 'bacs', 'cheque' ], true );
}

function dtb_checkout_handoff_is_unpaid_order( $order ): bool {
	return $order instanceof WC_Order && dtb_checkout_handoff_is_order_unpaid( $order );
}

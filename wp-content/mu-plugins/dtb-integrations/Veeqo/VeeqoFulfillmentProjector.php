<?php
/**
 * Veeqo → native WooCommerce Fulfillment projector.
 *
 * Bridges Veeqo-authoritative shipment facts into WooCommerce 10.9.4's
 * native Fulfillments domain (`Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment`,
 * registered under the `order-fulfillment` WC_Data_Store key), so
 * WooCommerce's own `WC_Email_Customer_Fulfillment_Created`/`Updated`
 * classes fire through the same `..._notification` actions the native
 * OrderFulfillmentsRestController uses — never through this site's own REST
 * API, never via a direct `$wpdb` write to `wc_order_fulfillments`, and
 * never through the separately-installed WooCommerce Shipping plugin's
 * `Automattic\WCShipping\Fulfillments\*` classes.
 *
 * Shipment identity contract (confirmed 2026-07-31 by inspecting two
 * shipped Veeqo orders, replacing the previously-deferred resolver):
 *
 *   allocations[*].shipment.id  — the immutable native shipment identifier.
 *   allocations[*].id           — the allocation identifier; every real
 *                                 shipment carries shipment.allocation_id
 *                                 back-referencing it.
 *   allocations[*].shipment.order_id — the Veeqo order the shipment belongs to.
 *
 * `shipment.tracking_number` (when present) is itself an object with its
 * own child `id` and a `shipment_id` back-reference — tracking data is
 * mutable and is never used as identity, only as enrichment.
 *
 * A single Veeqo order payload can carry multiple allocations, each with
 * its own shipment — every valid shipment is projected as an independent
 * native Fulfillment record (see dtb_veeqo_project_order_shipments()).
 *
 * This module owns the full projection lifecycle: shipment identity
 * resolution/normalization, a canonical customer-visible-state fingerprint,
 * a per-shipment projection lock, duplicate/replay detection, and
 * native-notification ownership. dtb-order-platform only records the
 * resulting outcome as an order event; it is not a second source of truth
 * for any of the above.
 *
 * See docs/operations/woocommerce-html-email-architecture.md for the full
 * contract this file was verified against (vendor source export
 * drywalltoolbox/wp/wp-content/woo/dtb-woocommerce-fulfillments-source-20260731-123114/).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_FULFILLMENT_SOURCE_META      = '_dtb_source_system';
const DTB_VEEQO_FULFILLMENT_ORDER_META       = '_dtb_veeqo_order_id';
const DTB_VEEQO_FULFILLMENT_ALLOCATION_META  = '_dtb_veeqo_allocation_id';
const DTB_VEEQO_FULFILLMENT_IDENTITY_META    = '_dtb_veeqo_shipment_id';
const DTB_VEEQO_FULFILLMENT_REVISION_META    = '_dtb_veeqo_source_revision';
const DTB_VEEQO_FULFILLMENT_FINGERPRINT_META = '_dtb_veeqo_payload_fingerprint';
const DTB_VEEQO_FULFILLMENT_PROJECTED_AT_META = '_dtb_projected_at';

/**
 * Filterable kill switch. Disabling never touches a previously created
 * native Fulfillment record — it only makes future calls defer to the
 * legacy notification path (see dtb-integrations/Veeqo/VeeqoOrderStatusApplier.php).
 *
 * @return bool
 */
function dtb_veeqo_fulfillment_projection_enabled(): bool {
	return (bool) apply_filters( 'dtb_veeqo_fulfillment_projection_enabled', true );
}

/**
 * Best-effort extraction of a tracking number string from a Veeqo shipment's
 * `tracking_number` field, which may be a scalar or a child object with its
 * own `id`/`shipment_id`. Never treated as identity.
 *
 * @param mixed $tracking_field Raw `shipment.tracking_number` value.
 * @return string
 */
function dtb_veeqo_extract_tracking_number( $tracking_field ): string {
	if ( is_string( $tracking_field ) ) {
		return sanitize_text_field( $tracking_field );
	}
	if ( is_array( $tracking_field ) ) {
		return sanitize_text_field( (string) ( $tracking_field['tracking_number'] ?? $tracking_field['number'] ?? '' ) );
	}
	return '';
}

/**
 * Normalize a Veeqo order payload into one observation per allocation, each
 * independently validated against the confirmed identity contract. Returns
 * both valid and deferred/rejected observations so the caller can record a
 * typed outcome for every allocation present in the payload — nothing is
 * silently dropped.
 *
 * @param array<string,mixed> $veeqo_order_payload      Decoded Veeqo order payload.
 * @param int                 $expected_veeqo_order_id  Veeqo order ID this payload was fetched for.
 * @return array<int,array<string,mixed>> Each entry has at least 'status' and, when known, 'shipment_id'/'allocation_id'.
 */
function dtb_veeqo_normalize_shipment_observations( array $veeqo_order_payload, int $expected_veeqo_order_id ): array {
	$allocations = is_array( $veeqo_order_payload['allocations'] ?? null ) ? $veeqo_order_payload['allocations'] : [];
	$raw         = [];

	foreach ( $allocations as $allocation ) {
		if ( ! is_array( $allocation ) ) {
			continue;
		}

		$allocation_id = absint( $allocation['id'] ?? 0 );
		if ( $allocation_id <= 0 ) {
			$raw[] = [ 'status' => 'deferred_incomplete_source', 'reason' => 'invalid_allocation_id', 'allocation_id' => null, 'shipment_id' => null ];
			continue;
		}

		$shipment = is_array( $allocation['shipment'] ?? null ) ? $allocation['shipment'] : null;
		if ( null === $shipment || empty( $shipment ) ) {
			$raw[] = [ 'status' => 'deferred_incomplete_source', 'reason' => 'shipment_absent', 'allocation_id' => $allocation_id, 'shipment_id' => null ];
			continue;
		}

		$shipment_id = absint( $shipment['id'] ?? 0 );
		if ( $shipment_id <= 0 ) {
			$raw[] = [ 'status' => 'deferred_incomplete_source', 'reason' => 'invalid_shipment_id', 'allocation_id' => $allocation_id, 'shipment_id' => null ];
			continue;
		}

		$shipment_allocation_id = absint( $shipment['allocation_id'] ?? 0 );
		if ( $shipment_allocation_id !== $allocation_id ) {
			$raw[] = [ 'status' => 'rejected_identity_conflict', 'reason' => 'allocation_mismatch', 'allocation_id' => $allocation_id, 'shipment_id' => $shipment_id ];
			continue;
		}

		$shipment_order_id = absint( $shipment['order_id'] ?? 0 );
		if ( $shipment_order_id <= 0 || $shipment_order_id !== $expected_veeqo_order_id ) {
			$raw[] = [ 'status' => 'rejected_identity_conflict', 'reason' => 'order_mismatch', 'allocation_id' => $allocation_id, 'shipment_id' => $shipment_id ];
			continue;
		}

		$raw[] = [
			'status'          => 'valid',
			'allocation_id'   => $allocation_id,
			'shipment_id'     => $shipment_id,
			// Only the enclosing allocation's own line items are associated
			// with this shipment — never the full order's line items.
			'line_items'      => is_array( $allocation['line_items'] ?? null ) ? $allocation['line_items'] : [],
			'tracking_number' => dtb_veeqo_extract_tracking_number( $shipment['tracking_number'] ?? null ),
			'carrier'         => sanitize_text_field( (string) ( $shipment['carrier']['name'] ?? $shipment['carrier'] ?? '' ) ),
			'tracking_url'    => esc_url_raw( (string) ( $shipment['tracking_url'] ?? '' ) ),
			'source_revision' => sanitize_text_field( (string) ( $shipment['updated_at'] ?? $shipment['revision'] ?? '' ) ),
		];
	}

	// Collapse/reject duplicates: the same shipment_id may legitimately
	// appear only once per payload. If Veeqo (or a malformed payload) lists
	// it more than once, identical repeats collapse idempotently; content
	// conflicts reject every observation sharing that shipment_id.
	$by_shipment = [];
	foreach ( $raw as $entry ) {
		$key           = 'valid' === $entry['status'] ? 'valid:' . $entry['shipment_id'] : spl_object_id( (object) $entry );
		$by_shipment[ $key ][] = $entry;
	}

	$normalized = [];
	foreach ( $by_shipment as $key => $entries ) {
		if ( 1 === count( $entries ) || ! str_starts_with( (string) $key, 'valid:' ) ) {
			array_push( $normalized, ...$entries );
			continue;
		}

		$fingerprints = array_unique( array_map( static fn( array $e ): string => hash( 'sha256', (string) wp_json_encode( $e ) ), $entries ) );
		if ( 1 === count( $fingerprints ) ) {
			// Identical duplicates: collapse to a single observation.
			$normalized[] = $entries[0];
			continue;
		}

		// Conflicting duplicates for the same shipment_id: reject all of them.
		foreach ( $entries as $entry ) {
			$normalized[] = [
				'status'        => 'rejected_identity_conflict',
				'reason'        => 'duplicate_conflicting_shipment',
				'allocation_id' => $entry['allocation_id'],
				'shipment_id'   => $entry['shipment_id'],
			];
		}
	}

	return $normalized;
}

/**
 * Confirm the native Fulfillments API is present and that the
 * `order-fulfillment` data store still resolves to WooCommerce core's own
 * implementation (guards the WooCommerce-Shipping non-dependency
 * requirement — this projector never references
 * `Automattic\WCShipping\Fulfillments\*`).
 *
 * @return bool
 */
function dtb_veeqo_fulfillment_capability_ready(): bool {
	if ( ! dtb_veeqo_fulfillment_projection_enabled() ) {
		return false;
	}

	if ( ! class_exists( 'Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\Fulfillment' ) ) {
		return false;
	}

	if ( ! class_exists( 'WC_Data_Store' ) ) {
		return false;
	}

	try {
		$store = \WC_Data_Store::load( 'order-fulfillment' );
	} catch ( \Throwable $e ) {
		return false;
	}

	return $store instanceof \Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore
		|| is_a( $store, '\\Automattic\\WooCommerce\\Admin\\Features\\Fulfillments\\DataStore\\FulfillmentsDataStore' );
}

/**
 * Acquire a short-lived, retry-safe per-shipment projection lock.
 *
 * Namespaced `veeqo:shipment:{shipment_id}` — a Veeqo shipment ID is
 * globally unique, so the lock does not need to be scoped by order.
 *
 * @param int $shipment_id Confirmed Veeqo shipment ID.
 * @return string|null Ownership token on success, null if already held.
 */
function dtb_veeqo_fulfillment_lock_acquire( int $shipment_id ): ?string {
	$transient_key = 'dtb_veeqo_fp_lock_' . md5( 'veeqo:shipment:' . $shipment_id );

	if ( false !== get_transient( $transient_key ) ) {
		return null;
	}

	$token = wp_generate_password( 20, false );
	set_transient( $transient_key, $token, 60 );

	// Guard against a race between the existence check and the write.
	if ( get_transient( $transient_key ) !== $token ) {
		return null;
	}

	return $token;
}

/**
 * Release a projection lock only if the caller still owns it.
 *
 * @param int    $shipment_id Confirmed Veeqo shipment ID.
 * @param string $token       Ownership token returned by the matching acquire call.
 * @return void
 */
function dtb_veeqo_fulfillment_lock_release( int $shipment_id, string $token ): void {
	$transient_key = 'dtb_veeqo_fp_lock_' . md5( 'veeqo:shipment:' . $shipment_id );

	if ( get_transient( $transient_key ) === $token ) {
		delete_transient( $transient_key );
	}
}

/**
 * Build a canonical fingerprint over only customer-visible fulfillment
 * state. Poll/sync timestamps and any other non-customer-visible metadata
 * are deliberately excluded so a poll that re-observes identical Veeqo
 * state always yields the same fingerprint (=> no_change, no duplicate
 * save, no duplicate notification).
 *
 * @param array<string,mixed> $facts {
 *     @type int    $shipment_id       Confirmed Veeqo shipment ID.
 *     @type string $status            Fulfillment status ('fulfilled').
 *     @type array  $items             [[item_id:int, qty:int|float], ...], pre-sorted by caller.
 *     @type string $carrier           Shipment provider slug.
 *     @type string $tracking_number   Tracking number.
 *     @type string $tracking_url      Tracking URL.
 *     @type string $source_revision   Optional Veeqo-supplied revision marker.
 * }
 * @return string
 */
function dtb_veeqo_fulfillment_fingerprint( array $facts ): string {
	$canonical = [
		'shipment_id'     => (int) ( $facts['shipment_id'] ?? 0 ),
		'status'          => (string) ( $facts['status'] ?? '' ),
		'items'           => $facts['items'] ?? [],
		'carrier'         => (string) ( $facts['carrier'] ?? '' ),
		'tracking_number' => (string) ( $facts['tracking_number'] ?? '' ),
		'tracking_url'    => (string) ( $facts['tracking_url'] ?? '' ),
		'source_revision' => (string) ( $facts['source_revision'] ?? '' ),
	];

	return hash( 'sha256', (string) wp_json_encode( $canonical ) );
}

/**
 * Map Veeqo-reported line data onto WooCommerce order items, validating
 * (never clamping) shipped quantity against ordered-minus-refunded quantity.
 *
 * @param WC_Order          $order            Order.
 * @param array<int,array>  $veeqo_line_items Veeqo-reported [{sku, qty}, ...], scoped to one allocation.
 * @return array<int,array{item_id:int,qty:int}>|null Null on any quantity conflict.
 */
function dtb_veeqo_fulfillment_map_items( \WC_Order $order, array $veeqo_line_items ): ?array {
	if ( empty( $veeqo_line_items ) ) {
		return null;
	}

	$mapped = [];

	foreach ( $order->get_items() as $order_item ) {
		$sku = $order_item->get_product() instanceof \WC_Product ? $order_item->get_product()->get_sku() : '';

		foreach ( $veeqo_line_items as $line ) {
			$line_sku = sanitize_text_field( (string) ( $line['sku'] ?? '' ) );
			if ( '' === $line_sku || $line_sku !== $sku ) {
				continue;
			}

			$requested_qty = (int) ( $line['qty'] ?? 0 );
			$available_qty = (int) $order_item->get_quantity() - (int) $order->get_qty_refunded_for_item( $order_item->get_id() );

			if ( $requested_qty <= 0 ) {
				continue;
			}

			if ( $requested_qty > $available_qty ) {
				// Quantity conflict: reject the whole observation rather
				// than silently clamp. Malformed upstream data must stay visible.
				return null;
			}

			$mapped[] = [
				'item_id' => $order_item->get_id(),
				'qty'     => $requested_qty,
			];
		}
	}

	if ( empty( $mapped ) ) {
		return null;
	}

	usort( $mapped, static fn( array $a, array $b ): int => $a['item_id'] <=> $b['item_id'] );

	return $mapped;
}

/**
 * Find an existing, non-deleted native Fulfillment record for this order
 * whose private identity meta matches the confirmed Veeqo shipment ID.
 *
 * @param \WC_Order $order       Order.
 * @param int       $shipment_id Confirmed Veeqo shipment ID.
 * @return \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment|null
 */
function dtb_veeqo_fulfillment_find_existing( \WC_Order $order, int $shipment_id ) {
	try {
		$store        = \WC_Data_Store::load( 'order-fulfillment' );
		$fulfillments = $store->read_fulfillments( \WC_Order::class, (string) $order->get_id() );
	} catch ( \Throwable $e ) {
		return null;
	}

	foreach ( $fulfillments as $fulfillment ) {
		if ( absint( $fulfillment->get_meta( DTB_VEEQO_FULFILLMENT_IDENTITY_META, true ) ) === $shipment_id ) {
			return $fulfillment;
		}
	}

	return null;
}

/**
 * Confirm no *other* WooCommerce order's native Fulfillment record already
 * claims this Veeqo shipment ID (never silently reassign a shipment across
 * orders — a shipment ID must never be reused across a different
 * WooCommerce or Veeqo order).
 *
 * @param int $shipment_id     Confirmed Veeqo shipment ID.
 * @param int $expected_order  WooCommerce order ID this shipment is being projected for.
 * @return bool True if a conflicting record exists.
 */
function dtb_veeqo_fulfillment_identity_conflicts( int $shipment_id, int $expected_order ): bool {
	global $wpdb;

	$fulfillment_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT m.fulfillment_id FROM {$wpdb->prefix}wc_order_fulfillment_meta m WHERE m.meta_key = %s AND m.meta_value = %s LIMIT 1",
			DTB_VEEQO_FULFILLMENT_IDENTITY_META,
			wp_json_encode( (string) $shipment_id )
		)
	);

	if ( ! $fulfillment_id ) {
		return false;
	}

	$owning_entity_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT entity_id FROM {$wpdb->prefix}wc_order_fulfillments WHERE fulfillment_id = %d AND entity_type = %s AND date_deleted IS NULL",
			(int) $fulfillment_id,
			\WC_Order::class
		)
	);

	return null !== $owning_entity_id && (int) $owning_entity_id !== $expected_order;
}

/**
 * Project one normalized, valid shipment observation into a native
 * WooCommerce Fulfillment record and, only after a fully verified commit,
 * fire the matching native customer-notification action.
 *
 * @param \WC_Order            $order            Order.
 * @param array<string,mixed>  $observation      One entry from dtb_veeqo_normalize_shipment_observations() with status 'valid'.
 * @param int                  $veeqo_order_id   Correlated Veeqo order ID.
 * @param string               $customer_note    Optional merchant note for an update notification.
 * @return array{result:string,fulfillment_id:int|null,shipment_id:int|null}
 */
function dtb_veeqo_project_shipment_observation( \WC_Order $order, array $observation, int $veeqo_order_id, string $customer_note = '' ): array {
	$order_id    = (int) $order->get_id();
	$shipment_id = (int) ( $observation['shipment_id'] ?? 0 );

	$outcome = static function ( string $result, ?int $fulfillment_id = null ) use ( $order_id, $shipment_id ): array {
		$payload = [
			'result'         => $result,
			'fulfillment_id' => $fulfillment_id,
			'shipment_id'    => $shipment_id ?: null,
		];

		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event(
				$order_id,
				str_starts_with( $result, 'rejected_' ) || 'failed_native_persistence' === $result
					? 'integration.veeqo.fulfillment_projection_rejected'
					: ( 'deferred_incomplete_source' === $result ? 'integration.veeqo.fulfillment_projection_deferred' : 'integration.veeqo.fulfillment_projected' ),
				[
					'source'     => 'veeqo_fulfillment_projector',
					'actor_type' => 'veeqo',
					'visibility' => 'operator',
					'payload'    => $payload,
				]
			);
		}

		if ( function_exists( 'dtb_veeqo_log' ) ) {
			dtb_veeqo_log(
				in_array( $result, [ 'created', 'updated', 'no_change' ], true ) ? 'info' : 'warn',
				'fulfillment_projection_' . $result,
				sprintf( 'Veeqo fulfillment projection for order %d, shipment %s: %s', $order_id, $shipment_id ?: 'n/a', $result ),
				$payload
			);
		}

		return $payload;
	};

	if ( 'valid' !== ( $observation['status'] ?? '' ) || $shipment_id <= 0 ) {
		return $outcome( 'deferred_incomplete_source' );
	}

	if ( ! dtb_veeqo_fulfillment_capability_ready() ) {
		return $outcome( 'deferred_incomplete_source' );
	}

	$token = dtb_veeqo_fulfillment_lock_acquire( $shipment_id );
	if ( null === $token ) {
		return $outcome( 'rejected_locked' );
	}

	try {
		if ( dtb_veeqo_fulfillment_identity_conflicts( $shipment_id, $order_id ) ) {
			return $outcome( 'rejected_identity_conflict' );
		}

		$mapped_items = dtb_veeqo_fulfillment_map_items( $order, (array) ( $observation['line_items'] ?? [] ) );
		if ( null === $mapped_items ) {
			return $outcome( 'rejected_quantity_conflict' );
		}

		$carrier         = sanitize_text_field( (string) ( $observation['carrier'] ?? '' ) );
		$tracking_number = sanitize_text_field( (string) ( $observation['tracking_number'] ?? '' ) );
		$tracking_url    = esc_url_raw( (string) ( $observation['tracking_url'] ?? '' ) );

		if ( '' === $tracking_url && '' !== $tracking_number ) {
			$parsed = apply_filters( 'woocommerce_fulfillment_parse_tracking_number', $tracking_number, '', $order->get_shipping_country() ?: $order->get_billing_country() );
			if ( is_array( $parsed ) ) {
				$tracking_url = esc_url_raw( (string) ( $parsed['tracking_url'] ?? '' ) );
				if ( '' === $carrier ) {
					$carrier = sanitize_text_field( (string) ( $parsed['shipping_provider'] ?? '' ) );
				}
			}
		}

		$fingerprint = dtb_veeqo_fulfillment_fingerprint(
			[
				'shipment_id'     => $shipment_id,
				'status'          => 'fulfilled',
				'items'           => $mapped_items,
				'carrier'         => $carrier,
				'tracking_number' => $tracking_number,
				'tracking_url'    => $tracking_url,
				'source_revision' => (string) ( $observation['source_revision'] ?? '' ),
			]
		);

		$existing    = dtb_veeqo_fulfillment_find_existing( $order, $shipment_id );
		$is_update   = null !== $existing;
		$fulfillment = $existing;

		if ( $is_update ) {
			if ( $fulfillment->is_locked() ) {
				return $outcome( 'rejected_locked', $fulfillment->get_id() );
			}

			$existing_fingerprint = (string) $fulfillment->get_meta( DTB_VEEQO_FULFILLMENT_FINGERPRINT_META, true );
			if ( $existing_fingerprint === $fingerprint ) {
				return $outcome( 'no_change', $fulfillment->get_id() );
			}
		} else {
			$fulfillment = new \Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment();
			$fulfillment->set_entity_type( \WC_Order::class );
			$fulfillment->set_entity_id( (string) $order_id );
		}

		// Build and save. Veeqo only ever reports shipments that already
		// happened — never created as an unfulfilled draft.
		$fulfillment->set_status( 'fulfilled' );
		$fulfillment->set_items( $mapped_items );
		if ( '' !== $tracking_number ) {
			$fulfillment->set_tracking_number( $tracking_number );
		}
		if ( '' !== $carrier ) {
			$fulfillment->set_shipment_provider( $carrier );
		}
		if ( '' !== $tracking_url ) {
			$fulfillment->set_tracking_url( $tracking_url );
		}
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_SOURCE_META, 'veeqo' );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_ORDER_META, $veeqo_order_id );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_ALLOCATION_META, (int) ( $observation['allocation_id'] ?? 0 ) );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_IDENTITY_META, $shipment_id );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_REVISION_META, (string) ( $observation['source_revision'] ?? '' ) );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_FINGERPRINT_META, $fingerprint );
		$fulfillment->update_meta_data( DTB_VEEQO_FULFILLMENT_PROJECTED_AT_META, current_time( 'mysql', true ) );

		try {
			$fulfillment->save();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'dtb_veeqo_log' ) ) {
				dtb_veeqo_log( 'error', 'fulfillment_projection_save_failed', $e->getMessage(), [ 'order_id' => $order_id, 'shipment_id' => $shipment_id ] );
			}
			return $outcome( 'failed_native_persistence' );
		}

		// Ownership transfers only after all four conditions hold.
		// (1) save() succeeded (above). (2) verify the committed record.
		if ( $fulfillment->get_id() <= 0 || ! $fulfillment->get_is_fulfilled() ) {
			return $outcome( 'failed_native_persistence', $fulfillment->get_id() ?: null );
		}

		// (3) invoke the correct native notification action.
		try {
			if ( $is_update ) {
				do_action( 'woocommerce_fulfillment_updated_notification', $order_id, $fulfillment, $order, $customer_note );
			} else {
				do_action( 'woocommerce_fulfillment_created_notification', $order_id, $fulfillment, $order );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'dtb_veeqo_log' ) ) {
				dtb_veeqo_log( 'error', 'fulfillment_projection_notify_failed', $e->getMessage(), [ 'order_id' => $order_id, 'fulfillment_id' => $fulfillment->get_id() ] );
			}
			// Record already exists but ownership (4) is intentionally not
			// recorded — the fingerprint meta already written above is
			// re-evaluated on the next attempt, and the legacy path remains
			// eligible until a later run completes all four conditions.
			return $outcome( 'failed_native_persistence', $fulfillment->get_id() );
		}

		// (4) ownership is the fingerprint meta already persisted with the
		// record above — idempotent by construction: a repeat commit of the
		// same fingerprint short-circuits to no_change.
		return $outcome( $is_update ? 'updated' : 'created', $fulfillment->get_id() );
	} finally {
		dtb_veeqo_fulfillment_lock_release( $shipment_id, $token );
	}
}

/**
 * Project every shipment found in a Veeqo order payload independently.
 * Each allocation/shipment becomes its own native Fulfillment record and
 * its own typed outcome — a payload with two shipments (a partial shipment
 * followed later by the remainder) results in two independent projections,
 * never one conflated result.
 *
 * @param \WC_Order            $order                Order.
 * @param array<string,mixed>  $veeqo_order_payload  Decoded Veeqo order payload.
 * @param int                  $veeqo_order_id       Correlated Veeqo order ID.
 * @param string               $customer_note        Optional merchant note for update notifications.
 * @return array{results:array<int,array>,native_notified:bool}
 */
function dtb_veeqo_project_order_shipments( \WC_Order $order, array $veeqo_order_payload, int $veeqo_order_id, string $customer_note = '' ): array {
	$observations = dtb_veeqo_normalize_shipment_observations( $veeqo_order_payload, $veeqo_order_id );

	$results         = [];
	$native_notified = false;

	foreach ( $observations as $observation ) {
		if ( 'valid' === ( $observation['status'] ?? '' ) ) {
			$result = dtb_veeqo_project_shipment_observation( $order, $observation, $veeqo_order_id, $customer_note );
		} else {
			$result = [
				'result'         => $observation['status'],
				'fulfillment_id' => null,
				'shipment_id'    => $observation['shipment_id'] ?? null,
			];

			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event(
					(int) $order->get_id(),
					str_starts_with( (string) $observation['status'], 'rejected_' )
						? 'integration.veeqo.fulfillment_projection_rejected'
						: 'integration.veeqo.fulfillment_projection_deferred',
					[
						'source'     => 'veeqo_fulfillment_projector',
						'actor_type' => 'veeqo',
						'visibility' => 'operator',
						'payload'    => $result + [ 'reason' => $observation['reason'] ?? null ],
					]
				);
			}
		}

		$results[] = $result;

		if ( in_array( $result['result'] ?? '', [ 'created', 'updated', 'no_change' ], true ) ) {
			$native_notified = true;
		}
	}

	return [
		'results'         => $results,
		'native_notified' => $native_notified,
	];
}

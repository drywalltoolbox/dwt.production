<?php
/**
 * Admin — RepairIntegrationPanel: integration status helpers for the meta box.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;


/**
 * Read a single value from the integration state JSON meta.
 *
 * @param int    $repair_id
 * @param string $integration  'woocommerce' | 'veeqo' | 'quickbooks' | 'rewards'
 * @param string $key          Key within the integration slice.
 * @return mixed
 */
function dtb_repair_admin_get_integration_state_value( int $repair_id, string $integration, string $key ): mixed {
	$raw     = (string) get_post_meta( $repair_id, '_repair_integration_state', true );
	$decoded = ( '' !== $raw ) ? json_decode( $raw, true ) : [];
	return $decoded[ $integration ][ $key ] ?? null;
}

/**
 * Render a small integration status badge — uses .dtb-int-pill pill classes.
 *
 * @param mixed $state  State string from the integration projection.
 * @return string  HTML badge.
 */
function dtb_repair_admin_integration_badge( mixed $state ): string {
	$state = (string) $state;

	if ( '' === $state || 'not_configured' === $state || 'not_eligible' === $state ) {
		return '<span class="dtb-int-pill dtb-int-not_configured">—</span>';
	}

	if ( in_array( $state, [ 'synced', 'issued', 'ok' ], true ) ) {
		return '<span class="dtb-int-pill dtb-int-synced">✓ ' . esc_html( $state ) . '</span>';
	}

	if ( 'pending' === $state || str_starts_with( $state, 'stub' ) ) {
		return '<span class="dtb-int-pill dtb-int-pending">' . esc_html( $state ) . '</span>';
	}

	if ( in_array( $state, [ 'error', 'failed' ], true ) ) {
		return '<span class="dtb-int-pill dtb-int-error">✗ Error</span>';
	}

	return '<span class="dtb-int-pill dtb-int-pending">' . esc_html( $state ) . '</span>';
}

<?php
/**
 * Domain — TicketType: ticket type registry and helper functions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return all valid ticket type slugs mapped to their display labels.
 *
 * @return array<string,string>
 */
function dtb_support_all_types(): array {
	return [
		'contact'  => __( 'Contact Us',       'drywall-toolbox' ),
		'support'  => __( 'Technical Support', 'drywall-toolbox' ),
		'billing'  => __( 'Billing & Orders',  'drywall-toolbox' ),
		'returns'  => __( 'Returns & Refunds', 'drywall-toolbox' ),
		'feedback' => __( 'Feedback',          'drywall-toolbox' ),
		'general'  => __( 'General Inquiry',   'drywall-toolbox' ),
	];
}

/**
 * Return the display label for a ticket type slug.
 *
 * @param string $type
 * @return string
 */
function dtb_support_type_label( string $type ): string {
	$all = dtb_support_all_types();
	return $all[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
}

/**
 * Return whether a given type slug is valid.
 *
 * @param string $type
 * @return bool
 */
function dtb_support_is_valid_type( string $type ): bool {
	return array_key_exists( $type, dtb_support_all_types() );
}

/**
 * Return the default ticket type.
 *
 * @return string
 */
function dtb_support_default_type(): string {
	return (string) apply_filters( 'dtb_support_default_type', 'contact' );
}

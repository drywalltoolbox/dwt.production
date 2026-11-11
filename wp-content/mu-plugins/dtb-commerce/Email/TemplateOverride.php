<?php
/**
 * Deterministic WooCommerce classic HTML email template routing.
 *
 * Replaces the previous woocommerce_email_header/footer output-buffer wrap
 * (removed) with an allowlisted `woocommerce_locate_template` resolver, the
 * mechanism WooCommerce itself expects theme/child overrides to use. Every
 * mapped template lives under Email/templates/emails/ and preserves the
 * runtime extension points and semantic order of the traced upstream
 * WooCommerce template (see the architecture document).
 *
 * Only the classic HTML template set is touched. `emails/plain/*` (plain
 * text), block-email, and POS templates are never in this map and therefore
 * always keep resolving to WooCommerce core, untouched.
 *
 * @package DrywalltoolboxCommerce
 */

namespace DTB\Commerce\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowlisted template overrides: WooCommerce relative template_name =>
 * [ 'file' => path under this directory's templates/, 'source_version' =>
 * the upstream WooCommerce template @version this override was traced
 * against, for upgrade-diff traceability ].
 *
 * @return array<string,array{file:string,source_version:string}>
 */
function dtb_commerce_wc_email_template_map(): array {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = [
		// Shared partials.
		'emails/email-header.php'             => [ 'file' => 'email-header.php', 'source_version' => '10.7.0' ],
		'emails/email-footer.php'              => [ 'file' => 'email-footer.php', 'source_version' => '10.4.0' ],
		'emails/email-styles.php'              => [ 'file' => 'email-styles.php', 'source_version' => '10.8.0' ],
		'emails/email-order-details.php'       => [ 'file' => 'email-order-details.php', 'source_version' => '10.8.0' ],
		'emails/email-order-items.php'         => [ 'file' => 'email-order-items.php', 'source_version' => '10.8.0' ],
		'emails/email-addresses.php'           => [ 'file' => 'email-addresses.php', 'source_version' => '10.6.0' ],
		'emails/email-customer-details.php'    => [ 'file' => 'email-customer-details.php', 'source_version' => '9.7.0' ],
		'emails/email-fulfillment-details.php' => [ 'file' => 'email-fulfillment-details.php', 'source_version' => '10.7.0' ],
		'emails/email-fulfillment-items.php'   => [ 'file' => 'email-fulfillment-items.php', 'source_version' => '10.8.0' ],

		// Admin (operator) emails.
		'emails/admin-new-order.php'       => [ 'file' => 'admin-new-order.php', 'source_version' => '10.4.0' ],
		'emails/admin-cancelled-order.php' => [ 'file' => 'admin-cancelled-order.php', 'source_version' => '9.8.0' ],
		'emails/admin-failed-order.php'    => [ 'file' => 'admin-failed-order.php', 'source_version' => '10.4.0' ],

		// Customer order-lifecycle emails.
		'emails/customer-processing-order.php' => [ 'file' => 'customer-processing-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-completed-order.php'  => [ 'file' => 'customer-completed-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-on-hold-order.php'    => [ 'file' => 'customer-on-hold-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-cancelled-order.php'  => [ 'file' => 'customer-cancelled-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-failed-order.php'     => [ 'file' => 'customer-failed-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-refunded-order.php'   => [ 'file' => 'customer-refunded-order.php', 'source_version' => '10.4.0' ],
		'emails/customer-invoice.php'          => [ 'file' => 'customer-invoice.php', 'source_version' => '10.4.0' ],
		'emails/customer-note.php'             => [ 'file' => 'customer-note.php', 'source_version' => '10.4.0' ],
		'emails/customer-new-account.php'      => [ 'file' => 'customer-new-account.php', 'source_version' => '10.9.0' ],
		'emails/customer-reset-password.php'   => [ 'file' => 'customer-reset-password.php', 'source_version' => '10.9.0' ],

		// Native WooCommerce Fulfillments customer emails.
		'emails/customer-fulfillment-created.php' => [ 'file' => 'customer-fulfillment-created.php', 'source_version' => '10.4.0' ],
		'emails/customer-fulfillment-updated.php' => [ 'file' => 'customer-fulfillment-updated.php', 'source_version' => '10.8.0' ],
	];

	return $map;
}

/**
 * Resolve a DTB template override for an allowlisted classic HTML email
 * template, only when WooCommerce would otherwise fall back to its own
 * bundled default (no theme override already resolved it).
 *
 * @param string $template      Template path WooCommerce already located.
 * @param string $template_name Relative template name being located.
 * @param string $template_path Template sub-path passed to wc_locate_template().
 * @return string
 */
function dtb_commerce_wc_locate_email_template( string $template, string $template_name, string $template_path ): string {
	if ( str_starts_with( $template_name, 'emails/plain/' ) ) {
		return $template;
	}

	$map = dtb_commerce_wc_email_template_map();
	if ( ! isset( $map[ $template_name ] ) ) {
		return $template;
	}

	// Back off if a theme has already provided its own override.
	$theme_paths = array_filter( [ trailingslashit( get_stylesheet_directory() ), trailingslashit( get_template_directory() ) ] );
	foreach ( $theme_paths as $theme_path ) {
		if ( '' !== $template && str_starts_with( wp_normalize_path( $template ), wp_normalize_path( $theme_path ) ) ) {
			return $template;
		}
	}

	$override = __DIR__ . '/templates/emails/' . $map[ $template_name ]['file'];

	return is_readable( $override ) ? $override : $template;
}
add_filter( 'woocommerce_locate_template', 'DTB\\Commerce\\Email\\dtb_commerce_wc_locate_email_template', 100, 3 );

/**
 * Explicitly keep the native customer_fulfillment_deleted email disabled.
 *
 * No DTB business rule currently originates a customer-visible fulfillment
 * deletion (Veeqo never reports deletions, and an operator deleting a
 * fulfillment record in wp-admin is an exceptional/corrective action, not a
 * customer-facing event). This is an intentional gate, not an oversight —
 * see docs/operations/woocommerce-html-email-architecture.md.
 *
 * @param bool $enabled Whether the email is enabled.
 * @return bool
 */
function dtb_commerce_disable_fulfillment_deleted_email( bool $enabled ): bool {
	return false;
}
add_filter( 'woocommerce_email_enabled_customer_fulfillment_deleted', 'DTB\\Commerce\\Email\\dtb_commerce_disable_fulfillment_deleted_email', 10, 1 );

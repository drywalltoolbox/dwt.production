<?php
/**
 * Marketplace Admin — MarketplaceSettingsPage
 *
 * Admin-only channel settings: credentials, OAuth, compliance.
 * Page slug: dtb-marketplace-settings
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_settings_page(): void {
	if ( ! current_user_can( 'dtb_manage_marketplace_settings' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Marketplace Settings', 'drywall-toolbox' ),
		'subtitle' => __( 'Channel credentials, OAuth, compliance endpoints. Secrets are never displayed after save.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-settings',
		'icon'     => 'dashicons-admin-settings',
		'tabs'     => dtb_marketplace_admin_tabs( 'settings' ),
	] );

	echo '<div class="dtb-notice dtb-notice--warning">';
	echo '<p><strong>' . esc_html__( 'Security:', 'drywall-toolbox' ) . '</strong> ' .
		esc_html__( 'Credentials are encrypted at rest. Never displayed after save. Enter a value only when updating it; leave blank to keep the existing value.', 'drywall-toolbox' ) . '</p>';
	echo '</div>';

	$nonce = wp_create_nonce( 'wp_rest' );

	// ---- Amazon Settings ----.
	echo '<h2 class="dtb-section-heading">' . esc_html__( 'Amazon SP-API', 'drywall-toolbox' ) . '</h2>';

	$amazon_cfg   = DTB_AmazonConfig::get();
	$amazon_cred  = DTB_MarketplaceCredentialFacade::status( DTB_CHANNEL_AMAZON, [ 'client_id', 'client_secret', 'refresh_token', 'seller_id', 'notification_endpoint' ] );

	echo '<form class="dtb-settings-form" id="dtb-amazon-settings-form" data-channel="amazon" data-nonce="' . esc_attr( $nonce ) . '">';

	echo '<table class="form-table"><tbody>';

	// Sandbox toggle.
	$sandbox_checked = $amazon_cfg['sandbox'] ? 'checked' : '';
	echo '<tr><th scope="row">' . esc_html__( 'Sandbox Mode', 'drywall-toolbox' ) . '</th>';
	echo '<td><label><input type="checkbox" name="is_sandbox" value="1" ' . esc_attr( $sandbox_checked ) . '> ' . esc_html__( 'Enable sandbox', 'drywall-toolbox' ) . '</label></td></tr>';

	// Marketplace ID (non-secret).
	echo '<tr><th scope="row">' . esc_html__( 'Marketplace ID', 'drywall-toolbox' ) . '</th>';
	echo '<td><input type="text" name="marketplace_id" class="regular-text" value="' . esc_attr( $amazon_cfg['marketplace_id'] ?? '' ) . '"></td></tr>';

	// Notification endpoint (non-secret).
	echo '<tr><th scope="row">' . esc_html__( 'Notification Endpoint', 'drywall-toolbox' ) . '</th>';
	echo '<td><input type="text" name="notification_endpoint" class="regular-text" value="' . esc_attr( $amazon_cfg['notification_endpoint'] ?? '' ) . '"><p class="description">' . esc_html__( 'Amazon SNS endpoint URL for order/notification webhooks.', 'drywall-toolbox' ) . '</p></td></tr>';

	// Credential fields — show status badges, not values.
	foreach ( [ 'client_id', 'client_secret', 'refresh_token', 'seller_id' ] as $f ) {
		$has   = $amazon_cred[ $f ] ?? false;
		$badge = $has
			? '<span class="dtb-badge dtb-badge--success">' . esc_html__( 'Set', 'drywall-toolbox' ) . '</span>'
			: '<span class="dtb-badge dtb-badge--warning">' . esc_html__( 'Not set', 'drywall-toolbox' ) . '</span>';

		$label = ucwords( str_replace( '_', ' ', $f ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . ' ' . $badge . '</th>'; // phpcs:ignore
		echo '<td><input type="password" name="' . esc_attr( $f ) . '" class="regular-text" placeholder="' . esc_attr__( 'Leave blank to keep existing', 'drywall-toolbox' ) . '" autocomplete="new-password"></td></tr>';
	}

	echo '</tbody></table>';
	echo '<p class="dtb-settings-form__actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save Amazon Settings', 'drywall-toolbox' ) . '</button> ';
	echo '<button type="button" class="button dtb-test-connection" data-channel="amazon">' . esc_html__( 'Test Connection', 'drywall-toolbox' ) . '</button>';
	echo '<span class="dtb-settings-result" aria-live="polite"></span>';
	echo '</p>';
	echo '</form>';

	echo '<hr class="dtb-divider">';

	// ---- eBay Settings ----.
	echo '<h2 class="dtb-section-heading">' . esc_html__( 'eBay APIs', 'drywall-toolbox' ) . '</h2>';

	$ebay_cfg  = DTB_EbayConfig::get();
	$ebay_cred = DTB_MarketplaceCredentialFacade::status( DTB_CHANNEL_EBAY, [ 'client_id', 'client_secret', 'redirect_uri', 'deletion_verify_token' ] );

	echo '<form class="dtb-settings-form" id="dtb-ebay-settings-form" data-channel="ebay" data-nonce="' . esc_attr( $nonce ) . '">';
	echo '<table class="form-table"><tbody>';

	$ebay_sandbox_checked = $ebay_cfg['sandbox'] ? 'checked' : '';
	echo '<tr><th scope="row">' . esc_html__( 'Sandbox Mode', 'drywall-toolbox' ) . '</th>';
	echo '<td><label><input type="checkbox" name="is_sandbox" value="1" ' . esc_attr( $ebay_sandbox_checked ) . '> ' . esc_html__( 'Enable sandbox', 'drywall-toolbox' ) . '</label></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Marketplace ID', 'drywall-toolbox' ) . '</th>';
	echo '<td><input type="text" name="marketplace_id" class="regular-text" value="' . esc_attr( $ebay_cfg['marketplace_id'] ?? '' ) . '"></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Redirect URI', 'drywall-toolbox' ) . '</th>';
	echo '<td><input type="text" name="redirect_uri" class="regular-text" value="' . esc_attr( $ebay_cfg['redirect_uri'] ?? '' ) . '"></td></tr>';

	foreach ( [ 'client_id', 'client_secret', 'deletion_verify_token' ] as $f ) {
		$has   = $ebay_cred[ $f ] ?? false;
		$badge = $has
			? '<span class="dtb-badge dtb-badge--success">' . esc_html__( 'Set', 'drywall-toolbox' ) . '</span>'
			: '<span class="dtb-badge dtb-badge--warning">' . esc_html__( 'Not set', 'drywall-toolbox' ) . '</span>';

		$label = ucwords( str_replace( '_', ' ', $f ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . ' ' . $badge . '</th>'; // phpcs:ignore
		echo '<td><input type="password" name="' . esc_attr( $f ) . '" class="regular-text" placeholder="' . esc_attr__( 'Leave blank to keep existing', 'drywall-toolbox' ) . '" autocomplete="new-password"></td></tr>';
	}

	echo '</tbody></table>';

	// eBay OAuth connect button.
	echo '<p>';
	echo '<a href="#" class="button dtb-ebay-oauth-connect" id="dtb-ebay-oauth-btn">' . esc_html__( 'Connect eBay Account (OAuth)', 'drywall-toolbox' ) . '</a>';
	echo ' <span class="dtb-settings-result dtb-ebay-oauth-status" aria-live="polite">';
	if ( ! empty( $ebay_cfg['is_connected'] ) ) {
		echo '<span class="dtb-badge dtb-badge--success">' . esc_html__( 'Connected', 'drywall-toolbox' ) . '</span>';
	} else {
		echo '<span class="dtb-badge dtb-badge--warning">' . esc_html__( 'Not connected', 'drywall-toolbox' ) . '</span>';
	}
	echo '</span>';
	echo '</p>';

	// eBay deletion endpoint notice.
	$del_endpoint = rest_url( 'dtb/v1/marketplace/ebay/deletion' );
	echo '<div class="dtb-notice dtb-notice--info">';
	echo '<p><strong>' . esc_html__( 'eBay Account Deletion Endpoint:', 'drywall-toolbox' ) . '</strong><br>';
	echo '<code>' . esc_html( $del_endpoint ) . '</code>';
	echo '<br>' . esc_html__( 'Register this URL in eBay Developer Portal as your Marketplace Account Deletion / Closure notification endpoint.', 'drywall-toolbox' ) . '</p>';
	echo '</div>';

	echo '<p class="dtb-settings-form__actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save eBay Settings', 'drywall-toolbox' ) . '</button> ';
	echo '<button type="button" class="button dtb-test-connection" data-channel="ebay">' . esc_html__( 'Test Connection', 'drywall-toolbox' ) . '</button>';
	echo '<span class="dtb-settings-result" aria-live="polite"></span>';
	echo '</p>';
	echo '</form>';

	dtb_admin_shell_close();
}

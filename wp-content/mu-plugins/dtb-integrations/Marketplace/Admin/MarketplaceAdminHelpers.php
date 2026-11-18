<?php
/**
 * Marketplace Admin — Shared UI helpers
 *
 * - dtb_marketplace_admin_tabs()
 * - dtb_marketplace_channel_badge()
 * - dtb_marketplace_relative_time()
 * - dtb_marketplace_enqueue_assets()
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the standard Marketplace tab array, with the active tab indicated.
 */
function dtb_marketplace_admin_tabs( string $active ): array {
	$can_settings = current_user_can( 'dtb_manage_marketplace_settings' );
	$tabs = [
		[
			'id'     => 'overview',
			'label'  => __( 'Overview', 'drywall-toolbox' ),
			'url'    => admin_url( 'admin.php?page=dtb-marketplace' ),
			'active' => 'overview' === $active,
		],
		[
			'id'     => 'orders',
			'label'  => __( 'Orders', 'drywall-toolbox' ),
			'url'    => admin_url( 'admin.php?page=dtb-marketplace-orders' ),
			'active' => 'orders' === $active,
		],
		[
			'id'     => 'messages',
			'label'  => __( 'Messages', 'drywall-toolbox' ),
			'url'    => admin_url( 'admin.php?page=dtb-marketplace-messages' ),
			'active' => 'messages' === $active,
		],
		[
			'id'     => 'exceptions',
			'label'  => __( 'Exceptions', 'drywall-toolbox' ),
			'url'    => admin_url( 'admin.php?page=dtb-marketplace-exceptions' ),
			'active' => 'exceptions' === $active,
		],
	];

	if ( $can_settings ) {
		$tabs[] = [
			'id'     => 'settings',
			'label'  => __( 'Settings', 'drywall-toolbox' ),
			'url'    => admin_url( 'admin.php?page=dtb-marketplace-settings' ),
			'active' => 'settings' === $active,
		];
	}

	return $tabs;
}

/**
 * Return a safe HTML channel badge string.
 */
function dtb_marketplace_channel_badge( string $channel ): string {
	$map = [
		DTB_CHANNEL_AMAZON => [ 'label' => 'Amazon', 'class' => 'dtb-badge--amazon' ],
		DTB_CHANNEL_EBAY   => [ 'label' => 'eBay',   'class' => 'dtb-badge--ebay' ],
	];
	$info = $map[ $channel ] ?? [ 'label' => esc_html( $channel ), 'class' => 'dtb-badge--muted' ];
	return '<span class="dtb-badge ' . esc_attr( $info['class'] ) . '">' . esc_html( $info['label'] ) . '</span>';
}

/**
 * Return a human-readable relative time string from a MySQL datetime string.
 */
function dtb_marketplace_relative_time( string $datetime ): string {
	if ( '' === $datetime ) {
		return '—';
	}
	$ts   = strtotime( $datetime );
	$diff = time() - $ts;
	if ( $diff < 0 ) {
		return __( 'In the future', 'drywall-toolbox' );
	}
	if ( $diff < 60 ) {
		return __( 'Just now', 'drywall-toolbox' );
	}
	if ( $diff < HOUR_IN_SECONDS ) {
		return sprintf( _n( '%d min ago', '%d mins ago', (int) floor( $diff / 60 ), 'drywall-toolbox' ), (int) floor( $diff / 60 ) );
	}
	if ( $diff < DAY_IN_SECONDS ) {
		return sprintf( _n( '%d hr ago', '%d hrs ago', (int) floor( $diff / HOUR_IN_SECONDS ), 'drywall-toolbox' ), (int) floor( $diff / HOUR_IN_SECONDS ) );
	}
	return sprintf( _n( '%d day ago', '%d days ago', (int) floor( $diff / DAY_IN_SECONDS ), 'drywall-toolbox' ), (int) floor( $diff / DAY_IN_SECONDS ) );
}

/**
 * Enqueue Marketplace admin assets on marketplace pages.
 */
function dtb_marketplace_enqueue_assets( string $hook ): void {
	$pages = [
		'toplevel_page_dtb-marketplace',
		'marketplace_page_dtb-marketplace-orders',
		'marketplace_page_dtb-marketplace-messages',
		'marketplace_page_dtb-marketplace-amazon-comms',
		'marketplace_page_dtb-marketplace-ebay-inbox',
		'marketplace_page_dtb-marketplace-exceptions',
		'marketplace_page_dtb-marketplace-settings',
		'admin_page_dtb-marketplace-orders',
		'admin_page_dtb-marketplace-messages',
		'admin_page_dtb-marketplace-amazon-comms',
		'admin_page_dtb-marketplace-ebay-inbox',
		'admin_page_dtb-marketplace-exceptions',
	];

	if ( ! in_array( $hook, $pages, true ) ) {
		return;
	}

	$base = trailingslashit( plugin_dir_url( __FILE__ ) ) . 'assets/';

	wp_enqueue_style(
		'dtb-marketplace',
		$base . 'dtb-marketplace.css',
		[],
		DTB_MARKETPLACE_DB_VERSION
	);

	wp_enqueue_script(
		'dtb-marketplace',
		$base . 'dtb-marketplace.js',
		[ 'jquery' ],
		DTB_MARKETPLACE_DB_VERSION,
		true
	);

	wp_localize_script( 'dtb-marketplace', 'DTB_MARKETPLACE', [
		'restBase'  => esc_url_raw( rest_url( 'dtb/v1/admin/marketplace/' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'channels'  => [
			'amazon' => DTB_CHANNEL_AMAZON,
			'ebay'   => DTB_CHANNEL_EBAY,
		],
		'i18n' => [
			'syncing'      => __( 'Syncing…', 'drywall-toolbox' ),
			'syncDone'     => __( 'Sync queued.', 'drywall-toolbox' ),
			'saving'       => __( 'Saving…', 'drywall-toolbox' ),
			'saved'        => __( 'Saved.', 'drywall-toolbox' ),
			'error'        => __( 'Error.', 'drywall-toolbox' ),
			'sending'      => __( 'Sending…', 'drywall-toolbox' ),
			'sent'         => __( 'Sent.', 'drywall-toolbox' ),
			'noActions'    => __( 'No supported message actions available for this order.', 'drywall-toolbox' ),
			'confirmRetry' => __( 'Retry this exception?', 'drywall-toolbox' ),
			'confirmResolve' => __( 'Mark exception as resolved?', 'drywall-toolbox' ),
		],
	] );
}
add_action( 'admin_enqueue_scripts', 'dtb_marketplace_enqueue_assets' );

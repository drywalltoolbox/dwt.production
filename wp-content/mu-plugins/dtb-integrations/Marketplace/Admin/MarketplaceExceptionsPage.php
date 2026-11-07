<?php
/**
 * Marketplace Admin — MarketplaceExceptionsPage
 *
 * Exception queue with resolve/retry actions.
 * Page slug: dtb-marketplace-exceptions
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_exceptions_page(): void {
	if ( ! current_user_can( 'dtb_manage_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$channel = sanitize_key( $_GET['channel'] ?? '' );

	dtb_admin_shell_open( [
		'title'    => __( 'Marketplace Exceptions', 'drywall-toolbox' ),
		'subtitle' => __( 'Auth, sync, import, messaging, and compliance failures.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-exceptions',
		'icon'     => 'dashicons-warning',
		'tabs'     => dtb_marketplace_admin_tabs( 'exceptions' ),
	] );

	echo '<form method="get" class="dtb-filter-row">';
	echo '<input type="hidden" name="page" value="dtb-marketplace-exceptions">';
	$ch_opts = [ '' => __( 'All channels', 'drywall-toolbox' ), DTB_CHANNEL_AMAZON => 'Amazon', DTB_CHANNEL_EBAY => 'eBay' ];
	echo '<select name="channel" class="dtb-filter-select">';
	foreach ( $ch_opts as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $channel, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';
	submit_button( __( 'Filter', 'drywall-toolbox' ), 'secondary small', 'submit', false );
	echo '</form>';

	$exceptions = DTB_MarketplaceExceptionService::get_open( $channel, 200 );

	if ( empty( $exceptions ) ) {
		echo '<p class="dtb-empty-state">' . esc_html__( 'No open exceptions. 🎉', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<table class="widefat striped dtb-table dtb-exceptions-table">';
		echo '<thead><tr>';
		foreach ( [
			__( 'ID', 'drywall-toolbox' ),
			__( 'Channel', 'drywall-toolbox' ),
			__( 'Category', 'drywall-toolbox' ),
			__( 'Severity', 'drywall-toolbox' ),
			__( 'Error', 'drywall-toolbox' ),
			__( 'Retryable', 'drywall-toolbox' ),
			__( 'Retries', 'drywall-toolbox' ),
			__( 'Created', 'drywall-toolbox' ),
			__( 'Actions', 'drywall-toolbox' ),
		] as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $exceptions as $e ) {
			$sev_class = match ( $e['severity'] ?? 'low' ) {
				'critical' => 'dtb-badge--danger',
				'high'     => 'dtb-badge--warning',
				default    => 'dtb-badge--muted',
			};

			echo '<tr data-exception-id="' . esc_attr( (string) $e['id'] ) . '">';
			echo '<td>' . esc_html( (string) $e['id'] ) . '</td>';
			echo '<td>' . dtb_marketplace_channel_badge( $e['channel_key'] ?? '' ) . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $e['category'] ?? '' ) . '</td>';
			echo '<td><span class="dtb-badge ' . esc_attr( $sev_class ) . '">' . esc_html( $e['severity'] ?? '' ) . '</span></td>';
			echo '<td class="dtb-exc-msg">' . esc_html( substr( $e['error_message'] ?? '', 0, 120 ) ) . '</td>';
			echo '<td>' . esc_html( $e['is_retryable'] ? __( 'Yes', 'drywall-toolbox' ) : __( 'No', 'drywall-toolbox' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $e['retry_count'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( $e['created_at'] ?? '' ) . '</td>';
			echo '<td>';
			echo '<button class="button button-small dtb-exc-action" data-action="resolve" data-id="' . esc_attr( (string) $e['id'] ) . '">' . esc_html__( 'Resolve', 'drywall-toolbox' ) . '</button>';
			if ( $e['is_retryable'] ) {
				echo ' <button class="button button-small dtb-exc-action" data-action="retry" data-id="' . esc_attr( (string) $e['id'] ) . '">' . esc_html__( 'Retry', 'drywall-toolbox' ) . '</button>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	dtb_admin_shell_close();
}

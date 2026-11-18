<?php
/**
 * DTB Platform — Cache Tools operator page.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_cache_tools_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_cache_tools' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$last_run = null;
	if ( isset( $_POST['dtb_cache_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['dtb_cache_nonce'] ) ), 'dtb_cache_tools' ) ) {
		$target   = sanitize_key( (string) ( $_POST['dtb_cache_action'] ?? '' ) );
		$last_run = DTB_CacheOperationsService::run( array_filter( [ $target ] ) );
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Cache Tools', 'drywall-toolbox' ),
		'subtitle' => __( 'Serialized, observable cache invalidation across DTB, WordPress, WooCommerce, PHP, SiteGround, and storefront refresh state.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-cache-tools',
		'template' => 'tool',
		'icon'     => 'dashicons-update',
	] );

	echo '<div id="dtb-cache-run-result">';
	if ( is_array( $last_run ) ) {
		echo dtb_cache_tools_render_results( $last_run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';

	$targets = DTB_CacheOperationsService::targets();
	$nonce   = wp_create_nonce( 'dtb_cache_tools' );
	$epc     = DTB_CacheOperationsService::page_cache_status();

	ob_start();
	?>
	<p><?php esc_html_e( 'Runs all supported layers in a deterministic sequence. The operation is locked to prevent overlapping destructive flushes. Unsupported host-owned layers are reported as skipped, never as false successes.', 'drywall-toolbox' ); ?></p>
	<p><strong><?php esc_html_e( 'Customer safety:', 'drywall-toolbox' ); ?></strong> <?php esc_html_e( 'The frontend refresh step does not delete carts, login state, checkout sessions, local storage, or customer data.', 'drywall-toolbox' ); ?></p>
	<button type="button" id="dtb-cache-run-all" class="button button-primary button-hero" data-dtb-cache-targets="all">
		<?php esc_html_e( 'Run Full System Clean & Refresh', 'drywall-toolbox' ); ?>
	</button>
	<span id="dtb-cache-run-all-status" class="dtb-cache-status" aria-live="polite"></span>
	<noscript>
		<form method="post" style="margin-top:12px;">
			<?php wp_nonce_field( 'dtb_cache_tools', 'dtb_cache_nonce' ); ?>
			<input type="hidden" name="dtb_cache_action" value="all">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run Full System Clean & Refresh', 'drywall-toolbox' ); ?></button>
		</form>
	</noscript>
	<?php
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => esc_html__( 'Full System Clean & Refresh', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	?>
	<table class="widefat striped">
		<tbody>
			<tr><td><strong><?php esc_html_e( 'SiteGround Dynamic Cache', 'drywall-toolbox' ); ?></strong></td><td><?php echo esc_html( $epc['level_label'] ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'SiteGround CDN', 'drywall-toolbox' ); ?></strong></td><td><?php esc_html_e( 'Host-owned unless a dedicated DTB provider adapter is registered.', 'drywall-toolbox' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'PHP OPcache', 'drywall-toolbox' ); ?></strong></td><td><?php esc_html_e( 'Reset is attempted only when PHP exposes opcache_reset() and host restrictions permit it.', 'drywall-toolbox' ); ?></td></tr>
		</tbody>
	</table>
	<?php
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => esc_html__( 'Runtime Capability Detection', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '<div class="dtb-grid dtb-grid--two">';
	foreach ( $targets as $key => $meta ) {
		ob_start();
		?>
		<p class="dtb-text-muted"><?php echo esc_html( $meta['description'] ); ?></p>
		<button type="button" class="button button-secondary dtb-cache-run-single" data-dtb-cache-targets="<?php echo esc_attr( $key ); ?>" data-dtb-cache-confirm="<?php echo esc_attr( sprintf( __( 'Flush %s?', 'drywall-toolbox' ), $meta['label'] ) ); ?>">
			<?php esc_html_e( 'Run', 'drywall-toolbox' ); ?>
		</button>
		<span class="dtb-cache-status" aria-live="polite"></span>
		<noscript>
			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'dtb_cache_tools', 'dtb_cache_nonce' ); ?>
				<input type="hidden" name="dtb_cache_action" value="<?php echo esc_attr( $key ); ?>">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Run', 'drywall-toolbox' ); ?></button>
			</form>
		</noscript>
		<?php
		echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => esc_html( $meta['label'] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';

	dtb_cache_tools_render_script( $nonce );
	dtb_admin_shell_close();
}

function dtb_cache_tools_render_results( array $run ): string {
	$summary = $run['summary'] ?? [ 'ok' => 0, 'skipped' => 0, 'failed' => 0 ];
	$type    = $summary['failed'] > 0 ? 'error' : ( $summary['skipped'] > 0 ? 'warning' : 'success' );
	$message = sprintf(
		__( 'Run %1$s: %2$d completed, %3$d skipped, %4$d failed.', 'drywall-toolbox' ),
		sanitize_text_field( (string) ( $run['run_id'] ?? 'unknown' ) ),
		(int) $summary['ok'],
		(int) $summary['skipped'],
		(int) $summary['failed']
	);
	ob_start();
	echo dtb_admin_ui_alert( $message, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<ol class="dtb-cache-run-detail">';
	foreach ( (array) ( $run['results'] ?? [] ) as $result ) {
		printf(
			'<li data-status="%1$s"><strong>%2$s</strong> — %3$s <code>%4$dms</code></li>',
			esc_attr( $result['status'] ),
			esc_html( $result['label'] ),
			esc_html( $result['message'] ),
			(int) $result['duration_ms']
		);
	}
	echo '</ol>';
	return (string) ob_get_clean();
}

function dtb_cache_tools_render_script( string $nonce ): void {
	?>
	<script>
	(function () {
		'use strict';
		const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		const nonce = <?php echo wp_json_encode( $nonce ); ?>;
		let requestInFlight = false;

		async function runTargets(targets, statusElement, button) {
			if (requestInFlight) return;
			requestInFlight = true;
			document.querySelectorAll('[data-dtb-cache-targets]').forEach((element) => { element.disabled = true; });
			if (statusElement) statusElement.textContent = 'Running…';
			const body = new URLSearchParams({ action: 'dtb_cache_tools_run', nonce, targets });
			try {
				const response = await fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Cache-Control': 'no-store' },
					body: body.toString(),
					cache: 'no-store'
				});
				const json = await response.json();
				const container = document.getElementById('dtb-cache-run-result');
				if (container && json && json.data && json.data.html) container.innerHTML = json.data.html;
				if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Cache purge failed.');
				if (statusElement) statusElement.textContent = 'Completed.';
			} catch (error) {
				if (statusElement) statusElement.textContent = error.message || 'Request failed.';
			} finally {
				requestInFlight = false;
				document.querySelectorAll('[data-dtb-cache-targets]').forEach((element) => { element.disabled = false; });
				if (button) button.focus();
			}
		}

		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('[data-dtb-cache-targets]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targets = button.getAttribute('data-dtb-cache-targets') || '';
					const confirmation = button.getAttribute('data-dtb-cache-confirm') || 'Run this cache operation?';
					if (targets === 'all' && !window.confirm('Run the full system clean and refresh?')) return;
					if (targets !== 'all' && !window.confirm(confirmation)) return;
					const statusElement = targets === 'all'
						? document.getElementById('dtb-cache-run-all-status')
						: button.parentElement?.querySelector('.dtb-cache-status');
					runTargets(targets, statusElement, button);
				});
			});
		});
	})();
	</script>
	<?php
}

add_action( 'wp_ajax_dtb_cache_tools_run', static function (): void {
	check_ajax_referer( 'dtb_cache_tools', 'nonce' );
	if ( ! current_user_can( 'dtb_manage_cache_tools' ) ) {
		wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'drywall-toolbox' ) ], 403 );
	}
	$raw     = sanitize_text_field( wp_unslash( (string) ( $_POST['targets'] ?? '' ) ) );
	$targets = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) ) );
	if ( empty( $targets ) ) {
		wp_send_json_error( [ 'message' => __( 'No cache target specified.', 'drywall-toolbox' ) ], 400 );
	}
	$run = DTB_CacheOperationsService::run( $targets );
	wp_send_json_success( [ 'html' => dtb_cache_tools_render_results( $run ), 'summary' => $run['summary'], 'run_id' => $run['run_id'] ] );
} );

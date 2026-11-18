<?php
/**
 * Catalog Health admin page.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'dtb_catalog_health_enqueue' );
add_action( 'rest_api_init', 'dtb_catalog_health_register_admin_route' );

/**
 * Localize nonce for legacy AJAX handlers that are still used by this page.
 */
function dtb_catalog_health_enqueue( string $hook ): void {
	if ( false === strpos( $hook, 'dtb-catalog-health' ) ) {
		return;
	}

	wp_localize_script(
		'dtb-admin',
		'dtbCH',
		[ 'nonce' => wp_create_nonce( 'dtb_catalog_health' ) ]
	);
}

/**
 * Register live-region endpoint for the Catalog Health page.
 */
function dtb_catalog_health_register_admin_route(): void {
	register_rest_route(
		'dtb/v1',
		'/admin/catalog-health',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_catalog_health_admin_queue_handler',
			'permission_callback' => static fn() => current_user_can( 'dtb_manage_catalog_health' ),
		]
	);
}

/**
 * REST handler for live-region partial updates.
 */
function dtb_catalog_health_admin_queue_handler( WP_REST_Request $request ): WP_REST_Response {
	$scan     = sanitize_key( (string) $request->get_param( 'tab' ) );
	$severity = sanitize_key( (string) $request->get_param( 'filter' ) );
	$search   = sanitize_text_field( (string) ( $request->get_param( 's' ) ?? $request->get_param( 'search' ) ?? '' ) );
	$paged    = max( 1, (int) $request->get_param( 'paged' ) );

	$scan     = in_array( $scan, [ 'variable', 'meta' ], true ) ? $scan : 'variable';
	$severity = in_array( $severity, [ 'all', 'error', 'warning', 'info' ], true ) ? $severity : 'all';

	$html = dtb_catalog_health_render_workspace( $scan, $severity, $search, $paged );

	return new WP_REST_Response(
		[
			'ok'   => true,
			'html' => $html,
		],
		200
	);
}

/**
 * Render callback registered via ToolLibraryMenu.
 */
function dtb_catalog_health_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_catalog_health' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$scan     = sanitize_key( $_GET['tab'] ?? 'variable' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$severity = sanitize_key( $_GET['filter'] ?? 'all' );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search   = sanitize_text_field( $_GET['s'] ?? '' );    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$live_search = sanitize_text_field( $_GET['search'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $search && '' !== $live_search ) {
		$search = $live_search;
	}
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );    // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$scan     = in_array( $scan, [ 'variable', 'meta' ], true ) ? $scan : 'variable';
	$severity = in_array( $severity, [ 'all', 'error', 'warning', 'info' ], true ) ? $severity : 'all';

	$base_url = admin_url( 'admin.php?page=dtb-catalog-health' );
	$tabs     = [
		[
			'id'     => 'variable',
			'label'  => __( 'Variable Products', 'drywall-toolbox' ),
			'active' => 'variable' === $scan,
			'url'    => add_query_arg( 'tab', 'variable', $base_url ),
		],
		[
			'id'     => 'meta',
			'label'  => __( 'DTB Meta', 'drywall-toolbox' ),
			'active' => 'meta' === $scan,
			'url'    => add_query_arg( 'tab', 'meta', $base_url ),
		],
	];

	dtb_admin_shell_open(
		[
			'title'       => __( 'Catalog Health', 'drywall-toolbox' ),
			'subtitle'    => __( 'Scan WooCommerce products for data quality issues.', 'drywall-toolbox' ),
			'section'     => 'tools',
			'page'        => 'dtb-catalog-health',
			'template'    => 'tool',
			'icon'        => 'dashicons-chart-bar',
			'tabs'        => $tabs,
			'live_target' => 'dtb-catalog-health-workspace',
		]
	);

	$export_url = admin_url(
		'admin-ajax.php?action=dtb_catalog_health_export_csv&nonce=' . wp_create_nonce( 'dtb_catalog_health' )
	);

	echo dtb_admin_ui_toolbar_open();
	echo dtb_admin_ui_search_input( __( 'Search issues…', 'drywall-toolbox' ), $search, true, 's' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_toolbar_spacer();
	echo dtb_admin_ui_button( __( 'Flush Cache', 'drywall-toolbox' ), [ 'type' => 'secondary', 'data' => [ 'dtb-ch-flush' => '1' ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Export CSV', 'drywall-toolbox' ), [ 'href' => $export_url, 'type' => 'ghost', 'icon' => 'dashicons-download', 'size' => 'sm' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_toolbar_close();

	dtb_admin_shell_live_region_open(
		[
			'id'       => 'dtb-catalog-health-workspace',
			'module'   => 'catalog-health',
			'endpoint' => rest_url( 'dtb/v1/admin/catalog-health' ),
			'interval' => 0,
		]
	);

	echo dtb_catalog_health_render_workspace( $scan, $severity, $search, $paged ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	dtb_admin_shell_live_region_close();
	?>
	<script>
	(function () {
		var workspaceId = 'dtb-catalog-health-workspace';
		document.addEventListener('click', function (event) {
			var btn = event.target.closest('[data-dtb-ch-flush]');
			if (!btn) return;
			event.preventDefault();

			if (!window.confirm('Flush catalog cache now?')) return;

			btn.disabled = true;
			var body = new URLSearchParams();
			body.set('action', 'dtb_catalog_health_flush');
			body.set('nonce', (window.dtbCH && window.dtbCH.nonce) ? window.dtbCH.nonce : '');

			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function () {
				if (window.DtbAdmin && typeof window.DtbAdmin.liveRefresh === 'function') {
					var region = document.querySelector('[data-dtb-live-region="' + workspaceId + '"]');
					if (region) window.DtbAdmin.liveRefresh(region);
				}
			}).finally(function () {
				btn.disabled = false;
			});
		});
	}());
	</script>
	<?php
	dtb_admin_shell_close();
}

/**
 * Build the live workspace fragment.
 */
function dtb_catalog_health_render_workspace(
	string $scan,
	string $severity,
	string $search,
	int $paged
): string {
	$per_page = 50;
	$issues   = 'meta' === $scan
		? dtb_catalog_health_run_dtb_meta_scan( $paged, $per_page )
		: dtb_catalog_health_run_scan( $paged, $per_page );

	if ( 'all' !== $severity ) {
		$issues = array_values(
			array_filter(
				$issues,
				static fn( array $issue ): bool => ( $issue['severity'] ?? '' ) === $severity
			)
		);
	}

	if ( '' !== $search ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
		$issues = array_values(
			array_filter(
				$issues,
				static function ( array $issue ) use ( $needle ): bool {
					$haystack = implode(
						' ',
						[
							(string) ( $issue['product_name'] ?? '' ),
							(string) ( $issue['sku'] ?? '' ),
							(string) ( $issue['code'] ?? '' ),
							(string) ( $issue['message'] ?? '' ),
						]
					);
					$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
					return str_contains( $haystack, $needle );
				}
			)
		);
	}

	ob_start();
	echo '<div class="dtb-filter-bar dtb-mb-16">';
	echo '<span class="dtb-filter-bar__label">' . esc_html__( 'Issue Type', 'drywall-toolbox' ) . '</span>';
	echo dtb_admin_ui_filter_chip( __( 'All', 'drywall-toolbox' ), 'all', 'all' === $severity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_filter_chip( __( 'Errors', 'drywall-toolbox' ), 'error', 'error' === $severity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_filter_chip( __( 'Warnings', 'drywall-toolbox' ), 'warning', 'warning' === $severity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_filter_chip( __( 'Info', 'drywall-toolbox' ), 'info', 'info' === $severity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';

	if ( empty( $issues ) ) {
		echo dtb_admin_ui_empty_state(
			__( 'No issues found', 'drywall-toolbox' ),
			__( 'No matching catalog health issues were found for the current filters.', 'drywall-toolbox' )
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return (string) ob_get_clean();
	}

	echo dtb_admin_ui_table_open(
		[
			[ 'label' => __( 'ID', 'drywall-toolbox' ), 'key' => 'product_id' ],
			[ 'label' => __( 'Product', 'drywall-toolbox' ), 'key' => 'product_name' ],
			[ 'label' => __( 'SKU', 'drywall-toolbox' ), 'key' => 'sku' ],
			[ 'label' => __( 'Severity', 'drywall-toolbox' ), 'key' => 'severity' ],
			[ 'label' => __( 'Code', 'drywall-toolbox' ), 'key' => 'code' ],
			[ 'label' => __( 'Message', 'drywall-toolbox' ), 'key' => 'message' ],
			[ 'label' => '', 'key' => 'actions' ],
		],
		[]
	); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	foreach ( $issues as $issue ) {
		$product_id   = (int) ( $issue['product_id'] ?? 0 );
		$product_name = (string) ( $issue['product_name'] ?? '—' );
		$sku          = (string) ( $issue['sku'] ?? '—' );
		$code         = (string) ( $issue['code'] ?? 'catalog_issue' );
		$message      = (string) ( $issue['message'] ?? '' );
		$sev          = (string) ( $issue['severity'] ?? 'warning' );
		$sev          = in_array( $sev, [ 'error', 'warning', 'info', 'neutral' ], true ) ? $sev : 'warning';

		echo '<tr>';
		echo '<td>' . esc_html( (string) $product_id ) . '</td>';
		echo '<td>' . esc_html( $product_name ) . '</td>';
		echo '<td><code>' . esc_html( $sku ) . '</code></td>';
		echo '<td>' . dtb_admin_ui_badge( strtoupper( $sev ), $sev ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td><code>' . esc_html( $code ) . '</code></td>';
		echo '<td>' . esc_html( $message ) . '</td>';
		echo '<td>';
		if ( $product_id > 0 ) {
			echo dtb_admin_ui_button( __( 'Edit', 'drywall-toolbox' ), [ 'href' => get_edit_post_link( $product_id ), 'type' => 'ghost', 'size' => 'sm' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</td>';
		echo '</tr>';
	}

	echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	return (string) ob_get_clean();
}

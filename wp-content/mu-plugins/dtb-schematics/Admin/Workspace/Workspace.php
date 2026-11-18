<?php
/**
 * Authoritative server-rendered Schematics Workspace.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_dtb_schematics_workspace_action', 'dtb_schematics_workspace_handle_action' );
add_action( 'wp_ajax_dtb_schematics_workspace_ajax_action', 'dtb_schematics_workspace_handle_ajax_action' );
add_action( 'admin_enqueue_scripts', 'dtb_schematics_workspace_enqueue_assets' );

function dtb_schematics_workspace_url( array $args = [] ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=dtb-schematics' ) );
}

function dtb_schematics_workspace_detail_url( int $id ): string {
	return dtb_schematics_workspace_url( [ 'view' => 'record', 'schematic_id' => max( 0, $id ) ] );
}

function dtb_schematics_workspace_enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, 'dtb-schematics' ) ) {
		return;
	}

	$css_path = __DIR__ . '/../assets/schematics-workspace.css';
	if ( is_file( $css_path ) ) {
		wp_enqueue_style(
			'dtb-schematics-workspace',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/schematics-workspace.css' ),
			[],
			(string) filemtime( $css_path )
		);
	}

	$js_path = __DIR__ . '/../assets/schematics-workspace.js';
	if ( is_file( $js_path ) ) {
		wp_enqueue_script(
			'dtb-schematics-workspace',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/schematics-workspace.js' ),
			[],
			(string) filemtime( $js_path ),
			true
		);
		wp_localize_script(
			'dtb-schematics-workspace',
			'dtbSchematicsWorkspace',
			[
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'workingLabel'      => __( 'Working…', 'drywall-toolbox' ),
				'previewingLabel'   => __( 'Previewing all hotspot files…', 'drywall-toolbox' ),
				'syncingLabel'      => __( 'Synchronizing all hotspot files…', 'drywall-toolbox' ),
				'genericErrorLabel' => __( 'Something went wrong. Please try again.', 'drywall-toolbox' ),
			]
		);
	}
}

function dtb_schematics_workspace_render_page(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	$view = sanitize_key( wp_unslash( $_GET['view'] ?? 'dashboard' ) );
	$view = in_array( $view, [ 'dashboard', 'catalog', 'record', 'operations' ], true ) ? $view : 'dashboard';
	echo '<main class="wrap dtb-schematics-workspace"><h1>' . esc_html__( 'Schematics & Hotspots', 'drywall-toolbox' ) . '</h1><p class="description">' . esc_html__( 'Control schematic records, hotspot synchronization, exact product links, and storefront readiness.', 'drywall-toolbox' ) . '</p>';
	echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) ) . '">' . esc_html__( 'Open Hotspot Resolution', 'drywall-toolbox' ) . '</a></p>';
	dtb_schematics_workspace_navigation( $view );
	echo '<div id="dtb-schematics-workspace-app">';
	echo dtb_schematics_workspace_render_app_content( $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup assembled by the render_* functions.
	echo '</div></main>';
}

/**
 * Renders the notice/run banner plus the requested view's content and
 * returns it as a string, so both the normal page load and the AJAX action
 * handler (dtb_schematics_workspace_handle_ajax_action()) can produce the
 * exact same markup — the AJAX response is a drop-in replacement for
 * #dtb-schematics-workspace-app, never a full page navigation.
 */
function dtb_schematics_workspace_render_app_content( string $view, ?string $notice = null, string $notice_type = 'success', string $run_id = '' ): string {
	ob_start();
	dtb_schematics_workspace_notice_and_run( $notice, $notice_type, $run_id );
	if ( 'catalog' === $view ) { dtb_schematics_workspace_render_catalog(); } elseif ( 'record' === $view ) { dtb_schematics_workspace_render_record(); } elseif ( 'operations' === $view ) { dtb_schematics_workspace_render_operations(); } else { dtb_schematics_workspace_render_dashboard(); }
	return (string) ob_get_clean();
}

function dtb_schematics_workspace_navigation( string $active ): void {
	echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Schematics sections', 'drywall-toolbox' ) . '">';
	foreach ( [ 'dashboard' => __( 'Dashboard', 'drywall-toolbox' ), 'catalog' => __( 'Catalog', 'drywall-toolbox' ), 'operations' => __( 'Operations & history', 'drywall-toolbox' ) ] as $view => $label ) {
		echo '<a class="nav-tab ' . ( $view === $active ? 'nav-tab-active' : '' ) . '" href="' . esc_url( dtb_schematics_workspace_url( [ 'view' => $view ] ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
}

/**
 * @param string|null $notice      Explicit notice text (AJAX callers). Null reads from $_GET (normal page loads).
 * @param string      $notice_type 'success' or 'error'; only used when $notice is explicitly passed.
 * @param string      $run_id      Explicit run id (AJAX callers). Ignored when $notice is null (reads $_GET instead).
 */
function dtb_schematics_workspace_notice_and_run( ?string $notice = null, string $notice_type = 'success', string $run_id = '' ): void {
	if ( null === $notice ) {
		if ( ! empty( $_GET['dtb_schematics_notice'] ) ) {
			$error = 'error' === sanitize_key( wp_unslash( $_GET['dtb_schematics_notice_type'] ?? '' ) );
			echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dtb_schematics_notice'] ) ) ) . '</p></div>';
		}
		$run_id = sanitize_text_field( wp_unslash( $_GET['run_id'] ?? '' ) );
	} elseif ( '' !== $notice ) {
		echo '<div class="notice ' . ( 'error' === $notice_type ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
	}
	if ( '' === $run_id || ! function_exists( 'dtb_schematic_operation_run_get_for_operator' ) ) { return; }
	$run = dtb_schematic_operation_run_get_for_operator( $run_id, get_current_user_id() );
	if ( ! $run ) { return; }
	$result = (array) ( $run['result'] ?? [] );
	$ok = 'completed' === ( $run['status'] ?? '' ) && empty( $result['failed'] ) && empty( $result['unresolved'] );
	echo '<section class="notice ' . ( $ok ? 'notice-info' : 'notice-error' ) . '" aria-label="' . esc_attr__( 'Operation result', 'drywall-toolbox' ) . '"><p><strong>' . esc_html__( 'Operation run', 'drywall-toolbox' ) . ':</strong> <code>' . esc_html( $run['id'] ) . '</code> · ' . esc_html( $run['kind'] ) . ' · ' . esc_html( ! empty( $run['dry_run'] ) ? __( 'preview', 'drywall-toolbox' ) : __( 'applied', 'drywall-toolbox' ) ) . ' · ' . esc_html( $run['status'] ) . '</p>';
	if ( ! empty( $run['error'] ) ) { echo '<p>' . esc_html( $run['error'] ) . '</p>'; } else { echo '<p>' . esc_html( sprintf( __( 'Examined %1$d; changed %2$d; skipped %3$d; unresolved parts %4$d; failed %5$d.', 'drywall-toolbox' ), (int) ( $result['examined'] ?? 0 ), (int) ( $result['changed'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ), (int) ( $result['unresolved'] ?? 0 ), (int) ( $result['failed'] ?? 0 ) ) ) . '</p>'; }
	if ( DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS === ( $run['kind'] ?? '' ) && ! empty( $result['results'] ) ) {
		dtb_schematics_workspace_hotspot_results_table( (array) $result['results'] );
	}
	echo '</section>';
}

/** Render the bounded per-record result artifact from a hotspot operation. */
function dtb_schematics_workspace_hotspot_results_table( array $items ): void {
	echo '<div class="dtb-schematics-workspace__results"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Schematic', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Status', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Parts', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Source and result', 'drywall-toolbox' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $items, 0, 500 ) as $item ) {
		$resolved   = (int) ( $item['parts_resolved'] ?? 0 );
		$unresolved = (int) ( $item['parts_unresolved'] ?? 0 );
		$source     = sanitize_text_field( (string) ( $item['source_file'] ?? '' ) );
		$detail     = sanitize_text_field( (string) ( $item['detail'] ?? '' ) );
		echo '<tr><td><code>' . esc_html( (string) ( $item['canonical_id'] ?? $item['schematic_id'] ?? '' ) ) . '</code></td><td><strong>' . esc_html( (string) ( $item['status'] ?? 'unknown' ) ) . '</strong></td><td>' . esc_html( sprintf( __( '%1$d resolved; %2$d unresolved', 'drywall-toolbox' ), $resolved, $unresolved ) ) . '</td><td>';
		if ( '' !== $source ) { echo '<code class="dtb-schematics-workspace__source-path">' . esc_html( $source ) . '</code><br>'; }
		echo esc_html( $detail ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

function dtb_schematics_workspace_health( DTB_Schematic_Record_Entity $record ): array {
	$missing_pages = 0;
	foreach ( array_slice( $record->pages, 0, 50 ) as $page ) { if ( empty( $page['attachment_id'] ) || DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET === $page['lifecycle_state'] ) { $missing_pages++; } }
	$unresolved_parts = count( array_filter( $record->parts, static fn( $part ) => empty( $part['product_id'] ) && ( $part['resolution_state'] ?? '' ) !== DTB_SCHEMATIC_PART_STATE_NOT_SOLD ) );
	return [ 'missing_pages' => $missing_pages, 'dataset' => dtb_schematic_hotspot_dataset_repo_get( $record->id ), 'reference' => (string) ( $record->hotspot_dataset['reference'] ?? '' ), 'unresolved_parts' => $unresolved_parts, 'requirements' => dtb_schematic_runtime_publication_requirements( $record ), 'preview' => dtb_schematic_resolve_preview( $record ) ];
}

function dtb_schematics_workspace_source_status(): array {
	$uploads      = wp_upload_dir();
	$expected_dir = ! empty( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) . DTB_SCHEMATIC_PRIMARY_UPLOADS_YEAR . '/schematics' : '';
	$detected     = '' !== $expected_dir && is_dir( $expected_dir );
	$mode         = dtb_schematics_source_resolution_mode();
	$active_pages      = 0;
	$retired_files     = 0;
	$source_schematics = [];
	if ( $detected ) {
		$manifest = dtb_schematics_read_source_manifest();
		foreach ( (array) ( $manifest['rows'] ?? [] ) as $row ) {
			$filename = (string) ( $row['filename'] ?? '' );
			if ( null !== dtb_schematics_retired_upload_reason( $filename ) ) {
				$retired_files++;
				continue;
			}
			$identity = dtb_schematics_parse_upload_filename( $filename );
			if ( is_array( $identity ) && ! empty( $identity['schematic_id'] ) ) {
				$active_pages++;
				$source_schematics[ sanitize_key( (string) $identity['schematic_id'] ) ] = true;
			}
		}
	}

	return [
		'available'   => 'unavailable' !== $mode,
		'mode'        => $mode,
		'detected'    => $detected,
		'image_count' => $detected ? count( dtb_schematics_scan_directory_image_files( $expected_dir ) ) : 0,
		'active_pages' => $active_pages,
		'retired_files' => $retired_files,
		'source_schematics' => count( $source_schematics ),
		'hotspot_files' => function_exists( 'dtb_schematic_hotspot_enumerate_source_files' ) ? count( dtb_schematic_hotspot_enumerate_source_files() ) : 0,
	];
}

function dtb_schematics_workspace_problem_summary( DTB_Schematic_Record_Entity $record, array $health ): string {
	$items = [];
	if ( $health['missing_pages'] ) { $items[] = sprintf( _n( '%d missing attachment', '%d missing attachments', $health['missing_pages'], 'drywall-toolbox' ), $health['missing_pages'] ); }
	if ( $health['reference'] && ! $health['dataset'] ) { $items[] = __( 'dataset not migrated', 'drywall-toolbox' ); }
	if ( $health['unresolved_parts'] ) { $items[] = sprintf( _n( '%d unresolved part', '%d unresolved parts', $health['unresolved_parts'], 'drywall-toolbox' ), $health['unresolved_parts'] ); }
	if ( ! $record->lifecycle->is_published() && $health['requirements'] ) {
		$labels = [
			'canonical_id_required'  => __( 'canonical ID missing', 'drywall-toolbox' ),
			'title_required'         => __( 'title missing', 'drywall-toolbox' ),
			'brand_id_required'      => __( 'brand missing', 'drywall-toolbox' ),
			'category_id_required'   => __( 'category missing', 'drywall-toolbox' ),
			'attached_page_required' => __( 'attached page missing', 'drywall-toolbox' ),
		];
		foreach ( $health['requirements'] as $requirement ) {
			$items[] = $labels[ $requirement ] ?? sanitize_text_field( (string) $requirement );
		}
	}
	return $items ? implode( '; ', $items ) : __( 'No readiness issues detected.', 'drywall-toolbox' );
}

function dtb_schematics_workspace_render_dashboard(): void {
	$all = dtb_schematic_record_repo_query( [ 'per_page' => 1 ] ); $published = dtb_schematic_record_repo_query( [ 'lifecycle' => DTB_Schematic_Lifecycle_Status::PUBLISHED, 'per_page' => 1 ] ); $recent = dtb_schematic_record_repo_query( [ 'per_page' => 25 ] ); $source = dtb_schematics_workspace_source_status();
	echo '<section>'; foreach ( [ __( 'Schematics registered', 'drywall-toolbox' ) => $all['total'], __( 'Active diagram pages', 'drywall-toolbox' ) => $source['active_pages'], __( 'Published to storefront', 'drywall-toolbox' ) => $published['total'] ] as $label => $value ) { echo '<div class="card"><h2>' . esc_html( (string) $value ) . '</h2><p>' . esc_html( $label ) . '</p></div>'; } echo '</section>';
	echo '<section class="card"><h2>' . esc_html__( 'SiteGround source and storefront', 'drywall-toolbox' ) . '</h2><dl class="dtb-schematics-workspace__details"><dt>' . esc_html__( 'uploads/2026/schematics', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( $source['detected'] ? sprintf( __( '%1$d files: %2$d active pages across %3$d schematics; %4$d retired residuals', 'drywall-toolbox' ), $source['image_count'], $source['active_pages'], $source['source_schematics'], $source['retired_files'] ) : __( 'Not detected', 'drywall-toolbox' ) ) . '</dd><dt>' . esc_html__( 'Effective source', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( $source['available'] ? $source['mode'] : __( 'Unavailable', 'drywall-toolbox' ) ) . '</dd><dt>' . esc_html__( 'Public API', 'drywall-toolbox' ) . '</dt><dd><a href="' . esc_url( rest_url( 'dtb/v1/schematics' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open schematic catalog endpoint', 'drywall-toolbox' ) . '</a></dd><dt>' . esc_html__( 'Storefront', 'drywall-toolbox' ) . '</dt><dd><a href="' . esc_url( home_url( '/schematics' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Schematics page', 'drywall-toolbox' ) . '</a></dd></dl></section>';
	echo '<section class="card"><h2>' . esc_html__( 'Needs attention', 'drywall-toolbox' ) . '</h2><p class="description">' . esc_html__( 'The 25 most recently updated records are checked here. Use Catalog to inspect the full collection.', 'drywall-toolbox' ) . '</p><ul>';
	$count = 0; foreach ( $recent['items'] as $record ) { $summary = dtb_schematics_workspace_problem_summary( $record, dtb_schematics_workspace_health( $record ) ); if ( __( 'No readiness issues detected.', 'drywall-toolbox' ) !== $summary ) { $count++; echo '<li><a href="' . esc_url( dtb_schematics_workspace_detail_url( $record->id ) ) . '">' . esc_html( $record->title ) . '</a>: ' . esc_html( $summary ) . '</li>'; } } if ( ! $count ) { echo '<li>' . esc_html__( 'No readiness issues detected in this review.', 'drywall-toolbox' ) . '</li>'; } echo '</ul></section>';
}

function dtb_schematics_workspace_render_catalog(): void {
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); $lifecycle = sanitize_key( wp_unslash( $_GET['lifecycle'] ?? '' ) ); $lifecycle = in_array( $lifecycle, DTB_Schematic_Lifecycle_Status::all(), true ) ? $lifecycle : ''; $page = min( 10000, max( 1, absint( $_GET['paged'] ?? 1 ) ) ); $results = dtb_schematic_record_repo_query( [ 'search' => $search, 'lifecycle' => $lifecycle, 'page' => $page, 'per_page' => 25 ] );
	echo '<form method="get" class="dtb-schematics-workspace__filters"><input type="hidden" name="page" value="dtb-schematics"><input type="hidden" name="view" value="catalog"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search title or ID', 'drywall-toolbox' ) . '" aria-label="' . esc_attr__( 'Search schematics', 'drywall-toolbox' ) . '"><select name="lifecycle"><option value="">' . esc_html__( 'All lifecycle states', 'drywall-toolbox' ) . '</option>'; foreach ( DTB_Schematic_Lifecycle_Status::all() as $state ) { echo '<option value="' . esc_attr( $state ) . '" ' . selected( $lifecycle, $state, false ) . '>' . esc_html( ( new DTB_Schematic_Lifecycle_Status( $state ) )->label() ) . '</option>'; } echo '</select><button class="button">' . esc_html__( 'Filter', 'drywall-toolbox' ) . '</button></form><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Schematic', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Lifecycle', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Readiness', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Updated', 'drywall-toolbox' ) . '</th></tr></thead><tbody>';
	foreach ( $results['items'] as $record ) { echo '<tr><td><a href="' . esc_url( dtb_schematics_workspace_detail_url( $record->id ) ) . '">' . esc_html( $record->title ) . '</a><br><code>' . esc_html( $record->canonical_id ) . '</code></td><td>' . esc_html( $record->lifecycle->label() ) . '</td><td>' . esc_html( dtb_schematics_workspace_problem_summary( $record, dtb_schematics_workspace_health( $record ) ) ) . '</td><td>' . esc_html( $record->updated_at ) . '</td></tr>'; } if ( ! $results['items'] ) { echo '<tr><td colspan="4">' . esc_html__( 'No schematics match this filter.', 'drywall-toolbox' ) . '</td></tr>'; } echo '</tbody></table>'; dtb_schematics_workspace_pagination( $page, $results['pages'], [ 'view' => 'catalog', 's' => $search, 'lifecycle' => $lifecycle ] );
}

function dtb_schematics_workspace_render_record(): void {
	$id = absint( $_GET['schematic_id'] ?? 0 ); $record = $id ? dtb_schematic_record_repo_get( $id ) : null; if ( ! $record ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'The requested schematic record was not found.', 'drywall-toolbox' ) . '</p></div>'; return; } $health = dtb_schematics_workspace_health( $record );
	echo '<p><a href="' . esc_url( dtb_schematics_workspace_url( [ 'view' => 'catalog' ] ) ) . '">&larr; ' . esc_html__( 'Back to catalog', 'drywall-toolbox' ) . '</a></p><section class="card"><div><h2>' . esc_html( $record->title ) . '</h2><code>' . esc_html( $record->canonical_id ) . '</code><p>' . esc_html( $record->lifecycle->label() ) . '</p></div><div>';
	if ( $record->lifecycle->is_published() ) { dtb_schematics_workspace_action_form( 'refresh_projection', __( 'Refresh public projection', 'drywall-toolbox' ), $id, true ); dtb_schematics_workspace_action_form( 'retire', __( 'Retire', 'drywall-toolbox' ), $id, true ); } elseif ( DTB_Schematic_Lifecycle_Status::READY === $record->lifecycle->value() ) { dtb_schematics_workspace_action_form( 'publish', __( 'Publish', 'drywall-toolbox' ), $id, true ); } echo '</div></section>';
	echo '<section class="dtb-schematics-workspace__panel"><h3>' . esc_html__( 'Readiness and health', 'drywall-toolbox' ) . '</h3><dl class="dtb-schematics-workspace__details"><dt>' . esc_html__( 'Pages', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$d pages; %2$d missing attachments', 'drywall-toolbox' ), count( $record->pages ), $health['missing_pages'] ) ) . '</dd><dt>' . esc_html__( 'Hotspot dataset', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( $health['dataset'] ? __( 'Normalized dataset available', 'drywall-toolbox' ) : ( $health['reference'] ? __( 'Reference exists but dataset is not migrated', 'drywall-toolbox' ) : __( 'No dataset reference', 'drywall-toolbox' ) ) ) . '</dd><dt>' . esc_html__( 'Parts and products', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$d linked products; %2$d unresolved parts', 'drywall-toolbox' ), count( $record->linked_products ), $health['unresolved_parts'] ) ) . '</dd><dt>' . esc_html__( 'Publication requirements', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( $health['requirements'] ? implode( '; ', array_map( 'strval', array_slice( $health['requirements'], 0, 10 ) ) ) : __( 'All requirements satisfied.', 'drywall-toolbox' ) ) . '</dd><dt>' . esc_html__( 'Preview', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( ucfirst( $health['preview']['source'] ) ) . ( $health['preview']['url'] ? ' <a href="' . esc_url( $health['preview']['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open preview', 'drywall-toolbox' ) . '</a>' : '' ) . '</dd></dl></section>';
	echo '<section class="dtb-schematics-workspace__panel"><h3>' . esc_html__( 'Pages and attachments', 'drywall-toolbox' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Attachment', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'State', 'drywall-toolbox' ) . '</th></tr></thead><tbody>'; foreach ( array_slice( $record->pages, 0, 50 ) as $page ) { echo '<tr><td>' . esc_html( $page['page_number'] . ': ' . $page['label'] ) . '</td><td>' . esc_html( $page['attachment_id'] ? '#' . $page['attachment_id'] : '—' ) . '</td><td>' . esc_html( $page['lifecycle_state'] ) . '</td></tr>'; } if ( ! $record->pages ) { echo '<tr><td colspan="3">' . esc_html__( 'No pages synchronized.', 'drywall-toolbox' ) . '</td></tr>'; } echo '</tbody></table></section>';
	echo '<section class="dtb-schematics-workspace__panel"><h3>' . esc_html__( 'Selected-record operations', 'drywall-toolbox' ) . '</h3><p>' . esc_html__( 'No path fields are accepted. Each operation is restricted to this record.', 'drywall-toolbox' ) . '</p>'; dtb_schematics_workspace_action_form( 'migrate_hotspots_preview', __( 'Preview hotspot synchronization', 'drywall-toolbox' ), $id ); dtb_schematics_workspace_action_form( 'migrate_hotspots_commit', __( 'Apply hotspot synchronization', 'drywall-toolbox' ), $id, true ); dtb_schematics_workspace_action_form( 'refresh_products_preview', __( 'Preview product linking', 'drywall-toolbox' ), $id ); dtb_schematics_workspace_action_form( 'refresh_products_commit', __( 'Apply product linking', 'drywall-toolbox' ), $id, true ); echo '</section>';
}

function dtb_schematics_workspace_render_operations(): void {
	$page = min( 10000, max( 1, absint( $_GET['paged'] ?? 1 ) ) ); $history = dtb_schematic_activity_query( [ 'page' => $page, 'per_page' => 25 ] ); $source = dtb_schematics_workspace_source_status();
	echo '<section class="dtb-schematics-workspace__panel"><h2>' . esc_html__( 'Schematic synchronization', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'Register and link a bounded batch from uploads/2026/schematics.', 'drywall-toolbox' ) . '</p>'; if ( $source['available'] ) { dtb_schematics_workspace_action_form( 'reconcile_preview', __( 'Preview schematic sync', 'drywall-toolbox' ) ); dtb_schematics_workspace_action_form( 'reconcile_commit', __( 'Register & link schematics', 'drywall-toolbox' ), 0, true ); } else { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Synchronization is unavailable because no usable schematic source images were detected.', 'drywall-toolbox' ) . '</p></div>'; } echo '</section>';
	echo '<section class="dtb-schematics-workspace__panel"><h2>' . esc_html__( 'Hotspot JSON synchronization', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'Read every approved brands/**/schematic_data*.json source, normalize hotspot geometry, resolve exact WooCommerce parts, and project the result into its authoritative schematic record.', 'drywall-toolbox' ) . '</p><dl class="dtb-schematics-workspace__details"><dt>' . esc_html__( 'Detected source files', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( (string) $source['hotspot_files'] ) . '</dd><dt>' . esc_html__( 'Workflow', 'drywall-toolbox' ) . '</dt><dd>' . esc_html__( 'Preview first. Apply only after reviewing the per-record source, status, and unresolved-part results.', 'drywall-toolbox' ) . '</dd></dl>';
	if ( $source['hotspot_files'] > 0 ) {
		dtb_schematics_workspace_action_form( 'migrate_hotspots_all_preview', __( 'Preview all hotspot JSON files', 'drywall-toolbox' ) );
		dtb_schematics_workspace_action_form( 'migrate_hotspots_all_commit', __( 'Apply all hotspot JSON files', 'drywall-toolbox' ), 0, true );
	} else {
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No approved hotspot JSON source files were detected. Verify the site-root brands directory before running synchronization.', 'drywall-toolbox' ) . '</p></div>';
	}
	echo '</section>';
	echo '<section class="dtb-schematics-workspace__panel"><h2>' . esc_html__( 'Regenerate Oversized Schematic Images (temporary, one-time)', 'drywall-toolbox' ) . '</h2><p class="description">' . esc_html__( 'Fixes blurry/pixelated schematic diagrams caused by WordPress automatically downscaling large uploads. This repairs attachments uploaded before that downscaling was disabled for schematics — new uploads are unaffected. Run once. Safe to re-run: attachments already at full resolution are skipped automatically.', 'drywall-toolbox' ) . '</p>';
	dtb_schematics_workspace_action_form( 'regenerate_oversized_preview', __( 'Preview: count affected images', 'drywall-toolbox' ) );
	dtb_schematics_workspace_action_form( 'regenerate_oversized_commit', __( 'Regenerate oversized images', 'drywall-toolbox' ), 0, true );
	echo '</section>';
	echo '<section class="dtb-schematics-workspace__panel"><h2>' . esc_html__( 'Operation history', 'drywall-toolbox' ) . '</h2>'; dtb_schematics_workspace_activity_table( $history['items'] ); echo '</section>'; dtb_schematics_workspace_pagination( $page, $history['pages'], [ 'view' => 'operations' ] );
}

function dtb_schematics_workspace_activity_table( array $items ): void { echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Operation', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Result', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Summary', 'drywall-toolbox' ) . '</th></tr></thead><tbody>'; foreach ( $items as $item ) { $summary = str_ireplace( [ 'Reconcile commit', 'Reconcile dry run' ], [ 'Schematic sync applied', 'Schematic sync previewed' ], (string) $item['summary'] ); $error = sanitize_text_field( (string) ( $item['detail']['error'] ?? '' ) ); if ( 'error' === $item['result'] && '' !== $error ) { $summary .= ' — ' . $error; } echo '<tr><td>' . esc_html( $item['completed_at'] ) . '</td><td>' . esc_html( $item['operation_type'] ) . '</td><td>' . esc_html( $item['result'] ) . '</td><td>' . esc_html( $summary ) . '</td></tr>'; } if ( ! $items ) { echo '<tr><td colspan="4">' . esc_html__( 'No recorded operations.', 'drywall-toolbox' ) . '</td></tr>'; } echo '</tbody></table>'; }
function dtb_schematics_workspace_pagination( int $current, int $pages, array $args ): void { if ( $pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( [ 'base' => esc_url_raw( add_query_arg( array_merge( $args, [ 'paged' => '%#%' ] ), admin_url( 'admin.php?page=dtb-schematics' ) ) ), 'format' => '', 'current' => $current, 'total' => $pages ] ) ) . '</div></div>'; } }
/**
 * Renders an operation button. No-JS fallback: a normal admin-post.php form
 * submit (full page redirect via dtb_schematics_workspace_handle_action()).
 * With JS: schematics-workspace.js intercepts the submit and runs it via
 * dtb_schematics_workspace_handle_ajax_action() instead, swapping
 * #dtb-schematics-workspace-app in place — no navigation, no confirmation
 * dialog/checkbox gate for either path.
 */
function dtb_schematics_workspace_action_form( string $operation, string $label, int $id = 0, bool $commit = false ): void { echo '<form class="dtb-schematics-workspace__inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-dtb-schematics-operation="' . esc_attr( $operation ) . '"><input type="hidden" name="action" value="dtb_schematics_workspace_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="schematic_id" value="' . esc_attr( (string) $id ) . '">'; wp_nonce_field( 'dtb_schematics_workspace_action', 'dtb_schematics_workspace_nonce' ); echo '<button class="button ' . ( $commit ? 'button-secondary' : 'button-primary' ) . '">' . esc_html( $label ) . '</button></form>'; }

const DTB_SCHEMATICS_WORKSPACE_ALLOWED_OPERATIONS = [ 'publish', 'retire', 'refresh_projection', 'reconcile_preview', 'reconcile_commit', 'migrate_hotspots_preview', 'migrate_hotspots_commit', 'migrate_hotspots_all_preview', 'migrate_hotspots_all_commit', 'refresh_products_preview', 'refresh_products_commit', 'regenerate_oversized_preview', 'regenerate_oversized_commit' ];

function dtb_schematics_workspace_handle_action(): void {
	if ( ! dtb_schematics_can_manage() ) { wp_die( esc_html__( 'You do not have permission to perform this action.', 'drywall-toolbox' ), 403 ); }
	check_admin_referer( 'dtb_schematics_workspace_action', 'dtb_schematics_workspace_nonce' );
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	$id        = absint( $_POST['schematic_id'] ?? 0 );
	if ( ! in_array( $operation, DTB_SCHEMATICS_WORKSPACE_ALLOWED_OPERATIONS, true ) ) { dtb_schematics_workspace_redirect( $id, __( 'Unsupported workspace action.', 'drywall-toolbox' ), 'error' ); }
	$commit             = str_ends_with( $operation, '_commit' );
	$is_all_hotspot_run = str_starts_with( $operation, 'migrate_hotspots_all_' );
	$is_record_run      = ( str_starts_with( $operation, 'migrate_hotspots_' ) && ! $is_all_hotspot_run ) || str_starts_with( $operation, 'refresh_products_' );
	$is_record_mutation = in_array( $operation, [ 'publish', 'retire', 'refresh_projection' ], true );
	$requires_record    = $is_record_run || $is_record_mutation;
	if ( $requires_record && ! dtb_schematic_record_repo_get( $id ) ) { dtb_schematics_workspace_redirect( $id, __( 'Schematic record not found.', 'drywall-toolbox' ), 'error' ); }
	$outcome = dtb_schematics_workspace_perform_operation( $operation, $id, $commit || $is_record_mutation );
	dtb_schematics_workspace_redirect( $id, $outcome['notice'], $outcome['notice_type'], $outcome['run_id'] );
}

/**
 * AJAX counterpart of dtb_schematics_workspace_handle_action(): same
 * validation and the same dtb_schematics_workspace_perform_operation() call,
 * but responds with the freshly rendered view markup as JSON instead of
 * issuing a redirect, so the browser never navigates or reloads.
 */
function dtb_schematics_workspace_handle_ajax_action(): void {
	if ( ! dtb_schematics_can_manage() ) { wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'drywall-toolbox' ) ], 403 ); }
	check_ajax_referer( 'dtb_schematics_workspace_action', 'dtb_schematics_workspace_nonce' );
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	$id        = absint( $_POST['schematic_id'] ?? 0 );
	if ( ! in_array( $operation, DTB_SCHEMATICS_WORKSPACE_ALLOWED_OPERATIONS, true ) ) { wp_send_json_error( [ 'message' => __( 'Unsupported workspace action.', 'drywall-toolbox' ) ] ); }
	$commit             = str_ends_with( $operation, '_commit' );
	$is_all_hotspot_run = str_starts_with( $operation, 'migrate_hotspots_all_' );
	$is_record_run      = ( str_starts_with( $operation, 'migrate_hotspots_' ) && ! $is_all_hotspot_run ) || str_starts_with( $operation, 'refresh_products_' );
	$is_record_mutation = in_array( $operation, [ 'publish', 'retire', 'refresh_projection' ], true );
	$requires_record    = $is_record_run || $is_record_mutation;
	if ( $requires_record && ! dtb_schematic_record_repo_get( $id ) ) { wp_send_json_error( [ 'message' => __( 'Schematic record not found.', 'drywall-toolbox' ) ] ); }

	$outcome = dtb_schematics_workspace_perform_operation( $operation, $id, $commit || $is_record_mutation );

	// Carry forward the caller's filter/pagination state so the re-rendered
	// view is a faithful drop-in replacement for what triggered the action.
	foreach ( [ 'view', 's', 'lifecycle', 'paged' ] as $key ) {
		if ( isset( $_POST[ $key ] ) ) { $_GET[ $key ] = wp_unslash( $_POST[ $key ] ); }
	}
	if ( $id ) { $_GET['schematic_id'] = $id; }
	$view = sanitize_key( wp_unslash( $_GET['view'] ?? ( $id ? 'record' : 'operations' ) ) );
	$view = in_array( $view, [ 'dashboard', 'catalog', 'record', 'operations' ], true ) ? $view : 'operations';

	wp_send_json_success(
		[
			'html' => dtb_schematics_workspace_render_app_content( $view, $outcome['notice'], $outcome['notice_type'], $outcome['run_id'] ),
			'view' => $view,
		]
	);
}

/**
 * Runs an operation and returns its outcome without redirecting or emitting
 * output — the single implementation shared by the admin-post.php (no-JS)
 * and AJAX action handlers above.
 */
function dtb_schematics_workspace_perform_operation( string $operation, int $id, bool $commit ): array {
	if ( str_starts_with( $operation, 'reconcile_' ) ) { $kind = DTB_SCHEMATIC_OPERATION_RECONCILE; } elseif ( str_starts_with( $operation, 'migrate_hotspots_' ) ) { $kind = DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS; } elseif ( str_starts_with( $operation, 'refresh_products_' ) ) { $kind = DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS; } elseif ( str_starts_with( $operation, 'regenerate_oversized_' ) ) { $kind = DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED; } else { $kind = [ 'publish' => DTB_SCHEMATIC_OPERATION_PUBLISH, 'retire' => DTB_SCHEMATIC_OPERATION_RETIRE, 'refresh_projection' => DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC ][ $operation ]; }
	$args = [ 'kind' => $kind, 'dry_run' => ! $commit, 'operator_id' => get_current_user_id() ];
	if ( DTB_SCHEMATIC_OPERATION_RECONCILE === $kind ) { $args['resume'] = true; } elseif ( str_starts_with( $operation, 'migrate_hotspots_all_' ) ) { $args['all_records'] = true; $args['per_page'] = 25; } else { $args['schematic_ids'] = [ $id ]; }
	$run = dtb_schematic_run_operation( $args );
	if ( is_wp_error( $run ) ) { return [ 'notice' => $run->get_error_message(), 'notice_type' => 'error', 'run_id' => '' ]; }
	$result = (array) ( $run['result'] ?? [] );
	$ok     = 'completed' === ( $run['status'] ?? '' ) && empty( $result['failed'] ) && empty( $result['unresolved'] );
	return [
		'notice'      => $ok ? __( 'Operation run completed.', 'drywall-toolbox' ) : (string) ( $run['error'] ?? __( 'Operation completed with unresolved or failed items.', 'drywall-toolbox' ) ),
		'notice_type' => $ok ? 'success' : 'error',
		'run_id'      => (string) ( $run['id'] ?? '' ),
	];
}

function dtb_schematics_workspace_redirect( int $id, string $notice, string $type = 'success', string $run_id = '' ): void { $args = [ 'view' => $id ? 'record' : 'operations', 'dtb_schematics_notice' => $notice, 'dtb_schematics_notice_type' => $type ]; if ( $id ) { $args['schematic_id'] = $id; } if ( $run_id ) { $args['run_id'] = sanitize_text_field( $run_id ); } wp_safe_redirect( dtb_schematics_workspace_url( $args ) ); exit; }

<?php
/**
 * Read-only frontend/public/brands source audit panel for Hotspot Resolver.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'dtb_schematic_hotspot_source_audit_enqueue_assets' );
add_action( 'admin_notices', 'dtb_schematic_hotspot_source_audit_render_panel' );

function dtb_schematic_hotspot_source_audit_enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) ) {
		return;
	}
	$css_path = __DIR__ . '/../assets/hotspot-source-audit.css';
	if ( is_file( $css_path ) ) {
		wp_enqueue_style(
			'dtb-schematic-hotspot-source-audit',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/hotspot-source-audit.css' ),
			[],
			(string) filemtime( $css_path )
		);
	}
}

function dtb_schematic_hotspot_source_audit_render_panel(): void {
	if ( ! dtb_schematics_can_manage() ) {
		return;
	}
	$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
	if ( DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG !== $page ) {
		return;
	}

	$filters = function_exists( 'dtb_schematic_hotspot_resolver_request_filters' )
		? dtb_schematic_hotspot_resolver_request_filters()
		: [ 'schematic_id' => 0, 'page' => 1, 'per_page' => 25, 'search' => '' ];
	$report = dtb_schematic_hotspot_source_audit_scan( $filters );

	echo '<section class="dtb-hotspot-resolver__source-audit" aria-label="' . esc_attr__( 'Frontend hotspot source audit', 'drywall-toolbox' ) . '">';
	echo '<div class="dtb-hotspot-resolver__source-audit-heading"><div><span class="dtb-hotspot-resolver__eyebrow">' . esc_html__( 'Source truth audit', 'drywall-toolbox' ) . '</span><h2>' . esc_html__( 'frontend/public/brands hotspot integrity', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'Reads the current schematic_data*.json sources through the same approved reader, normalization, source grouping, and merge semantics used by the migration pipeline. This audit is read-only.', 'drywall-toolbox' ) . '</p></div></div>';

	$metrics = [
		[ __( 'Source files', 'drywall-toolbox' ), (int) $report['source_files_examined'] ],
		[ __( 'Parts interpreted', 'drywall-toolbox' ), (int) $report['source_parts'] ],
		[ __( 'Hotspots interpreted', 'drywall-toolbox' ), (int) $report['source_hotspots'] ],
		[ __( 'Drifted records', 'drywall-toolbox' ), (int) $report['source_drift_records'] ],
		[ __( 'Source read errors', 'drywall-toolbox' ), (int) $report['source_read_errors'] ],
		[ __( 'Resolvable exactly', 'drywall-toolbox' ), (int) $report['exactly_resolvable'] ],
		[ __( 'Unresolved at source', 'drywall-toolbox' ), (int) $report['unresolved_at_source'] ],
		[ __( 'Invalid/dangling hotspots', 'drywall-toolbox' ), (int) $report['invalid_hotspots'] + (int) $report['dangling_hotspots'] ],
	];

	echo '<div class="dtb-hotspot-resolver__source-metrics">';
	foreach ( $metrics as $metric ) {
		echo '<div><strong>' . esc_html( (string) $metric[1] ) . '</strong><span>' . esc_html( $metric[0] ) . '</span></div>';
	}
	echo '</div>';

	if ( (int) $report['source_drift_records'] > 0 ) {
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Source drift detected.', 'drywall-toolbox' ) . '</strong> ' . esc_html__( 'At least one current frontend/public/brands source no longer matches the persisted normalized hotspot dataset. Preview hotspot synchronization before applying relationship repairs so source projection and product resolution stay aligned.', 'drywall-toolbox' ) . '</p></div>';
	}
	if ( (int) $report['source_read_errors'] > 0 || (int) $report['source_missing'] > 0 ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'One or more expected brand hotspot sources could not be read or associated. Resolve source-path/schema problems before treating resolver output as complete.', 'drywall-toolbox' ) . '</p></div>';
	}

	echo '<div class="dtb-hotspot-resolver__source-table"><table class="widefat striped"><thead><tr>';
	foreach ( [ __( 'Schematic', 'drywall-toolbox' ), __( 'Source', 'drywall-toolbox' ), __( 'Schema / volume', 'drywall-toolbox' ), __( 'Projection drift', 'drywall-toolbox' ), __( 'Integrity findings', 'drywall-toolbox' ), __( 'Resolution signal', 'drywall-toolbox' ) ] as $heading ) {
		echo '<th>' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( (array) $report['items'] as $item ) {
		$findings = [];
		if ( ! empty( $item['source_only_parts'] ) ) { $findings[] = sprintf( __( '%d source-only part(s)', 'drywall-toolbox' ), count( $item['source_only_parts'] ) ); }
		if ( ! empty( $item['stored_only_parts'] ) ) { $findings[] = sprintf( __( '%d stored-only part(s)', 'drywall-toolbox' ), count( $item['stored_only_parts'] ) ); }
		if ( ! empty( $item['dangling_hotspots'] ) ) { $findings[] = sprintf( __( '%d dangling hotspot(s)', 'drywall-toolbox' ), count( $item['dangling_hotspots'] ) ); }
		if ( ! empty( $item['invalid_hotspots'] ) ) { $findings[] = sprintf( __( '%d invalid-coordinate hotspot(s)', 'drywall-toolbox' ), count( $item['invalid_hotspots'] ) ); }
		if ( ! empty( $item['duplicate_hotspot_ids'] ) ) { $findings[] = sprintf( __( '%d duplicate hotspot ID(s)', 'drywall-toolbox' ), count( $item['duplicate_hotspot_ids'] ) ); }
		if ( ! empty( $item['page_mismatches'] ) ) { $findings[] = sprintf( __( '%d page mismatch(es)', 'drywall-toolbox' ), count( $item['page_mismatches'] ) ); }
		if ( ! empty( $item['read_errors'] ) ) { $findings[] = sprintf( __( '%d source read error(s)', 'drywall-toolbox' ), count( $item['read_errors'] ) ); }
		if ( empty( $findings ) ) { $findings[] = __( 'No structural source issues detected.', 'drywall-toolbox' ); }

		$source_evaluable = ! in_array( (string) ( $item['status'] ?? '' ), [ 'missing', 'error' ], true ) && ! empty( $item['source_files'] );
		$projection_label = ! $source_evaluable
			? __( 'Not evaluable', 'drywall-toolbox' )
			: ( ! empty( $item['drift'] ) ? __( 'Out of sync', 'drywall-toolbox' ) : __( 'Aligned', 'drywall-toolbox' ) );
		$resolution_signal = $source_evaluable
			? sprintf( __( '%1$d exact · %2$d unresolved', 'drywall-toolbox' ), (int) $item['exactly_resolvable'], (int) $item['unresolved_at_source'] )
			: __( 'Unavailable until source is restored', 'drywall-toolbox' );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $item['title'] ) . '</strong><br><code>' . esc_html( $item['canonical_id'] ) . '</code></td>';
		echo '<td>';
		foreach ( array_slice( (array) $item['source_files'], 0, 4 ) as $source_file ) {
			echo '<code class="dtb-hotspot-resolver__source-path">' . esc_html( $source_file ) . '</code><br>';
		}
		if ( empty( $item['source_files'] ) ) { echo '—'; }
		echo '</td>';
		echo '<td>' . esc_html( strtoupper( (string) $item['source_schema'] ) ?: '—' ) . '<br>' . esc_html( sprintf( __( '%1$d parts · %2$d hotspots', 'drywall-toolbox' ), (int) $item['parts_count'], (int) $item['hotspot_count'] ) ) . '</td>';
		echo '<td><strong>' . esc_html( $projection_label ) . '</strong></td>';
		echo '<td>' . esc_html( implode( '; ', $findings ) ) . '</td>';
		echo '<td>' . esc_html( $resolution_signal ) . '</td>';
		echo '</tr>';
	}
	if ( empty( $report['items'] ) ) {
		echo '<tr><td colspan="6">' . esc_html__( 'No schematic records match this resolver scope.', 'drywall-toolbox' ) . '</td></tr>';
	}
	echo '</tbody></table></div></section>';
}

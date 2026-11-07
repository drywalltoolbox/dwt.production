<?php
/**
 * Temporary wp-admin hotspot/part diagnostic resolver.
 *
 * Transport/UI only. Diagnostic and mutation logic lives in
 * Application/DiagnoseSchematicHotspots.php and all record writes continue
 * through Application/ManageSchematicRecord.php.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG = 'dtb-schematic-hotspot-resolver';
const DTB_SCHEMATIC_HOTSPOT_RESOLVER_NONCE_ACTION = 'dtb_schematic_hotspot_resolver_action';

add_action( 'admin_menu', 'dtb_schematic_hotspot_resolver_register_page', 6 );
add_action( 'admin_enqueue_scripts', 'dtb_schematic_hotspot_resolver_enqueue_assets' );
add_action( 'admin_post_dtb_schematic_hotspot_resolver_action', 'dtb_schematic_hotspot_resolver_handle_post' );
add_action( 'wp_ajax_dtb_schematic_hotspot_resolver_action', 'dtb_schematic_hotspot_resolver_handle_ajax' );

/** Register the temporary tool through the shared DTB admin registry. */
function dtb_schematic_hotspot_resolver_register_page(): void {
	if ( ! function_exists( 'dtb_register_admin_page' ) ) {
		return;
	}
	dtb_register_admin_page(
		[
			'library'    => 'tools',
			'slug'       => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
			'title'      => __( 'Hotspot Resolver', 'drywall-toolbox' ),
			'menu_title' => __( 'Hotspot Resolver', 'drywall-toolbox' ),
			'capability' => 'dtb_manage_schematics',
			'callback'   => 'dtb_schematic_hotspot_resolver_render_page',
			'position'   => 11,
			'template'   => 'tool',
			'section'    => 'Catalog Maintenance',
		]
	);
}

/** Load assets only on this page. */
function dtb_schematic_hotspot_resolver_enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) ) {
		return;
	}

	$css_path = __DIR__ . '/../assets/hotspot-resolver.css';
	$js_path  = __DIR__ . '/../assets/hotspot-resolver.js';

	if ( is_file( $css_path ) ) {
		wp_enqueue_style(
			'dtb-schematic-hotspot-resolver',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/hotspot-resolver.css' ),
			[],
			(string) filemtime( $css_path )
		);
	}
	if ( is_file( $js_path ) ) {
		wp_enqueue_script(
			'dtb-schematic-hotspot-resolver',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/hotspot-resolver.js' ),
			[],
			(string) filemtime( $js_path ),
			true
		);
		wp_localize_script(
			'dtb-schematic-hotspot-resolver',
			'dtbHotspotResolver',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'workingLabel' => __( 'Applying…', 'drywall-toolbox' ),
				'errorLabel'   => __( 'The resolver action failed. Reload and try again.', 'drywall-toolbox' ),
			]
		);
	}
}

function dtb_schematic_hotspot_resolver_url( array $args = [] ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) );
}

/** Main page. */
function dtb_schematic_hotspot_resolver_render_page(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}

	$filters = dtb_schematic_hotspot_resolver_request_filters();
	$report  = dtb_schematic_hotspot_diagnostics_scan( $filters );

	echo '<main class="wrap dtb-hotspot-resolver">';
	echo '<header class="dtb-hotspot-resolver__hero">';
	echo '<div><span class="dtb-hotspot-resolver__eyebrow">' . esc_html__( 'Temporary diagnostic module', 'drywall-toolbox' ) . '</span><h1>' . esc_html__( 'Schematic Hotspot Resolver', 'drywall-toolbox' ) . '</h1><p>' . esc_html__( 'Diagnose unresolved hotspot-to-product relationships, apply deterministic exact-match repairs, and review ambiguous candidates without changing product identifiers.', 'drywall-toolbox' ) . '</p></div>';
	echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=dtb-schematics&view=operations' ) ) . '">' . esc_html__( 'Open Schematics Operations', 'drywall-toolbox' ) . '</a>';
	echo '</header>';

	echo '<div id="dtb-hotspot-resolver-app">';
	dtb_schematic_hotspot_resolver_render_app( $report, $filters );
	echo '</div></main>';
}

/** Render the replaceable app region. */
function dtb_schematic_hotspot_resolver_render_app( array $report, array $filters, string $notice = '', string $notice_type = 'success' ): void {
	if ( '' !== $notice ) {
		echo '<div class="notice ' . ( 'error' === $notice_type ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( $notice ) . '</p></div>';
	}

	dtb_schematic_hotspot_resolver_render_filters( $filters );
	dtb_schematic_hotspot_resolver_render_metrics( $report );

	if ( ! empty( $report['truncated'] ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The diagnostic result hit its safety bound of 250 unresolved parts. Narrow the schematic filter before applying repairs.', 'drywall-toolbox' ) . '</p></div>';
	}

	$record_ids = array_values( array_unique( array_map( static fn( $item ) => (int) $item['schematic_id'], (array) $report['items'] ) ) );
	if ( (int) $report['safe_fixes'] > 0 && ! empty( $record_ids ) ) {
		echo '<section class="dtb-hotspot-resolver__bulk"><div><strong>' . esc_html__( 'Safe exact repairs available', 'drywall-toolbox' ) . '</strong><p>' . esc_html__( 'This only applies matches that satisfy the existing SKU / brand+MPN / explicit compatibility resolver contract. It will not apply review-only candidates.', 'drywall-toolbox' ) . '</p></div>';
		dtb_schematic_hotspot_resolver_action_form(
			'apply_safe',
			__( 'Apply safe exact fixes', 'drywall-toolbox' ),
			[ 'record_ids' => implode( ',', array_slice( $record_ids, 0, DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS ) ) ],
			true,
			'dtb-button-primary'
		);
		echo '</section>';
	}

	if ( empty( $report['items'] ) ) {
		echo '<section class="dtb-hotspot-resolver__empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h2>' . esc_html__( 'No unresolved hotspot parts in this scope', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'The selected records do not currently contain unresolved part relationships.', 'drywall-toolbox' ) . '</p></section>';
		dtb_schematic_hotspot_resolver_render_pagination( $report, $filters );
		return;
	}

	echo '<section class="dtb-hotspot-resolver__results" aria-label="' . esc_attr__( 'Unresolved hotspot diagnostics', 'drywall-toolbox' ) . '">';
	$current_schematic = 0;
	foreach ( (array) $report['items'] as $item ) {
		if ( $current_schematic !== (int) $item['schematic_id'] ) {
			if ( 0 !== $current_schematic ) {
				echo '</div>';
			}
			$current_schematic = (int) $item['schematic_id'];
			echo '<div class="dtb-hotspot-resolver__record-group"><div class="dtb-hotspot-resolver__record-heading"><div><h2>' . esc_html( $item['schematic_title'] ) . '</h2><code>' . esc_html( $item['canonical_id'] ) . '</code></div><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=dtb-schematics&view=record&schematic_id=' . $current_schematic ) ) . '">' . esc_html__( 'Open record', 'drywall-toolbox' ) . '</a></div>';
		}
		dtb_schematic_hotspot_resolver_render_item( $item );
	}
	if ( 0 !== $current_schematic ) {
		echo '</div>';
	}
	echo '</section>';

	dtb_schematic_hotspot_resolver_render_pagination( $report, $filters );
}

function dtb_schematic_hotspot_resolver_render_filters( array $filters ): void {
	echo '<form method="get" class="dtb-hotspot-resolver__filters">';
	echo '<input type="hidden" name="page" value="' . esc_attr( DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) . '">';
	echo '<label><span>' . esc_html__( 'Schematic ID', 'drywall-toolbox' ) . '</span><input type="number" min="1" name="schematic_id" value="' . esc_attr( $filters['schematic_id'] ?: '' ) . '" placeholder="' . esc_attr__( 'All in page', 'drywall-toolbox' ) . '"></label>';
	echo '<label class="dtb-hotspot-resolver__search"><span>' . esc_html__( 'Search schematics', 'drywall-toolbox' ) . '</span><input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Title or keyword', 'drywall-toolbox' ) . '"></label>';
	echo '<button class="button button-primary">' . esc_html__( 'Run diagnostic', 'drywall-toolbox' ) . '</button>';
	if ( $filters['schematic_id'] || $filters['search'] ) {
		echo '<a class="button" href="' . esc_url( dtb_schematic_hotspot_resolver_url() ) . '">' . esc_html__( 'Clear', 'drywall-toolbox' ) . '</a>';
	}
	echo '</form>';
}

function dtb_schematic_hotspot_resolver_render_metrics( array $report ): void {
	$metrics = [
		[ 'label' => __( 'Records scanned', 'drywall-toolbox' ), 'value' => (int) $report['records_examined'], 'tone' => 'neutral' ],
		[ 'label' => __( 'Unresolved parts', 'drywall-toolbox' ), 'value' => (int) $report['unresolved_parts'], 'tone' => (int) $report['unresolved_parts'] ? 'warning' : 'success' ],
		[ 'label' => __( 'Safe exact fixes', 'drywall-toolbox' ), 'value' => (int) $report['safe_fixes'], 'tone' => (int) $report['safe_fixes'] ? 'action' : 'neutral' ],
		[ 'label' => __( 'Review candidates', 'drywall-toolbox' ), 'value' => (int) $report['review_candidates'], 'tone' => (int) $report['review_candidates'] ? 'review' : 'neutral' ],
		[ 'label' => __( 'Missing datasets', 'drywall-toolbox' ), 'value' => (int) $report['missing_datasets'], 'tone' => (int) $report['missing_datasets'] ? 'danger' : 'neutral' ],
	];

	echo '<section class="dtb-hotspot-resolver__metrics">';
	foreach ( $metrics as $metric ) {
		echo '<div class="dtb-hotspot-resolver__metric dtb-hotspot-resolver__metric--' . esc_attr( $metric['tone'] ) . '"><strong>' . esc_html( (string) $metric['value'] ) . '</strong><span>' . esc_html( $metric['label'] ) . '</span></div>';
	}
	echo '</section>';
}

/** Render one unresolved part card. */
function dtb_schematic_hotspot_resolver_render_item( array $item ): void {
	$issue_tone = ! empty( $item['safe_fix'] ) ? 'safe' : ( ! empty( $item['candidates'] ) ? 'review' : 'blocked' );
	echo '<article class="dtb-hotspot-resolver__part" data-part-ref="' . esc_attr( $item['part_ref'] ) . '">';
	echo '<div class="dtb-hotspot-resolver__part-main">';
	echo '<div class="dtb-hotspot-resolver__part-title"><span class="dtb-hotspot-resolver__status dtb-hotspot-resolver__status--' . esc_attr( $issue_tone ) . '">' . esc_html( ucfirst( $issue_tone ) ) . '</span><div><h3>' . esc_html( $item['title'] ?: $item['part_ref'] ) . '</h3><code>' . esc_html( $item['part_ref'] ) . '</code></div></div>';
	echo '<dl class="dtb-hotspot-resolver__identifiers"><div><dt>' . esc_html__( 'SKU', 'drywall-toolbox' ) . '</dt><dd><code>' . esc_html( $item['sku'] ?: '—' ) . '</code></dd></div><div><dt>' . esc_html__( 'MPN / display ID', 'drywall-toolbox' ) . '</dt><dd><code>' . esc_html( $item['mpn'] ?: '—' ) . '</code></dd></div><div><dt>' . esc_html__( 'Hotspots', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( (string) $item['occurrence_count'] ) . '</dd></div></dl>';
	echo '<p class="dtb-hotspot-resolver__diagnosis"><strong>' . esc_html__( 'Diagnosis:', 'drywall-toolbox' ) . '</strong> ' . esc_html( $item['issue'] ) . '</p>';
	echo '</div>';

	echo '<div class="dtb-hotspot-resolver__resolution">';
	if ( ! empty( $item['safe_fix']['product'] ) ) {
		dtb_schematic_hotspot_resolver_render_candidate( $item, $item['safe_fix']['product'], true, (string) $item['safe_fix']['method'] );
	} elseif ( ! empty( $item['candidates'] ) ) {
		echo '<h4>' . esc_html__( 'Review-only candidates', 'drywall-toolbox' ) . '</h4><p class="description">' . esc_html__( 'These candidates are diagnostic hints. Linking one creates an explicit operator override and will not modify its SKU, MPN, brand, or other catalog fields.', 'drywall-toolbox' ) . '</p>';
		foreach ( $item['candidates'] as $candidate ) {
			dtb_schematic_hotspot_resolver_render_candidate( $item, $candidate, false );
		}
	} else {
		echo '<div class="dtb-hotspot-resolver__no-match"><span class="dashicons dashicons-search" aria-hidden="true"></span><p>' . esc_html__( 'No bounded candidate was found. Verify the hotspot source identifiers or create/correct the authoritative WooCommerce catalog product before re-running.', 'drywall-toolbox' ) . '</p></div>';
	}

	echo '<div class="dtb-hotspot-resolver__part-actions">';
	dtb_schematic_hotspot_resolver_action_form( 'mark_not_sold', __( 'Mark intentionally not sold', 'drywall-toolbox' ), [ 'schematic_id' => $item['schematic_id'], 'part_ref' => $item['part_ref'] ], true );
	echo '</div></div></article>';
}

function dtb_schematic_hotspot_resolver_render_candidate( array $item, array $candidate, bool $safe, string $method = '' ): void {
	$brand_match = ! empty( $candidate['brand_matches'] );
	echo '<div class="dtb-hotspot-resolver__candidate ' . ( $safe ? 'dtb-hotspot-resolver__candidate--safe' : '' ) . '">';
	echo '<div class="dtb-hotspot-resolver__candidate-copy"><div><strong>' . esc_html( $candidate['name'] ?: '#' . $candidate['id'] ) . '</strong> <code>#' . esc_html( (string) $candidate['id'] ) . '</code></div><span>' . esc_html( implode( ' · ', array_filter( [ $candidate['brand'] ?: '', $candidate['sku'] ? 'SKU ' . $candidate['sku'] : '', $candidate['mpn'] ? 'MPN ' . $candidate['mpn'] : '', $candidate['type'] ?: '' ] ) ) ) . '</span>';
	if ( $safe ) {
		echo '<span class="dtb-hotspot-resolver__why">' . esc_html( sprintf( __( 'Exact resolver: %s', 'drywall-toolbox' ), $method ) ) . '</span>';
	} elseif ( ! $brand_match && ! empty( $candidate['brand'] ) ) {
		echo '<span class="dtb-hotspot-resolver__why dtb-hotspot-resolver__why--warning">' . esc_html__( 'Brand does not exactly match the schematic. Review before linking.', 'drywall-toolbox' ) . '</span>';
	}
	echo '</div><div class="dtb-hotspot-resolver__candidate-actions">';
	if ( ! empty( $candidate['edit_url'] ) ) {
		echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( $candidate['edit_url'] ) . '">' . esc_html__( 'Inspect product', 'drywall-toolbox' ) . '</a>';
	}
	if ( $safe ) {
		dtb_schematic_hotspot_resolver_action_form( 'apply_safe', __( 'Apply exact fix', 'drywall-toolbox' ), [ 'record_ids' => (string) $item['schematic_id'] ], true, 'button-primary' );
	} else {
		dtb_schematic_hotspot_resolver_action_form( 'explicit_link', __( 'Link explicitly', 'drywall-toolbox' ), [ 'schematic_id' => $item['schematic_id'], 'part_ref' => $item['part_ref'], 'product_id' => $candidate['id'] ], true );
	}
	echo '</div></div>';
}

/** Build a nonce-protected mutation form. */
function dtb_schematic_hotspot_resolver_action_form( string $operation, string $label, array $fields, bool $confirm = false, string $button_class = '' ): void {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dtb-hotspot-resolver__action-form"' . ( $confirm ? ' data-confirm="1"' : '' ) . '>';
	echo '<input type="hidden" name="action" value="dtb_schematic_hotspot_resolver_action">';
	echo '<input type="hidden" name="resolver_operation" value="' . esc_attr( $operation ) . '">';
	wp_nonce_field( DTB_SCHEMATIC_HOTSPOT_RESOLVER_NONCE_ACTION, '_dtb_hotspot_resolver_nonce' );
	foreach ( $fields as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( sanitize_key( $name ) ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	echo '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button></form>';
}

function dtb_schematic_hotspot_resolver_render_pagination( array $report, array $filters ): void {
	if ( $filters['schematic_id'] || (int) $report['total_pages'] <= 1 ) {
		return;
	}
	$current = max( 1, (int) $report['page'] );
	$total   = max( 1, (int) $report['total_pages'] );
	echo '<nav class="tablenav-pages dtb-hotspot-resolver__pagination" aria-label="' . esc_attr__( 'Diagnostic pages', 'drywall-toolbox' ) . '">';
	if ( $current > 1 ) {
		echo '<a class="button" href="' . esc_url( dtb_schematic_hotspot_resolver_url( [ 'paged' => $current - 1, 's' => $filters['search'] ] ) ) . '">&larr; ' . esc_html__( 'Previous', 'drywall-toolbox' ) . '</a>';
	}
	echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'drywall-toolbox' ), $current, $total ) ) . '</span>';
	if ( $current < $total ) {
		echo '<a class="button" href="' . esc_url( dtb_schematic_hotspot_resolver_url( [ 'paged' => $current + 1, 's' => $filters['search'] ] ) ) . '">' . esc_html__( 'Next', 'drywall-toolbox' ) . ' &rarr;</a>';
	}
	echo '</nav>';
}

/** Shared request filter sanitizer. */
function dtb_schematic_hotspot_resolver_request_filters(): array {
	return [
		'schematic_id' => max( 0, absint( $_REQUEST['schematic_id'] ?? 0 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		'page'         => min( 10000, max( 1, absint( $_REQUEST['paged'] ?? 1 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		'per_page'     => DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS,
		'search'       => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	];
}

/** Enforce capability and CSRF protection for every mutation. */
function dtb_schematic_hotspot_resolver_authorize(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	check_admin_referer( DTB_SCHEMATIC_HOTSPOT_RESOLVER_NONCE_ACTION, '_dtb_hotspot_resolver_nonce' );
}

/** Run one bounded resolver mutation. */
function dtb_schematic_hotspot_resolver_perform_action(): array {
	$operation = sanitize_key( wp_unslash( $_POST['resolver_operation'] ?? '' ) );

	if ( 'apply_safe' === $operation ) {
		$record_ids = array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['record_ids'] ?? '' ) ) ) ) ), 0, DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS );
		if ( empty( $record_ids ) ) {
			return [ 'ok' => false, 'message' => __( 'No schematic records were selected.', 'drywall-toolbox' ) ];
		}
		$result = dtb_schematic_hotspot_apply_safe_repairs( $record_ids );
		$ok     = empty( $result['errors'] );
		$message = sprintf(
			__( 'Examined %1$d record(s); updated %2$d; resolved %3$d part(s); %4$d remain unresolved.', 'drywall-toolbox' ),
			(int) $result['examined'],
			(int) $result['changed'],
			(int) $result['resolved'],
			(int) $result['unresolved']
		);
		if ( ! $ok ) {
			$message .= ' ' . implode( ' ', array_map( 'sanitize_text_field', array_slice( $result['errors'], 0, 5 ) ) );
		}
		return [ 'ok' => $ok, 'message' => $message ];
	}

	$schematic_id = absint( $_POST['schematic_id'] ?? 0 );
	$part_ref     = sanitize_key( wp_unslash( $_POST['part_ref'] ?? '' ) );
	if ( $schematic_id <= 0 || '' === $part_ref ) {
		return [ 'ok' => false, 'message' => __( 'A schematic and part reference are required.', 'drywall-toolbox' ) ];
	}

	if ( 'explicit_link' === $operation ) {
		$result = dtb_schematic_hotspot_set_explicit_product( $schematic_id, $part_ref, absint( $_POST['product_id'] ?? 0 ) );
		return is_wp_error( $result )
			? [ 'ok' => false, 'message' => $result->get_error_message() ]
			: [ 'ok' => true, 'message' => __( 'Explicit product link saved.', 'drywall-toolbox' ) ];
	}
	if ( 'mark_not_sold' === $operation ) {
		$result = dtb_schematic_hotspot_mark_not_sold( $schematic_id, $part_ref );
		return is_wp_error( $result )
			? [ 'ok' => false, 'message' => $result->get_error_message() ]
			: [ 'ok' => true, 'message' => __( 'Part marked intentionally not sold.', 'drywall-toolbox' ) ];
	}
	if ( 'reset_resolution' === $operation ) {
		$result = dtb_schematic_hotspot_reset_resolution( $schematic_id, $part_ref );
		return is_wp_error( $result )
			? [ 'ok' => false, 'message' => $result->get_error_message() ]
			: [ 'ok' => true, 'message' => __( 'Part reset to unresolved.', 'drywall-toolbox' ) ];
	}

	return [ 'ok' => false, 'message' => __( 'Unknown resolver operation.', 'drywall-toolbox' ) ];
}

/** No-JS mutation endpoint. */
function dtb_schematic_hotspot_resolver_handle_post(): void {
	dtb_schematic_hotspot_resolver_authorize();
	$outcome = dtb_schematic_hotspot_resolver_perform_action();
	$filters = dtb_schematic_hotspot_resolver_request_filters();
	wp_safe_redirect(
		dtb_schematic_hotspot_resolver_url(
			[
				'schematic_id' => $filters['schematic_id'] ?: null,
				'paged'        => $filters['page'],
				's'            => $filters['search'],
				'resolver_notice' => $outcome['message'],
				'resolver_notice_type' => $outcome['ok'] ? 'success' : 'error',
			]
		)
	);
	exit;
}

/** AJAX mutation endpoint used by progressive enhancement. */
function dtb_schematic_hotspot_resolver_handle_ajax(): void {
	dtb_schematic_hotspot_resolver_authorize();
	$outcome = dtb_schematic_hotspot_resolver_perform_action();
	$filters = dtb_schematic_hotspot_resolver_request_filters();
	$report  = dtb_schematic_hotspot_diagnostics_scan( $filters );

	ob_start();
	dtb_schematic_hotspot_resolver_render_app( $report, $filters, $outcome['message'], $outcome['ok'] ? 'success' : 'error' );
	$html = (string) ob_get_clean();

	wp_send_json_success( [ 'html' => $html, 'ok' => (bool) $outcome['ok'] ] );
}

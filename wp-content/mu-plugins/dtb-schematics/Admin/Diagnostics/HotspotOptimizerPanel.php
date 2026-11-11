<?php
/**
 * Temporary one-time hotspot optimizer panel and transport.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_NONCE_ACTION = 'dtb_schematic_hotspot_optimizer_run';
const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_CONFIRM_CODE = 'RUN_ONE_TIME_HOTSPOT_OPTIMIZER';

add_action( 'admin_notices', 'dtb_schematic_hotspot_optimizer_render_panel' );
add_action( 'admin_enqueue_scripts', 'dtb_schematic_hotspot_optimizer_enqueue_assets' );
add_action( 'admin_post_dtb_schematic_hotspot_optimizer_run', 'dtb_schematic_hotspot_optimizer_handle_run' );

/** Only load optimizer assets on the temporary resolver page. */
function dtb_schematic_hotspot_optimizer_enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG ) ) {
		return;
	}

	$css_path = __DIR__ . '/../assets/hotspot-optimizer.css';
	$js_path  = __DIR__ . '/../assets/hotspot-optimizer.js';
	if ( is_file( $css_path ) ) {
		wp_enqueue_style(
			'dtb-schematic-hotspot-optimizer',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/hotspot-optimizer.css' ),
			[],
			(string) filemtime( $css_path )
		);
	}
	if ( is_file( $js_path ) ) {
		wp_enqueue_script(
			'dtb-schematic-hotspot-optimizer',
			content_url( '/mu-plugins/dtb-schematics/Admin/assets/hotspot-optimizer.js' ),
			[],
			(string) filemtime( $js_path ),
			true
		);
	}
}

/** Render the optimizer control surface and the current operator's selected run result. */
function dtb_schematic_hotspot_optimizer_render_panel(): void {
	if ( ! dtb_schematics_can_manage() ) {
		return;
	}
	$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
	if ( DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG !== $page ) {
		return;
	}

	$run_id = sanitize_text_field( wp_unslash( $_GET['optimizer_run_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only run selection.
	$run    = '' !== $run_id ? dtb_schematic_operation_run_get_for_operator( $run_id, get_current_user_id() ) : null;
	$error  = sanitize_text_field( wp_unslash( $_GET['optimizer_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- escaped operator message.

	echo '<section id="dtb-hotspot-optimizer" class="dtb-hotspot-optimizer">';
	echo '<div class="dtb-hotspot-optimizer__head">';
	echo '<div><span class="dtb-hotspot-resolver__eyebrow">' . esc_html__( 'One-time synchronization optimizer', 'drywall-toolbox' ) . '</span><h2>' . esc_html__( 'Hotspot source → product resolution optimizer', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'Audits current brand source JSON, synchronizes normalized hotspot datasets, reruns deterministic WooCommerce resolution, collapses repeated unresolved identities, and outputs the remaining root causes and required fixes. It never rewrites protected catalog identifiers or auto-applies fuzzy matches.', 'drywall-toolbox' ) . '</p></div>';
	echo '<div class="dtb-hotspot-optimizer__actions">';
	dtb_schematic_hotspot_optimizer_render_form( true );
	dtb_schematic_hotspot_optimizer_render_form( false );
	echo '</div></div>';

	echo '<div class="dtb-hotspot-optimizer__contract"><strong>' . esc_html__( 'Commit contract', 'drywall-toolbox' ) . '</strong><span>' . esc_html__( 'The apply pass uses the existing process-wide schematic commit lease and existing migration/update services. It can synchronize schematic hotspot projections and apply exact SKU / brand+MPN / explicit compatibility resolutions only. Catalog products and protected identifiers remain untouched.', 'drywall-toolbox' ) . '</span></div>';

	if ( '' !== $error ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
	}
	if ( $run ) {
		dtb_schematic_hotspot_optimizer_render_result( $run );
	}
	echo '</section>';
}

/** Render preview/apply form. */
function dtb_schematic_hotspot_optimizer_render_form( bool $dry_run ): void {
	$label = $dry_run ? __( 'Preview full optimizer', 'drywall-toolbox' ) : __( 'Run one-time optimizer', 'drywall-toolbox' );
	$class = $dry_run ? 'button' : 'button button-primary';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dtb-hotspot-optimizer__form" data-optimizer-mode="' . esc_attr( $dry_run ? 'preview' : 'apply' ) . '">';
	echo '<input type="hidden" name="action" value="dtb_schematic_hotspot_optimizer_run">';
	echo '<input type="hidden" name="optimizer_mode" value="' . esc_attr( $dry_run ? 'preview' : 'apply' ) . '">';
	if ( ! $dry_run ) {
		echo '<input type="hidden" name="optimizer_confirm" value="' . esc_attr( DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_CONFIRM_CODE ) . '">';
	}
	wp_nonce_field( DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_NONCE_ACTION, '_dtb_hotspot_optimizer_nonce' );
	echo '<button type="submit" class="' . esc_attr( $class ) . '" data-idle-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</button>';
	echo '</form>';
}

/** Capability + nonce protected one-time run endpoint. */
function dtb_schematic_hotspot_optimizer_handle_run(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	check_admin_referer( DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_NONCE_ACTION, '_dtb_hotspot_optimizer_nonce' );

	$mode    = sanitize_key( wp_unslash( $_POST['optimizer_mode'] ?? '' ) );
	$dry_run = 'apply' !== $mode;
	if ( ! $dry_run ) {
		$confirmation = sanitize_text_field( wp_unslash( $_POST['optimizer_confirm'] ?? '' ) );
		if ( ! hash_equals( DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_CONFIRM_CODE, $confirmation ) ) {
			dtb_schematic_hotspot_optimizer_redirect_error( __( 'The one-time optimizer confirmation was missing or invalid.', 'drywall-toolbox' ) );
		}
	}

	$run = dtb_schematic_run_operation(
		[
			'kind'        => DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS,
			'dry_run'     => $dry_run,
			'all_records' => true,
			'per_page'    => DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE,
			'operator_id' => get_current_user_id(),
		]
	);
	if ( is_wp_error( $run ) ) {
		dtb_schematic_hotspot_optimizer_redirect_error( $run->get_error_message() );
	}

	wp_safe_redirect(
		add_query_arg(
			[
				'page'             => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
				'optimizer_run_id' => sanitize_text_field( (string) ( $run['id'] ?? '' ) ),
			],
			admin_url( 'admin.php' )
		) . '#dtb-hotspot-optimizer'
	);
	exit;
}

function dtb_schematic_hotspot_optimizer_redirect_error( string $message ): void {
	wp_safe_redirect(
		add_query_arg(
			[
				'page'            => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
				'optimizer_error' => sanitize_text_field( $message ),
			],
			admin_url( 'admin.php' )
		) . '#dtb-hotspot-optimizer'
	);
	exit;
}

/** Render a complete, bounded operator-facing optimizer report. */
function dtb_schematic_hotspot_optimizer_render_result( array $run ): void {
	$result  = (array) ( $run['result'] ?? [] );
	$metrics = (array) ( $result['metrics'] ?? [] );
	$failed  = 'failed' === (string) ( $run['status'] ?? '' );
	$mode    = ! empty( $run['dry_run'] ) ? __( 'Preview', 'drywall-toolbox' ) : __( 'Applied', 'drywall-toolbox' );

	echo '<div class="dtb-hotspot-optimizer__result">';
	echo '<div class="dtb-hotspot-optimizer__result-head"><div><span class="dtb-hotspot-optimizer__run-mode">' . esc_html( $mode ) . '</span><h3>' . esc_html__( 'Optimizer result', 'drywall-toolbox' ) . '</h3><code>' . esc_html( (string) ( $run['id'] ?? '' ) ) . '</code></div><span class="dtb-hotspot-optimizer__run-status dtb-hotspot-optimizer__run-status--' . esc_attr( $failed ? 'error' : 'ok' ) . '">' . esc_html( $failed ? __( 'Failed', 'drywall-toolbox' ) : __( 'Completed', 'drywall-toolbox' ) ) . '</span></div>';

	if ( ! empty( $run['error'] ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $run['error'] ) . '</p></div>';
	}

	$cards = [
		[ __( 'Records', 'drywall-toolbox' ), (int) ( $result['examined'] ?? 0 ) ],
		[ __( 'Source files', 'drywall-toolbox' ), (int) ( $metrics['source_files'] ?? 0 ) ],
		[ __( 'Parts interpreted', 'drywall-toolbox' ), (int) ( $metrics['source_parts'] ?? 0 ) ],
		[ __( 'Hotspots interpreted', 'drywall-toolbox' ), (int) ( $metrics['source_hotspots'] ?? 0 ) ],
		[ __( 'Drift before run', 'drywall-toolbox' ), (int) ( $metrics['source_drift_before'] ?? 0 ) ],
		[ __( 'Source errors', 'drywall-toolbox' ), (int) ( $metrics['source_read_errors'] ?? 0 ) ],
		[ __( 'Exactly resolvable', 'drywall-toolbox' ), (int) ( $metrics['exactly_resolvable'] ?? 0 ) ],
		[ ! empty( $run['dry_run'] ) ? __( 'Projected exact repairs', 'drywall-toolbox' ) : __( 'Applied exact repairs', 'drywall-toolbox' ), ! empty( $run['dry_run'] ) ? (int) ( $metrics['projected_exact_repairs'] ?? 0 ) : (int) ( $metrics['applied_exact_repairs'] ?? 0 ) ],
		[ __( 'Remaining unresolved', 'drywall-toolbox' ), (int) ( $metrics['remaining_unresolved'] ?? 0 ) ],
		[ __( 'Resolution groups', 'drywall-toolbox' ), (int) ( $metrics['resolution_groups'] ?? 0 ) ],
	];
	echo '<div class="dtb-hotspot-optimizer__metrics">';
	foreach ( $cards as $card ) {
		echo '<div><strong>' . esc_html( (string) $card[1] ) . '</strong><span>' . esc_html( $card[0] ) . '</span></div>';
	}
	echo '</div>';

	$reason_counts = (array) ( $result['reason_counts'] ?? [] );
	if ( $reason_counts ) {
		echo '<div class="dtb-hotspot-optimizer__reasons"><h4>' . esc_html__( 'Remaining root causes', 'drywall-toolbox' ) . '</h4><div class="dtb-hotspot-optimizer__reason-grid">';
		foreach ( $reason_counts as $code => $count ) {
			echo '<div><code>' . esc_html( (string) $code ) . '</code><strong>' . esc_html( (string) (int) $count ) . '</strong></div>';
		}
		echo '</div></div>';
	}

	$source_errors = (array) ( $result['source_errors'] ?? [] );
	if ( $source_errors ) {
		echo '<details class="dtb-hotspot-optimizer__errors"><summary>' . esc_html( sprintf( __( 'Source errors (%d)', 'drywall-toolbox' ), count( $source_errors ) ) ) . '</summary><ul>';
		foreach ( $source_errors as $item ) {
			echo '<li><code>' . esc_html( (string) ( $item['canonical_id'] ?? '' ) ) . '</code> — ' . esc_html( (string) ( $item['message'] ?? '' ) ) . '</li>';
		}
		echo '</ul></details>';
	}

	$groups = (array) ( $result['resolution_groups'] ?? [] );
	if ( $groups ) {
		echo '<div class="dtb-hotspot-optimizer__queue"><div class="dtb-hotspot-optimizer__queue-head"><div><h4>' . esc_html__( 'Resolution work queue', 'drywall-toolbox' ) . '</h4><p>' . esc_html__( 'Repeated identities are collapsed. Every row states why automatic resolution stopped and the next authoritative fix.', 'drywall-toolbox' ) . '</p></div></div>';
		echo '<div class="dtb-hotspot-optimizer__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Root cause', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Source identity', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Impact', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Candidate evidence', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Required resolution', 'drywall-toolbox' ) . '</th></tr></thead><tbody>';
		foreach ( $groups as $group ) {
			dtb_schematic_hotspot_optimizer_render_group_row( (array) $group );
		}
		echo '</tbody></table></div>';
		if ( ! empty( $result['groups_truncated'] ) ) {
			echo '<p class="description">' . esc_html__( 'The work queue reached its bounded display limit. Narrow the source problem set and rerun after the first repairs.', 'drywall-toolbox' ) . '</p>';
		}
		echo '</div>';
	}
	echo '</div>';
}

function dtb_schematic_hotspot_optimizer_render_group_row( array $group ): void {
	$identity_bits = array_filter(
		[
			(string) ( $group['brand'] ?? '' ),
			! empty( $group['sku'] ) ? 'SKU ' . $group['sku'] : '',
			! empty( $group['mpn'] ) ? 'MPN ' . $group['mpn'] : '',
		]
	);
	$candidates = (array) ( $group['candidates'] ?? [] );

	echo '<tr>';
	echo '<td><strong>' . esc_html( (string) ( $group['issue_label'] ?? '' ) ) . '</strong><br><code>' . esc_html( (string) ( $group['issue_code'] ?? '' ) ) . '</code></td>';
	echo '<td><strong>' . esc_html( (string) ( $group['title'] ?? $group['part_ref'] ?? '' ) ) . '</strong><br><span>' . esc_html( implode( ' · ', $identity_bits ) ) . '</span>' . ( ! empty( $group['part_ref'] ) ? '<br><code>' . esc_html( (string) $group['part_ref'] ) . '</code>' : '' ) . '</td>';
	echo '<td><strong>' . esc_html( sprintf( _n( '%d relationship', '%d relationships', (int) ( $group['relationship_count'] ?? 0 ), 'drywall-toolbox' ), (int) ( $group['relationship_count'] ?? 0 ) ) ) . '</strong><br><span>' . esc_html( sprintf( __( '%d hotspot occurrence(s)', 'drywall-toolbox' ), (int) ( $group['occurrences'] ?? 0 ) ) ) . '</span><br><code>' . esc_html( implode( ', ', (array) ( $group['schematics'] ?? [] ) ) ) . '</code></td>';
	echo '<td>';
	if ( ! $candidates ) {
		echo '<span class="dtb-hotspot-optimizer__none">' . esc_html__( 'No bounded candidate', 'drywall-toolbox' ) . '</span>';
	} else {
		foreach ( $candidates as $candidate ) {
			$bits = array_filter( [ (string) ( $candidate['brand'] ?? '' ), ! empty( $candidate['sku'] ) ? 'SKU ' . $candidate['sku'] : '', ! empty( $candidate['mpn'] ) ? 'MPN ' . $candidate['mpn'] : '' ] );
			echo '<div class="dtb-hotspot-optimizer__candidate"><strong>' . esc_html( (string) ( $candidate['name'] ?? '#' . (int) ( $candidate['id'] ?? 0 ) ) ) . '</strong><code>#' . esc_html( (string) (int) ( $candidate['id'] ?? 0 ) ) . '</code><span>' . esc_html( implode( ' · ', $bits ) ) . '</span></div>';
		}
	}
	echo '</td>';
	echo '<td>' . esc_html( (string) ( $group['recommended_fix'] ?? '' ) ) . '</td>';
	echo '</tr>';
}

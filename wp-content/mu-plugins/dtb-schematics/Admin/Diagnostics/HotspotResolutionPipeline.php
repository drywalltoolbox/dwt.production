<?php
/**
 * DTB Schematics — unified hotspot resolution pipeline UI/transport.
 *
 * One admin workflow: build -> review/export -> approve -> freshness check ->
 * apply -> verify/export. All domain writes remain delegated to application
 * services and the shared schematic operation boundary.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE = 'dtb_schematic_hotspot_pipeline';
const DTB_SCHEMATIC_HOTSPOT_PIPELINE_REVIEW_LIMIT = 150;

remove_action( 'admin_menu', 'dtb_schematic_hotspot_workflow_register_page', 6 );
remove_action( 'admin_post_dtb_schematic_hotspot_resolver_action', 'dtb_schematic_hotspot_resolver_handle_post' );
remove_action( 'wp_ajax_dtb_schematic_hotspot_resolver_action', 'dtb_schematic_hotspot_resolver_handle_ajax' );
remove_action( 'admin_post_dtb_schematic_hotspot_workflow_preview', 'dtb_schematic_hotspot_workflow_handle_preview' );
remove_action( 'admin_post_dtb_schematic_hotspot_workflow_apply', 'dtb_schematic_hotspot_workflow_handle_apply' );
remove_action( 'admin_post_dtb_schematic_hotspot_export_report', 'dtb_schematic_hotspot_workflow_export_report' );
add_action( 'admin_menu', 'dtb_schematic_hotspot_pipeline_register_page', 7 );
add_action( 'admin_post_dtb_hotspot_pipeline_build', 'dtb_schematic_hotspot_pipeline_build' );
add_action( 'admin_post_dtb_hotspot_pipeline_apply', 'dtb_schematic_hotspot_pipeline_apply' );
add_action( 'admin_post_dtb_hotspot_pipeline_export', 'dtb_schematic_hotspot_pipeline_export' );

function dtb_schematic_hotspot_pipeline_register_page(): void {
	if ( ! function_exists( 'dtb_register_admin_page' ) ) {
		return;
	}
	dtb_register_admin_page( [
		'library' => 'tools', 'slug' => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
		'title' => __( 'Hotspot Resolver', 'drywall-toolbox' ), 'menu_title' => __( 'Hotspot Resolver', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_schematics', 'callback' => 'dtb_schematic_hotspot_pipeline_render',
		'position' => 11, 'template' => 'tool', 'section' => 'Catalog Maintenance', 'menu_visible' => false,
	] );
}

function dtb_schematic_hotspot_pipeline_build(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	check_admin_referer( DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':build', '_dtb_pipeline_nonce' );
	$run = dtb_schematic_run_operation( [
		'kind' => DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS,
		'dry_run' => true,
		'all_records' => true,
		'per_page' => DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE,
		'operator_id' => get_current_user_id(),
	] );
	dtb_schematic_hotspot_pipeline_redirect_run( $run, 'plan_run_id' );
}

function dtb_schematic_hotspot_pipeline_apply(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	$run_id = sanitize_text_field( wp_unslash( $_POST['plan_run_id'] ?? '' ) );
	check_admin_referer( DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':apply:' . $run_id, '_dtb_pipeline_nonce' );
	$run = dtb_schematic_operation_run_get_for_operator( $run_id, get_current_user_id() );
	if ( ! is_array( $run ) || empty( $run['dry_run'] ) || 'completed' !== (string) ( $run['status'] ?? '' ) || ! empty( $run['error'] ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( 'The selected pre-apply plan is not eligible for Apply.' );
	}
	if ( '1' !== (string) ( $_POST['approve_plan'] ?? '' ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( 'Review and explicitly approve the complete plan before Apply.', $run_id );
	}

	$approved_plan = dtb_schematic_hotspot_build_resolution_plan( (array) ( $run['result'] ?? [] ) );
	if ( empty( $approved_plan['can_apply'] ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( 'Apply is blocked because the reviewed plan contains no complete deterministic mutation set or has integrity failures.', $run_id );
	}
	$approved_fp = strtolower( trim( (string) ( $approved_plan['fingerprint'] ?? '' ) ) );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $approved_fp ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( 'The reviewed plan does not contain a valid approval fingerprint. Build one new plan.', $run_id );
	}

	$apply = dtb_schematic_run_operation( [
		'kind' => DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS,
		'dry_run' => false,
		'all_records' => true,
		'per_page' => DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE,
		'operator_id' => get_current_user_id(),
		'approved_fingerprint' => $approved_fp,
	] );
	if ( is_wp_error( $apply ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( $apply->get_error_message(), $run_id );
	}
	wp_safe_redirect( add_query_arg( [
		'page' => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
		'plan_run_id' => $run_id,
		'apply_run_id' => sanitize_text_field( (string) ( $apply['id'] ?? '' ) ),
	], admin_url( 'admin.php' ) ) . '#dtb-hotspot-pipeline' );
	exit;
}

function dtb_schematic_hotspot_pipeline_export(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	$run_id = sanitize_text_field( wp_unslash( $_GET['run_id'] ?? '' ) );
	$type   = sanitize_key( (string) ( $_GET['artifact'] ?? 'audit_xlsx' ) );
	check_admin_referer( DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':export:' . $run_id, '_dtb_pipeline_nonce' );
	$run = dtb_schematic_operation_run_get_for_operator( $run_id, get_current_user_id() );
	if ( ! is_array( $run ) ) {
		wp_die( esc_html__( 'The requested resolver run is unavailable.', 'drywall-toolbox' ), 404 );
	}
	$payload = dtb_schematic_hotspot_plan_export_payload( $run );

	if ( 'audit_xlsx' === $type ) {
		$workbook = dtb_schematic_hotspot_resolution_workbook_create( $payload );
		if ( is_wp_error( $workbook ) ) {
			wp_die( esc_html( $workbook->get_error_message() ), 500 );
		}
		$path = (string) ( $workbook['path'] ?? '' );
		$filename = sanitize_file_name( (string) ( $workbook['filename'] ?? 'dtb-hotspot-resolution-audit.xlsx' ) );
		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The hotspot audit workbook could not be read after generation.', 'drywall-toolbox' ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $path );
		exit;
	}

	if ( 'report_json' === $type ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="dtb-hotspot-resolution-' . sanitize_file_name( $run_id ) . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	wp_die( esc_html__( 'Unsupported resolver artifact.', 'drywall-toolbox' ), 400 );
}

function dtb_schematic_hotspot_pipeline_render(): void {
	if ( ! dtb_schematics_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to manage schematics.', 'drywall-toolbox' ), 403 );
	}
	$plan_id   = sanitize_text_field( wp_unslash( $_GET['plan_run_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$apply_id  = sanitize_text_field( wp_unslash( $_GET['apply_run_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error     = sanitize_text_field( wp_unslash( $_GET['pipeline_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$plan_run  = '' !== $plan_id ? dtb_schematic_operation_run_get_for_operator( $plan_id, get_current_user_id() ) : null;
	$apply_run = '' !== $apply_id ? dtb_schematic_operation_run_get_for_operator( $apply_id, get_current_user_id() ) : null;

	echo '<main class="wrap dtb-hotspot-resolver" id="dtb-hotspot-pipeline">';
	echo '<header class="dtb-hotspot-resolver__hero"><div><span class="dtb-hotspot-resolver__eyebrow">' . esc_html__( 'Single controlled workflow', 'drywall-toolbox' ) . '</span><h1>' . esc_html__( 'Schematic Hotspot Resolution Pipeline', 'drywall-toolbox' ) . '</h1><p>' . esc_html__( 'Analyze authoritative schematic sources against live WooCommerce, classify every unresolved identity, generate one consolidated audit workbook, review the exact safe write set, approve once, then apply and verify.', 'drywall-toolbox' ) . '</p></div></header>';
	if ( '' !== $error ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
	}

	echo '<section class="dtb-hotspot-optimizer"><div class="dtb-hotspot-optimizer__head"><div><h2>' . esc_html__( '1. Build complete resolution plan', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'Read-only. Rebuild whenever schematic source data or WooCommerce product identity changes.', 'drywall-toolbox' ) . '</p></div>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-optimizer-mode="preview"><input type="hidden" name="action" value="dtb_hotspot_pipeline_build">';
	wp_nonce_field( DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':build', '_dtb_pipeline_nonce' );
	echo '<button class="button button-primary" type="submit">' . esc_html__( 'Build full resolution plan', 'drywall-toolbox' ) . '</button></form></div>';
	echo '<div class="dtb-hotspot-optimizer__contract"><strong>' . esc_html__( 'Authority boundary', 'drywall-toolbox' ) . '</strong><span>' . esc_html__( 'The pipeline may persist approved deterministic part relationships. It never creates products or rewrites SKU, MPN, GTIN, brand, source JSON, or repository catalog files.', 'drywall-toolbox' ) . '</span></div></section>';

	if ( ! $plan_run ) {
		echo '<div class="dtb-hotspot-resolver__empty"><p>' . esc_html__( 'Build the plan to generate the complete audit, deterministic mapping set, consolidated correction workbook, and bounded manual-review queue.', 'drywall-toolbox' ) . '</p></div></main>';
		return;
	}

	$plan = dtb_schematic_hotspot_build_resolution_plan( (array) ( $plan_run['result'] ?? [] ) );
	dtb_schematic_hotspot_pipeline_render_plan( $plan_run, $plan, true );
	if ( $apply_run ) {
		$post = dtb_schematic_hotspot_build_resolution_plan( (array) ( $apply_run['result'] ?? [] ) );
		echo '<hr><h2>' . esc_html__( '4. Post-apply result', 'drywall-toolbox' ) . '</h2>';
		dtb_schematic_hotspot_pipeline_render_plan( $apply_run, $post, false );
	}
	echo '</main>';
}

function dtb_schematic_hotspot_pipeline_render_plan( array $run, array $plan, bool $pre_apply ): void {
	$s = (array) ( $plan['summary'] ?? [] );
	$cards = [
		'Schematics' => 'schematics_examined',
		'Source parts' => 'source_parts',
		'Hotspots' => 'hotspot_occurrences',
		'Proposed mappings' => 'projected_new_mappings',
		'Applied mappings' => 'applied_new_mappings',
		'Active unresolved' => 'active_hotspot_unresolved',
		'Catalog-only unresolved' => 'catalog_only_unresolved',
		'Resolution groups' => 'resolution_groups',
	];
	echo '<section class="dtb-hotspot-optimizer"><h2>' . esc_html( $pre_apply ? '2. Review complete pre-apply plan' : 'Committed result' ) . '</h2><div class="dtb-hotspot-optimizer__metrics">';
	foreach ( $cards as $label => $key ) {
		echo '<div class="dtb-hotspot-optimizer__metric"><strong>' . esc_html( number_format_i18n( (int) ( $s[ $key ] ?? 0 ) ) ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}
	echo '</div>';

	if ( ! empty( $plan['blockers'] ) ) {
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Apply is intentionally unavailable.', 'drywall-toolbox' ) . '</strong> ' . esc_html__( 'Correct the listed authoritative source/integrity condition and rebuild this same plan.', 'drywall-toolbox' ) . '</p><ul>';
		foreach ( $plan['blockers'] as $blocker ) {
			echo '<li>' . esc_html( (string) ( $blocker['message'] ?? '' ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	dtb_schematic_hotspot_pipeline_render_dispositions( $plan );

	$repairs = (array) ( $plan['proposed_mappings'] ?? [] );
	echo '<h3>' . esc_html__( 'Deterministic mapping plan', 'drywall-toolbox' ) . '</h3>';
	if ( empty( $repairs ) ) {
		echo '<p>' . esc_html__( 'No new deterministic relationship writes are currently available. The consolidated audit workbook below contains the authoritative next work for this run.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Schematic</th><th>Source part</th><th>Target product</th><th>Method</th><th>Hotspots</th></tr></thead><tbody>';
		foreach ( array_slice( $repairs, 0, 250 ) as $repair ) {
			$product = (array) ( $repair['product'] ?? [] );
			echo '<tr><td>' . esc_html( (string) ( $repair['canonical_id'] ?? $repair['schematic_id'] ?? '' ) ) . '</td><td>' . esc_html( trim( (string) ( $repair['source_sku'] ?? '' ) . ' ' . (string) ( $repair['title'] ?? '' ) ) ) . '</td><td>' . esc_html( trim( '#' . (int) ( $repair['product_id'] ?? 0 ) . ' ' . (string) ( $product['sku'] ?? '' ) . ' ' . (string) ( $product['name'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $repair['resolution_method'] ?? '' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $repair['occurrences'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '<h3>' . esc_html__( 'Consolidated audit export', 'drywall-toolbox' ) . '</h3><p>' . esc_html__( 'One workbook contains the summary, full resolution plan, catalog corrections, source corrections, manual review, deterministic mappings, source audit, and run metadata. JSON remains available only as the lossless machine-readable representation of the same run.', 'drywall-toolbox' ) . '</p><div class="dtb-hotspot-optimizer__actions">';
	dtb_schematic_hotspot_pipeline_export_link( $run, 'audit_xlsx', 'Export audit workbook (.xlsx)', true );
	dtb_schematic_hotspot_pipeline_export_link( $run, 'report_json', 'Machine-readable JSON', false );
	echo '</div>';

	if ( $pre_apply ) {
		if ( ! empty( $plan['can_apply'] ) ) {
			echo '<section class="dtb-hotspot-resolver__bulk"><h2>' . esc_html__( '3. Approve & apply', 'drywall-toolbox' ) . '</h2><p>' . esc_html__( 'The shared operation runner acquires its commit lease, rebuilds the current authoritative plan, and aborts without writes unless the reviewed material fingerprint still matches exactly.', 'drywall-toolbox' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dtb_hotspot_pipeline_apply"><input type="hidden" name="plan_run_id" value="' . esc_attr( (string) ( $run['id'] ?? '' ) ) . '">';
			wp_nonce_field( DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':apply:' . (string) ( $run['id'] ?? '' ), '_dtb_pipeline_nonce' );
			echo '<label><input type="checkbox" name="approve_plan" value="1" required> ' . esc_html__( 'I reviewed the complete mapping plan and remediation dispositions.', 'drywall-toolbox' ) . '</label><p><button class="button button-primary" type="submit">' . esc_html__( 'Approve & apply resolution plan', 'drywall-toolbox' ) . '</button></p></form></section>';
		} elseif ( empty( $plan['blockers'] ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'No Apply action is necessary for this plan.', 'drywall-toolbox' ) . '</strong> ' . esc_html__( 'The engine found no new DTB-owned deterministic mapping mutation. Use the consolidated workbook when upstream owning data requires correction.', 'drywall-toolbox' ) . '</p></div>';
		}
	}
	echo '</section>';
}

function dtb_schematic_hotspot_pipeline_render_dispositions( array $plan ): void {
	$counts = (array) ( $plan['disposition_counts'] ?? [] );
	if ( empty( $counts ) ) {
		return;
	}
	echo '<h3>' . esc_html__( 'Resolution dispositions', 'drywall-toolbox' ) . '</h3><div class="dtb-hotspot-optimizer__metrics">';
	foreach ( $counts as $code => $count ) {
		$label = ucwords( str_replace( '_', ' ', (string) $code ) );
		echo '<div class="dtb-hotspot-optimizer__metric"><strong>' . esc_html( number_format_i18n( (int) $count ) ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}
	echo '</div>';

	$groups = (array) ( $plan['resolution_groups'] ?? [] );
	if ( empty( $groups ) ) {
		return;
	}
	echo '<details><summary><strong>' . esc_html__( 'Review prioritized unresolved groups', 'drywall-toolbox' ) . '</strong></summary><table class="widefat striped"><thead><tr><th>Disposition</th><th>Identity</th><th>Impact</th><th>Required action</th></tr></thead><tbody>';
	foreach ( array_slice( $groups, 0, DTB_SCHEMATIC_HOTSPOT_PIPELINE_REVIEW_LIMIT ) as $group ) {
		$identity = trim( (string) ( $group['brand'] ?? '' ) . ' · ' . (string) ( $group['source_sku'] ?? $group['sku'] ?? '' ) . ' · ' . (string) ( $group['part_name'] ?? $group['name'] ?? '' ) );
		$impact = sprintf( '%d relationship(s) · %d hotspot occurrence(s)', (int) ( $group['relationship_count'] ?? 0 ), (int) ( $group['occurrences'] ?? 0 ) );
		echo '<tr><td><code>' . esc_html( (string) ( $group['disposition'] ?? '' ) ) . '</code></td><td>' . esc_html( $identity ) . '</td><td>' . esc_html( $impact ) . '</td><td>' . esc_html( (string) ( $group['required_action'] ?? '' ) ) . '</td></tr>';
	}
	echo '</tbody></table></details>';
}

function dtb_schematic_hotspot_pipeline_export_link( array $run, string $artifact, string $label, bool $primary = false ): void {
	$url = wp_nonce_url( add_query_arg( [
		'action' => 'dtb_hotspot_pipeline_export',
		'run_id' => (string) ( $run['id'] ?? '' ),
		'artifact' => $artifact,
	], admin_url( 'admin-post.php' ) ), DTB_SCHEMATIC_HOTSPOT_PIPELINE_NONCE . ':export:' . (string) ( $run['id'] ?? '' ), '_dtb_pipeline_nonce' );
	$class = $primary ? 'button button-primary' : 'button';
	echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
}

function dtb_schematic_hotspot_pipeline_redirect_run( $run, string $arg ): void {
	if ( is_wp_error( $run ) ) {
		dtb_schematic_hotspot_pipeline_redirect_error( $run->get_error_message() );
	}
	wp_safe_redirect( add_query_arg( [
		'page' => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG,
		$arg => sanitize_text_field( (string) ( $run['id'] ?? '' ) ),
	], admin_url( 'admin.php' ) ) . '#dtb-hotspot-pipeline' );
	exit;
}

function dtb_schematic_hotspot_pipeline_redirect_error( string $message, string $plan_id = '' ): void {
	$args = [ 'page' => DTB_SCHEMATIC_HOTSPOT_RESOLVER_SLUG, 'pipeline_error' => sanitize_text_field( $message ) ];
	if ( '' !== $plan_id ) {
		$args['plan_run_id'] = $plan_id;
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#dtb-hotspot-pipeline' );
	exit;
}

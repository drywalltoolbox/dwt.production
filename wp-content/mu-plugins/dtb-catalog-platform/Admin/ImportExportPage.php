<?php
/**
 * DTB Catalog — ImportExportPage
 *
 * Renders dtb-import-export — product catalog CSV import/export.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_import_export_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_import_export' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'export' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base_url   = admin_url( 'admin.php?page=dtb-import-export' );

	$tabs = [
		[ 'id' => 'export', 'label' => __( 'Export', 'drywall-toolbox' ), 'active' => $active_tab === 'export', 'url' => add_query_arg( 'tab', 'export', $base_url ) ],
		[ 'id' => 'import', 'label' => __( 'Import', 'drywall-toolbox' ), 'active' => $active_tab === 'import', 'url' => add_query_arg( 'tab', 'import', $base_url ) ],
	];

	dtb_admin_shell_open( [
		'title'    => __( 'Import / Export', 'drywall-toolbox' ),
		'subtitle' => __( 'Export catalog data to CSV or import from external sources.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-import-export',
		'template' => 'tool',
		'icon'     => 'dashicons-migrate',
		'tabs'     => $tabs,
	] );

	if ( $active_tab === 'import' ) {
		dtb_import_export_render_import_tab();
	} else {
		dtb_import_export_render_export_tab();
	}

	dtb_admin_shell_close();
}

function dtb_import_export_render_export_tab(): void {
	if ( isset( $_POST['dtb_export_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['dtb_export_nonce'] ), 'dtb_export' ) ) {
		// Delegate to module if available.
		if ( function_exists( 'dtb_catalog_export_csv' ) ) {
			dtb_catalog_export_csv( sanitize_key( $_POST['dtb_export_type'] ?? 'all' ) );
		}
	}

	ob_start();
	echo '<form method="post">';
	echo wp_nonce_field( 'dtb_export', 'dtb_export_nonce', true, false );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_select( 'dtb_export_type', [
			'all'         => __( 'All Products', 'drywall-toolbox' ),
			'simple'      => __( 'Simple Products', 'drywall-toolbox' ),
			'variable'    => __( 'Variable Products', 'drywall-toolbox' ),
			'parts'       => __( 'Parts', 'drywall-toolbox' ),
			'schematics'  => __( 'Schematics', 'drywall-toolbox' ),
		] ),
		__( 'Export Type', 'drywall-toolbox' ),
		[]
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Export CSV', 'drywall-toolbox' ), [
		'type'    => 'primary',
		'attr'    => 'type="submit"',
		'icon'    => 'dashicons-download',
		'loading' => true,
	] );
	echo '</form>';
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Export Catalog', 'drywall-toolbox' ) ] );
}

function dtb_import_export_render_import_tab(): void {
	if ( isset( $_POST['dtb_import_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['dtb_import_nonce'] ), 'dtb_import' ) ) {
		if ( function_exists( 'dtb_catalog_import_csv' ) && ! empty( $_FILES['dtb_import_file']['tmp_name'] ) ) {
			$result = dtb_catalog_import_csv( $_FILES['dtb_import_file']['tmp_name'] );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo dtb_admin_ui_alert( $result->get_error_message(), 'danger' );
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo dtb_admin_ui_alert( sprintf( __( 'Import complete. %d products processed.', 'drywall-toolbox' ), (int) $result ), 'success' );
			}
		}
	}

	ob_start();
	echo '<form method="post" enctype="multipart/form-data">';
	echo wp_nonce_field( 'dtb_import', 'dtb_import_nonce', true, false );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		'<input type="file" name="dtb_import_file" accept=".csv" class="dtb-input">',
		__( 'CSV File', 'drywall-toolbox' ),
		[ 'description' => __( 'Upload a product catalog CSV. Must match the DTB export format.', 'drywall-toolbox' ) ]
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Import', 'drywall-toolbox' ), [
		'type'    => 'primary',
		'attr'    => 'type="submit"',
		'icon'    => 'dashicons-upload',
		'confirm' => __( 'This will update existing products and add new ones. Continue?', 'drywall-toolbox' ),
		'loading' => true,
	] );
	echo '</form>';
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Import Products', 'drywall-toolbox' ) ] );
}

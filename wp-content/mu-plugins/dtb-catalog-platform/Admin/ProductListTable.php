<?php
/**
 * Operational product-list columns for WooCommerce wp-admin.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keep the product list focused on high-value catalog and fulfillment data.
 *
 * @param array<string,string> $columns Existing product-list columns.
 * @return array<string,string>
 */
function dtb_catalog_product_list_columns( array $columns ): array {
	$updated = [];

	foreach ( [ 'cb', 'thumb', 'name', 'sku' ] as $key ) {
		if ( isset( $columns[ $key ] ) ) {
			$updated[ $key ] = $columns[ $key ];
		}
	}

	$updated['dtb_inventory'] = __( 'Stock', 'drywall-toolbox' );

	if ( isset( $columns['price'] ) ) {
		$updated['price'] = $columns['price'];
	}

	if ( isset( $columns['product_cat'] ) ) {
		$updated['product_cat'] = $columns['product_cat'];
	}

	$updated['dtb_brand']    = __( 'Brands', 'drywall-toolbox' );
	$updated['dtb_shipping'] = __( 'Shipping', 'drywall-toolbox' );
	$updated['dtb_updated']  = __( 'Updated', 'drywall-toolbox' );

	return $updated;
}
add_filter( 'manage_edit-product_columns', 'dtb_catalog_product_list_columns', 100 );

/**
 * Register sorting for operational product columns.
 *
 * @param array<string,string> $columns Sortable product-list columns.
 * @return array<string,string>
 */
function dtb_catalog_product_list_sortable_columns( array $columns ): array {
	$columns['dtb_inventory'] = 'stock';
	$columns['dtb_updated']   = 'modified';

	return $columns;
}
add_filter( 'manage_edit-product_sortable_columns', 'dtb_catalog_product_list_sortable_columns', 100 );

/**
 * Render DTB-owned product-list columns from WooCommerce's local projections.
 *
 * Inventory is intentionally read from WooCommerce. Veeqo remains authoritative
 * and reconciles into this checkout-facing projection asynchronously.
 *
 * @param string $column  Column key.
 * @param int    $post_id Product post ID.
 */
function dtb_catalog_render_product_list_column( string $column, int $post_id ): void {
	if ( ! in_array( $column, [ 'dtb_inventory', 'dtb_brand', 'dtb_shipping', 'dtb_updated' ], true ) ) {
		return;
	}

	if ( 'dtb_updated' === $column ) {
		$modified_time = get_post_modified_time( 'U', false, $post_id );
		if ( false === $modified_time ) {
			echo '<span class="dtb-product-cell-muted">&mdash;</span>';
			return;
		}

		printf(
			'<time datetime="%1$s"><strong>%2$s</strong><span class="dtb-product-cell-meta">%3$s</span></time>',
			esc_attr( get_post_modified_time( DATE_W3C, true, $post_id ) ),
			esc_html( get_post_modified_time( get_option( 'date_format' ), false, $post_id, true ) ),
			esc_html( get_post_modified_time( get_option( 'time_format' ), false, $post_id, true ) )
		);
		return;
	}

	$product = wc_get_product( $post_id );
	if ( ! $product ) {
		echo '<span class="dtb-product-cell-muted">&mdash;</span>';
		return;
	}

	if ( 'dtb_inventory' === $column ) {
		dtb_catalog_render_inventory_cell( $product );
		return;
	}

	if ( 'dtb_brand' === $column ) {
		dtb_catalog_render_brand_cell( $post_id );
		return;
	}

	dtb_catalog_render_shipping_cell( $product );
}
add_action( 'manage_product_posts_custom_column', 'dtb_catalog_render_product_list_column', 10, 2 );

/** Render current WooCommerce stock projection. */
function dtb_catalog_render_inventory_cell( WC_Product $product ): void {
	$status = $product->get_stock_status();
	$labels = [
		'instock'     => __( 'In stock', 'drywall-toolbox' ),
		'outofstock'  => __( 'Out of stock', 'drywall-toolbox' ),
		'onbackorder' => __( 'Backorder', 'drywall-toolbox' ),
	];
	$label = $labels[ $status ] ?? ucfirst( str_replace( '-', ' ', $status ) );

	printf(
		'<span class="dtb-stock-status dtb-stock-status--%1$s"><span aria-hidden="true"></span>%2$s</span>',
		esc_attr( $status ),
		esc_html( $label )
	);

	if ( $product->managing_stock() ) {
		printf(
			'<strong class="dtb-stock-quantity">%s</strong>',
			esc_html( sprintf( __( '%s available', 'drywall-toolbox' ), wc_stock_amount( $product->get_stock_quantity() ) ) )
		);
	} elseif ( $product->is_type( 'variable' ) ) {
		echo '<span class="dtb-product-cell-meta">' . esc_html__( 'Managed by variation', 'drywall-toolbox' ) . '</span>';
	} else {
		echo '<span class="dtb-product-cell-meta">' . esc_html__( 'Quantity not tracked', 'drywall-toolbox' ) . '</span>';
	}
}

/** Render native WooCommerce brand taxonomy terms. */
function dtb_catalog_render_brand_cell( int $post_id ): void {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		echo '<span class="dtb-product-cell-muted">&mdash;</span>';
		return;
	}

	$terms = get_the_terms( $post_id, 'product_brand' );
	if ( ! is_array( $terms ) || [] === $terms ) {
		echo '<span class="dtb-product-cell-muted">&mdash;</span>';
		return;
	}

	$links = [];
	foreach ( $terms as $term ) {
		$url     = add_query_arg(
			[
				'post_type'     => 'product',
				'product_brand' => $term->slug,
			],
			admin_url( 'edit.php' )
		);
		$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $term->name ) );
	}

	echo wp_kses_post( implode( ', ', $links ) );
}

/** Render the two shipping attributes most useful during catalog operations. */
function dtb_catalog_render_shipping_cell( WC_Product $product ): void {
	$weight = $product->get_weight();
	if ( '' !== $weight ) {
		echo '<strong class="dtb-shipping-weight">' . wp_kses_post( wc_format_weight( $weight ) ) . '</strong>';
	} else {
		echo '<span class="dtb-product-cell-muted">' . esc_html__( 'No weight', 'drywall-toolbox' ) . '</span>';
	}

	$shipping_class_id = $product->get_shipping_class_id();
	if ( $shipping_class_id > 0 ) {
		$shipping_class = get_term( $shipping_class_id, 'product_shipping_class' );
		if ( $shipping_class instanceof WP_Term ) {
			echo '<span class="dtb-product-cell-meta">' . esc_html( $shipping_class->name ) . '</span>';
			return;
		}
	}

	echo '<span class="dtb-product-cell-meta">' . esc_html__( 'No shipping class', 'drywall-toolbox' ) . '</span>';
}

/** Load product-list-only styles without affecting other wp-admin tables. */
function dtb_catalog_product_list_styles(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-product' !== $screen->id ) {
		return;
	}

	$asset_path = __DIR__ . '/assets/dtb-product-list-table.css';
	if ( ! file_exists( $asset_path ) ) {
		return;
	}

	wp_enqueue_style(
		'dtb-product-list-table',
		plugin_dir_url( __FILE__ ) . 'assets/dtb-product-list-table.css',
		[],
		(string) filemtime( $asset_path )
	);
}
add_action( 'admin_enqueue_scripts', 'dtb_catalog_product_list_styles' );

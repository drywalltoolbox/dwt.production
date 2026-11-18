<?php
/**
 * Batched, redacted read model for the Veeqo wp-admin control center.
 *
 * Interactive dashboard reads use WooCommerce/DTB projections. Live Veeqo
 * access is limited to explicit connection tests and exact-SKU inspection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Admin_Read_Model {
	private const LOW_STOCK_THRESHOLD = 3;

	public static function overview(): array {
		$diagnostics = defined( 'DTB_VEEQO_INVENTORY_DIAGNOSTICS' )
			? (array) get_option( DTB_VEEQO_INVENTORY_DIAGNOSTICS, [] )
			: [];
		$last_sync            = class_exists( 'DTB_VeeqoSyncJob' ) ? (int) DTB_VeeqoSyncJob::last_timestamp( 'inventory' ) : 0;
		$last_catalog_sync    = class_exists( 'DTB_VeeqoSyncJob' ) ? (int) DTB_VeeqoSyncJob::last_timestamp( 'catalog_weight' ) : 0;
		$catalog_diagnostics  = defined( 'DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS' )
			? (array) get_option( DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS, [] )
			: [];
		$last_order_poll = class_exists( 'DTB_VeeqoSyncJob' ) ? (int) DTB_VeeqoSyncJob::last_timestamp( 'order_status_poll' ) : 0;
		$poll_state      = defined( 'DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION' )
			? (array) get_option( DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION, [] )
			: [];
		$active          = class_exists( 'DTB_Veeqo_Operation_Store' ) ? DTB_Veeqo_Operation_Store::active() : [];

		return [
			'readiness' => function_exists( 'dtb_veeqo_runtime_readiness' )
				? dtb_veeqo_runtime_readiness()
				: [ 'order_projection_ready' => false, 'inventory_ready' => false, 'webhooks_enabled' => false ],
			'configuration' => self::settings(),
			'inventory'     => self::inventory_summary(),
			'orders'        => self::order_summary(),
			'sync'          => [
				'last_inventory_sync_at' => $last_sync > 0 ? gmdate( 'c', $last_sync ) : null,
				'stale'                  => $last_sync <= 0 || ( time() - $last_sync ) > 2 * HOUR_IN_SECONDS,
				'diagnostics'            => self::diagnostic_summary( $diagnostics ),
				// No "stale" flag here on purpose: this job is on-demand, not scheduled
				// (see VeeqoCatalogWeightSyncService.php), so there's no expected
				// recency to measure it against — it's just "when did we last run it."
				'last_catalog_weight_sync_at' => $last_catalog_sync > 0 ? gmdate( 'c', $last_catalog_sync ) : null,
				'catalog_weight_sync_diagnostics' => [
					'completed_at'        => sanitize_text_field( (string) ( $catalog_diagnostics['completed_at'] ?? '' ) ),
					'partial'             => ! empty( $catalog_diagnostics['partial'] ),
					'pages'               => absint( $catalog_diagnostics['pages'] ?? 0 ),
					'sellables_seen'      => absint( $catalog_diagnostics['sellables_seen'] ?? 0 ),
					'updated'             => absint( $catalog_diagnostics['updated'] ?? 0 ),
					'unchanged'           => absint( $catalog_diagnostics['unchanged'] ?? 0 ),
					'mapped'              => absint( $catalog_diagnostics['mapped'] ?? 0 ),
					'unmapped_count'      => count( (array) ( $catalog_diagnostics['unmapped_skus'] ?? [] ) ),
					'duplicate_count'     => count( (array) ( $catalog_diagnostics['duplicate_skus'] ?? [] ) ),
					'push_failure_count'  => count( (array) ( $catalog_diagnostics['push_failures'] ?? [] ) ),
				],
				'last_order_status_poll_at' => $last_order_poll > 0 ? gmdate( 'c', $last_order_poll ) : null,
				'order_status_poll_state'   => sanitize_key( (string) ( $poll_state['status'] ?? 'idle' ) ),
				'order_status_poll_next_at' => ! empty( $poll_state['next_run_at'] ) ? gmdate( 'c', (int) $poll_state['next_run_at'] ) : null,
				'order_status_poll_expired_count' => absint( $poll_state['expired_count'] ?? 0 ),
				'order_status_poll_stale'   => in_array( (string) ( $poll_state['status'] ?? '' ), [ 'active', 'running', 'scheduled' ], true )
					&& (int) ( $poll_state['updated_at'] ?? 0 ) < time() - 3 * HOUR_IN_SECONDS,
			],
			'active_operation'  => empty( $active ) || ! class_exists( 'DTB_Veeqo_Operation_Store' ) ? null : DTB_Veeqo_Operation_Store::summary( $active ),
			'recent_operations' => class_exists( 'DTB_Veeqo_Operation_Store' ) ? DTB_Veeqo_Operation_Store::recent() : [],
		];
	}

	public static function settings(): array {
		$config      = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
		$diagnostics = defined( 'DTB_VEEQO_CONFIGURATION_DIAGNOSTICS_OPTION' )
			? (array) get_option( DTB_VEEQO_CONFIGURATION_DIAGNOSTICS_OPTION, [] )
			: [];
		return [
			'api_key_configured' => function_exists( 'dtb_veeqo_production_api_key_configured' ) && dtb_veeqo_production_api_key_configured(),
			'channels_discovered' => ! empty( $diagnostics['channels_discovered'] ),
			'order_channels_eligible' => absint( $diagnostics['eligible_order_channels'] ?? 0 ),
			'channel_id'         => absint( $config['channel_id'] ?? 0 ),
			'warehouse_id'       => absint( $config['warehouse_id'] ?? 0 ),
			'delivery_method_id' => absint( $config['delivery_method_id'] ?? 0 ),
			'sources'             => [
				'channel_id'         => defined( 'DTB_VEEQO_CHANNEL_ID' ) && (int) DTB_VEEQO_CHANNEL_ID > 0 ? 'server_constant' : 'wordpress_option',
				'warehouse_id'       => defined( 'DTB_VEEQO_WAREHOUSE_ID' ) && (int) DTB_VEEQO_WAREHOUSE_ID > 0 ? 'server_constant' : 'wordpress_option',
				'delivery_method_id' => defined( 'DTB_VEEQO_DELIVERY_METHOD_ID' ) && (int) DTB_VEEQO_DELIVERY_METHOD_ID > 0 ? 'server_constant' : 'wordpress_option',
			],
			'readiness'           => function_exists( 'dtb_veeqo_production_readiness' ) ? dtb_veeqo_production_readiness() : [ 'ready' => false ],
			'last_validation'     => [
				'checked_at' => sanitize_text_field( (string) ( $diagnostics['checked_at'] ?? '' ) ),
				'ready'      => ! empty( $diagnostics['ready'] ),
				'errors'     => array_values( array_map( 'sanitize_text_field', (array) ( $diagnostics['errors'] ?? [] ) ) ),
			],
			'candidates'          => [
				'channels'         => self::sanitize_candidates( (array) ( $diagnostics['channel_candidates'] ?? [] ), true ),
				'warehouses'       => self::sanitize_candidates( (array) ( $diagnostics['warehouse_candidates'] ?? [] ) ),
				'delivery_methods' => self::sanitize_candidates( (array) ( $diagnostics['delivery_candidates'] ?? [] ) ),
			],
		];
	}

	public static function save_settings( array $input ) {
		if ( array_key_exists( 'channel_id', $input ) && absint( $input['channel_id'] ) > 0 ) {
			$diagnostics = (array) get_option( 'dtb_veeqo_configuration_diagnostics', [] );
			$eligible = [];
			foreach ( (array) ( $diagnostics['channel_candidates'] ?? [] ) as $candidate ) {
				if ( is_array( $candidate ) && 'direct' === (string) ( $candidate['type_code'] ?? '' ) ) {
					$eligible[] = absint( $candidate['id'] ?? 0 );
				}
			}
			if ( ! in_array( absint( $input['channel_id'] ), $eligible, true ) ) {
				return new WP_Error( 'veeqo_channel_not_eligible', 'The selected channel has not been validated as a Direct channel for API order export. Discover resources and select an eligible channel.', [ 'status' => 422 ] );
			}
		}

		$settings = (array) get_option( 'woocommerce_dtb_veeqo_settings', [] );
		$fields   = [ 'channel_id', 'warehouse_id', 'delivery_method_id' ];
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue; // Omitted or server-locked controls must not erase stored IDs.
			}
			$constant = 'DTB_VEEQO_' . strtoupper( $field );
			if ( defined( $constant ) && (int) constant( $constant ) > 0 ) {
				continue;
			}
			$settings[ $field ] = absint( $input[ $field ] ?? 0 );
		}
		unset( $settings['api_key'], $settings['webhook_secret'] );
		update_option( 'woocommerce_dtb_veeqo_settings', $settings, false );
		unset( $GLOBALS['_dtb_veeqo_config'] );
		// A changed warehouse_id invalidates the checkout rate-shopping
		// origin-address cache (DTB_VeeqoShippingService::warehouse_origin_address())
		// immediately rather than waiting up to an hour for it to expire.
		delete_transient( 'dtb_veeqo_warehouse_origin_address' );
		return self::settings();
	}

	public static function inventory( array $args ): array {
		global $wpdb;
		$page      = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page  = min( 100, max( 10, absint( $args['per_page'] ?? 50 ) ) );
		$offset    = ( $page - 1 ) * $per_page;
		$search    = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$stock     = sanitize_key( (string) ( $args['stock_status'] ?? '' ) );
		$mapping   = sanitize_key( (string) ( $args['mapping'] ?? '' ) );
		$type      = sanitize_key( (string) ( $args['type'] ?? '' ) );
		$order     = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
		$orderby   = sanitize_key( (string) ( $args['orderby'] ?? 'sku' ) );
		$order_map = [
			'name'           => 'product_name',
			'sku'            => 'lookup.sku',
			'stock_quantity' => 'lookup.stock_quantity',
			'stock_status'   => 'lookup.stock_status',
			'updated'        => 'p.post_modified_gmt',
		];
		$order_column = $order_map[ $orderby ] ?? $order_map['sku'];

		$sellable_product_filter = "(p.post_type = 'product_variation' OR (p.post_type = 'product' AND EXISTS (
			SELECT 1
			FROM {$wpdb->term_relationships} product_type_relationship
			INNER JOIN {$wpdb->term_taxonomy} product_type_taxonomy
				ON product_type_taxonomy.term_taxonomy_id = product_type_relationship.term_taxonomy_id
			INNER JOIN {$wpdb->terms} product_type_term
				ON product_type_term.term_id = product_type_taxonomy.term_id
			WHERE product_type_relationship.object_id = p.ID
				AND product_type_taxonomy.taxonomy = 'product_type'
				AND product_type_term.slug = 'simple'
		)))";
		$where  = [ $sellable_product_filter, "p.post_status NOT IN ('trash','auto-draft')", "lookup.sku <> ''" ];
		$params = [];
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = "(COALESCE(NULLIF(p.post_title, ''), parent.post_title) LIKE %s OR lookup.sku LIKE %s)";
			$params[] = $like;
			$params[] = $like;
		}
		if ( in_array( $stock, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			$where[]  = 'lookup.stock_status = %s';
			$params[] = $stock;
		} elseif ( 'lowstock' === $stock ) {
			$where[]  = 'lookup.stock_quantity > 0 AND lookup.stock_quantity <= %d';
			$params[] = self::LOW_STOCK_THRESHOLD;
		}
		if ( 'mapped' === $mapping ) {
			$where[] = "sellable.meta_value IS NOT NULL AND sellable.meta_value <> ''";
		} elseif ( 'unmapped' === $mapping ) {
			$where[] = "(sellable.meta_value IS NULL OR sellable.meta_value = '')";
		}
		if ( 'simple' === $type ) {
			$where[] = "p.post_type = 'product'";
		} elseif ( 'variation' === $type ) {
			$where[] = "p.post_type = 'product_variation'";
		}

		$sellable_meta = "(SELECT post_id, MAX(meta_value) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_veeqo_sellable_id' GROUP BY post_id)";
		$mapped_meta   = "(SELECT post_id, MAX(meta_value) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_veeqo_mapped_sku' GROUP BY post_id)";
		$thumb_meta    = "(SELECT post_id, MAX(meta_value) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' GROUP BY post_id)";
		$from = "FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
			INNER JOIN {$wpdb->prefix}wc_product_meta_lookup lookup ON lookup.product_id = p.ID
			LEFT JOIN {$sellable_meta} sellable ON sellable.post_id = p.ID
			LEFT JOIN {$mapped_meta} mapped ON mapped.post_id = p.ID
			LEFT JOIN {$thumb_meta} thumb ON thumb.post_id = p.ID";
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) {$from} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, ...$params ) );

		$select_sql = "SELECT p.ID, p.post_parent, COALESCE(NULLIF(p.post_title, ''), parent.post_title) AS product_name,
			p.post_type, p.post_status, p.post_modified_gmt, lookup.sku, lookup.stock_quantity, lookup.stock_status,
			sellable.meta_value AS veeqo_sellable_id, mapped.meta_value AS veeqo_mapped_sku, thumb.meta_value AS thumbnail_id
			{$from} WHERE {$where_sql} ORDER BY {$order_column} {$order}, p.ID ASC LIMIT %d OFFSET %d";
		$select_params = array_merge( $params, [ $per_page, $offset ] );
		$rows          = $wpdb->get_results( $wpdb->prepare( $select_sql, ...$select_params ), ARRAY_A );

		$items = [];
		foreach ( (array) $rows as $row ) {
			$product_id = absint( $row['ID'] ?? 0 );
			$items[] = [
				'product_id'        => $product_id,
				'parent_id'         => absint( $row['post_parent'] ?? 0 ),
				'name'              => sanitize_text_field( (string) ( $row['product_name'] ?? '' ) ),
				'type'              => 'product_variation' === (string) ( $row['post_type'] ?? '' ) ? 'variation' : 'simple',
				'publish_status'    => sanitize_key( (string) ( $row['post_status'] ?? '' ) ),
				'sku'               => sanitize_text_field( (string) ( $row['sku'] ?? '' ) ),
				'on_hand'           => is_numeric( $row['stock_quantity'] ?? null ) ? (int) $row['stock_quantity'] : null,
				'committed'         => null,
				'available'         => is_numeric( $row['stock_quantity'] ?? null ) ? (int) $row['stock_quantity'] : null,
				'incoming'          => null,
				'stock_status'      => sanitize_key( (string) ( $row['stock_status'] ?? '' ) ),
				'veeqo_sellable_id' => absint( $row['veeqo_sellable_id'] ?? 0 ) ?: null,
				'veeqo_mapped_sku'  => sanitize_text_field( (string) ( $row['veeqo_mapped_sku'] ?? '' ) ),
				'mapping_status'    => absint( $row['veeqo_sellable_id'] ?? 0 ) > 0 ? 'mapped' : 'unmapped',
				'image_url'         => absint( $row['thumbnail_id'] ?? 0 ) > 0 ? (string) wp_get_attachment_image_url( absint( $row['thumbnail_id'] ), 'thumbnail' ) : '',
				'updated_at'        => sanitize_text_field( (string) ( $row['post_modified_gmt'] ?? '' ) ),
				'edit_url'          => $product_id > 0 ? get_edit_post_link( $product_id, 'raw' ) : '',
			];
		}

		return [
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
			'items'    => $items,
			'source'   => 'woocommerce_projection',
		];
	}

	public static function inventory_summary(): array {
		global $wpdb;
		$sellable_meta = "(SELECT post_id, MAX(meta_value) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_veeqo_sellable_id' GROUP BY post_id)";
		$sellable_product_filter = "(p.post_type = 'product_variation' OR (p.post_type = 'product' AND EXISTS (
			SELECT 1
			FROM {$wpdb->term_relationships} product_type_relationship
			INNER JOIN {$wpdb->term_taxonomy} product_type_taxonomy
				ON product_type_taxonomy.term_taxonomy_id = product_type_relationship.term_taxonomy_id
			INNER JOIN {$wpdb->terms} product_type_term
				ON product_type_term.term_id = product_type_taxonomy.term_id
			WHERE product_type_relationship.object_id = p.ID
				AND product_type_taxonomy.taxonomy = 'product_type'
				AND product_type_term.slug = 'simple'
		)))";
		$sql = "SELECT
			COUNT(*) AS total,
			SUM(CASE WHEN lookup.stock_status = 'instock' THEN 1 ELSE 0 END) AS in_stock,
			SUM(CASE WHEN lookup.stock_quantity > 0 AND lookup.stock_quantity <= %d THEN 1 ELSE 0 END) AS low_stock,
			SUM(CASE WHEN lookup.stock_status = 'outofstock' THEN 1 ELSE 0 END) AS out_of_stock,
			SUM(CASE WHEN sellable.meta_value IS NULL OR sellable.meta_value = '' THEN 1 ELSE 0 END) AS unmapped
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->prefix}wc_product_meta_lookup lookup ON lookup.product_id = p.ID
			LEFT JOIN {$sellable_meta} sellable ON sellable.post_id = p.ID
			WHERE {$sellable_product_filter} AND p.post_status NOT IN ('trash','auto-draft') AND lookup.sku <> ''";
		$row = (array) $wpdb->get_row( $wpdb->prepare( $sql, self::LOW_STOCK_THRESHOLD ), ARRAY_A );
		return [
			'total'        => absint( $row['total'] ?? 0 ),
			'in_stock'     => absint( $row['in_stock'] ?? 0 ),
			'low_stock'    => absint( $row['low_stock'] ?? 0 ),
			'out_of_stock' => absint( $row['out_of_stock'] ?? 0 ),
			'unmapped'     => absint( $row['unmapped'] ?? 0 ),
		];
	}

	public static function orders( array $args, bool $fulfillment_only = false ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 'page' => 1, 'per_page' => 25, 'total' => 0, 'pages' => 1, 'items' => [], 'error' => 'WooCommerce order APIs are unavailable.' ];
		}
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 10, absint( $args['per_page'] ?? 25 ) ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$search   = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$query = [ 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ];
		if ( '' !== $status && 'all' !== $status ) {
			$query['status'] = [ $status ];
		}
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$query['include'] = [ absint( $search ) ];
			} elseif ( false !== strpos( $search, '@' ) ) {
				$query['billing_email'] = sanitize_email( $search );
			} else {
				$query['search'] = $search;
			}
		}
		if ( $fulfillment_only ) {
			$query['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => '_dtb_veeqo_order_id', 'compare' => 'EXISTS' ],
				[ 'key' => '_veeqo_order_id', 'compare' => 'EXISTS' ],
				[ 'key' => '_dtb_veeqo_sync_status', 'compare' => 'EXISTS' ],
			];
		}
		try {
			$result = wc_get_orders( $query );
		} catch ( Throwable $throwable ) {
			return [
				'page' => $page, 'per_page' => $per_page, 'total' => 0, 'pages' => 1, 'items' => [],
				'error' => sanitize_text_field( $throwable->getMessage() ),
			];
		}
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
		$items  = [];
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$items[] = self::order_row( $order );
			}
		}
		$total = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $items );
		$pages = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
		return [ 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => max( 1, $pages ), 'items' => $items ];
	}

	public static function order_summary(): array {
		$statuses = [ 'processing', 'shipped', 'on-hold', 'completed', 'failed', 'cancelled' ];
		$counts   = [];
		foreach ( $statuses as $status ) {
			$counts[ str_replace( '-', '_', $status ) ] = function_exists( 'wc_orders_count' ) ? absint( wc_orders_count( $status ) ) : 0;
		}
		return $counts;
	}

	public static function exact_sku( string $sku ) {
		$sku = trim( sanitize_text_field( $sku ) );
		if ( '' === $sku || strlen( $sku ) > 100 ) {
			return new WP_Error( 'invalid_sku', 'Enter a valid exact SKU.', [ 'status' => 400 ] );
		}
		$woo_id  = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
		$product = $woo_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $woo_id ) : null;
		$woo = null;
		if ( $product instanceof WC_Product ) {
			$woo = [
				'product_id' => $product->get_id(),
				'name' => $product->get_name(),
				'sku' => $product->get_sku(),
				'manage_stock' => $product->managing_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status' => $product->get_stock_status(),
				'veeqo_sellable_id' => absint( $product->get_meta( '_veeqo_sellable_id', true ) ) ?: null,
				'edit_url' => get_edit_post_link( $product->get_id(), 'raw' ),
			];
		}
		$veeqo = [];
		if ( function_exists( 'dtb_veeqo_request' ) ) {
			$response = dtb_veeqo_request( 'GET', '/products', [ 'query' => $sku, 'page' => '1', 'page_size' => '20' ] );
			if ( ! empty( $response['ok'] ) && is_array( $response['data'] ?? null ) ) {
				foreach ( $response['data'] as $veeqo_product ) {
					if ( ! is_array( $veeqo_product ) ) {
						continue;
					}
					foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
						if ( ! is_array( $sellable ) || $sku !== trim( (string) ( $sellable['sku_code'] ?? '' ) ) ) {
							continue;
						}
						$entries = [];
						foreach ( (array) ( $sellable['stock_entries'] ?? [] ) as $entry ) {
							if ( ! is_array( $entry ) ) {
								continue;
							}
							$entries[] = [
								'warehouse_id' => absint( $entry['warehouse_id'] ?? ( $entry['warehouse']['id'] ?? 0 ) ),
								'warehouse_name' => sanitize_text_field( (string) ( $entry['warehouse']['name'] ?? $entry['warehouse_name'] ?? '' ) ),
								'available' => array_key_exists( 'available_stock_level', $entry ) && is_numeric( $entry['available_stock_level'] ) ? (int) $entry['available_stock_level'] : ( array_key_exists( 'available_stock', $entry ) && is_numeric( $entry['available_stock'] ) ? (int) $entry['available_stock'] : null ),
								'on_hand' => array_key_exists( 'physical_stock_level', $entry ) && is_numeric( $entry['physical_stock_level'] ) ? (int) $entry['physical_stock_level'] : null,
								'committed' => array_key_exists( 'allocated_stock_level', $entry ) && is_numeric( $entry['allocated_stock_level'] ) ? (int) $entry['allocated_stock_level'] : null,
								'infinite' => ! empty( $entry['infinite'] ),
							];
						}
						$veeqo[] = [
							'product_id' => absint( $veeqo_product['id'] ?? 0 ),
							'product_name' => sanitize_text_field( (string) ( $veeqo_product['title'] ?? $veeqo_product['name'] ?? '' ) ),
							'sellable_id' => absint( $sellable['id'] ?? 0 ),
							'sku' => $sku,
							'stock_entries' => $entries,
						];
					}
				}
			}
		}
		return [ 'sku' => $sku, 'woo' => $woo, 'veeqo' => $veeqo ];
	}

	private static function order_row( WC_Order $order ): array {
		$veeqo_id    = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) ?: $order->get_meta( '_veeqo_order_id', true ) );
		$sync_status = sanitize_key( (string) $order->get_meta( '_dtb_veeqo_sync_status', true ) );
		$tracking    = sanitize_text_field( (string) ( $order->get_meta( '_tracking_number', true ) ?: $order->get_meta( '_dtb_veeqo_tracking_number', true ) ) );
		$carrier     = sanitize_text_field( (string) ( $order->get_meta( '_tracking_carrier', true ) ?: $order->get_meta( '_dtb_veeqo_carrier', true ) ) );
		$fulfillment = '' !== $tracking ? 'shipped' : ( $veeqo_id > 0 ? 'in_veeqo' : ( 'failed' === $sync_status ? 'failed' : ( 'queued' === $sync_status ? 'queued' : 'not_projected' ) ) );
		$date        = $order->get_date_created();
		return [
			'order_id' => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'created_at' => $date ? $date->date( DATE_ATOM ) : null,
			'status' => $order->get_status(),
			'customer' => sanitize_text_field( trim( $order->get_formatted_billing_full_name() ) ),
			'email' => sanitize_email( $order->get_billing_email() ),
			'total' => (string) $order->get_total(),
			'currency' => $order->get_currency(),
			'item_count' => $order->get_item_count(),
			'veeqo_order_id' => $veeqo_id ?: null,
			'sync_status' => $sync_status ?: 'not_started',
			'sync_error' => sanitize_text_field( (string) $order->get_meta( '_dtb_veeqo_sync_error', true ) ),
			'last_attempt_at' => sanitize_text_field( (string) $order->get_meta( '_dtb_veeqo_last_sync_attempt_at', true ) ),
			'last_synced_at' => sanitize_text_field( (string) $order->get_meta( '_dtb_veeqo_last_synced_at', true ) ),
			'fulfillment_status' => $fulfillment,
			'tracking_number' => $tracking,
			'carrier' => $carrier,
			'edit_url' => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
		];
	}

	private static function diagnostic_summary( array $diagnostics ): array {
		return [
			'completed_at' => sanitize_text_field( (string) ( $diagnostics['completed_at'] ?? '' ) ),
			'paused_at' => sanitize_text_field( (string) ( $diagnostics['paused_at'] ?? '' ) ),
			'partial' => ! empty( $diagnostics['partial'] ),
			'pages' => absint( $diagnostics['pages'] ?? 0 ),
			'sellables_seen' => absint( $diagnostics['sellables_seen'] ?? 0 ),
			'updated' => absint( $diagnostics['updated'] ?? 0 ),
			'unchanged' => absint( $diagnostics['unchanged'] ?? 0 ),
			'unmapped_count' => count( (array) ( $diagnostics['unmapped_skus'] ?? [] ) ),
			'duplicate_count' => count( (array) ( $diagnostics['duplicate_skus'] ?? [] ) ),
			'missing_warehouse_count' => count( (array) ( $diagnostics['missing_warehouse_entries'] ?? [] ) ),
			'invalid_stock_count' => count( (array) ( $diagnostics['invalid_stock_entries'] ?? [] ) ),
		];
	}

	private static function sanitize_candidates( array $items, bool $include_channel_type = false ): array {
		$rows = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || absint( $item['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$row = [ 'id' => absint( $item['id'] ), 'name' => sanitize_text_field( (string) ( $item['name'] ?? 'Veeqo resource' ) ) ];
			if ( $include_channel_type ) {
				$row['type_code'] = sanitize_key( (string) ( $item['type_code'] ?? '' ) );
				$row['order_export_eligible'] = 'direct' === $row['type_code'];
			}
			$rows[] = $row;
		}
		return $rows;
	}
}

<?php
/**
 * DTB_MetaBackfillTool
 *
 * Backfills canonical _dtb_* product meta fields on existing WooCommerce
 * products using the same resolution logic as the live catalog normalizer.
 *
 * Exposed as:
 *   WP-CLI:  wp dtb catalog backfill-meta [--dry-run] [--batch=50]
 *   Admin AJAX: action=dtb_catalog_meta_backfill (dtb_admin_ops cap required)
 *
 * The tool is idempotent: it writes missing values and performs only explicitly
 * allowlisted canonical-family repairs for known historical misclassifications.
 * Other existing non-empty values are preserved unless --force is passed.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// WP-CLI command registration
// =============================================================================

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'init', 'dtb_register_cli_backfill', 99 );
}

function dtb_register_cli_backfill(): void {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	WP_CLI::add_command( 'dtb catalog backfill-meta', [ DTB_MetaBackfillTool::class, 'cli_command' ] );
}

// =============================================================================
// Admin AJAX handler
// =============================================================================

add_action( 'wp_ajax_dtb_catalog_meta_backfill',      'dtb_catalog_meta_backfill_ajax' );
add_action( 'wp_ajax_dtb_catalog_meta_backfill_page', 'dtb_catalog_meta_backfill_ajax' );

function dtb_catalog_meta_backfill_ajax(): void {
	check_ajax_referer( 'dtb_catalog_meta_backfill', 'nonce' );

	// DTB_CAP_CATALOG is defined in dtb-ops-dashboard.php, which loads before
	// this file via 00-dtb-loader.php.  The fallback covers edge-case load orders.
	$cap = defined( 'DTB_CAP_CATALOG' ) ? DTB_CAP_CATALOG : 'manage_woocommerce';
	if ( ! current_user_can( $cap ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$page     = max( 1, absint( $_POST['page']     ?? 1 ) );
	$per_page = max( 1, min( 100, absint( $_POST['per_page'] ?? 50 ) ) );
	$dry_run  = ! empty( $_POST['dry_run'] );
	$force    = ! empty( $_POST['force'] );

	$result = DTB_MetaBackfillTool::run_page( $page, $per_page, $dry_run, $force );
	wp_send_json_success( $result );
}

// =============================================================================
// Tool class
// =============================================================================

final class DTB_MetaBackfillTool {

	/**
	 * WP-CLI entry point.
	 *
	 * Usage:
	 *   wp dtb catalog backfill-meta
	 *   wp dtb catalog backfill-meta --dry-run
	 *   wp dtb catalog backfill-meta --batch=25 --force
	 *
	 * @param  array $args
	 * @param  array $assoc_args
	 */
	public static function cli_command( array $args, array $assoc_args ): void {
		$dry_run  = isset( $assoc_args['dry-run'] );
		$batch    = max( 1, min( 200, absint( $assoc_args['batch'] ?? 50 ) ) );
		$force    = isset( $assoc_args['force'] );

		WP_CLI::log( sprintf(
			'DTB Meta Backfill — %s. Batch size: %d.',
			$dry_run ? 'DRY RUN (no writes)' : 'COMMIT MODE',
			$batch
		) );

		$total_count = self::count_products();
		$pages       = (int) ceil( $total_count / $batch );
		$total_set   = 0;
		$total_skip  = 0;

		for ( $page = 1; $page <= $pages; $page++ ) {
			$result     = self::run_page( $page, $batch, $dry_run, $force );
			$total_set  += $result['written'];
			$total_skip += $result['skipped'];

			WP_CLI::log( sprintf(
				'  Page %d/%d — %d product(s) processed, %d field(s) %s.',
				$page,
				$pages,
				count( $result['products'] ),
				$result['written'],
				$dry_run ? 'would be set' : 'written'
			) );
		}

		WP_CLI::success( sprintf(
			'Complete. %d field(s) %s, %d skipped (already set).',
			$total_set,
			$dry_run ? 'would be set' : 'written',
			$total_skip
		) );
	}

	/**
	 * Process one page of products.
	 *
	 * @param  int  $page
	 * @param  int  $per_page
	 * @param  bool $dry_run   When true, compute but do not write.
	 * @param  bool $force     When true, overwrite even non-empty values.
	 * @return array{ products: array[], written: int, skipped: int }
	 */
	public static function run_page( int $page, int $per_page, bool $dry_run, bool $force ): array {
		$query = new WP_Query( [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		] );

		$written  = 0;
		$skipped  = 0;
		$products = [];

		foreach ( $query->posts as $post_id ) {
			$result = self::backfill_product( (int) $post_id, $dry_run, $force );
			$written  += $result['written'];
			$skipped  += $result['skipped'];
			$products[] = $result;
		}

		return compact( 'products', 'written', 'skipped' );
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/** Count all published product + variation posts. */
	private static function count_products(): int {
		$q = new WP_Query( [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return (int) $q->found_posts;
	}

	/**
	 * Compute and optionally write DTB meta for a single product.
	 *
	 * @param  int  $post_id
	 * @param  bool $dry_run
	 * @param  bool $force    Overwrite existing non-empty values.
	 * @return array{ id: int, written: int, skipped: int, fields: array }
	 */
	private static function backfill_product( int $post_id, bool $dry_run, bool $force ): array {
		$wc_product = wc_get_product( $post_id );
		if ( ! $wc_product ) {
			return [ 'id' => $post_id, 'written' => 0, 'skipped' => 0, 'fields' => [] ];
		}

		$is_variation = $wc_product->is_type( 'variation' );
		$parent_id    = $is_variation ? $wc_product->get_parent_id() : 0;

		// Collect WC category objects for the product (or parent's for variations).
		$source_id = $parent_id ?: $post_id;
		$cat_terms = get_the_terms( $source_id, 'product_cat' );
		$wc_cats   = is_array( $cat_terms ) ? array_map( static fn( $t ) => [
			'id'   => $t->term_id,
			'name' => $t->name,
			'slug' => $t->slug,
		], $cat_terms ) : [];

		// Resolve brand from WC Brands taxonomy → meta.
		$brand_term = self::get_brand_from_taxonomy( $source_id );
		$brand      = DTB_BrandNormalizer::normalize( $brand_term );

		// Resolve category.
		$meta_cat_key = (string) get_post_meta( $post_id, DTB_ProductMeta::CATEGORY_KEY, true );
		$category     = DTB_CategoryNormalizer::resolve( $wc_cats, $meta_cat_key );

		// Determine product kind.
		$is_parts    = self::is_parts_product( $wc_cats, $wc_product );
		$product_kind = $is_parts ? 'part' : 'tool';

		// Resolve tool family. Broad category keys are not sufficient for
		// multi-family buckets such as taping, finishing, corner, handles, or
		// mudboxes; pass the canonical display category as stronger evidence.
		$existing_family     = (string) get_post_meta( $post_id, DTB_ProductMeta::TOOL_FAMILY, true );
		$builder_slots       = DTB_CatalogProductNormalizer::decode_csv_or_array(
			(string) get_post_meta( $post_id, DTB_ProductMeta::BUILDER_SLOTS, true )
		);
		$display_category_raw = (string) get_post_meta( $post_id, DTB_ProductMeta::DISPLAY_CATEGORY_KEY, true );
		if ( '' === $display_category_raw && $parent_id > 0 ) {
			$display_category_raw = (string) get_post_meta( $parent_id, DTB_ProductMeta::DISPLAY_CATEGORY_KEY, true );
		}
		$display_category_key = DTB_CategoryNormalizer::canonical_display_slug( $display_category_raw );
		$tool_family = DTB_ToolFamilyResolver::resolve(
			$existing_family,
			$builder_slots,
			$category['key'],
			$display_category_key,
			$wc_product->get_name(),
			$is_parts
		);

		// For variable parents, find the best default variation.
		$default_var_id = null;
		if ( $wc_product->is_type( 'variable' ) ) {
			$default_var_id = self::resolve_default_variation_id( $wc_product );
		}

		// Build candidate writes.
		$candidates = [];

		if ( '' !== $brand['key'] ) {
			$candidates[ DTB_ProductMeta::BRAND_KEY ]   = $brand['key'];
			$candidates[ DTB_ProductMeta::BRAND_LABEL ] = $brand['label'];
		}
		if ( '' !== $category['key'] ) {
			$candidates[ DTB_ProductMeta::CATEGORY_KEY ] = $category['key'];
		}
		if ( '' !== $tool_family ) {
			$candidates[ DTB_ProductMeta::TOOL_FAMILY ] = $tool_family;
		}
		$candidates[ DTB_ProductMeta::PRODUCT_KIND ] = $product_kind;
		$candidates[ DTB_ProductMeta::IS_PARTS ]     = $is_parts ? '1' : '0';

		if ( null !== $default_var_id ) {
			$candidates[ DTB_ProductMeta::DEFAULT_VARIATION_ID ] = (string) $default_var_id;
		}

		if ( $is_variation ) {
			$parent_sku = $parent_id ? get_post_meta( $parent_id, '_sku', true ) : '';
			if ( '' !== $parent_sku ) {
				$candidates[ DTB_ProductMeta::PARENT_PRODUCT_SKU ] = (string) $parent_sku;
			}
		}

		// Write (or report).
		$written = 0;
		$skipped = 0;
		$fields  = [];

		foreach ( $candidates as $meta_key => $new_value ) {
			$existing = get_post_meta( $post_id, $meta_key, true );
			$canonical_family_repair = DTB_ProductMeta::TOOL_FAMILY === $meta_key
				&& DTB_ToolFamilyResolver::is_canonical_repair(
					(string) $existing,
					(string) $new_value,
					$display_category_key,
					$wc_product->get_name()
				);

			if ( ! $force && '' !== $existing && ! $canonical_family_repair ) {
				$skipped++;
				$fields[ $meta_key ] = [ 'action' => 'skip', 'current' => $existing ];
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $post_id, $meta_key, $new_value );
			}
			$written++;
			$fields[ $meta_key ] = [
				'action'   => $dry_run ? 'would_set' : 'set',
				'value'    => $new_value,
				'previous' => $canonical_family_repair ? $existing : '',
			];
		}

		return compact( 'written', 'skipped', 'fields' ) + [ 'id' => $post_id ];
	}

	/** Read brand name from WooCommerce Brands taxonomy (wc_product_brands term). */
	private static function get_brand_from_taxonomy( int $post_id ): string {
		$taxonomy = 'product_brand';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// Some installs use 'pwb-brand' (Perfect WooCommerce Brands).
			$taxonomy = taxonomy_exists( 'pwb-brand' ) ? 'pwb-brand' : '';
		}
		if ( '' === $taxonomy ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	/** Determine whether this product is a replacement part from its WC categories. */
	private static function is_parts_product( array $wc_cats, WC_Product $product ): bool {
		foreach ( $wc_cats as $cat ) {
			if ( preg_match( '/parts|replacement|repair/i', (string) ( $cat['name'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pick the best default variation ID for a variable parent.
	 * Mirrors DefaultVariationResolver priority without a full DTO build.
	 *
	 * @param  WC_Product_Variable $parent
	 * @return int|null
	 */
	private static function resolve_default_variation_id( WC_Product $parent ): ?int {
		$child_ids = $parent->get_children();
		if ( empty( $child_ids ) ) {
			return null;
		}

		// Explicit existing meta wins.
		$explicit = (int) get_post_meta( $parent->get_id(), DTB_ProductMeta::DEFAULT_VARIATION_ID, true );
		if ( $explicit > 0 && in_array( $explicit, $child_ids, true ) ) {
			return $explicit;
		}

		// First in-stock and purchasable.
		foreach ( $child_ids as $var_id ) {
			$var = wc_get_product( (int) $var_id );
			if ( $var && 'outofstock' !== $var->get_stock_status() && $var->is_purchasable() ) {
				return (int) $var_id;
			}
		}

		// First child.
		return (int) $child_ids[0];
	}
}

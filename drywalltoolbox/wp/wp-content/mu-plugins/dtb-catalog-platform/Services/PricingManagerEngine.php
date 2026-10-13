<?php
/**
 * DTB Catalog Platform — production pricing rules engine.
 *
 * WooCommerce owns persisted prices and native Cost of Goods. DTB owns the
 * deterministic pricing policy, recommendation logic, hard guardrails, audit
 * reason codes, and operator-triggered application workflow.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_PRICING_MAP_PRICE_META  = DTB_ProductMeta::MAP_PRICE;
const DTB_PRICING_MAP_SOURCE_META = DTB_ProductMeta::MAP_SOURCE;
const DTB_PRICING_INDEX_TRANSIENT = 'dtb_catalog_pricing_index_v3';

/** Clear the short-lived pricing read-model cache. */
function dtb_pricing_invalidate_index(): void {
	delete_transient( DTB_PRICING_INDEX_TRANSIENT );
	delete_transient( 'dtb_catalog_pricing_index_v2' );
	delete_transient( 'dtb_catalog_pricing_index_v1' );
}

add_action( 'woocommerce_update_product', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_update_product_variation', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_new_product', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_new_product_variation', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_delete_product', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_trash_product', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'woocommerce_untrash_product', 'dtb_pricing_invalidate_index', 10, 0 );
add_action( 'added_post_meta', 'dtb_pricing_invalidate_product_meta', 10, 3 );
add_action( 'updated_post_meta', 'dtb_pricing_invalidate_product_meta', 10, 4 );
add_action( 'deleted_post_meta', 'dtb_pricing_invalidate_product_meta', 10, 4 );
add_action( 'set_object_terms', 'dtb_pricing_invalidate_product_terms', 10, 6 );

/** Invalidate pricing snapshots when WooCommerce or another editor changes catalog metadata. */
function dtb_pricing_invalidate_product_meta( int|array $meta_id, int $object_id, string $meta_key, mixed $meta_value = null ): void {
	if ( in_array( get_post_type( $object_id ), [ 'product', 'product_variation' ], true ) ) {
		dtb_pricing_invalidate_index();
	}
}

/** Invalidate policy snapshots when product brand or category assignments change. */
function dtb_pricing_invalidate_product_terms( mixed $object_ids, mixed $terms, mixed $tt_ids, string $taxonomy ): void {
	if ( in_array( $taxonomy, [ 'product_brand', 'product_cat' ], true ) ) {
		dtb_pricing_invalidate_index();
	}
}

/** Convert a WooCommerce decimal price into exact integer minor units. */
function dtb_pricing_money_minor_units( mixed $value ): ?int {
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return null;
	}
	$decimals   = max( 0, wc_get_price_decimals() );
	$normalized = wc_format_decimal( (string) $value, $decimals );
	if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/', $normalized ) ) {
		return null;
	}
	$negative = str_starts_with( $normalized, '-' );
	$unsigned = ltrim( $normalized, '-' );
	$parts    = explode( '.', $unsigned, 2 );
	$whole    = (int) $parts[0];
	$fraction = str_pad( substr( $parts[1] ?? '', 0, $decimals ), $decimals, '0' );
	$scale    = 10 ** $decimals;
	$minor    = ( $whole * $scale ) + ( '' === $fraction ? 0 : (int) $fraction );
	return $negative ? -$minor : $minor;
}

/** Compare monetary values at WooCommerce currency precision. */
function dtb_pricing_money_compare( mixed $left, mixed $right ): ?int {
	$left_minor  = dtb_pricing_money_minor_units( $left );
	$right_minor = dtb_pricing_money_minor_units( $right );
	return null === $left_minor || null === $right_minor ? null : ( $left_minor <=> $right_minor );
}

/** Return the greatest valid monetary value. */
function dtb_pricing_money_max( mixed ...$values ): ?float {
	$winner       = null;
	$winner_minor = null;
	foreach ( $values as $value ) {
		$minor = dtb_pricing_money_minor_units( $value );
		if ( null === $minor ) {
			continue;
		}
		if ( null === $winner_minor || $minor > $winner_minor ) {
			$winner       = (float) wc_format_decimal( (string) $value, wc_get_price_decimals() );
			$winner_minor = $minor;
		}
	}
	return $winner;
}

/** Resolve native WooCommerce Cost of Goods. */
function dtb_pricing_product_cost( WC_Product $product ): ?float {
	if ( ! method_exists( $product, 'get_cogs_value' ) ) {
		return null;
	}
	$value = $product->get_cogs_value( 'edit' );
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return null;
	}
	$cost = (float) $value;
	return $cost > 0 ? $cost : null;
}

/** Resolve DTB-owned official MAP evidence. */
function dtb_pricing_product_map_price( WC_Product $product ): ?float {
	$value = $product->get_meta( DTB_PRICING_MAP_PRICE_META, true, 'edit' );
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return null;
	}
	$map = (float) $value;
	return $map > 0 ? $map : null;
}

/** Return a compact brand label from WooCommerce brand taxonomy. */
function dtb_pricing_product_brand( WC_Product $product ): string {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		return '';
	}
	$object_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$terms     = get_the_terms( $object_id, 'product_brand' );
	if ( ! is_array( $terms ) || [] === $terms ) {
		return '';
	}
	return implode( ', ', array_map( static fn( WP_Term $term ): string => $term->name, $terms ) );
}

function dtb_pricing_gross_profit( ?float $price, ?float $cost ): ?float {
	return null === $price || null === $cost ? null : round( $price - $cost, wc_get_price_decimals() );
}

function dtb_pricing_gross_margin( ?float $price, ?float $cost ): ?float {
	return null === $price || null === $cost || $price <= 0 ? null : round( ( ( $price - $cost ) / $price ) * 100, 2 );
}

function dtb_pricing_markup( ?float $price, ?float $cost ): ?float {
	return null === $price || null === $cost || $cost <= 0 ? null : round( ( ( $price - $cost ) / $cost ) * 100, 2 );
}

/** price = cost / (1 - margin), ceiling-rounded to currency precision. */
function dtb_pricing_target_price( ?float $cost, float $target_margin ): ?float {
	if ( null === $cost || $cost <= 0 || $target_margin <= 0 || $target_margin >= 100 ) {
		return null;
	}
	$cost_minor = dtb_pricing_money_minor_units( $cost );
	if ( null === $cost_minor || $cost_minor <= 0 ) {
		return null;
	}
	$basis_points = 10000;
	$margin_bps   = (int) round( $target_margin * 100 );
	$denominator  = $basis_points - $margin_bps;
	if ( $denominator <= 0 ) {
		return null;
	}
	$target_minor = intdiv( ( $cost_minor * $basis_points ) + $denominator - 1, $denominator );
	$scale        = 10 ** max( 0, wc_get_price_decimals() );
	return $target_minor / $scale;
}

function dtb_pricing_is_below( mixed $price, ?float $floor ): bool {
	if ( null === $floor ) {
		return false;
	}
	$comparison = dtb_pricing_money_compare( $price, $floor );
	return null !== $comparison && $comparison < 0;
}

function dtb_pricing_is_below_map( mixed $price, ?float $map ): bool {
	return dtb_pricing_is_below( $price, $map );
}

/**
 * Resolve hard minimum price for a product: COGS, minimum-margin price and MAP.
 * MAP is omitted when unavailable; missing MAP is never inferred.
 */
function dtb_pricing_hard_floor( WC_Product $product, ?array $policy = null ): ?float {
	$policy        = $policy ?? dtb_pricing_resolve_policy( $product );
	$cost          = dtb_pricing_product_cost( $product );
	$map           = dtb_pricing_product_map_price( $product );
	$minimum_price = dtb_pricing_target_price( $cost, (float) $policy['minimum_margin'] );
	return dtb_pricing_money_max( $cost, $minimum_price, $map );
}

/**
 * Calculate an advisory economics floor for operator reporting.
 *
 * Catalog pricing is authoritative from the reviewed official CSV. This helper
 * must not mutate WooCommerce values during imports or catalog updates.
 */
function dtb_pricing_enforce_hard_floor_on_product( $product ): void {
	if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
		return;
	}
	$policy = dtb_pricing_resolve_policy( $product );
	$floor  = dtb_pricing_hard_floor( $product, $policy );
	if ( null === $floor ) {
		return;
	}
	$floor_decimal = wc_format_decimal( (string) $floor, wc_get_price_decimals() );
	$regular       = $product->get_regular_price( 'edit' );
	$sale          = $product->get_sale_price( 'edit' );
	if ( '' !== $regular && is_numeric( $regular ) && dtb_pricing_is_below( $regular, $floor ) ) {
		$product->set_regular_price( $floor_decimal );
	}
	if ( '' !== $sale && is_numeric( $sale ) && dtb_pricing_is_below( $sale, $floor ) ) {
		$product->set_sale_price( $floor_decimal );
	}
	$regular = $product->get_regular_price( 'edit' );
	$sale    = $product->get_sale_price( 'edit' );
	if ( $product->is_on_sale( 'edit' ) && '' !== $sale && is_numeric( $sale ) ) {
		$product->set_price( $sale );
	} elseif ( '' !== $regular && is_numeric( $regular ) ) {
		$product->set_price( $regular );
	}
}

/** Backward-compatible advisory helper retained for existing callers. */
function dtb_pricing_enforce_map_floor_on_product( $product ): void {
	dtb_pricing_enforce_hard_floor_on_product( $product );
}

/** Convert a price-owning WooCommerce product into a rules-engine snapshot. */
function dtb_pricing_product_snapshot( WC_Product $product, ?float $target_margin_override = null ): array {
	$policy         = dtb_pricing_resolve_policy( $product );
	$minimum_margin = (float) $policy['minimum_margin'];
	$target_margin  = null === $target_margin_override ? (float) $policy['target_margin'] : $target_margin_override;
	$regular_raw    = $product->get_regular_price( 'edit' );
	$sale_raw       = $product->get_sale_price( 'edit' );
	$effective_raw  = $product->get_price( 'edit' );
	$regular        = is_numeric( $regular_raw ) ? (float) $regular_raw : null;
	$sale           = is_numeric( $sale_raw ) ? (float) $sale_raw : null;
	$effective      = is_numeric( $effective_raw ) ? (float) $effective_raw : null;
	$cost           = dtb_pricing_product_cost( $product );
	$map            = dtb_pricing_product_map_price( $product );
	$on_sale        = $product->is_on_sale( 'edit' );
	$has_map        = null !== $map;

	$regular_margin = dtb_pricing_gross_margin( $regular, $cost );
	$effective_margin = dtb_pricing_gross_margin( $effective, $cost );
	$markup           = dtb_pricing_markup( $effective, $cost );
	$minimum_price    = dtb_pricing_target_price( $cost, $minimum_margin );
	$target_price     = dtb_pricing_target_price( $cost, $target_margin );
	$hard_floor       = dtb_pricing_money_max( $cost, $minimum_price, $map );
	$preferred_floor  = dtb_pricing_money_max( $hard_floor, $target_price, $map );

	$regular_below_cogs = null !== $cost && null !== $regular && dtb_pricing_is_below( $regular, $cost );
	$sale_below_cogs    = null !== $cost && null !== $sale && dtb_pricing_is_below( $sale, $cost );
	$effective_below_cogs = null !== $cost && null !== $effective && dtb_pricing_is_below( $effective, $cost );
	$regular_below_minimum = null !== $minimum_price && null !== $regular && dtb_pricing_is_below( $regular, $minimum_price );
	$sale_below_minimum    = null !== $minimum_price && null !== $sale && dtb_pricing_is_below( $sale, $minimum_price );
	$regular_map_violation = $has_map && null !== $regular && dtb_pricing_is_below_map( $regular, $map );
	$sale_map_violation    = $has_map && null !== $sale && dtb_pricing_is_below_map( $sale, $map );
	$effective_map_violation = $has_map && null !== $effective && dtb_pricing_is_below_map( $effective, $map );
	$map_violation = $regular_map_violation || $sale_map_violation || $effective_map_violation;
	$below_minimum = $regular_below_minimum || $sale_below_minimum;
	$below_cogs    = $regular_below_cogs || $sale_below_cogs || $effective_below_cogs;

	$suggested_regular = null !== $regular ? dtb_pricing_money_max( $regular, $preferred_floor ) : null;
	$suggested_sale    = null !== $sale ? dtb_pricing_money_max( $sale, $hard_floor ) : null;
	$suggested_effective = $on_sale && null !== $suggested_sale ? $suggested_sale : $suggested_regular;
	$change_pct        = dtb_pricing_change_percent( $regular, $suggested_regular );
	$guardrails        = $policy['guardrails'];

	$reason_codes = [];
	if ( $regular_below_cogs ) { $reason_codes[] = 'REGULAR_BELOW_COGS'; }
	if ( $sale_below_cogs ) { $reason_codes[] = 'SALE_BELOW_COGS'; }
	if ( $effective_below_cogs && ! $sale_below_cogs && ! $regular_below_cogs ) { $reason_codes[] = 'EFFECTIVE_BELOW_COGS'; }
	if ( $map_violation ) { $reason_codes[] = 'MAP_FLOOR_VIOLATION'; }
	if ( $below_minimum ) { $reason_codes[] = 'BELOW_MINIMUM_MARGIN'; }
	if ( null === $cost ) { $reason_codes[] = 'MISSING_COGS'; }
	if ( ! $has_map ) { $reason_codes[] = 'MAP_NOT_CONFIGURED'; }
	if ( null === $regular || $regular <= 0 ) { $reason_codes[] = 'MISSING_PRICE'; }
	if ( $on_sale ) { $reason_codes[] = 'ACTIVE_SALE'; }
	if ( null !== $regular_margin && $regular_margin + 0.005 < $target_margin ) { $reason_codes[] = 'BELOW_TARGET_MARGIN'; }
	$reason_codes[] = strtoupper( $policy['source'] ) . '_POLICY_APPLIED';

	$status       = 'healthy';
	$status_label = __( 'Healthy', 'drywall-toolbox' );
	$severity     = 'info';
	$action       = 'hold';
	$primary      = 'PRICE_HEALTHY';

	if ( null === $regular || $regular <= 0 ) {
		$status = 'missing_price'; $status_label = __( 'Missing price', 'drywall-toolbox' ); $severity = 'critical'; $action = 'blocked'; $primary = 'MISSING_PRICE';
	} elseif ( $below_cogs ) {
		$status = 'below_cogs'; $status_label = __( 'Price below COGS', 'drywall-toolbox' ); $severity = 'critical'; $action = 'optimize'; $primary = $sale_below_cogs ? 'SALE_BELOW_COGS' : 'REGULAR_BELOW_COGS';
	} elseif ( $map_violation ) {
		$status = 'below_map'; $status_label = __( 'MAP violation', 'drywall-toolbox' ); $severity = 'critical'; $action = 'optimize'; $primary = 'MAP_FLOOR_VIOLATION';
	} elseif ( $below_minimum ) {
		$status = 'below_minimum'; $status_label = __( 'Below minimum margin', 'drywall-toolbox' ); $severity = 'high'; $action = 'optimize'; $primary = 'BELOW_MINIMUM_MARGIN';
	} elseif ( null === $cost ) {
		$status = 'missing_cost'; $status_label = __( 'Missing cost', 'drywall-toolbox' ); $severity = 'medium'; $action = 'hold'; $primary = 'MISSING_COGS';
	} elseif ( $on_sale ) {
		$status = 'sale_active'; $status_label = __( 'Sale active', 'drywall-toolbox' ); $severity = 'medium'; $action = 'review'; $primary = 'ACTIVE_SALE';
	} elseif ( null !== $regular_margin && $regular_margin + 0.005 < $target_margin ) {
		$status = 'below_target'; $status_label = __( 'Below target', 'drywall-toolbox' ); $severity = 'medium'; $action = 'optimize'; $primary = 'BELOW_TARGET_MARGIN';
	} elseif ( ! $has_map ) {
		$status = 'missing_map'; $status_label = __( 'MAP not configured', 'drywall-toolbox' ); $severity = 'info'; $action = 'hold'; $primary = 'MAP_NOT_CONFIGURED';
	}

	// Hard violations always remain actionable. Change guardrails apply to normal
	// target-margin opportunities so large repricing jumps require review.
	if ( 'optimize' === $action && ! $below_cogs && ! $map_violation && ! $below_minimum && null !== $change_pct ) {
		if ( $change_pct < (float) $guardrails['no_change_threshold_pct'] ) {
			$action = 'hold'; $primary = 'CHANGE_BELOW_THRESHOLD'; $reason_codes[] = 'CHANGE_BELOW_THRESHOLD';
		} elseif ( $change_pct >= (float) $guardrails['block_change_threshold_pct'] ) {
			$action = 'blocked'; $severity = 'high'; $primary = 'MAX_CHANGE_EXCEEDED'; $reason_codes[] = 'MAX_CHANGE_EXCEEDED';
		} elseif ( $change_pct >= (float) $guardrails['review_change_threshold_pct'] ) {
			$action = 'review'; $severity = 'high'; $primary = 'LARGE_CHANGE_REVIEW'; $reason_codes[] = 'LARGE_CHANGE_REVIEW';
		}
	}

	$optimizer_eligible = null !== $regular && ( null !== $cost || $map_violation );
	$reason_codes       = array_values( array_unique( $reason_codes ) );
	$image_id           = $product->get_image_id();
	$image_url          = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : false;

	return [
		'id' => $product->get_id(), 'parent_id' => $product->get_parent_id(), 'type' => $product->get_type(),
		'name' => wp_strip_all_tags( $product->get_name() ), 'sku' => (string) $product->get_sku( 'edit' ),
		'brand' => dtb_pricing_product_brand( $product ), 'catalog_status' => $product->get_status( 'edit' ),
		'regular_price' => $regular, 'sale_price' => $sale, 'effective_price' => $effective,
		'cost' => $cost, 'map_price' => $map,
		'map_source' => sanitize_text_field( (string) $product->get_meta( DTB_PRICING_MAP_SOURCE_META, true, 'edit' ) ),
		'has_map' => $has_map, 'map_violation' => $map_violation,
		'regular_map_violation' => $regular_map_violation, 'sale_map_violation' => $sale_map_violation,
		'regular_below_cogs' => $regular_below_cogs, 'sale_below_cogs' => $sale_below_cogs, 'effective_below_cogs' => $effective_below_cogs,
		'below_minimum_margin' => $below_minimum,
		'gross_profit' => dtb_pricing_gross_profit( $effective, $cost ), 'gross_margin' => $effective_margin,
		'regular_gross_margin' => $regular_margin, 'markup' => $markup,
		'minimum_margin' => $minimum_margin, 'target_margin' => $target_margin,
		'minimum_price' => $minimum_price, 'target_price' => $target_price,
		'optimization_floor' => $hard_floor, 'preferred_price' => $preferred_floor,
		'suggested_price' => $suggested_regular, 'suggested_sale_price' => $suggested_sale,
		'suggested_effective_price' => $suggested_effective,
		'suggested_gross_margin' => dtb_pricing_gross_margin( $suggested_effective, $cost ),
		'price_change_pct' => $change_pct, 'optimizer_eligible' => $optimizer_eligible,
		'recommendation_action' => $action, 'reason_code' => $primary, 'reason_codes' => $reason_codes,
		'severity' => $severity, 'status' => $status, 'status_label' => $status_label, 'on_sale' => $on_sale,
		'policy_source' => $policy['source'], 'policy_source_label' => $policy['source_label'],
		'policy_evidence_count' => $policy['evidence_count'], 'policy_fallback_chain' => $policy['fallback_chain'],
		'image_url' => $image_url ? esc_url_raw( $image_url ) : '',
		'edit_url' => esc_url_raw( get_edit_post_link( $product->get_id(), 'raw' ) ?: '' ),
	];
}

/** Build a bounded, short-lived flat index of price-owning records. */
function dtb_pricing_build_index(): array {
	$cached = get_transient( DTB_PRICING_INDEX_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$rows = []; $page = 1; $per_page = 100; $product_types = array_keys( wc_get_product_types() );
	do {
		$result = wc_get_products( [ 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'status' => [ 'publish', 'draft', 'private', 'pending' ], 'type' => $product_types, 'orderby' => 'ID', 'order' => 'ASC' ] );
		$products  = is_object( $result ) && isset( $result->products ) ? (array) $result->products : [];
		$max_pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1;
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) { continue; }
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation ) { $rows[] = dtb_pricing_product_snapshot( $variation ); }
				}
				continue;
			}
			$rows[] = dtb_pricing_product_snapshot( $product );
		}
		++$page;
	} while ( $page <= $max_pages );
	set_transient( DTB_PRICING_INDEX_TRANSIENT, $rows, 2 * MINUTE_IN_SECONDS );
	return $rows;
}

/** Query pricing records for Products/Optimizer tables. */
function dtb_pricing_query_products( array $args = [] ): array {
	$args = wp_parse_args( $args, [ 'search' => '', 'brand' => '', 'status' => '', 'map_only' => false, 'page' => 1, 'per_page' => 25, 'sort' => 'name', 'direction' => 'asc' ] );
	$search = strtolower( trim( sanitize_text_field( (string) $args['search'] ) ) );
	$brand = sanitize_text_field( (string) $args['brand'] ); $status = sanitize_key( (string) $args['status'] );
	$map_only = rest_sanitize_boolean( $args['map_only'] ); $page = max( 1, absint( $args['page'] ) );
	$per_page = min( 100, max( 10, absint( $args['per_page'] ) ) ); $sort = sanitize_key( (string) $args['sort'] );
	$direction = 'desc' === strtolower( (string) $args['direction'] ) ? 'desc' : 'asc'; $rows = dtb_pricing_build_index();
	$filtered = array_values( array_filter( $rows, static function ( array $row ) use ( $search, $brand, $status, $map_only ): bool {
		if ( '' !== $search ) { $haystack = strtolower( implode( ' ', [ $row['name'], $row['sku'], $row['brand'] ] ) ); if ( false === strpos( $haystack, $search ) ) { return false; } }
		if ( '' !== $brand && $row['brand'] !== $brand ) { return false; }
		if ( $map_only && empty( $row['has_map'] ) ) { return false; }
		if ( 'needs_action' === $status ) { return 'optimize' === $row['recommendation_action']; }
		if ( 'needs_review' === $status ) { return in_array( $row['recommendation_action'], [ 'review', 'blocked' ], true ); }
		if ( '' !== $status && 'all' !== $status && $row['status'] !== $status ) { return false; }
		return true;
	} ) );
	$sort_map = [ 'name' => 'name', 'sku' => 'sku', 'price' => 'effective_price', 'cost' => 'cost', 'margin' => 'gross_margin', 'suggested' => 'suggested_price', 'severity' => 'severity' ];
	$sort_key = $sort_map[ $sort ] ?? 'name';
	usort( $filtered, static function ( array $a, array $b ) use ( $sort_key, $direction ): int {
		$av = $a[ $sort_key ] ?? null; $bv = $b[ $sort_key ] ?? null; if ( $av === $bv ) { return 0; } if ( null === $av ) { return 1; } if ( null === $bv ) { return -1; }
		$cmp = is_numeric( $av ) && is_numeric( $bv ) ? ( (float) $av <=> (float) $bv ) : strcasecmp( (string) $av, (string) $bv ); return 'desc' === $direction ? -$cmp : $cmp;
	} );
	$total = count( $filtered ); $total_page = max( 1, (int) ceil( $total / $per_page ) ); $page = min( $page, $total_page ); $offset = ( $page - 1 ) * $per_page;
	return [ 'items' => array_slice( $filtered, $offset, $per_page ), 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'total_pages' => $total_page ];
}

/** Overall pricing metrics, policy and filters. */
function dtb_pricing_get_data_summary(): array {
	$rows = dtb_pricing_build_index(); $brands = [];
	$counts = [ 'total' => count( $rows ), 'with_cost' => 0, 'with_map' => 0, 'missing_map' => 0, 'optimizer_actions' => 0, 'review_actions' => 0, 'blocked_actions' => 0, 'healthy' => 0, 'below_target' => 0, 'below_map' => 0, 'below_cogs' => 0, 'below_minimum' => 0, 'missing_cost' => 0, 'missing_price' => 0, 'sale_active' => 0 ];
	foreach ( $rows as $row ) {
		if ( null !== $row['cost'] ) { ++$counts['with_cost']; }
		if ( ! empty( $row['has_map'] ) ) { ++$counts['with_map']; } else { ++$counts['missing_map']; }
		if ( 'optimize' === $row['recommendation_action'] ) { ++$counts['optimizer_actions']; }
		if ( 'review' === $row['recommendation_action'] ) { ++$counts['review_actions']; }
		if ( 'blocked' === $row['recommendation_action'] ) { ++$counts['blocked_actions']; }
		if ( isset( $counts[ $row['status'] ] ) ) { ++$counts[ $row['status'] ]; }
		if ( '' !== $row['brand'] ) { $brands[ $row['brand'] ] = true; }
	}
	$brand_names = array_keys( $brands ); natcasesort( $brand_names );
	return [
		'counts' => $counts, 'brands' => array_values( $brand_names ),
		'target_margin' => dtb_pricing_get_target_margin(), 'policy' => dtb_pricing_get_policy_settings(),
		'brand_policies' => dtb_pricing_policy_brand_defaults(), 'category_policies' => dtb_pricing_policy_category_defaults(),
		'optimizer_mode' => 'rules_v2',
		'sources' => [ 'prices' => __( 'WooCommerce regular and sale prices', 'drywall-toolbox' ), 'cost' => __( 'WooCommerce Cost of Goods', 'drywall-toolbox' ), 'map' => __( 'DTB product MAP field', 'drywall-toolbox' ) ],
	];
}

function dtb_pricing_get_product( int $product_id ): ?array {
	$product = wc_get_product( $product_id );
	return ! $product || $product->is_type( 'variable' ) ? null : dtb_pricing_product_snapshot( $product );
}

/** Apply explicit pricing fields through WooCommerce CRUD; hard floors are enforced before save. */
function dtb_pricing_update_product( int $product_id, array $fields ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) { return new WP_Error( 'dtb_pricing_product_not_found', __( 'Product not found.', 'drywall-toolbox' ), [ 'status' => 404 ] ); }
	if ( $product->is_type( 'variable' ) ) { return new WP_Error( 'dtb_pricing_variable_parent', __( 'Variable parent prices are managed by their variations.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	$before = dtb_pricing_product_snapshot( $product );
	if ( array_key_exists( 'map_price', $fields ) ) {
		$raw_map = trim( (string) $fields['map_price'] );
		if ( '' === $raw_map ) { $product->delete_meta_data( DTB_PRICING_MAP_PRICE_META ); }
		elseif ( is_numeric( $raw_map ) && (float) $raw_map > 0 ) { $product->update_meta_data( DTB_PRICING_MAP_PRICE_META, wc_format_decimal( $raw_map, wc_get_price_decimals() ) ); }
		else { return new WP_Error( 'dtb_pricing_invalid_map', __( 'MAP must be blank or a valid positive amount.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	}
	if ( array_key_exists( 'map_source', $fields ) ) { $source = sanitize_text_field( (string) $fields['map_source'] ); '' === $source ? $product->delete_meta_data( DTB_PRICING_MAP_SOURCE_META ) : $product->update_meta_data( DTB_PRICING_MAP_SOURCE_META, $source ); }
	if ( array_key_exists( 'regular_price', $fields ) ) { $raw = trim( (string) $fields['regular_price'] ); if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) { return new WP_Error( 'dtb_pricing_invalid_price', __( 'Regular price must be a valid non-negative amount.', 'drywall-toolbox' ), [ 'status' => 400 ] ); } $product->set_regular_price( wc_format_decimal( $raw, wc_get_price_decimals() ) ); }
	if ( array_key_exists( 'sale_price', $fields ) ) { $raw_sale = trim( (string) $fields['sale_price'] ); if ( '' === $raw_sale ) { $product->set_sale_price( '' ); } elseif ( is_numeric( $raw_sale ) && (float) $raw_sale >= 0 ) { $product->set_sale_price( wc_format_decimal( $raw_sale, wc_get_price_decimals() ) ); } else { return new WP_Error( 'dtb_pricing_invalid_sale_price', __( 'Sale price must be blank or a valid non-negative amount.', 'drywall-toolbox' ), [ 'status' => 400 ] ); } }
	$product->save(); wc_delete_product_transients( $product_id ); dtb_pricing_invalidate_index();
	$after_product = wc_get_product( $product_id ); $after = $after_product ? dtb_pricing_product_snapshot( $after_product ) : $before;
	if ( function_exists( 'dtb_admin_audit_write' ) ) { dtb_admin_audit_write( 'catalog_pricing', $product_id, 'catalog_pricing.product_updated', [ 'before' => [ 'regular_price' => $before['regular_price'], 'sale_price' => $before['sale_price'], 'map_price' => $before['map_price'] ], 'after' => [ 'regular_price' => $after['regular_price'], 'sale_price' => $after['sale_price'], 'map_price' => $after['map_price'] ], 'reason_codes' => $before['reason_codes'], 'policy_source' => $before['policy_source'], 'minimum_margin' => $before['minimum_margin'], 'target_margin' => $before['target_margin'] ], [ 'source' => 'pricing_manager' ] ); }
	return $after;
}

/** Apply selected server-recalculated optimizer recommendations. */
function dtb_pricing_apply_selected( array $items ): array {
	$items = array_slice( $items, 0, 100 ); $result = [ 'updated' => [], 'conflicts' => [], 'errors' => [] ];
	foreach ( $items as $item ) {
		$product_id = absint( $item['product_id'] ?? 0 ); $expected = $item['expected_regular_price'] ?? null; $product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || $product->is_type( 'variable' ) ) { $result['errors'][] = [ 'product_id' => $product_id, 'message' => __( 'Product is unavailable for pricing.', 'drywall-toolbox' ) ]; continue; }
		$current = (string) $product->get_regular_price( 'edit' );
		if ( null !== $expected && 0 !== dtb_pricing_money_compare( $expected, $current ) ) { $result['conflicts'][] = [ 'product_id' => $product_id, 'current_regular_price' => $current ]; continue; }
		$snapshot = dtb_pricing_product_snapshot( $product );
		if ( empty( $snapshot['optimizer_eligible'] ) ) { $result['errors'][] = [ 'product_id' => $product_id, 'message' => __( 'A valid regular price plus COGS or a hard MAP violation is required for optimizer application.', 'drywall-toolbox' ) ]; continue; }
		if ( 'optimize' !== $snapshot['recommendation_action'] ) { $result['conflicts'][] = [ 'product_id' => $product_id, 'current_regular_price' => $current, 'reason_code' => $snapshot['reason_code'] ]; continue; }
		$fields = [ 'regular_price' => $snapshot['suggested_price'] ];
		if ( null !== $snapshot['sale_price'] && null !== $snapshot['suggested_sale_price'] && 0 !== dtb_pricing_money_compare( $snapshot['sale_price'], $snapshot['suggested_sale_price'] ) ) { $fields['sale_price'] = $snapshot['suggested_sale_price']; }
		$updated = dtb_pricing_update_product( $product_id, $fields );
		if ( is_wp_error( $updated ) ) { $result['errors'][] = [ 'product_id' => $product_id, 'message' => $updated->get_error_message() ]; continue; }
		$result['updated'][] = $updated;
	}
	return $result;
}

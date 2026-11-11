<?php
/**
 * DTB Catalog Platform — deterministic pricing policy resolver.
 *
 * Resolves evidence-backed category → brand → global minimum/target gross-margin
 * policy plus price-change guardrails. WooCommerce remains authoritative for
 * persisted prices; this file only owns pricing policy and deterministic rule
 * resolution.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_PRICING_POLICY_OPTION        = 'dtb_catalog_pricing_policy_v2';
const DTB_PRICING_TARGET_MARGIN_OPTION = 'dtb_catalog_pricing_target_margin'; // Legacy compatibility.

/** Evidence-backed launch defaults derived from the canonical MAP + COGS study. */
function dtb_pricing_policy_defaults(): array {
	return [
		'global_minimum_margin'       => 30.50,
		'global_target_margin'        => 33.50,
		'no_change_threshold_pct'     => 1.00,
		'review_change_threshold_pct' => 25.00,
		'block_change_threshold_pct'  => 50.00,
	];
}

/** Brand-level policy candidates with >= 5 eligible MAP + COGS observations. */
function dtb_pricing_policy_brand_defaults(): array {
	return [
		'Columbia Tools' => [ 'minimum_margin' => 32.00, 'target_margin' => 34.50, 'evidence_count' => 91 ],
		'TapeTech'       => [ 'minimum_margin' => 29.00, 'target_margin' => 30.50, 'evidence_count' => 49 ],
	];
}

/** Category-level policy candidates with >= 5 eligible MAP + COGS observations. */
function dtb_pricing_policy_category_defaults(): array {
	return [
		'Angle Heads'                            => [ 'minimum_margin' => 31.00, 'target_margin' => 33.00, 'evidence_count' => 8 ],
		'Corner Rollers'                         => [ 'minimum_margin' => 33.00, 'target_margin' => 36.00, 'evidence_count' => 7 ],
		'Flat Box Handles'                       => [ 'minimum_margin' => 31.00, 'target_margin' => 33.00, 'evidence_count' => 29 ],
		'Flat Boxes'                             => [ 'minimum_margin' => 30.00, 'target_margin' => 33.50, 'evidence_count' => 24 ],
		'Loading Pumps'                          => [ 'minimum_margin' => 25.00, 'target_margin' => 30.50, 'evidence_count' => 5 ],
		'Predator Family'                        => [ 'minimum_margin' => 34.00, 'target_margin' => 34.00, 'evidence_count' => 9 ],
		'Compound Applicators'                   => [ 'minimum_margin' => 33.50, 'target_margin' => 34.50, 'evidence_count' => 13 ],
		'Compound Tubes'                         => [ 'minimum_margin' => 26.50, 'target_margin' => 31.00, 'evidence_count' => 13 ],
		'Corner Flushers'                        => [ 'minimum_margin' => 38.50, 'target_margin' => 40.50, 'evidence_count' => 15 ],
		'Semi-Automatic Taping Tool Accessories' => [ 'minimum_margin' => 31.00, 'target_margin' => 31.50, 'evidence_count' => 7 ],
	];
}

/** Normalize a raw global policy into a valid deterministic contract. */
function dtb_pricing_normalize_policy_settings( array $raw ): array {
	$policy = array_merge( dtb_pricing_policy_defaults(), $raw );

	$policy['global_minimum_margin'] = min( 95, max( 0.01, round( (float) $policy['global_minimum_margin'], 2 ) ) );
	$policy['global_target_margin']  = min( 95, max( $policy['global_minimum_margin'], round( (float) $policy['global_target_margin'], 2 ) ) );
	$policy['no_change_threshold_pct'] = min( 25, max( 0, round( (float) $policy['no_change_threshold_pct'], 2 ) ) );
	$policy['review_change_threshold_pct'] = min( 100, max( $policy['no_change_threshold_pct'], round( (float) $policy['review_change_threshold_pct'], 2 ) ) );
	$policy['block_change_threshold_pct'] = min( 500, max( $policy['review_change_threshold_pct'], round( (float) $policy['block_change_threshold_pct'], 2 ) ) );

	return $policy;
}

/** Read and normalize persisted global pricing policy. */
function dtb_pricing_get_policy_settings(): array {
	$stored = get_option( DTB_PRICING_POLICY_OPTION, [] );
	return dtb_pricing_normalize_policy_settings( is_array( $stored ) ? $stored : [] );
}

/** Persist supported global policy settings after normalization. */
function dtb_pricing_set_policy_settings( array $incoming ): array {
	$current = dtb_pricing_get_policy_settings();
	foreach ( array_keys( dtb_pricing_policy_defaults() ) as $key ) {
		if ( array_key_exists( $key, $incoming ) && is_numeric( $incoming[ $key ] ) ) {
			$current[ $key ] = (float) $incoming[ $key ];
		}
	}
	$current = dtb_pricing_normalize_policy_settings( $current );

	update_option( DTB_PRICING_POLICY_OPTION, $current, false );
	update_option( DTB_PRICING_TARGET_MARGIN_OPTION, (float) $current['global_target_margin'], false );
	if ( function_exists( 'dtb_pricing_invalidate_index' ) ) {
		dtb_pricing_invalidate_index();
	}
	return $current;
}

/** Legacy target-margin getter retained for existing callers/UI. */
function dtb_pricing_get_target_margin(): float {
	$policy = dtb_pricing_get_policy_settings();
	return (float) $policy['global_target_margin'];
}

/** Legacy target-margin setter retained for existing callers/UI. */
function dtb_pricing_set_target_margin( float $margin ): float {
	$policy = dtb_pricing_set_policy_settings( [ 'global_target_margin' => $margin ] );
	return (float) $policy['global_target_margin'];
}

/** Return native WooCommerce product-category terms, resolving variations to parent. */
function dtb_pricing_policy_product_categories( WC_Product $product ): array {
	$object_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$terms     = wp_get_post_terms( $object_id, 'product_cat' );
	return is_array( $terms ) && ! is_wp_error( $terms ) ? $terms : [];
}

/** Choose the deepest supported category deterministically. */
function dtb_pricing_policy_matching_category( WC_Product $product, array $category_defaults ): ?WP_Term {
	$matches = [];
	foreach ( dtb_pricing_policy_product_categories( $product ) as $term ) {
		if ( ! $term instanceof WP_Term || ! isset( $category_defaults[ $term->name ] ) ) {
			continue;
		}
		$matches[] = [
			'term'  => $term,
			'depth' => count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ),
		];
	}
	if ( [] === $matches ) {
		return null;
	}
	usort(
		$matches,
		static function ( array $left, array $right ): int {
			if ( $left['depth'] !== $right['depth'] ) {
				return $right['depth'] <=> $left['depth'];
			}
			return strcasecmp( $left['term']->name, $right['term']->name );
		}
	);
	return $matches[0]['term'];
}

/** Resolve evidence-backed category → brand → global policy for a product. */
function dtb_pricing_resolve_policy( WC_Product $product ): array {
	$settings          = dtb_pricing_get_policy_settings();
	$category_defaults = apply_filters( 'dtb_pricing_category_policies', dtb_pricing_policy_category_defaults() );
	$brand_defaults    = apply_filters( 'dtb_pricing_brand_policies', dtb_pricing_policy_brand_defaults() );
	$brand             = function_exists( 'dtb_pricing_product_brand' ) ? dtb_pricing_product_brand( $product ) : '';
	$category          = dtb_pricing_policy_matching_category( $product, $category_defaults );

	if ( $category instanceof WP_Term ) {
		$rule = $category_defaults[ $category->name ];
		return [
			'source'         => 'category',
			'source_label'   => $category->name,
			'minimum_margin' => (float) $rule['minimum_margin'],
			'target_margin'  => max( (float) $rule['minimum_margin'], (float) $rule['target_margin'] ),
			'evidence_count' => (int) $rule['evidence_count'],
			'fallback_chain' => [ 'category', 'brand', 'global' ],
			'guardrails'     => $settings,
		];
	}

	if ( '' !== $brand && isset( $brand_defaults[ $brand ] ) ) {
		$rule = $brand_defaults[ $brand ];
		return [
			'source'         => 'brand',
			'source_label'   => $brand,
			'minimum_margin' => (float) $rule['minimum_margin'],
			'target_margin'  => max( (float) $rule['minimum_margin'], (float) $rule['target_margin'] ),
			'evidence_count' => (int) $rule['evidence_count'],
			'fallback_chain' => [ 'brand', 'global' ],
			'guardrails'     => $settings,
		];
	}

	return [
		'source'         => 'global',
		'source_label'   => __( 'Global launch policy', 'drywall-toolbox' ),
		'minimum_margin' => (float) $settings['global_minimum_margin'],
		'target_margin'  => (float) $settings['global_target_margin'],
		'evidence_count' => 140,
		'fallback_chain' => [ 'global' ],
		'guardrails'     => $settings,
	];
}

/** Percentage change from current regular price to a recommendation. */
function dtb_pricing_change_percent( ?float $current, ?float $suggested ): ?float {
	if ( null === $current || null === $suggested || $current <= 0 ) {
		return null;
	}
	return round( ( ( $suggested - $current ) / $current ) * 100, 2 );
}

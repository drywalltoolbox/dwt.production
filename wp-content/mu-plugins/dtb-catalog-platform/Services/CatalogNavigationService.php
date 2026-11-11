<?php
/**
 * Canonical storefront navigation projected from the catalog taxonomy registry.
 *
 * WooCommerce product_cat remains runtime authority for term existence,
 * ancestry, counts, descriptions, and media. The deployed JSON projection owns
 * only the canonical allowlist and intentional ordering from taxonomy.json.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogNavigationService {

	public const CONTRACT_VERSION = '2.3';

	private const REGISTRY_PATH = __DIR__ . '/../Resources/catalog-taxonomy.json';

	/** Shop exposes the complete catalog parent taxonomy, including Parts. */
	private const STOREFRONT_ROOT_KEYS = [
		'automatic_taping_tools',
		'taping_finishing_tools',
		'corner_bead_tools',
		'hanging_tools',
		'stilts',
		'replacement_parts',
		'tool_storage_cases',
		'materials_consumables',
	];

	/** @return array<int,array<string,mixed>> */
	public static function get_groups(): array {
		$registry = self::registry();
		$taxa     = $registry['taxa'];
		$by_key   = [];
		$slugs    = [];

		foreach ( $taxa as $taxon ) {
			$key = sanitize_key( (string) ( $taxon['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$by_key[ $key ] = $taxon;
			$slugs[]        = sanitize_title( (string) ( $taxon['slug'] ?? '' ) );
		}

		$slugs = array_values( array_filter( array_unique( $slugs ) ) );
		if ( [] === $slugs ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'slug'       => $slugs,
			'number'     => count( $slugs ),
		] );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$terms_by_slug = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$terms_by_slug[ sanitize_title( $term->slug ) ] = $term;
			}
		}

		$groups = [];
		foreach ( self::STOREFRONT_ROOT_KEYS as $root_key ) {
			$root_taxon = $by_key[ $root_key ] ?? null;
			if ( ! is_array( $root_taxon ) ) {
				continue;
			}

			$root_slug = sanitize_title( (string) ( $root_taxon['slug'] ?? '' ) );
			$root_term = $terms_by_slug[ $root_slug ] ?? null;
			if ( ! $root_term instanceof WP_Term ) {
				continue;
			}

			$children = [];
			foreach ( $taxa as $child_taxon ) {
				if ( $root_key !== sanitize_key( (string) ( $child_taxon['parent_key'] ?? '' ) ) ) {
					continue;
				}

				$child_slug = sanitize_title( (string) ( $child_taxon['slug'] ?? '' ) );
				$child_term = $terms_by_slug[ $child_slug ] ?? null;
				if ( ! $child_term instanceof WP_Term || (int) $child_term->parent !== (int) $root_term->term_id ) {
					continue;
				}

				$publish_when_empty = ! empty( $child_taxon['publish_when_empty'] );
				if ( ! $publish_when_empty && absint( $child_term->count ) < 1 ) {
					continue;
				}

				$children[] = self::term_dto( $child_term, $child_taxon );
			}

			usort( $children, [ self::class, 'compare_sort' ] );
			if ( [] === $children && absint( $root_term->count ) < 1 && empty( $root_taxon['publish_when_empty'] ) ) {
				continue;
			}

			$group                 = self::term_dto( $root_term, $root_taxon );
			$group['productCount'] = [] === $children
				? absint( $root_term->count )
				: array_sum( array_map( static fn ( array $child ): int => absint( $child['productCount'] ?? 0 ), $children ) );
			$group['children']     = $children;
			$groups[]              = $group;
		}

		usort( $groups, [ self::class, 'compare_sort' ] );
		return $groups;
	}

	/** @return array<string,mixed>|null */
	public static function get_group( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		foreach ( self::get_groups() as $group ) {
			if ( $slug === ( $group['slug'] ?? '' ) ) {
				return $group;
			}
		}
		return null;
	}

	/** @return array{schema_version:int,root_taxa:array<int,string>,taxa:array<int,array<string,mixed>>} */
	public static function registry(): array {
		static $registry = null;
		if ( is_array( $registry ) ) {
			return $registry;
		}

		$raw     = is_readable( self::REGISTRY_PATH ) ? file_get_contents( self::REGISTRY_PATH ) : false;
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) || ! is_array( $decoded['taxa'] ?? null ) ) {
			$registry = [ 'schema_version' => 0, 'root_taxa' => [], 'taxa' => [] ];
			return $registry;
		}

		$registry = [
			'schema_version' => absint( $decoded['schema_version'] ?? 0 ),
			'root_taxa'      => array_values( array_map( 'sanitize_key', (array) ( $decoded['root_taxa'] ?? [] ) ) ),
			'taxa'           => array_values( $decoded['taxa'] ),
		];
		return $registry;
	}

	/** @param array<string,mixed> $taxon */
	private static function term_dto( WP_Term $term, array $taxon ): array {
		$image        = '';
		$thumbnail_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
		if ( $thumbnail_id > 0 ) {
			$url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
			if ( is_string( $url ) ) {
				$image = esc_url_raw( $url );
			}
		}

		return [
			'key'          => sanitize_key( (string) ( $taxon['key'] ?? '' ) ),
			'label'        => sanitize_text_field( wp_specialchars_decode( (string) $term->name, ENT_QUOTES ) ),
			'slug'         => sanitize_title( (string) $term->slug ),
			'sort'         => (int) ( $taxon['sort'] ?? PHP_INT_MAX ),
			'description'  => sanitize_text_field( wp_specialchars_decode( wp_strip_all_tags( (string) $term->description ), ENT_QUOTES ) ),
			'image'        => $image,
			'productCount' => absint( $term->count ),
		];
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	private static function compare_sort( array $left, array $right ): int {
		$order = (int) ( $left['sort'] ?? PHP_INT_MAX ) <=> (int) ( $right['sort'] ?? PHP_INT_MAX );
		return 0 !== $order ? $order : strcmp( (string) ( $left['slug'] ?? '' ), (string) ( $right['slug'] ?? '' ) );
	}
}

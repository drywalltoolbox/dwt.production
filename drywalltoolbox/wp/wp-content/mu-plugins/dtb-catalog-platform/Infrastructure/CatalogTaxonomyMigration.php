<?php
/**
 * Idempotent runtime repairs for canonical WooCommerce product_cat identity.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogTaxonomyMigration {

	private const OPTION_VERSION = 'dtb_catalog_taxonomy_migration_version';
	/*
	 * Version 1 could complete before a later catalog import recreated the
	 * historical `goosenecks` term. Bump the idempotent migration so existing
	 * installations reconcile that live legacy term on their next init.
	 */
	private const VERSION        = 4;
	private const BATCH_SIZE     = 100;

	public static function maybe_run(): void {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) >= self::VERSION || ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		$current_version = (int) get_option( self::OPTION_VERSION, 0 );
		$result = $current_version < 2 ? self::migrate_goosenecks_term() : true;
		if ( ! is_wp_error( $result ) ) {
			$result = self::migrate_category_hierarchy();
		}
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'DTB_Logger' ) ) {
				DTB_Logger::error( 'Catalog taxonomy migration failed', [
					'version' => self::VERSION,
					'code'    => $result->get_error_code(),
				] );
			}
			return;
		}

		update_option( self::OPTION_VERSION, self::VERSION, false );
		dtb_catalog_cache_invalidate_all();
	}

	/**
	 * Move existing Woo terms before a CSV import creates duplicate child slugs.
	 * Existing term IDs and product relationships are preserved.
	 *
	 * @return true|WP_Error
	 */
	private static function migrate_category_hierarchy() {
		$roots = [
			'automatic-taping-tools'  => 'Automatic Taping Tools',
			'corner-bead-tools'       => 'Corner Bead Tools',
			'hanging-tools'           => 'Hanging Tools',
			'tool-cases'              => 'Tool Cases',
			'materials-consumables'   => 'Drywall Supplies',
		];
		foreach ( $roots as $slug => $label ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				if ( 0 !== (int) $term->parent && 'tool-cases' !== $slug ) {
					return new WP_Error( 'catalog_taxonomy_root_conflict', 'Catalog root slug already belongs to a child term: ' . $slug );
				}
				continue;
			}
			$created = wp_insert_term( $label, 'product_cat', [ 'slug' => $slug, 'parent' => 0 ] );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		$move = [
			[ 'automatic-tapers', 'automatic-tapers', 'Automatic Tapers', 'automatic-taping-tools' ],
			[ 'flat-boxes', 'flat-boxes', 'Flat Boxes', 'automatic-taping-tools' ],
			[ 'corner-finishers', 'corner-finishers', 'Corner Finishers', 'automatic-taping-tools' ],
			[ 'corner-applicators-angle-boxes', 'angle-boxes-corner-applicators', 'Angle Boxes & Corner Applicators', 'automatic-taping-tools' ],
			[ 'compound-tubes', 'compound-tubes', 'Compound Tubes', 'automatic-taping-tools' ],
			[ 'powered-compound-applicators', 'powered-compound-applicators', 'Powered Compound Applicators', 'automatic-taping-tools' ],
			[ 'applicator-heads', 'applicator-heads', 'Applicator Heads', 'automatic-taping-tools' ],
			[ 'corner-flushers', 'corner-flushers', 'Corner Flushers', 'automatic-taping-tools' ],
			[ 'corner-rollers', 'corner-rollers', 'Corner Rollers', 'automatic-taping-tools' ],
			[ 'nail-spotters', 'nail-spotters', 'Nail Spotters', 'automatic-taping-tools' ],
			[ 'loading-compound-pumps', 'loading-pumps', 'Loading Pumps', 'automatic-taping-tools' ],
			[ 'goosenecks-box-fillers-adapters', 'goosenecks-box-fillers-adapters', 'Goosenecks, Box Fillers & Adapters', 'automatic-taping-tools' ],
			[ 'handles-extensions', 'handles', 'Handles', 'automatic-taping-tools' ],
			[ 'tool-storage-cases', 'tool-cases', 'Tool Cases', '' ],
			[ 'tool-cases', 'tool-cases', 'Tool Cases', '' ],
			[ 'mud-pan-accessories', 'mud-pan-accessories', 'Mud Pan Accessories', 'taping-finishing-tools' ],
			[ 'bead-tools', 'bead-hoppers', 'Bead Hoppers', 'corner-bead-tools' ],
			[ 'drywall-sealants', 'sealants', 'Sealants', 'materials-consumables' ],
			[ 'stilts', 'drywall-stilts', 'Drywall Stilts', '' ],
		];
		foreach ( $move as [ $old_slug, $new_slug, $label, $parent_slug ] ) {
			$source = get_term_by( 'slug', $old_slug, 'product_cat' );
			$target = get_term_by( 'slug', $new_slug, 'product_cat' );
			if ( ! $source instanceof WP_Term ) {
				continue;
			}
			if ( $target instanceof WP_Term && (int) $target->term_id !== (int) $source->term_id ) {
				return new WP_Error( 'catalog_taxonomy_term_conflict', 'Catalog category slug already belongs to another term: ' . $new_slug );
			}
			$parent = '' === $parent_slug ? null : get_term_by( 'slug', $parent_slug, 'product_cat' );
			if ( '' !== $parent_slug && ! $parent instanceof WP_Term ) {
				return new WP_Error( 'catalog_taxonomy_parent_missing', 'Catalog parent category is missing: ' . $parent_slug );
			}
			$parent_id = $parent instanceof WP_Term ? (int) $parent->term_id : 0;
			if ( $source->slug === $new_slug && (int) $source->parent === $parent_id && $source->name === $label ) {
				continue;
			}
			$updated = wp_update_term( $source->term_id, 'product_cat', [
				'name' => $label,
				'slug' => $new_slug,
				'parent' => $parent_id,
			] );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		$removed = self::remove_empty_accessories_root();
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		return true;
	}

	/** Remove the retired parent only when no direct products or child terms remain. */
	private static function remove_empty_accessories_root() {
		$term = get_term_by( 'slug', 'accessories', 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return true;
		}
		$children = get_terms( [
			'taxonomy'   => 'product_cat',
			'parent'     => (int) $term->term_id,
			'hide_empty' => false,
			'fields'     => 'ids',
		] );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		if ( 0 !== (int) $term->count || ! empty( $children ) ) {
			return new WP_Error( 'catalog_accessories_root_not_empty', 'Accessories still has direct products or child terms.' );
		}
		$deleted = wp_delete_term( (int) $term->term_id, 'product_cat' );
		return is_wp_error( $deleted ) ? $deleted : true;
	}

	/** Preserve category archive URLs after removing the Accessories ancestry. */
	public static function redirect_legacy_category_archives(): void {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ), [ 'GET', 'HEAD' ], true ) ) {
			return;
		}
		$permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : [];
		$base       = trim( (string) ( $permalinks['category_rewrite_slug'] ?? 'product-category' ), '/' );
		$path       = trim( (string) wp_parse_url( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		$home_path  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}
		$legacy_root = '' === $base ? 'accessories' : $base . '/accessories';
		$destination = '';
		if ( $path === $legacy_root ) {
			$destination = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
			if ( '' === $destination ) {
				$destination = home_url( '/' );
			}
		} elseif ( $path === $legacy_root . '/tool-cases' ) {
			$term = get_term_by( 'slug', 'tool-cases', 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term, 'product_cat' );
				$destination = is_wp_error( $link ) ? '' : $link;
			}
		} elseif ( $path === $legacy_root . '/mud-pan-accessories' ) {
			$term = get_term_by( 'slug', 'mud-pan-accessories', 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term, 'product_cat' );
				$destination = is_wp_error( $link ) ? '' : $link;
			}
		}
		if ( '' !== $destination ) {
			wp_safe_redirect( $destination, 301, 'DTB Catalog Taxonomy' );
			exit;
		}
	}

	/** @return true|WP_Error */
	private static function migrate_goosenecks_term() {
		$root = get_term_by( 'slug', 'taping-finishing-tools', 'product_cat' );
		if ( ! $root instanceof WP_Term ) {
			return new WP_Error( 'catalog_taxonomy_root_missing', 'Canonical Taping & Finishing Tools category is missing.' );
		}

		$canonical = get_term_by( 'slug', 'goosenecks-box-fillers-adapters', 'product_cat' );
		$legacy    = get_term_by( 'slug', 'goosenecks', 'product_cat' );

		if ( ! $canonical instanceof WP_Term && $legacy instanceof WP_Term ) {
			$updated = wp_update_term( $legacy->term_id, 'product_cat', [
				'name'   => 'Goosenecks, Box Fillers & Adapters',
				'slug'   => 'goosenecks-box-fillers-adapters',
				'parent' => $root->term_id,
			] );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$canonical = get_term( absint( $updated['term_id'] ?? 0 ), 'product_cat' );
			$legacy    = false;
		}

		$split_term = get_term_by( 'slug', 'box-fillers-adapters', 'product_cat' );
		if ( ! $canonical instanceof WP_Term && $split_term instanceof WP_Term ) {
			$inserted = wp_insert_term( 'Goosenecks, Box Fillers & Adapters', 'product_cat', [
				'slug'   => 'goosenecks-box-fillers-adapters',
				'parent' => $root->term_id,
			] );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
			$canonical = get_term( absint( $inserted['term_id'] ?? 0 ), 'product_cat' );
		}

		if ( ! $canonical instanceof WP_Term ) {
			// No affected runtime terms exist yet; a later catalog import may create them.
			return true;
		}

		foreach ( [ $legacy, $split_term ] as $source ) {
			if ( ! $source instanceof WP_Term || (int) $source->term_id === (int) $canonical->term_id ) {
				continue;
			}

			$merged = self::merge_term( $source, $canonical );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
		}

		clean_term_cache( [ $root->term_id, $canonical->term_id ], 'product_cat' );
		return true;
	}

	/** @return true|WP_Error */
	private static function merge_term( WP_Term $source, WP_Term $target ) {
		self::copy_term_media_if_missing( $source, $target );

		$page = 1;
		do {
			$object_ids = get_posts( [
				'post_type'              => [ 'product', 'product_variation' ],
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => [
					[
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => [ $source->term_id ],
					],
				],
			] );

			foreach ( $object_ids as $object_id ) {
				$result = wp_set_object_terms( absint( $object_id ), [ $target->term_id ], 'product_cat', true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$page++;
		} while ( count( $object_ids ) === self::BATCH_SIZE );

		$deleted = wp_delete_term( $source->term_id, 'product_cat' );
		return is_wp_error( $deleted ) ? $deleted : true;
	}

	private static function copy_term_media_if_missing( WP_Term $source, WP_Term $target ): void {
		foreach ( [ 'thumbnail_id', 'dtb_hero_image_id' ] as $meta_key ) {
			if ( get_term_meta( $target->term_id, $meta_key, true ) ) {
				continue;
			}
			$value = get_term_meta( $source->term_id, $meta_key, true );
			if ( '' !== $value && null !== $value ) {
				update_term_meta( $target->term_id, $meta_key, $value );
			}
		}
	}
}

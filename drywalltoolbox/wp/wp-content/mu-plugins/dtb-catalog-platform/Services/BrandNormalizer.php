<?php
/**
 * DTB_BrandNormalizer
 *
 * Maps raw brand strings (from WC categories, attributes, meta, or CSV) into
 * the canonical DTB brand identity: { key, label, slug }.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_BrandNormalizer {

	/**
	 * Canonical brand label → slug key.
	 * Slug is used in URLs (e.g. /products?brand=tapetech).
	 *
	 * @var array<string, string>
	 */
	const BRAND_TO_SLUG = [
		'TapeTech'               => 'tapetech',
		'Columbia Tools'         => 'columbia-taping-tools',
		'Asgard'                 => 'asgard',
		'SurPro'                 => 'surpro',
		'Graco'                  => 'graco',
		'Platinum Drywall Tools' => 'platinum',
		'Dura-Stilts'            => 'dura-stilts',
		'Level 5'                => 'level5',
		'USG Sheetrock® Tools'   => 'usg-sheetrock-tools',
	];

	/**
	 * Alias → canonical label.
	 * Covers common CSV/import variants that are not themselves canonical.
	 *
	 * @var array<string, string>
	 */
	const BRAND_ALIASES = [
		'Columbia'                => 'Columbia Tools',
		'columbia'                => 'Columbia Tools',
		'COLUMBIA'                => 'Columbia Tools',
		'Columbia Taping Tools'   => 'Columbia Tools',
		'columbia taping tools'   => 'Columbia Tools',
		'COLUMBIA TAPING TOOLS'   => 'Columbia Tools',
		'columbia-taping-tools'   => 'Columbia Tools',
		'columbia-tools'          => 'Columbia Tools',
		'TAPETECH'                => 'TapeTech',
		'Tape Tech'               => 'TapeTech',
		'LEVEL 5'                 => 'Level 5',
		'level 5'                 => 'Level 5',
		'Level5'                  => 'Level 5',
		'GRACO'                   => 'Graco',
		'SURPRO'                  => 'SurPro',
		'Sur-Pro'                 => 'SurPro',
		'SUR PRO'                 => 'SurPro',
		'DURA-STILTS'             => 'Dura-Stilts',
		'Dura Stilts'             => 'Dura-Stilts',
		'ASGARD'                  => 'Asgard',
		'Platinum'                => 'Platinum Drywall Tools',
		'PLATINUM'                => 'Platinum Drywall Tools',
		'USG'                     => 'USG Sheetrock® Tools',
		'usg'                     => 'USG Sheetrock® Tools',
		'USG Sheetrock Tools'     => 'USG Sheetrock® Tools',
		'usg sheetrock tools'     => 'USG Sheetrock® Tools',
		'usg-sheetrock-tools'     => 'USG Sheetrock® Tools',
	];

	/**
	 * Non-canonical slug aliases mapped to canonical slugs.
	 *
	 * @var array<string, string>
	 */
	const SLUG_ALIASES = [
		'columbia-tools' => 'columbia-taping-tools',
	];

	/**
	 * Normalize a raw brand string to the canonical { key, label, slug } tuple.
	 *
	 * Resolution order:
	 *   1. Alias → canonical label.
	 *   2. Exact canonical label in BRAND_TO_SLUG.
	 *   3. Case-insensitive alias/canonical scan.
	 *   4. Unknown brand → derive slug from sanitize_title().
	 *
	 * Aliases intentionally run before canonical lookup so imported brand labels
	 * such as "Columbia" collapse to the customer-facing canonical label
	 * "Columbia Tools" rather than creating a second frontend brand.
	 *
	 * @param  string $raw  Raw brand string.
	 * @return array{ key: string, label: string, slug: string }
	 */
	public static function normalize( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [ 'key' => '', 'label' => '', 'slug' => '' ];
		}

		// 1. Alias → canonical label.
		if ( isset( self::BRAND_ALIASES[ $raw ] ) ) {
			$canonical = self::BRAND_ALIASES[ $raw ];
			$slug      = self::BRAND_TO_SLUG[ $canonical ] ?? sanitize_title( $canonical );
			return [ 'key' => $slug, 'label' => $canonical, 'slug' => $slug ];
		}

		// 2. Exact canonical label.
		if ( isset( self::BRAND_TO_SLUG[ $raw ] ) ) {
			$slug = self::BRAND_TO_SLUG[ $raw ];
			return [ 'key' => $slug, 'label' => $raw, 'slug' => $slug ];
		}

		// 3. Case-insensitive alias/canonical scan.
		$lower = strtolower( $raw );
		foreach ( self::BRAND_ALIASES as $alias => $canonical ) {
			if ( strtolower( $alias ) === $lower ) {
				$slug = self::BRAND_TO_SLUG[ $canonical ] ?? sanitize_title( $canonical );
				return [ 'key' => $slug, 'label' => $canonical, 'slug' => $slug ];
			}
		}
		foreach ( self::BRAND_TO_SLUG as $label => $slug ) {
			if ( strtolower( $label ) === $lower ) {
				return [ 'key' => $slug, 'label' => $label, 'slug' => $slug ];
			}
		}

		// 4. Unknown brand — return as-is with derived slug.
		$slug = sanitize_title( $raw );
		return [ 'key' => $slug, 'label' => $raw, 'slug' => $slug ];
	}

	/**
	 * Normalize a URL brand slug (e.g. "tapetech") to the canonical label.
	 *
	 * @param  string $slug
	 * @return string  Canonical label, or empty string if not found.
	 */
	public static function label_from_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		if ( isset( self::SLUG_ALIASES[ $slug ] ) ) {
			$slug = self::SLUG_ALIASES[ $slug ];
		}
		foreach ( self::BRAND_TO_SLUG as $label => $s ) {
			if ( $s === $slug ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Return exact legacy and canonical meta values for a canonical brand slug.
	 *
	 * Older catalog imports stored brand keys with underscores or stored an
	 * accepted alias as the brand label. Keep those records discoverable through
	 * the canonical storefront brand route without rewriting protected catalog
	 * identity in place.
	 *
	 * @param  string $slug Canonical brand slug.
	 * @return array{keys:string[],labels:string[]}
	 */
	public static function meta_query_candidates( string $slug ): array {
		$label = self::label_from_slug( $slug );
		if ( '' === $label ) {
			return [
				'keys'   => [ sanitize_key( $slug ) ],
				'labels' => [],
			];
		}

		$keys   = [ $slug, str_replace( '-', '_', $slug ) ];
		$labels = [ $label ];

		foreach ( self::BRAND_ALIASES as $alias => $canonical_label ) {
			if ( $canonical_label !== $label ) {
				continue;
			}

			$alias_key = sanitize_title( $alias );
			if ( '' !== $alias_key ) {
				$keys[] = $alias_key;
				$keys[] = str_replace( '-', '_', $alias_key );
			}
			$labels[] = $alias;
		}

		return [
			'keys'   => array_values( array_unique( array_filter( $keys ) ) ),
			'labels' => array_values( array_unique( array_filter( $labels ) ) ),
		];
	}

	/** Returns true when $slug is a known canonical brand slug. */
	public static function is_known_slug( string $slug ): bool {
		$slug = strtolower( trim( $slug ) );
		if ( isset( self::SLUG_ALIASES[ $slug ] ) ) {
			$slug = self::SLUG_ALIASES[ $slug ];
		}
		return in_array( $slug, array_values( self::BRAND_TO_SLUG ), true );
	}
}

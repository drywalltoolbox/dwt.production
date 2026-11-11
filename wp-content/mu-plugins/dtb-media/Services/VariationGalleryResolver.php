<?php
/**
 * Canonical resolver for variation-specific product image galleries.
 *
 * The active catalog manifest is authoritative for ordering. Explicit native
 * WooCommerce variation media is the fallback when the manifest is unavailable.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_VariationGalleryResolver {

	/** @var string[] */
	private const IMAGE_EXTENSIONS = [ 'webp' ];

	/** @var array<string,array<int,array<string,mixed>>> */
	private static array $cache = [];

	/** @var array<string,mixed> */
	private static array $last_diagnostics = [];

	/**
	 * Resolve the complete ordered gallery for one variation SKU.
	 *
	 * Resolution is deterministic:
	 * 1. exact filenames from the active catalog CSV manifest;
	 * 2. explicit selected-variation WooCommerce attachments as fallback.
	 *
	 * @param string $sku          Variation SKU.
	 * @param int    $variation_id WooCommerce variation post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function resolve( string $sku, int $variation_id = 0 ): array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			self::$last_diagnostics = [
				'sku'           => '',
				'variationId'   => $variation_id,
				'resolvedCount' => 0,
				'reason'        => 'missing_sku',
			];
			return [];
		}

		$cache_key = strtolower( $sku ) . ':' . max( 0, $variation_id );
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$manifest = self::resolve_from_catalog_manifest( $sku );
		$woo      = $variation_id > 0
			? self::resolve_from_woocommerce_media( $sku, $variation_id )
			: [];

		$gallery = self::normalize_gallery( ! empty( $manifest ) ? $manifest : $woo );

		self::$last_diagnostics = [
			'sku'           => $sku,
			'variationId'   => $variation_id,
			'manifestCount' => count( $manifest ),
			'uploadCount'   => 0,
			'wooCount'      => count( $woo ),
			'resolvedCount' => count( $gallery ),
		];
		self::$cache[ $cache_key ] = $gallery;

		return $gallery;
	}

	/**
	 * Return diagnostics from the most recent resolve call.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_last_diagnostics(): array {
		return self::$last_diagnostics;
	}

	/**
	 * Normalize and deduplicate gallery entries while preserving source order.
	 *
	 * @param array<int,mixed> $gallery Raw gallery entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_gallery( array $gallery ): array {
		$out  = [];
		$seen = [];

		foreach ( $gallery as $image ) {
			if ( is_string( $image ) ) {
				$image = [ 'src' => $image ];
			}
			if ( ! is_array( $image ) ) {
				continue;
			}

			$src = trim( (string) ( $image['src'] ?? $image['url'] ?? $image['full'] ?? $image['large'] ?? '' ) );
			if ( '' === $src ) {
				continue;
			}

			$key = strtolower( rtrim( strtok( $src, '?' ) ?: $src, '/' ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$image['src']  = $src;
			$out[]         = $image;
		}

		return $out;
	}

	/**
	 * Resolve exact catalog-manifest filenames for a SKU.
	 *
	 * @param string $sku Variation SKU.
	 * @return array<int,array{src:string}>
	 */
	private static function resolve_from_catalog_manifest( string $sku ): array {
		if ( ! function_exists( 'dtb_get_catalog_image_filenames_by_sku' ) ) {
			return [];
		}

		$manifest = dtb_get_catalog_image_filenames_by_sku();
		$files    = $manifest[ strtolower( trim( $sku ) ) ] ?? [];
		if ( ! is_array( $files ) || empty( $files ) ) {
			return [];
		}

		$index = self::get_upload_file_index();
		if ( empty( $index ) ) {
			return [];
		}

		$by_basename = [];
		foreach ( $index as $file ) {
			$filename = strtolower( (string) ( $file['filename'] ?? '' ) );
			$url      = (string) ( $file['url'] ?? '' );
			if ( '' === $filename || '' === $url || isset( $by_basename[ $filename ] ) ) {
				continue;
			}
			$by_basename[ $filename ] = $url;
		}

		$gallery = [];
		foreach ( $files as $filename ) {
			$key = strtolower( basename( (string) $filename ) );
			if ( '' !== $key && isset( $by_basename[ $key ] ) ) {
				$gallery[] = [ 'src' => $by_basename[ $key ] ];
			}
		}

		return self::normalize_gallery( $gallery );
	}

	/**
	 * Resolve all managed upload files that contain the exact SKU token.
	 *
	 * @param string $sku Variation SKU.
	 * @return array<int,array{src:string}>
	 */
	private static function resolve_from_uploads( string $sku ): array {
		$pattern = self::build_sku_pattern( $sku );
		if ( '' === $pattern ) {
			return [];
		}

		$matches = [];
		foreach ( self::get_upload_file_index() as $file ) {
			$filename = (string) ( $file['filename'] ?? '' );
			$url      = (string) ( $file['url'] ?? '' );
			if ( '' === $filename || '' === $url || 1 !== preg_match( $pattern, $filename ) ) {
				continue;
			}
			$matches[] = [
				'filename' => $filename,
				'path'     => (string) ( $file['path'] ?? '' ),
				'src'      => $url,
			];
		}

		usort( $matches, static function ( array $a, array $b ): int {
			$by_name = strnatcasecmp( (string) $a['filename'], (string) $b['filename'] );
			return 0 !== $by_name ? $by_name : strcmp( (string) $a['path'], (string) $b['path'] );
		} );

		return self::normalize_gallery( array_map(
			static fn( array $image ): array => [ 'src' => (string) $image['src'] ],
			$matches
		) );
	}

	/**
	 * Resolve explicit selected-variation WooCommerce attachments.
	 *
	 * @param string $sku          Variation SKU.
	 * @param int    $variation_id WooCommerce variation post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function resolve_from_woocommerce_media( string $sku, int $variation_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return [];
		}

		$primary_id = method_exists( $variation, 'get_image_id' )
			? (int) $variation->get_image_id( 'edit' )
			: 0;
		$ids        = [];

		if ( $primary_id > 0 ) {
			$ids[] = $primary_id;
		}
		if ( method_exists( $variation, 'get_gallery_image_ids' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $variation->get_gallery_image_ids() ) );
		}

		$gallery = [];
		if ( $primary_id > 0 ) {
			$primary = self::image_entry_from_attachment_id( $primary_id );
			if ( ! empty( $primary ) ) {
				$gallery[] = $primary;
			}
		}

		foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $attachment_id ) {
			$entry = self::image_entry_from_attachment_id( (int) $attachment_id );
			if ( empty( $entry['src'] ) ) {
				continue;
			}
			$gallery[] = $entry;
		}

		return self::normalize_gallery( $gallery );
	}

	/**
	 * Build an attachment gallery entry.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	private static function image_entry_from_attachment_id( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return [];
		}

		$src = function_exists( 'wp_get_attachment_image_url' )
			? wp_get_attachment_image_url( $attachment_id, 'full' )
			: false;
		if ( ! $src && function_exists( 'wp_get_attachment_url' ) ) {
			$src = wp_get_attachment_url( $attachment_id );
		}
		if ( ! $src ) {
			return [];
		}

		$entry = [
			'id'  => $attachment_id,
			'src' => (string) $src,
		];
		$alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			$entry['alt'] = trim( $alt );
		}

		return $entry;
	}

	/**
	 * Build searchable attachment text without discarding SKU separators.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $src           Attachment URL.
	 * @return string
	 */
	private static function attachment_match_text( int $attachment_id, string $src ): string {
		$parts = [
			basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ),
			(string) get_the_title( $attachment_id ),
			(string) wp_get_attachment_caption( $attachment_id ),
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		];

		return strtolower( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * Return the request-cached recursive upload file index.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_upload_file_index(): array {
		if ( ! function_exists( 'dtb_get_image_file_index' ) ) {
			return [];
		}

		$upload = self::resolve_upload_directory();
		$dir    = (string) ( $upload['basedir'] ?? '' );
		$url    = (string) ( $upload['baseurl'] ?? '' );
		if ( '' === $dir || '' === $url || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return [];
		}

		return dtb_get_image_file_index( $dir, $url, self::IMAGE_EXTENSIONS );
	}

	/**
	 * Resolve the managed catalog media directory.
	 *
	 * @return array{basedir:string,baseurl:string}
	 */
	private static function resolve_upload_directory(): array {
		$relative_path = defined( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH' )
			? trim( (string) DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH, '/\\' )
			: '2026/media';

		if ( function_exists( 'dtb_image_sync_resolve_upload_directory' ) ) {
			$resolved = dtb_image_sync_resolve_upload_directory( $relative_path );
			if ( is_array( $resolved ) && ! empty( $resolved['basedir'] ) && ! empty( $resolved['baseurl'] ) ) {
				return [
					'basedir' => (string) $resolved['basedir'],
					'baseurl' => (string) $resolved['baseurl'],
				];
			}
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
			return [ 'basedir' => '', 'baseurl' => '' ];
		}

		return [
			'basedir' => trailingslashit( (string) $upload['basedir'] ) . $relative_path,
			'baseurl' => trailingslashit( (string) $upload['baseurl'] ) . $relative_path,
		];
	}

	/**
	 * Build an exact, separator-tolerant SKU matcher.
	 *
	 * Character boundaries prevent a short SKU from matching a longer sibling
	 * while still matching files such as level5_4_600p_01.webp.
	 *
	 * @param string $sku Variation SKU.
	 * @return string Valid PCRE pattern or an empty string.
	 */
	private static function build_sku_pattern( string $sku ): string {
		$token = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $sku ) ?? '' );
		if ( strlen( $token ) < 3 ) {
			return '';
		}

		$characters = str_split( $token );
		$body       = implode( '[^a-z0-9]*', array_map(
			static fn( string $character ): string => preg_quote( $character, '/' ),
			$characters
		) );

		return '/(?<![a-z0-9])' . $body . '(?![a-z0-9])/i';
	}
}

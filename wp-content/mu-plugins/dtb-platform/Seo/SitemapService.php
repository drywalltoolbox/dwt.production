<?php
/**
 * DTB Platform — Canonical sitemap service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// This file is the sole declaration site. The platform bootstrap loads it
// with require_once, so duplicate class sentinels or compatibility shadows
// must not be introduced elsewhere in the MU-plugin root.
final class DTB_SitemapService {
	private const REWRITE_VERSION_OPTION = 'dtb_sitemap_rewrite_version';
	private const GENERATION_OPTION      = 'dtb_sitemap_generation';
	private const REWRITE_VERSION        = '1';
	private const CACHE_TTL              = HOUR_IN_SECONDS;

	private static bool $invalidated = false;

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_rewrites' ], 5 );
		add_action( 'init', [ self::class, 'maybe_flush_rewrites' ], 99 );
		add_filter( 'query_vars', [ self::class, 'register_query_vars' ] );
		add_action( 'template_redirect', [ self::class, 'maybe_serve' ], 0 );
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter( 'robots_txt', [ self::class, 'filter_robots_txt' ], 20, 2 );

		add_action( 'save_post_product', [ self::class, 'invalidate_from_post' ], 10, 3 );
		add_action( 'before_delete_post', [ self::class, 'invalidate_from_deleted_post' ] );
		add_action( 'created_term', [ self::class, 'invalidate_from_term' ], 10, 3 );
		add_action( 'edited_term', [ self::class, 'invalidate_from_term' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'invalidate_from_term' ], 10, 3 );
	}

	public static function register_rewrites(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?dtb_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemaps/([a-z0-9-]+)-([1-9][0-9]*)\.xml$', 'index.php?dtb_sitemap=$matches[1]&dtb_sitemap_page=$matches[2]', 'top' );
	}

	public static function maybe_flush_rewrites(): void {
		if ( self::REWRITE_VERSION === (string) get_option( self::REWRITE_VERSION_OPTION, '' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false );
	}

	/** @param string[] $vars */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'dtb_sitemap';
		$vars[] = 'dtb_sitemap_page';
		return array_values( array_unique( $vars ) );
	}

	public static function maybe_serve(): void {
		$type = sanitize_key( (string) get_query_var( 'dtb_sitemap', '' ) );
		if ( '' === $type ) {
			return;
		}

		$page = max( 1, absint( get_query_var( 'dtb_sitemap_page', 1 ) ) );
		$xml  = self::document( $type, $page );
		if ( null === $xml ) {
			status_header( 404 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			echo '<?xml version="1.0" encoding="UTF-8"?><error><message>Sitemap not found.</message></error>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$etag = '"' . hash( 'sha256', $xml ) . '"';
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			$request_etag = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );
			if ( hash_equals( $etag, $request_etag ) ) {
				status_header( 304 );
				header( 'ETag: ' . $etag );
				exit;
			}
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=86400' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'ETag: ' . $etag );
		header( 'Vary: Accept-Encoding' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function filter_robots_txt( string $output, bool $public ): string {
		unset( $public );
		$lines = preg_split( '/\R/', trim( $output ) ) ?: [];
		$lines = array_values(
			array_filter(
				$lines,
				static fn( string $line ): bool => 0 !== stripos( trim( $line ), 'Sitemap:' )
			)
		);
		$lines[] = 'Sitemap: ' . home_url( '/sitemap.xml' );
		return trim( implode( "\n", $lines ) ) . "\n";
	}

	public static function invalidate( string $reason = 'content_changed' ): int {
		if ( self::$invalidated ) {
			return (int) get_option( self::GENERATION_OPTION, 1 );
		}

		self::$invalidated = true;
		$generation       = max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) + 1 );
		update_option( self::GENERATION_OPTION, $generation, false );

		if ( function_exists( 'dtb_log_cache_event' ) ) {
			dtb_log_cache_event(
				'sitemap_cache_invalidated',
				[ 'reason' => sanitize_key( $reason ), 'generation' => $generation ]
			);
		}

		return $generation;
	}

	public static function invalidate_from_post( int $post_id, WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::invalidate( 'product_saved' );
	}

	public static function invalidate_from_deleted_post( int $post_id ): void {
		if ( 'product' === get_post_type( $post_id ) ) {
			self::invalidate( 'product_deleted' );
		}
	}

	public static function invalidate_from_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $term_id, $tt_id );
		$brand_taxonomy = DTB_SitemapUrlRepository::brand_taxonomy();
		if ( 'product_cat' === $taxonomy || ( '' !== $brand_taxonomy && $brand_taxonomy === $taxonomy ) ) {
			self::invalidate( 'taxonomy_changed' );
		}
	}

	private static function document( string $type, int $page ): ?string {
		$generation = max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );
		$cache_key  = 'drywall_cache_sitemap_' . md5( $generation . '|' . $type . '|' . $page . '|' . home_url( '/' ) );
		$cached     = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$xml = self::build_document( $type, $page );
		if ( null !== $xml ) {
			set_transient( $cache_key, $xml, self::CACHE_TTL );
		}
		return $xml;
	}

	private static function build_document( string $type, int $page ): ?string {
		if ( 'index' === $type ) {
			return DTB_SitemapXmlRenderer::render_index( self::index_entries() );
		}

		switch ( $type ) {
			case 'static':
				return 1 === $page ? DTB_SitemapXmlRenderer::render_urlset( DTB_SitemapUrlRepository::static_urls() ) : null;
			case 'products':
				return $page <= DTB_SitemapUrlRepository::product_pages()
					? DTB_SitemapXmlRenderer::render_urlset( DTB_SitemapUrlRepository::product_urls( $page ) )
					: null;
			case 'product-categories':
				return $page <= DTB_SitemapUrlRepository::taxonomy_pages( 'product_cat' )
					? DTB_SitemapXmlRenderer::render_urlset( DTB_SitemapUrlRepository::taxonomy_urls( 'product_cat', $page, '/category' ) )
					: null;
			case 'brands':
				$taxonomy = DTB_SitemapUrlRepository::brand_taxonomy();
				return '' !== $taxonomy && $page <= DTB_SitemapUrlRepository::taxonomy_pages( $taxonomy )
					? DTB_SitemapXmlRenderer::render_urlset( DTB_SitemapUrlRepository::taxonomy_urls( $taxonomy, $page, '/products/brands' ) )
					: null;
			default:
				return null;
		}
	}

	/** @return array<int,array{loc:string}> */
	private static function index_entries(): array {
		$entries = [ [ 'loc' => home_url( '/sitemaps/static-1.xml' ) ] ];
		foreach (
			[
				'products'           => DTB_SitemapUrlRepository::product_pages(),
				'product-categories' => DTB_SitemapUrlRepository::taxonomy_pages( 'product_cat' ),
			] as $type => $pages
		) {
			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = [ 'loc' => home_url( '/sitemaps/' . $type . '-' . $page . '.xml' ) ];
			}
		}

		$brand_taxonomy = DTB_SitemapUrlRepository::brand_taxonomy();
		if ( '' !== $brand_taxonomy ) {
			$pages = DTB_SitemapUrlRepository::taxonomy_pages( $brand_taxonomy );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = [ 'loc' => home_url( '/sitemaps/brands-' . $page . '.xml' ) ];
			}
		}

		return $entries;
	}
}

DTB_SitemapService::register();

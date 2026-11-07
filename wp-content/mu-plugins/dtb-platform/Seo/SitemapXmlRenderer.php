<?php
/**
 * DTB Platform — Sitemap XML renderer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_SitemapXmlRenderer' ) ) {
	return;
}

final class DTB_SitemapXmlRenderer {
	/**
	 * Render a sitemap index.
	 *
	 * @param array<int,array{loc:string,lastmod?:string}> $sitemaps Sitemap records.
	 */
	public static function render_index( array $sitemaps ): string {
		$xml = self::declaration();
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ( $sitemaps as $sitemap ) {
			$loc = self::validated_url( $sitemap['loc'] ?? '' );
			if ( '' === $loc ) {
				continue;
			}

			$xml .= '<sitemap><loc>' . esc_xml( $loc ) . '</loc>';
			if ( ! empty( $sitemap['lastmod'] ) ) {
				$xml .= '<lastmod>' . esc_xml( self::normalized_lastmod( (string) $sitemap['lastmod'] ) ) . '</lastmod>';
			}
			$xml .= '</sitemap>';
		}

		return $xml . '</sitemapindex>';
	}

	/**
	 * Render a URL set.
	 *
	 * @param array<int,array{loc:string,lastmod?:string}> $urls URL records.
	 */
	public static function render_urlset( array $urls ): string {
		$xml = self::declaration();
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ( $urls as $url ) {
			$loc = self::validated_url( $url['loc'] ?? '' );
			if ( '' === $loc ) {
				continue;
			}

			$xml .= '<url><loc>' . esc_xml( $loc ) . '</loc>';
			if ( ! empty( $url['lastmod'] ) ) {
				$xml .= '<lastmod>' . esc_xml( self::normalized_lastmod( (string) $url['lastmod'] ) ) . '</lastmod>';
			}
			$xml .= '</url>';
		}

		return $xml . '</urlset>';
	}

	private static function declaration(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>';
	}

	/** Only emit absolute HTTP(S) URLs on the configured site host. */
	private static function validated_url( string $url ): string {
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $url ) {
			return '';
		}

		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return '' !== $site_host && hash_equals( $site_host, $url_host ) ? $url : '';
	}

	private static function normalized_lastmod( string $value ): string {
		$timestamp = strtotime( $value );
		return false === $timestamp ? gmdate( DATE_W3C ) : gmdate( DATE_W3C, $timestamp );
	}
}

<?php
/**
 * Render the registered public storefront route shell with useful HTML before
 * React loads. Route copy remains in the versioned frontend build manifest.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_StorefrontRouteRenderer {
	private const MANIFEST_FILE = 'storefront-public-routes.json';
	private const MAX_MANIFEST_BYTES = 262144;
	private const MAX_SHELL_BYTES = 2097152;

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_render' ], 0 );
	}

	/**
	 * Render a published WooCommerce product after the catalog route has
	 * authoritatively confirmed its identity and availability.
	 *
	 * @param array<string,mixed> $product WooCommerce REST-shaped product.
	 * @param string              $slug    Canonical storefront slug.
	 */
	public static function render_product( array $product, string $slug ): void {
		$document_root = dirname( ABSPATH );
		$shell_path    = $document_root . '/index.html';
		$shell_size    = is_file( $shell_path ) ? filesize( $shell_path ) : false;
		if ( false === $shell_size || $shell_size < 1 || $shell_size > self::MAX_SHELL_BYTES || ! is_readable( $shell_path ) ) {
			self::unavailable();
		}

		$name = is_string( $product['name'] ?? null ) ? trim( $product['name'] ) : '';
		if ( '' === $name || $slug !== (string) ( $product['slug'] ?? '' ) ) {
			self::unavailable();
		}

		$meta        = self::product_meta( $product['meta_data'] ?? [] );
		$seo_title   = trim( (string) ( $meta['_dtb_seo_title'] ?? '' ) );
		$seo_desc    = trim( (string) ( $meta['_dtb_seo_description'] ?? '' ) );
		$description = '' !== $seo_desc ? $seo_desc : self::plain_text( $product['short_description'] ?? $product['description'] ?? '' );
		if ( '' === $description ) {
			$description = 'View product details and catalog information from Drywall Toolbox.';
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $description ) > 160 ) {
			$description = rtrim( mb_substr( $description, 0, 159 ) ) . '…';
		} elseif ( strlen( $description ) > 160 ) {
			$description = rtrim( substr( $description, 0, 159 ) ) . '…';
		}

		$canonical_path = '/products/' . rawurlencode( $slug );
		$custom_canonical = trim( (string) ( $meta['_dtb_seo_canonical'] ?? '' ) );
		if ( '' !== $custom_canonical ) {
			$parsed = wp_parse_url( $custom_canonical );
			if ( is_array( $parsed ) && 'https' === strtolower( (string) ( $parsed['scheme'] ?? '' ) ) && strtolower( (string) ( $parsed['host'] ?? '' ) ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) && empty( $parsed['user'] ) && empty( $parsed['pass'] ) ) {
				$canonical_path = (string) ( $parsed['path'] ?? '/' );
				if ( isset( $parsed['query'] ) ) {
					$canonical_path .= '?' . $parsed['query'];
				}
			}
		}
		$canonical = home_url( $canonical_path );
		$title     = '' !== $seo_title ? $seo_title : $name . ' | Drywall Toolbox';
		$noindex   = '1' === (string) ( $meta['_dtb_seo_noindex'] ?? '' );
		$image     = '';
		if ( isset( $product['images'][0]['src'] ) && is_string( $product['images'][0]['src'] ) ) {
			$image = esc_url( $product['images'][0]['src'] );
		}

		$content  = '<main class="dtb-home-static-fallback dtb-route-static-content dtb-product-static-content">';
		$content .= '<h1>' . esc_html( $name ) . '</h1>';
		if ( '' !== $image ) {
			$content .= '<img src="' . $image . '" alt="' . esc_attr( $name ) . '" width="640" height="640" loading="eager" />';
		}
		$short_description      = wp_kses_post( (string) ( $product['short_description'] ?? '' ) );
		$full_description       = wp_kses_post( (string) ( $product['description'] ?? '' ) );
		$short_description_text = self::plain_text( $short_description );
		$full_description_text  = self::plain_text( $full_description );
		if ( '' !== $short_description_text ) {
			$content .= '<section><h2>Product overview</h2>' . $short_description . '</section>';
		}
		if ( '' !== $full_description_text && $full_description_text !== $short_description_text ) {
			$content .= '<section><h2>Product details</h2>' . $full_description . '</section>';
		}
		if ( '' === $short_description_text && '' === $full_description_text ) {
			$content .= '<p>' . esc_html( $description ) . '</p>';
		}
		if ( ! empty( $product['sku'] ) && is_string( $product['sku'] ) ) {
			$content .= '<p>SKU: ' . esc_html( $product['sku'] ) . '</p>';
		}
		$content .= '<nav aria-label="Related Drywall Toolbox pages"><ul><li><a href="' . esc_url( home_url( '/products' ) ) . '">Browse all products</a></li><li><a href="' . esc_url( home_url( '/parts' ) ) . '">Find replacement parts</a></li><li><a href="' . esc_url( home_url( '/schematics' ) ) . '">Browse tool schematics</a></li></ul></nav></main>';

		$shell = file_get_contents( $shell_path );
		if ( ! is_string( $shell ) ) {
			self::unavailable();
		}
		// Backend can be deployed before the frontend shell. Keep existing
		// product links available until the marked shell arrives.
		if ( 0 === substr_count( $shell, 'data-dtb-route-content' ) && ! self::has_seo_markers( $shell ) ) {
			self::send_response( $shell, strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		}
		if ( ! self::has_route_markers( $shell ) ) {
			self::unavailable();
		}
		$html = self::replace_seo_tag( $shell, 'title', $title, 'text' );
		$html = self::replace_seo_tag( $html, 'description', $description, 'content' );
		$html = self::replace_seo_tag( $html, 'robots', $noindex ? 'noindex, follow' : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1', 'content' );
		$html = self::replace_seo_tag( $html, 'canonical', $canonical, 'href' );
		$html = self::replace_seo_tag( $html, 'og-title', $title, 'content' );
		$html = self::replace_seo_tag( $html, 'og-description', $description, 'content' );
		$html = self::replace_seo_tag( $html, 'og-url', $canonical, 'content' );
		$html = self::replace_seo_tag( $html, 'twitter-title', $title, 'content' );
		$html = self::replace_seo_tag( $html, 'twitter-description', $description, 'content' );
		$html = self::replace_route_content( $html, $content );
		if ( ! is_string( $html ) ) {
			self::unavailable();
		}
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
		self::send_response( $html, strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
	}

	public static function maybe_render(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( '1' !== (string) ( $_GET['dtb_storefront'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing marker is set only by explicit read-only Apache routes.
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$raw_path    = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $raw_path ) || str_contains( $raw_path, "\0" ) ) {
			return;
		}

		$path = '/' . trim( rawurldecode( $raw_path ), '/' );
		if ( '/' === $path || str_starts_with( $path, '/staging/' ) ) {
			return;
		}

		$taxonomy_route = self::taxonomy_route( $path );
		if ( null !== $taxonomy_route ) {
			self::render_taxonomy( $taxonomy_route['taxonomy'], $taxonomy_route['slug'], $path, $method );
		}

		$document_root = dirname( ABSPATH );
		$manifest_path = $document_root . '/' . self::MANIFEST_FILE;
		$manifest_size = is_file( $manifest_path ) ? filesize( $manifest_path ) : false;
		if ( false === $manifest_size || $manifest_size < 2 || $manifest_size > self::MAX_MANIFEST_BYTES || ! is_readable( $manifest_path ) ) {
			self::unavailable();
		}

		$manifest_json = file_get_contents( $manifest_path );
		$manifest      = is_string( $manifest_json ) ? json_decode( $manifest_json, true ) : null;
		$route         = is_array( $manifest ) && 1 === ( $manifest['version'] ?? null )
			? ( $manifest['routes'][ $path ] ?? null )
			: null;
		if ( ! is_array( $route ) ) {
			self::unavailable();
		}

		$route = self::validated_route( $route );
		if ( null === $route ) {
			self::unavailable();
		}

		$shell_path = $document_root . '/index.html';
		$shell_size = is_file( $shell_path ) ? filesize( $shell_path ) : false;
		if ( false === $shell_size || $shell_size < 1 || $shell_size > self::MAX_SHELL_BYTES || ! is_readable( $shell_path ) ) {
			self::unavailable();
		}

		$shell = file_get_contents( $shell_path );
		if ( ! is_string( $shell ) || ! self::has_route_markers( $shell ) ) {
			self::unavailable();
		}

		$canonical = home_url( $path );
		$title     = $route['title'] . ' | Drywall Toolbox';
		$noindex   = self::has_noncanonical_catalog_query( $path );
		$content   = self::render_content( $route, $path );
		$html      = self::replace_seo_tag( $shell, 'title', $title, 'text' );
		$html      = self::replace_seo_tag( $html, 'description', $route['description'], 'content' );
		$html      = self::replace_seo_tag( $html, 'robots', $noindex ? 'noindex, follow' : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1', 'content' );
		$html      = self::replace_seo_tag( $html, 'canonical', $canonical, 'href' );
		$html      = self::replace_seo_tag( $html, 'og-title', $title, 'content' );
		$html      = self::replace_seo_tag( $html, 'og-description', $route['description'], 'content' );
		$html      = self::replace_seo_tag( $html, 'og-url', $canonical, 'content' );
		$html      = self::replace_seo_tag( $html, 'twitter-title', $title, 'content' );
		$html      = self::replace_seo_tag( $html, 'twitter-description', $route['description'], 'content' );
		$html      = self::replace_route_content( $html, $content );
		if ( ! is_string( $html ) ) {
			self::unavailable();
		}
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}

		self::send_response( $html, $method );
	}

	/** @return array{taxonomy:string,slug:string}|null */
	private static function taxonomy_route( string $path ): ?array {
		if ( preg_match( '~^/category/([^/]+)$~uD', $path, $matches ) ) {
			return [ 'taxonomy' => 'product_cat', 'slug' => $matches[1] ];
		}
		if ( preg_match( '~^/products/brands/([^/]+)$~uD', $path, $matches ) && class_exists( 'DTB_SitemapUrlRepository' ) ) {
			$taxonomy = DTB_SitemapUrlRepository::brand_taxonomy();
			return '' !== $taxonomy ? [ 'taxonomy' => $taxonomy, 'slug' => $matches[1] ] : null;
		}
		return null;
	}

	private static function render_taxonomy( string $taxonomy, string $slug, string $path, string $method ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			self::unavailable();
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( is_wp_error( $term ) ) {
			self::unavailable();
		}
		global $wpdb;
		if ( false === $term && '' !== (string) $wpdb->last_error ) {
			self::unavailable();
		}
		if ( ! $term instanceof WP_Term || 0 === (int) $term->count ) {
			status_header( 404 );
			header( 'Cache-Control: public, max-age=60' );
			exit;
		}
		$description = self::plain_text( (string) $term->description );
		if ( '' === $description ) {
			$description = 'Browse ' . $term->name . ' products from Drywall Toolbox.';
		}
		$description = self::truncate( $description, 160 );
		$title     = $term->name . ' | Drywall Toolbox';
		$canonical = home_url( $path );
		$content   = '<main class="dtb-home-static-fallback dtb-route-static-content"><h1>' . esc_html( $term->name ) . '</h1><p>' . esc_html( $description ) . '</p><p><a href="' . esc_url( home_url( '/products' ) ) . '">Browse all products</a></p>';
		if ( 'product_cat' === $taxonomy && function_exists( 'wc_get_products' ) ) {
			try {
				$products = wc_get_products(
					[
						'status'     => 'publish',
						'visibility' => 'visible',
						'category'   => [ $slug ],
						'limit'      => 12,
						'orderby'    => 'menu_order',
						'order'      => 'ASC',
						'return'     => 'objects',
					]
				);
			} catch ( Throwable $error ) {
				$products = [];
			}

			if ( is_array( $products ) && ! empty( $products ) ) {
				$content .= '<section aria-labelledby="dtb-category-products-heading"><h2 id="dtb-category-products-heading">Products in ' . esc_html( $term->name ) . '</h2><ul>';
				foreach ( $products as $product ) {
					if ( ! $product instanceof WC_Product ) {
						continue;
					}
					$product_slug = trim( (string) $product->get_slug() );
					$product_name = trim( (string) $product->get_name() );
					if ( '' === $product_slug || '' === $product_name ) {
						continue;
					}

					$content .= '<li><a href="' . esc_url( home_url( '/products/' . rawurlencode( $product_slug ) ) ) . '">' . esc_html( $product_name ) . '</a>';
					$product_summary = self::plain_text( (string) $product->get_short_description() );
					if ( '' !== $product_summary ) {
						$content .= '<p>' . esc_html( self::truncate( $product_summary, 180 ) ) . '</p>';
					}
					$content .= '</li>';
				}
				$content .= '</ul></section>';
			}
		}
		$content .= '</main>';
		self::render_route_document( $title, $description, $canonical, $content, $method );
	}

	private static function render_route_document( string $title, string $description, string $canonical, string $content, string $method ): void {
		$document_root = dirname( ABSPATH );
		$shell_path    = $document_root . '/index.html';
		$shell_size    = is_file( $shell_path ) ? filesize( $shell_path ) : false;
		if ( false === $shell_size || $shell_size < 1 || $shell_size > self::MAX_SHELL_BYTES || ! is_readable( $shell_path ) ) {
			self::unavailable();
		}
		$shell = file_get_contents( $shell_path );
		if ( ! is_string( $shell ) || ! self::has_route_markers( $shell ) ) {
			self::unavailable();
		}
		$html = self::replace_seo_tag( $shell, 'title', $title, 'text' );
		$html = self::replace_seo_tag( $html, 'description', $description, 'content' );
		$html = self::replace_seo_tag( $html, 'robots', 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1', 'content' );
		$html = self::replace_seo_tag( $html, 'canonical', $canonical, 'href' );
		$html = self::replace_seo_tag( $html, 'og-title', $title, 'content' );
		$html = self::replace_seo_tag( $html, 'og-description', $description, 'content' );
		$html = self::replace_seo_tag( $html, 'og-url', $canonical, 'content' );
		$html = self::replace_seo_tag( $html, 'twitter-title', $title, 'content' );
		$html = self::replace_seo_tag( $html, 'twitter-description', $description, 'content' );
		$html = self::replace_route_content( $html, $content );
		if ( ! is_string( $html ) ) {
			self::unavailable();
		}
		self::send_response( $html, $method );
	}

	/** @return array{title:string,description:string,heading:string,intro:string,sections:array<int,array{heading:string,text:string}>,links:array<int,array{label:string,href:string}>}|null */
	private static function validated_route( array $route ): ?array {
		$strings = [ 'title', 'description', 'heading', 'intro' ];
		foreach ( $strings as $key ) {
			if ( ! isset( $route[ $key ] ) || ! is_string( $route[ $key ] ) || '' === trim( $route[ $key ] ) || strlen( $route[ $key ] ) > 1000 ) {
				return null;
			}
		}
		if ( ! isset( $route['sections'], $route['links'] ) || ! is_array( $route['sections'] ) || ! is_array( $route['links'] ) || count( $route['sections'] ) > 12 || count( $route['links'] ) > 24 ) {
			return null;
		}

		$sections = [];
		foreach ( $route['sections'] as $section ) {
			if ( ! is_array( $section ) || ! is_string( $section['heading'] ?? null ) || ! is_string( $section['text'] ?? null ) || '' === trim( $section['heading'] ) || '' === trim( $section['text'] ) || strlen( $section['heading'] ) > 200 || strlen( $section['text'] ) > 2000 ) {
				return null;
			}
			$sections[] = [ 'heading' => $section['heading'], 'text' => $section['text'] ];
		}

		$links = [];
		foreach ( $route['links'] as $link ) {
			$href = is_array( $link ) ? ( $link['href'] ?? null ) : null;
			if ( ! is_array( $link ) || ! is_string( $link['label'] ?? null ) || ! is_string( $href ) || ! preg_match( '~^/(?!/)[a-zA-Z0-9/_-]*$~D', $href ) || str_contains( $href, '..' ) ) {
				return null;
			}
			$links[] = [ 'label' => $link['label'], 'href' => $href ];
		}

		return [
			'title'       => $route['title'],
			'description' => $route['description'],
			'heading'     => $route['heading'],
			'intro'       => $route['intro'],
			'sections'    => $sections,
			'links'       => $links,
		];
	}

	private static function render_content( array $route, string $path ): string {
		$html  = '<main class="dtb-home-static-fallback dtb-route-static-content">';
		$html .= '<h1>' . esc_html( $route['heading'] ) . '</h1>';
		$html .= '<p>' . esc_html( $route['intro'] ) . '</p>';
		foreach ( $route['sections'] as $section ) {
			$html .= '<section><h2>' . esc_html( $section['heading'] ) . '</h2><p>' . esc_html( $section['text'] ) . '</p></section>';
		}
		if ( ! empty( $route['links'] ) ) {
			$html .= '<nav aria-label="Related Drywall Toolbox pages"><ul>';
			foreach ( $route['links'] as $link ) {
				$html .= '<li><a href="' . esc_url( home_url( $link['href'] ) ) . '">' . esc_html( $link['label'] ) . '</a></li>';
			}
			$html .= '</ul></nav>';
		}
		if ( '/products' === $path && class_exists( 'DTB_SitemapUrlRepository' ) ) {
			$catalog_urls = DTB_SitemapUrlRepository::catalog_links();
			$html .= '<nav aria-label="Browse products, categories, and brands"><h2>Browse the catalog</h2><ul>';
			foreach ( $catalog_urls as $catalog_url ) {
				$href = wp_parse_url( (string) ( $catalog_url['loc'] ?? '' ), PHP_URL_PATH );
				$label = $catalog_url['label'] ?? '';
				if ( ! is_string( $href ) || ! str_starts_with( $href, '/' ) || ! is_string( $label ) || '' === trim( $label ) ) {
					continue;
				}
				$html .= '<li><a href="' . esc_url( home_url( $href ) ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$html .= '</ul></nav>';
		}
		return $html . '</main>';
	}

	/** @return array<string,mixed> */
	private static function product_meta( $meta_data ): array {
		if ( ! is_array( $meta_data ) ) {
			return [];
		}
		$allowed = [ '_dtb_seo_title', '_dtb_seo_description', '_dtb_seo_canonical', '_dtb_seo_noindex' ];
		$meta    = [];
		foreach ( $meta_data as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['key'] ?? null ) && in_array( $entry['key'], $allowed, true ) ) {
				$meta[ $entry['key'] ] = is_scalar( $entry['value'] ?? null ) ? (string) $entry['value'] : '';
			}
		}
		return $meta;
	}

	private static function plain_text( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_option( 'blog_charset' ) ) ) ) ?? '' );
	}

	private static function truncate( string $value, int $limit ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $limit ) {
			return rtrim( mb_substr( $value, 0, $limit - 1 ) ) . '…';
		}
		if ( strlen( $value ) > $limit ) {
			return rtrim( substr( $value, 0, $limit - 3 ) ) . '...';
		}
		return $value;
	}

	private static function replace_seo_tag( string $html, string $key, string $value, string $attribute ): string {
		$escaped = esc_attr( $value );
		if ( 'text' === $attribute ) {
			$pattern = '~<title\b(?=[^>]*\bdata-dtb-seo=["\']' . preg_quote( $key, '~' ) . '["\'])[^>]*>.*?</title>~is';
			$replacement = '<title data-dtb-seo="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</title>';
		} else {
			$pattern = '~<(?:(?:meta|link))\b(?=[^>]*\bdata-dtb-seo=["\']' . preg_quote( $key, '~' ) . '["\'])[^>]*>~i';
			$replacement = null;
		}

		if ( null !== $replacement ) {
			$result = preg_replace( $pattern, $replacement, $html, 1 );
			return is_string( $result ) ? $result : $html;
		}

		$result = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $attribute, $escaped ): string {
				$tag = preg_replace(
					'~\b' . preg_quote( $attribute, '~' ) . '\s*=\s*(["\']).*?\1~is',
					$attribute . '="' . $escaped . '"',
					$matches[0],
					1
				);
				return is_string( $tag ) ? $tag : $matches[0];
			},
			$html,
			1
		);
		return is_string( $result ) ? $result : $html;
	}

	private static function has_seo_markers( string $html ): bool {
		foreach ( [ 'title', 'description', 'robots', 'canonical', 'og-title', 'og-description', 'og-url', 'twitter-title', 'twitter-description' ] as $key ) {
			$tag = 'title' === $key ? 'title' : '(?:meta|link)';
			if ( 1 !== preg_match_all( '~<' . $tag . '\b(?=[^>]*\bdata-dtb-seo=["\']' . preg_quote( $key, '~' ) . '["\'])[^>]*>~i', $html ) ) {
				return false;
			}
		}
		return true;
	}

	private static function has_route_markers( string $html ): bool {
		return 1 === substr_count( $html, '<dtb-route-content data-dtb-route-content style="display:block">' )
			&& 1 === substr_count( $html, '</dtb-route-content>' )
			&& self::has_seo_markers( $html );
	}

	private static function replace_route_content( string $html, string $content ) {
		$result = preg_replace_callback(
			'~(<dtb-route-content data-dtb-route-content style="display:block">).*?(</dtb-route-content>)~is',
			static fn( array $matches ) => $matches[1] . $content . $matches[2],
			$html,
			1
		);
		return is_string( $result ) ? $result : null;
	}

	private static function has_noncanonical_catalog_query( string $path ): bool {
		if ( '/products' !== $path && '/products/brands' !== $path ) {
			return false;
		}
		foreach ( array_keys( $_GET ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $key, [ 'page', 'dtb_storefront' ], true ) || str_starts_with( $key, 'utm_' ) || in_array( $key, [ 'gclid', 'fbclid' ], true ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	private static function send_response( string $html, string $method ): void {
		$etag          = '"' . hash( 'sha256', $html ) . '"';
		$has_set_cookie = (bool) preg_grep( '/^Set-Cookie:/i', headers_list() );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		if ( $has_set_cookie || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		} else {
			header( 'Cache-Control: public, max-age=0, s-maxage=300, stale-while-revalidate=600' );
			header( 'Vary: Accept-Encoding' );
			header( 'ETag: ' . $etag );
			$if_none_match = trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) );
			if ( hash_equals( $etag, $if_none_match ) ) {
				status_header( 304 );
				exit;
			}
		}
		if ( 'HEAD' !== $method ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete HTML is escaped at construction and uses a trusted built shell.
		}
		exit;
	}

	private static function unavailable(): void {
		status_header( 503 );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'Retry-After: 60' );
		exit;
	}
}

DTB_StorefrontRouteRenderer::register();

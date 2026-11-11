<?php
/**
 * DTB Media: Variation Gallery REST Enricher.
 *
 * Product-detail REST responses are normalized after their route callbacks so
 * legacy and canonical detail envelopes expose the same complete variation
 * image gallery. Gallery resolution is owned by DTB_VariationGalleryResolver
 * (dtb-media/Services/VariationGalleryResolver.php) — this file only wires
 * that resolver into the REST response pipeline.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_request_after_callbacks', 'dtb_variation_gallery_enrich_rest_response', 10, 3 );

/**
 * Enrich DTB product-detail REST payloads with variation-specific image galleries.
 *
 * @param mixed           $response REST response or data.
 * @param array           $handler  Route handler metadata.
 * @param WP_REST_Request $request  Current REST request.
 * @return mixed
 */
function dtb_variation_gallery_enrich_rest_response( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( ! dtb_variation_gallery_should_enrich_route( $route ) ) {
		return $response;
	}

	$rest_response = rest_ensure_response( $response );
	$data          = $rest_response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	$rest_response->set_data( dtb_variation_gallery_enrich_payload( $data ) );
	return $rest_response;
}

/**
 * Determine whether the current REST route is a product-detail payload.
 *
 * @param string $route REST route.
 * @return bool
 */
function dtb_variation_gallery_should_enrich_route( string $route ): bool {
	return (
		str_contains( $route, '/dtb/v1/catalog/products/' )
		&& str_contains( $route, '/detail' )
	) || (
		str_contains( $route, '/drywall/v1/products/' )
		&& str_contains( $route, '/detail' )
	);
}

/**
 * Recursively enrich variation arrays in a product payload.
 *
 * @param array<string,mixed> $payload REST payload.
 * @return array<string,mixed>
 */
function dtb_variation_gallery_enrich_payload( array $payload ): array {
	if ( isset( $payload['variations'] ) && is_array( $payload['variations'] ) ) {
		$payload['variations'] = array_map( 'dtb_variation_gallery_enrich_variation', $payload['variations'] );
	}

	foreach ( $payload as $key => $value ) {
		if ( 'variations' === $key ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$payload[ $key ] = dtb_variation_gallery_enrich_payload( $value );
		}
	}

	return $payload;
}

/**
 * Enrich one variation DTO without discarding a larger existing gallery.
 *
 * @param mixed $variation Variation DTO.
 * @return mixed
 */
function dtb_variation_gallery_enrich_variation( $variation ) {
	if ( ! is_array( $variation ) ) {
		return $variation;
	}

	$sku = trim( (string) ( $variation['sku'] ?? '' ) );
	if ( '' === $sku ) {
		return $variation;
	}

	$existing     = dtb_variation_gallery_collect_existing( $variation );
	$variation_id = (int) ( $variation['id'] ?? 0 );
	$resolved     = dtb_variation_gallery_find_for_sku( $sku, $variation_id );
	$gallery      = dtb_variation_gallery_normalize( array_merge( $resolved, $existing ) );

	if ( empty( $gallery ) ) {
		return $variation;
	}

	$media = is_array( $variation['media'] ?? null ) ? $variation['media'] : [];
	$media['variationImages'] = $gallery;
	$media['images']          = $gallery;
	$media['image']           = (string) ( $gallery[0]['src'] ?? $media['image'] ?? '' );

	// Always synchronize all canonical and compatibility aliases. Even when the
	// URL set is unchanged, the resolver may contribute attachment metadata and a
	// payload may expose the gallery under only one legacy alias.
	$variation['media']                    = $media;
	$variation['images']                   = $gallery;
	$variation['image']                    = $gallery[0] ?? ( $variation['image'] ?? null );
	$variation['variationImages']          = $gallery;
	$variation['variationGalleryImages']   = $gallery;
	$variation['variation_gallery_images'] = $gallery;

	return $variation;
}

/**
 * Delegate SKU gallery resolution to the owning dtb-media service.
 *
 * @param string $sku          Variation SKU.
 * @param int    $variation_id WooCommerce variation post ID.
 * @return array<int,array<string,mixed>>
 */
function dtb_variation_gallery_find_for_sku( string $sku, int $variation_id = 0 ): array {
	if ( ! class_exists( 'DTB_VariationGalleryResolver' ) ) {
		return [];
	}

	return DTB_VariationGalleryResolver::resolve( $sku, $variation_id );
}

/**
 * Collect explicit variation gallery aliases already present in a DTO.
 *
 * Generic images/media.images are intentionally excluded because legacy payloads
 * may fill them with the parent gallery.
 *
 * @param array<string,mixed> $variation Variation DTO.
 * @return array<int,array<string,mixed>>
 */
function dtb_variation_gallery_collect_existing( array $variation ): array {
	$media = is_array( $variation['media'] ?? null ) ? $variation['media'] : [];
	$sets  = [
		$variation['variationGalleryImages'] ?? [],
		$variation['variationImages'] ?? [],
		$variation['variation_gallery_images'] ?? [],
		$media['variationImages'] ?? [],
		$media['variation_images'] ?? [],
	];

	return dtb_variation_gallery_normalize( array_merge( ...array_map(
		static fn( $images ): array => is_array( $images ) ? $images : [],
		$sets
	) ) );
}

/**
 * Normalize gallery entries through the canonical resolver when available.
 *
 * @param array<int,mixed> $gallery Raw gallery entries.
 * @return array<int,array<string,mixed>>
 */
function dtb_variation_gallery_normalize( array $gallery ): array {
	if ( class_exists( 'DTB_VariationGalleryResolver' ) ) {
		return DTB_VariationGalleryResolver::normalize_gallery( $gallery );
	}

	$out  = [];
	$seen = [];
	foreach ( $gallery as $image ) {
		if ( is_string( $image ) ) {
			$image = [ 'src' => $image ];
		}
		if ( ! is_array( $image ) ) {
			continue;
		}
		$src = trim( (string) ( $image['src'] ?? $image['url'] ?? '' ) );
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

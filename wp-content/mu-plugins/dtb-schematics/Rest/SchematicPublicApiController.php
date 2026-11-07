<?php
/**
 * DTB Schematics — public REST transport.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_register_schematics_public_api_routes' );

function dtb_register_schematics_public_api_routes(): void {
	register_rest_route(
		'dtb/v1',
		'/schematics',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_schematics_public_api_collection',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'dtb/v1',
		'/schematics/(?P<schematic_id>[a-zA-Z0-9_-]+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_schematics_public_api_detail',
			'permission_callback' => '__return_true',
			'args'                => [
				'schematic_id' => [
					'required'          => true,
					'validate_callback' => static function ( $value ) {
						return is_string( $value ) && '' !== sanitize_key( $value );
					},
				],
			],
		]
	);
}

function dtb_schematics_public_api_apply_cache_headers( WP_REST_Request $request, WP_REST_Response $response, string $etag_seed, int $ttl = 300 ): bool {
	$etag = '"' . md5( $etag_seed ) . '"';
	$response->header( 'Cache-Control', 'public, max-age=' . max( 0, $ttl ) );
	$response->header( 'ETag', $etag );

	$if_none_match = $request->get_header( 'if_none_match' );
	return is_string( $if_none_match ) && trim( $if_none_match ) === $etag;
}

function dtb_schematics_public_api_versioned_url( string $url, string $checksum, int $publication_version ): string {
	if ( '' === $url ) {
		return '';
	}
	$version = '' !== $checksum ? $checksum : ( 'v' . max( 0, $publication_version ) );
	return add_query_arg( 'v', rawurlencode( $version ), $url );
}

/**
 * Enforce deterministic page ordering and serialize only structurally valid
 * page/source records. Optional hotspot/media data degrades instead of
 * invalidating the complete public schematic response.
 */
function dtb_schematics_public_api_order_and_annotate_pages( array $pages, int $publication_version ): array {
	$pages = array_values( array_filter( $pages, 'is_array' ) );

	usort(
		$pages,
		static fn( $a, $b ) => (int) ( $a['page_number'] ?? 0 ) <=> (int) ( $b['page_number'] ?? 0 )
	);

	$seen = [];
	$pages = array_values(
		array_filter(
			$pages,
			static function ( $page ) use ( &$seen ) {
				if ( ! is_array( $page ) ) {
					return false;
				}
				$page_id = (string) ( $page['page_id'] ?? '' );
				if ( '' === $page_id || isset( $seen[ $page_id ] ) ) {
					return false;
				}
				$seen[ $page_id ] = true;
				return true;
			}
		)
	);

	$annotated = array_map(
		static function ( $page ) use ( $publication_version ) {
			if ( ! is_array( $page ) ) {
				return null;
			}

			$checksum = sanitize_text_field( (string) ( $page['source_checksum'] ?? '' ) );

			if ( '' !== ( $page['url'] ?? '' ) ) {
				$page['url'] = dtb_schematics_public_api_versioned_url( (string) $page['url'], $checksum, $publication_version );
			}

			$sources = [];
			foreach ( (array) ( $page['sources'] ?? [] ) as $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}
				$source['url'] = dtb_schematics_public_api_versioned_url(
					(string) ( $source['url'] ?? '' ),
					$checksum,
					$publication_version
				);
				$sources[] = $source;
			}
			$page['sources'] = $sources;

			$dataset         = is_array( $page['hotspot_dataset'] ?? null ) ? $page['hotspot_dataset'] : [];
			$occurrences     = array_values( array_filter( (array) ( $dataset['occurrences'] ?? [] ), 'is_array' ) );
			$has_reference   = ! empty( $dataset['type'] ) && 'none' !== $dataset['type'];
			$has_occurrences = ! empty( $occurrences );

			if ( $has_occurrences ) {
				$page['hotspot_dataset'] = [
					'available'      => true,
					'schema_version' => sanitize_text_field( (string) ( $dataset['schema_version'] ?? DTB_SCHEMATIC_HOTSPOT_SCHEMA_VERSION ) ),
					'checksum'       => sanitize_text_field( (string) ( $dataset['checksum'] ?? '' ) ),
					'occurrences'    => $occurrences,
				];
			} else {
				$page['hotspot_dataset'] = [
					'available' => false,
					'reason'    => 'hotspot_data_unavailable',
				];
			}

			return $page;
		},
		$pages
	);

	return array_values( array_filter( $annotated, 'is_array' ) );
}

function dtb_schematics_public_api_annotate_parts( array $parts ): array {
	$annotated = array_map(
		static function ( $part ) {
			if ( ! is_array( $part ) ) {
				return null;
			}
			$part['available'] = DTB_SCHEMATIC_PART_STATE_RESOLVED === ( $part['resolution_state'] ?? '' )
				&& is_array( $part['product'] ?? null );
			return $part;
		},
		$parts
	);

	return array_values( array_filter( $annotated, 'is_array' ) );
}

function dtb_schematics_public_api_collection( WP_REST_Request $request ) {
	$per_page = 200;
	$page     = 1;
	$items    = [];

	do {
		$result = dtb_schematic_record_repo_query(
			[
				'lifecycle' => DTB_Schematic_Lifecycle_Status::PUBLISHED,
				'page'      => $page,
				'per_page'  => $per_page,
			]
		);

		if ( ! is_array( $result ) ) {
			break;
		}

		foreach ( (array) ( $result['items'] ?? [] ) as $record ) {
			if ( ! $record instanceof DTB_Schematic_Record_Entity ) {
				continue;
			}
			if ( ! $record->lifecycle->is_published() ) {
				continue;
			}
			if ( ! empty( dtb_schematic_runtime_publication_requirements( $record ) ) ) {
				continue;
			}

			$entry = dtb_schematic_generate_catalog_entry( $record );
			if ( '' !== ( $entry['preview']['url'] ?? '' ) ) {
				$entry['preview']['url'] = dtb_schematics_public_api_versioned_url(
					(string) $entry['preview']['url'],
					'',
					$record->publication_version
				);
			}
			$items[] = $entry;
		}

		$total_pages = max( 0, (int) ( $result['pages'] ?? 0 ) );
		++$page;
	} while ( $page <= $total_pages && $page <= 50 );

	$catalog_version = dtb_schematics_public_catalog_version();
	$response = rest_ensure_response(
		[
			'schema_version'  => 'v1',
			'catalog_version' => $catalog_version,
			'count'           => count( $items ),
			'items'           => $items,
		]
	);

	if ( dtb_schematics_public_api_apply_cache_headers( $request, $response, 'collection:' . $catalog_version . ':' . count( $items ) ) ) {
		return new WP_REST_Response( null, 304 );
	}

	return $response;
}

function dtb_schematics_public_api_detail( WP_REST_Request $request ) {
	$schematic_id = sanitize_key( (string) $request->get_param( 'schematic_id' ) );
	$record       = dtb_schematic_record_repo_find_by_canonical_id( $schematic_id );

	if ( ! $record instanceof DTB_Schematic_Record_Entity || ! $record->lifecycle->is_published() || ! empty( dtb_schematic_runtime_publication_requirements( $record ) ) ) {
		return new WP_Error(
			'dtb_schematic_not_found',
			__( 'Schematic not found.', 'drywall-toolbox' ),
			[ 'status' => 404 ]
		);
	}

	try {
		$body          = dtb_schematic_generate_detail_response( $record );
		$body['pages'] = dtb_schematics_public_api_order_and_annotate_pages( (array) ( $body['pages'] ?? [] ), $record->publication_version );
		$body['parts'] = dtb_schematics_public_api_annotate_parts( (array) ( $body['parts'] ?? [] ) );
	} catch ( Throwable $error ) {
		if ( function_exists( 'dtb_schematic_log_public_projection_failure' ) ) {
			dtb_schematic_log_public_projection_failure( $record->canonical_id, 'detail_response', $error );
		}
		return new WP_Error(
			'dtb_schematic_projection_failed',
			__( 'Schematic data is temporarily unavailable.', 'drywall-toolbox' ),
			[ 'status' => 503 ]
		);
	}

	$response = rest_ensure_response( $body );
	if (
		dtb_schematics_public_api_apply_cache_headers(
			$request,
			$response,
			'detail:v4:' . $record->canonical_id . ':' . $record->publication_version . ':' . md5( wp_json_encode( $body['parts'] ?? [] ) )
		)
	) {
		return new WP_REST_Response( null, 304 );
	}

	$last_modified = mysql2date( 'D, d M Y H:i:s', $record->updated_at, false );
	if ( $last_modified ) {
		$response->header( 'Last-Modified', $last_modified . ' GMT' );
	}

	return $response;
}

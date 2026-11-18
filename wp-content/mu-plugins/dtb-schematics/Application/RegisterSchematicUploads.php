<?php
/**
 * Upload-filename parsing shared by the reconciliation engine
 * (Application/ReconcileSchematicSource.php), which is the sole live caller
 * of these helpers and the sole writer of WordPress attachments for
 * schematic uploads.
 *
 * Expected filename contract (either form accepted):
 *   {schematic-id}--page-{n}.{ext}
 *   {schematic-id}--preview.{ext}
 *   {sku}_SCH-page-{n}.{ext}                 (SKU resolved via DTB_SKU_SCHEMATIC_MAP)
 *   {sku}_SCH-preview.{ext}
 *   {brand}_{sku}_sch-page-{n}.{ext}         (canonical products/launch/media/schematics naming;
 *                                             see scripts/catalog/normalize_schematic_filenames.py.
 *                                             {brand} is stripped before the SKU lookup.)
 *
 * Files remain in place under wp-content/uploads; no copy or rename occurs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identify residual uploads for the retired Asgard schematic catalog. The
 * "-ad" token marks an Asgard *adapter* part (e.g. "ah25-ad" = Angle Head
 * 2.5 Adapter); Asgard schematics are retired and must never enter
 * DTB_SKU_SCHEMATIC_MAP (see scripts/catalog/gen_sku_schematic_map.py).
 * An optional leading "{brand}_" token is stripped first so canonical
 * {brand}_{sku}_sch-page-{n}.webp exports (e.g. a re-branded/misfiled
 * "asgard_ah25-ad_sch-page-001.webp") are still recognized as retired
 * instead of falling through to an opaque "unknown SKU" error.
 */
function dtb_schematics_is_retired_upload_filename( string $filename ): bool {
	return null !== dtb_schematics_retired_upload_reason( $filename );
}

/**
 * Explicit denylist of catalog SKU tokens that are confirmed retired (not
 * present in products/launch/official/dtb_official_catalog.csv
 * and not resolvable to any active product) but still have residual
 * schematic upload files on disk from a prior export batch. Unlike the
 * "-ad" Asgard pattern below, these SKUs don't share a structural filename
 * marker, so they must be listed by exact SKU. Confirmed retired
 * 2026-08-11 with the catalog owner:
 *   - COL-SANDER-HEAD: not a real active product.
 *   - HMP-2022: not the active Hot Mud Pump SKU (that is "HMP", already
 *     mapped in DTB_SKU_SCHEMATIC_MAP); no HMP-2022 SKU exists in the
 *     official catalog.
 *   - TOMAHAWK: not a real active product.
 *   - TBMP-2022: not a real catalog SKU (no "TBMP-2022" row exists in
 *     dtb_official_catalog.csv; the active Tall Boy Mud Pump
 *     variation SKU is "TBMP", already mapped in DTB_SKU_SCHEMATIC_MAP).
 *     Confirmed 2026-08-13 with the catalog owner: columbia_tbmp-2022_sch-
 *     page-001.webp and columbia_tbmp_sch-page-001.webp are the same
 *     physical pump model's diagram (both single-page, same schematic
 *     content) — NOT distinct products, unlike Columbia HMP-2022 vs
 *     TBMP-2022, which per the original spec doc genuinely are distinct
 *     tools and must stay untouched. This entry only stops the "-2022"
 *     export-batch filename from being independently registered as a
 *     WordPress attachment (it would otherwise collide with the canonical
 *     TBMP filename's schematic_id + page, per
 *     Application/ReconcileSchematicSource.php's DUPLICATE_SCHEMATIC_PAGE
 *     disposition). The physical source file is kept on disk, unrenamed,
 *     under products/launch/media/schematics/ as a historical alias; it is
 *     not deleted. TBMP-2022 -> TBMP SKU resolution elsewhere (e.g.
 *     DTB_SKU_SCHEMATIC_MAP, admin SKU lookups) is unaffected — only
 *     upload-filename registration is skipped for this exact source file.
 */
const DTB_RETIRED_SCHEMATIC_UPLOAD_SKUS = [ 'COL-SANDER-HEAD', 'HMP-2022', 'TOMAHAWK', 'TBMP-2022' ];

/**
 * Identify residual uploads for retired products/schematics, returning a
 * human-readable skip reason, or null if the file is not retired.
 * Two independent mechanisms are checked:
 *   1. The retired Asgard schematic catalog. The "-ad" token marks an
 *      Asgard *adapter* part (e.g. "ah25-ad" = Angle Head 2.5 Adapter);
 *      Asgard schematics are retired and must never enter
 *      DTB_SKU_SCHEMATIC_MAP (see scripts/catalog/gen_sku_schematic_map.py).
 *   2. DTB_RETIRED_SCHEMATIC_UPLOAD_SKUS, an explicit denylist for
 *      individually retired SKUs that don't share a structural marker.
 * An optional leading "{brand}_" token is stripped first so canonical
 * {brand}_{sku}_sch-page-{n}.webp exports (e.g. a re-branded/misfiled
 * "asgard_ah25-ad_sch-page-001.webp") are still recognized as retired
 * instead of falling through to an opaque "unknown SKU" error.
 */
function dtb_schematics_retired_upload_reason( string $filename ): ?string {
	$name = pathinfo( $filename, PATHINFO_FILENAME );
	$name = preg_replace( '/^(?:columbia|tapetech|platinum|level5|dura-stilts|asgard)_/i', '', $name );

	if ( 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*-ad[-_]+sch[-_]+(?:page[-_]*[0-9]+|preview)$/i', $name ) ) {
		return 'Retired Asgard schematic upload.';
	}

	if ( preg_match( '/^([a-z0-9-]+)[-_]+sch[-_]+(?:page[-_]*[0-9]+|preview)$/i', $name, $matches ) ) {
		$sku = strtoupper( $matches[1] );
		if ( in_array( $sku, DTB_RETIRED_SCHEMATIC_UPLOAD_SKUS, true ) ) {
			return sprintf( 'Retired SKU upload (%s is not an active catalog product).', $sku );
		}
	}

	return null;
}

/**
 * Final gate applied to every successfully resolved filename, regardless of
 * which pattern matched. DTB_RETIRED_SCHEMATIC_IDS (generated by
 * scripts/catalog/gen_sku_schematic_map.py from Asgard rows in
 * all_brands_schematic_parts_master.csv) is the authoritative denylist; this
 * is the only enforcement point for the {schematic-id}--page-{n} /
 * {schematic-id}--preview passthrough patterns below, which resolve a
 * schematic id directly from the filename with no SKU/verbose-id map lookup
 * and would otherwise never see a retired-brand check.
 */
function dtb_schematics_reject_if_retired( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( isset( DTB_RETIRED_SCHEMATIC_IDS[ $result['schematic_id'] ] ) ) {
		return new WP_Error( 'dtb_retired_schematic_id', sprintf( 'Schematic id "%s" belongs to a retired brand catalog and must not be registered.', $result['schematic_id'] ) );
	}
	return $result;
}

function dtb_schematics_parse_upload_filename( string $filename ) {
	$name = pathinfo( $filename, PATHINFO_FILENAME );
	$legacy_key = strtolower( $name );
	if ( isset( DTB_LEGACY_SCHEMATIC_FILENAME_MAP[ $legacy_key ] ) ) {
		$legacy = DTB_LEGACY_SCHEMATIC_FILENAME_MAP[ $legacy_key ];
		return dtb_schematics_reject_if_retired( [
			'schematic_id' => sanitize_key( $legacy['schematic_id'] ),
			'type' => 'diagram',
			'page' => (string) absint( $legacy['page'] ),
		] );
	}
	if ( preg_match( '/^([a-z0-9][a-z0-9-]*)--preview$/', strtolower( $name ), $matches ) ) {
		return dtb_schematics_reject_if_retired( [ 'schematic_id' => sanitize_key( $matches[1] ), 'type' => 'preview', 'page' => '1' ] );
	}
	if ( preg_match( '/^([a-z0-9][a-z0-9-]*)--page-([1-9][0-9]*)$/', strtolower( $name ), $matches ) ) {
		return dtb_schematics_reject_if_retired( [ 'schematic_id' => sanitize_key( $matches[1] ), 'type' => 'diagram', 'page' => (string) absint( $matches[2] ) ] );
	}

	$sku_match = dtb_schematics_parse_sku_upload_filename( $name );
	if ( ! is_wp_error( $sku_match ) ) {
		return dtb_schematics_reject_if_retired( $sku_match );
	}

	$dura_stilts_match = dtb_schematics_parse_dura_stilts_upload_filename( $name );
	if ( ! is_wp_error( $dura_stilts_match ) ) {
		return dtb_schematics_reject_if_retired( $dura_stilts_match );
	}

	$verbose_match = dtb_schematics_parse_verbose_upload_filename( $name );
	if ( ! is_wp_error( $verbose_match ) ) {
		return dtb_schematics_reject_if_retired( $verbose_match );
	}

	// Generic SKU patterns also match descriptive export basenames such as
	// "platinum_flat_box-page-001". Only report their lookup error after the
	// more specific Dura-Stilts and verbose-id resolvers have had a chance.
	if ( 'dtb_unknown_schematic_sku' === $dura_stilts_match->get_error_code() ) {
		return $dura_stilts_match;
	}
	if ( 'dtb_unknown_schematic_sku' === $sku_match->get_error_code() ) {
		return $sku_match;
	}
	if ( 'dtb_unknown_verbose_schematic_id' === $verbose_match->get_error_code() ) {
		return $verbose_match;
	}

	return new WP_Error( 'dtb_invalid_schematic_filename', 'Filename must match {schematic-id}--page-{n}, {schematic-id}--preview, {sku}_SCH-page-{n}, {verbose-id}-schematic-page-{n}, or model-4-{range}.' );
}

/**
 * Accept the SiteGround export naming convention, which varies in separator and infix
 * across export batches: {sku}_SCH-page-{n}, {sku}_SCH_page_{n}, {sku}_SCH-{n},
 * {sku}_SCH_v{n}_page_{n}, {sku}-page_{n}, {sku}_SCH-preview, etc. Resolves the SKU to a
 * schematic id (and, where the catalog map pins a specific page for that SKU, that page)
 * via DTB_SKU_SCHEMATIC_MAP.
 */
function dtb_schematics_parse_sku_upload_filename( string $name ) {
	if ( preg_match( '/^(.+?)[-_]+sch[-_]+preview$/i', $name, $matches ) ) {
		$sku = dtb_schematics_resolve_upload_sku( $matches[1] );
		if ( null === $sku ) {
			return new WP_Error( 'dtb_unknown_schematic_sku', sprintf( 'SKU "%s" is not present in the schematic catalog map.', strtoupper( $matches[1] ) ) );
		}
		return [ 'schematic_id' => sanitize_key( DTB_SKU_SCHEMATIC_MAP[ $sku ]['schematic_id'] ), 'type' => 'preview', 'page' => '1' ];
	}

	// {sku}_SCH[_v{n}][_page]_{n} — "sch" required, "page" word and version infix optional.
	$matched = preg_match( '/^(.+?)[-_]+sch(?:[-_]+v[0-9]+)?[-_]+(?:page[-_]*)?([0-9]+)$/i', $name, $matches );
	if ( ! $matched ) {
		// {sku}-page_{n} — no "sch" token at all.
		$matched = preg_match( '/^(.+?)[-_]+page[-_]*([0-9]+)$/i', $name, $matches );
	}
	if ( ! $matched ) {
		return new WP_Error( 'dtb_invalid_schematic_filename', 'Filename does not match a known {sku}...page-{n} or {sku}...preview convention.' );
	}

	$sku = dtb_schematics_resolve_upload_sku( $matches[1] );
	if ( null === $sku ) {
		return new WP_Error( 'dtb_unknown_schematic_sku', sprintf( 'SKU "%s" is not present in the schematic catalog map.', strtoupper( $matches[1] ) ) );
	}

	$mapped = DTB_SKU_SCHEMATIC_MAP[ $sku ];
	$page = null !== $mapped['page'] ? (int) $mapped['page'] : absint( $matches[2] );
	return [ 'schematic_id' => sanitize_key( $mapped['schematic_id'] ), 'type' => 'diagram', 'page' => (string) $page ];
}

/**
 * Known brand tokens used by the canonical products/launch/media/schematics filename
 * convention ({brand}_{sku}_sch-page-{n}.webp — see
 * scripts/catalog/normalize_schematic_filenames.py). DTB_SKU_SCHEMATIC_MAP
 * keys are bare catalog SKUs (no brand prefix), so a captured token like
 * "columbia_3ns" must have its brand prefix stripped before lookup.
 */
const DTB_SCHEMATIC_UPLOAD_BRAND_PREFIXES = [ 'ASGARD', 'COLUMBIA', 'TAPETECH', 'PLATINUM', 'LEVEL5', 'DURA-STILTS' ];

/**
 * Resolves a captured filename token to a DTB_SKU_SCHEMATIC_MAP key, trying
 * the token as-is first (legacy bare-SKU exports) and then with a known
 * brand prefix stripped (current canonical {brand}_{sku} exports). Returns
 * null if neither form is present in the map.
 */
function dtb_schematics_resolve_upload_sku( string $captured ): ?string {
	$sku = strtoupper( $captured );
	if ( isset( DTB_SKU_SCHEMATIC_MAP[ $sku ] ) ) {
		return $sku;
	}
	foreach ( DTB_SCHEMATIC_UPLOAD_BRAND_PREFIXES as $prefix ) {
		if ( 0 === strpos( $sku, $prefix . '_' ) ) {
			$stripped = substr( $sku, strlen( $prefix ) + 1 );
			if ( isset( DTB_SKU_SCHEMATIC_MAP[ $stripped ] ) ) {
				return $stripped;
			}
		}
	}
	return null;
}

/**
 * Accept the Columbia/TapeTech/Platinum export convention:
 *   {verbose-schematic-id}-schematic-page-{n}.{ext}   (Columbia/TapeTech)
 *   {name}-page-{n}.{ext}                             (Platinum, underscore-separated)
 * The captured id is normalized (lowercase, non-alphanumeric stripped) and looked up in
 * DTB_VERBOSE_SCHEMATIC_ID_MAP. A pinned map page overrides the filename page; a null
 * map page preserves the filename page for a single verbose id spanning multiple pages.
 */
function dtb_schematics_parse_verbose_upload_filename( string $name ) {
	if ( ! preg_match( '/^(.+?)(?:-schematic)?-page-([0-9]+)$/i', $name, $matches ) ) {
		return new WP_Error( 'dtb_invalid_schematic_filename', 'Filename does not match {verbose-id}-schematic-page-{n} or {name}-page-{n}.' );
	}

	$key = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $matches[1] ) );
	if ( ! isset( DTB_VERBOSE_SCHEMATIC_ID_MAP[ $key ] ) ) {
		return new WP_Error( 'dtb_unknown_verbose_schematic_id', sprintf( 'Verbose schematic id "%s" is not present in the schematic catalog map.', $matches[1] ) );
	}

	$mapped = DTB_VERBOSE_SCHEMATIC_ID_MAP[ $key ];
	$page = null !== $mapped['page'] ? (int) $mapped['page'] : absint( $matches[2] );
	return [ 'schematic_id' => sanitize_key( $mapped['schematic_id'] ), 'type' => 'diagram', 'page' => (string) $page ];
}

/**
 * Accept the Dura-Stilts export convention: model-4-{range}.{ext}, e.g. model-4-14-22.webp.
 * Reconstructs the catalog SKU (D{range}) and resolves via DTB_SKU_SCHEMATIC_MAP, which
 * pins each Dura-Stilts size to its correct page of the combined "Dura III" schematic.
 */
function dtb_schematics_parse_dura_stilts_upload_filename( string $name ) {
	if ( ! preg_match( '/^model-4-([0-9]+-[0-9]+)$/i', $name, $matches ) ) {
		return new WP_Error( 'dtb_invalid_schematic_filename', 'Filename does not match model-4-{range}.' );
	}

	$sku = 'D' . $matches[1];
	if ( ! isset( DTB_SKU_SCHEMATIC_MAP[ $sku ] ) ) {
		return new WP_Error( 'dtb_unknown_schematic_sku', sprintf( 'SKU "%s" is not present in the schematic catalog map.', $sku ) );
	}

	$mapped = DTB_SKU_SCHEMATIC_MAP[ $sku ];
	$page = null !== $mapped['page'] ? (int) $mapped['page'] : 1;
	return [ 'schematic_id' => sanitize_key( $mapped['schematic_id'] ), 'type' => 'diagram', 'page' => (string) $page ];
}

function dtb_schematics_find_attachment_by_relative_file( string $relative_file ): int {
	$ids = get_posts(
		[
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_wp_attached_file',
			'meta_value' => $relative_file,
		]
	);
	return empty( $ids ) ? 0 : (int) $ids[0];
}


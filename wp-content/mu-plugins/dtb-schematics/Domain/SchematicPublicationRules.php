<?php
/**
 * DTB Schematics — SchematicPublicationRules.
 *
 * Publication eligibility must be derived from the authoritative record and
 * its required relationships — not asserted independently by any consumer.
 *
 * "Required" for publication eligibility means:
 *   - a non-empty immutable canonical ID;
 *   - a non-empty customer-facing title;
 *   - a non-empty brand ID;
 *   - a non-empty category ID;
 *   - at least one page with a stable page ID and a non-zero attachment relationship.
 *
 * Runtime attachment existence, public URL availability, normalized hotspot
 * projection, and exact part-resolution requirements are evaluated by
 * Application/EvaluateSchematicReadiness.php before ready/publish transitions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the list of unmet publication requirements for a schematic record.
 * An empty array means the record is eligible for publication.
 *
 * @param array $record Result of DTB_Schematic_Record_Entity::to_array().
 * @return string[] Machine-readable requirement keys still unmet.
 */
function dtb_schematic_publication_requirements( array $record ): array {
	$unmet = [];

	if ( '' === trim( (string) ( $record['canonical_id'] ?? '' ) ) ) {
		$unmet[] = 'canonical_id_required';
	}

	if ( '' === trim( (string) ( $record['title'] ?? '' ) ) ) {
		$unmet[] = 'title_required';
	}

	if ( '' === trim( (string) ( $record['brand_id'] ?? '' ) ) ) {
		$unmet[] = 'brand_id_required';
	}

	if ( '' === trim( (string) ( $record['category_id'] ?? '' ) ) ) {
		$unmet[] = 'category_id_required';
	}

	$pages = is_array( $record['pages'] ?? null ) ? $record['pages'] : [];
	$has_attached_page = false;
	foreach ( $pages as $page ) {
		if ( is_array( $page ) && dtb_schematic_page_is_attached( $page ) ) {
			$has_attached_page = true;
			break;
		}
	}
	if ( ! $has_attached_page ) {
		$unmet[] = 'attached_page_required';
	}

	return $unmet;
}

function dtb_schematic_is_publication_eligible( array $record ): bool {
	return empty( dtb_schematic_publication_requirements( $record ) );
}

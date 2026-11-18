<?php
/**
 * DTB_ProductMeta
 *
 * Defines every canonical DTB product meta key and its type/description.
 * This class is the single source of truth for meta key names; all services,
 * controllers, and the bootstrap registration routine reference it.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ProductMeta {

	// ── Identity keys ──────────────────────────────────────────────────────────

	/** Canonical brand slug key (e.g. tapetech). */
	const BRAND_KEY = '_dtb_brand_key';

	/** Human-readable brand label (e.g. TapeTech). */
	const BRAND_LABEL = '_dtb_brand_label';

	/** Manufacturer part number used by the manufacturer. */
	const MANUFACTURER_SKU = '_dtb_manufacturer_sku';

	/** Official manufacturer part number / MPN. */
	const MPN = '_dtb_mpn';

	/** UPC / GTIN barcode. */
	const UPC = '_dtb_upc';

	/** Raw brand value as it appeared in the catalog source. */
	const SOURCE_BRAND = '_dtb_source_brand';

	/** Manufacturer or distributor product page URL. */
	const SOURCE_URL = '_dtb_source_url';

	/** Catalog data provenance (official_pdf | shopamestools | manual). */
	const CATALOG_SOURCE = '_dtb_catalog_source';

	// ── Classification keys ────────────────────────────────────────────────────

	/** Product kind: tool | part | accessory | toolset | stilt. */
	const PRODUCT_KIND = '_dtb_product_kind';

	/** Tool family key (flat_box | automatic_taper | angle_head | pump | handle | …). */
	const TOOL_FAMILY = '_dtb_tool_family';

	/** Role in a toolset: primary_tool | handle | adapter | replacement_part | included_accessory. */
	const TOOL_ROLE = '_dtb_tool_role';

	/** DTB catalog category key (finishing | taping | corner | mudboxes | sanding | stilts | parts | services). */
	const CATEGORY_KEY = '_dtb_category_key';

	/** Display category key for brand-scoped UX (e.g. finishing_boxes). */
	const DISPLAY_CATEGORY_KEY = '_dtb_display_category_key';

	/** Whether this product is a replacement part (0 | 1). */
	const IS_PARTS = '_dtb_is_parts';

	/** Whether this tool is repair-service eligible (0 | 1). */
	const IS_REPAIRABLE = '_dtb_is_repairable_tool';

	// ── Variation keys ─────────────────────────────────────────────────────────

	/** Parent variable product SKU — stored on each variation. */
	const PARENT_PRODUCT_SKU = '_dtb_parent_product_sku';

	/** Canonical variation axis key (size | length | width | style | handedness). */
	const VARIATION_AXIS = '_dtb_variation_axis';

	/** Canonical variation value (e.g. 7 for 7-inch box). */
	const VARIATION_VALUE = '_dtb_variation_value';

	/** Human-readable variation label (e.g. 7 in). */
	const VARIATION_LABEL = '_dtb_variation_label';

	/** ID of the default variation for cards/modals. Stored on parent. */
	const DEFAULT_VARIATION_ID = '_dtb_default_variation_id';

	/**
	 * SKU of the default variation for cards/modals. Stored on parent.
	 * Preferred over DEFAULT_VARIATION_ID in CSV imports because WooCommerce
	 * variation IDs are not stable before import; SKUs are.
	 */
	const DEFAULT_VARIATION_SKU = '_dtb_default_variation_sku';

	/** Whether this variation should fall back to the parent's image (0 | 1). */
	const INHERIT_PARENT_IMAGE = '_dtb_inherit_parent_image';

	/** Sort weight within a variable product (lower = first). */
	const VARIATION_SORT = '_dtb_variation_sort';

	// ── Commerce keys ──────────────────────────────────────────────────────────

	/**
	 * Commerce mode for this product. Governs pricing, cart, and visibility.
	 * Valid values: purchasable | quote_only | hidden_reference | repair_only | included_item
	 */
	const COMMERCE_MODE = '_dtb_commerce_mode';

	/** Optional minimum advertised price used by the catalog pricing workspace. */
	const MAP_PRICE = '_dtb_map_price';

	/** Short operator-facing source/evidence label for MAP. */
	const MAP_SOURCE = '_dtb_map_source';

	// ── Toolset Builder keys ───────────────────────────────────────────────────

	/** Whether eligible for Toolset Builder slot selection (0 | 1). */
	const BUILDER_ELIGIBLE = '_dtb_builder_eligible';

	/** Comma-separated or serialized builder slot IDs (flatBox,flatBox2). */
	const BUILDER_SLOTS = '_dtb_builder_slots';

	/** Comma-separated workflow scopes this product covers (full,finishing,flatbox). */
	const WORKFLOW_SCOPES = '_dtb_workflow_scopes';

	/** Sort rank within a builder slot (lower number = displayed first). */
	const BUILDER_RANK = '_dtb_builder_rank';

	/** Whether this is a required accessory (always-included, non-selectable). */
	const BUILDER_REQUIRED_ACCESSORY = '_dtb_builder_required_accessory';

	/** Whether this product is a kit-included item (not sold separately in kit). */
	const KIT_INCLUDED_ITEM = '_dtb_kit_included_item';

	// ── Compatibility / schematics keys ───────────────────────────────────────

	/** Comma-separated SKUs of tools this product is compatible with. */
	const COMPATIBLE_TOOL_SKUS = '_dtb_compatible_tool_skus';

	/** Comma-separated parent tool SKUs this is a replacement part for. */
	const REPLACEMENT_PART_FOR = '_dtb_replacement_part_for';

	/** Schematics brand slug (tapetech | columbia-taping-tools | …). */
	const SCHEMATIC_BRAND = '_dtb_schematic_brand';

	/** Schematics tool group identifier (automatic_taper | flat_box | …). */
	const SCHEMATIC_GROUP = '_dtb_schematic_group';

	/** Position number on the schematic diagram. */
	const SCHEMATIC_POSITION = '_dtb_schematic_position';

	// ── Universal parts keys ───────────────────────────────────────────────────

	/** Backend-only universal physical part identifier. */
	const UNIVERSAL_PART_ID = '_dtb_universal_part_id';

	/** Universal part import/sync status: active | review | quarantine. */
	const UNIVERSAL_PART_STATUS = '_dtb_universal_part_status';

	/** Universal part confidence: verified | high | medium | low | review. */
	const UNIVERSAL_PART_CONFIDENCE = '_dtb_universal_part_confidence';

	/** Universal part family: screw | nut | washer | pin | o-ring | bolt | set screw. */
	const UNIVERSAL_PART_FAMILY = '_dtb_universal_part_family';

	/** Canonical title/spec signature used during universal-part matching. */
	const UNIVERSAL_PART_SIGNATURE = '_dtb_universal_part_signature';

	/** Timestamp of the most recent universal-parts sync touching this product. */
	const UNIVERSAL_PART_SYNCED_AT = '_dtb_universal_part_synced_at';

	/** Validated serialized JSON document for customer-facing features. */
	const FEATURES_JSON = '_dtb_features_json';

	/** Version of the structured content contract. */
	const CONTENT_SCHEMA_VERSION = '_dtb_content_schema_version';

	// ── Field registry ─────────────────────────────────────────────────────────

	/**
	 * Complete field registry: meta_key => [ type, description ].
	 * Type is one of: string | boolean | integer | array.
	 * 'array' fields are stored as serialized/JSON strings in WP meta.
	 *
	 * @var array<string, array{ type: string, description: string }>
	 */
	const FIELDS = [
		self::FEATURES_JSON => [ 'type' => 'string', 'description' => 'Versioned DTB Features JSON.' ],
		self::CONTENT_SCHEMA_VERSION => [ 'type' => 'string', 'description' => 'Structured product content schema version.' ],
		self::BRAND_KEY             => [ 'type' => 'string',  'description' => 'Canonical brand slug key (e.g. tapetech).' ],
		self::BRAND_LABEL           => [ 'type' => 'string',  'description' => 'Human-readable brand label (e.g. TapeTech).' ],
		self::MANUFACTURER_SKU      => [ 'type' => 'string',  'description' => 'Manufacturer SKU / part number.' ],
		self::MPN                   => [ 'type' => 'string',  'description' => 'Official manufacturer part number (MPN).' ],
		self::UPC                   => [ 'type' => 'string',  'description' => 'UPC / GTIN barcode.' ],
		self::SOURCE_BRAND          => [ 'type' => 'string',  'description' => 'Raw brand as sourced from catalog data.' ],
		self::SOURCE_URL            => [ 'type' => 'string',  'description' => 'Official product page URL.' ],
		self::CATALOG_SOURCE        => [ 'type' => 'string',  'description' => 'Catalog data provenance identifier.' ],
		self::PRODUCT_KIND          => [ 'type' => 'string',  'description' => 'Product kind: tool | part | accessory | service | kit.' ],
		self::TOOL_FAMILY           => [ 'type' => 'string',  'description' => 'Canonical tool family key.' ],
		self::TOOL_ROLE             => [ 'type' => 'string',  'description' => 'Role in a toolset: primary_tool | handle | adapter | ….' ],
		self::CATEGORY_KEY          => [ 'type' => 'string',  'description' => 'DTB catalog category key.' ],
		self::DISPLAY_CATEGORY_KEY  => [ 'type' => 'string',  'description' => 'Display category key for brand-scoped UX.' ],
		self::IS_PARTS              => [ 'type' => 'boolean', 'description' => 'True when this is a replacement part.' ],
		self::IS_REPAIRABLE         => [ 'type' => 'boolean', 'description' => 'True when eligible for repair service.' ],
		self::PARENT_PRODUCT_SKU    => [ 'type' => 'string',  'description' => 'Parent variable product SKU (stored on variation).' ],
		self::VARIATION_AXIS        => [ 'type' => 'string',  'description' => 'Canonical variation axis key (size | length | …).' ],
		self::VARIATION_VALUE       => [ 'type' => 'string',  'description' => 'Canonical variation value (e.g. 7).' ],
		self::VARIATION_LABEL       => [ 'type' => 'string',  'description' => 'Human-readable variation label (e.g. 7 in).' ],
		self::DEFAULT_VARIATION_ID  => [ 'type' => 'integer', 'description' => 'ID of the default variation for card display.' ],
		self::DEFAULT_VARIATION_SKU => [ 'type' => 'string',  'description' => 'SKU of the default variation (stable across imports; resolved before ID fallback).' ],
		self::INHERIT_PARENT_IMAGE  => [ 'type' => 'boolean', 'description' => 'True when variation should inherit parent image.' ],
		self::VARIATION_SORT        => [ 'type' => 'integer', 'description' => 'Sort weight within a variable product.' ],
		self::BUILDER_ELIGIBLE      => [ 'type' => 'boolean', 'description' => 'True when eligible for Toolset Builder slots.' ],
		self::BUILDER_SLOTS         => [ 'type' => 'array',   'description' => 'Builder slot IDs this product can fill.' ],
		self::WORKFLOW_SCOPES       => [ 'type' => 'array',   'description' => 'Workflow scopes this product applies to.' ],
		self::BUILDER_RANK          => [ 'type' => 'integer', 'description' => 'Sort rank within a builder slot (lower = first).' ],
		self::BUILDER_REQUIRED_ACCESSORY => [ 'type' => 'boolean', 'description' => 'True when this is a required kit accessory.' ],
		self::KIT_INCLUDED_ITEM     => [ 'type' => 'boolean', 'description' => 'True when this item is kit-included (not selectable).' ],
		self::COMPATIBLE_TOOL_SKUS  => [ 'type' => 'array',   'description' => 'SKUs of tools this product is compatible with.' ],
		self::REPLACEMENT_PART_FOR  => [ 'type' => 'array',   'description' => 'Parent tool SKUs this is a replacement part for.' ],
		self::SCHEMATIC_BRAND       => [ 'type' => 'string',  'description' => 'Schematics brand slug.' ],
		self::SCHEMATIC_GROUP       => [ 'type' => 'string',  'description' => 'Schematics tool group identifier.' ],
		self::SCHEMATIC_POSITION    => [ 'type' => 'integer', 'description' => 'Position number on schematic diagram.' ],
		self::COMMERCE_MODE         => [ 'type' => 'string',  'description' => 'Commerce mode: purchasable | quote_only | hidden_reference | repair_only | included_item.' ],
		self::MAP_PRICE             => [ 'type' => 'string',  'description' => 'Optional minimum advertised price used by the catalog pricing workspace.' ],
		self::MAP_SOURCE            => [ 'type' => 'string',  'description' => 'Short operator-facing source/evidence label for MAP.' ],
		self::UNIVERSAL_PART_ID         => [ 'type' => 'string', 'description' => 'Backend-only universal physical part identifier.' ],
		self::UNIVERSAL_PART_STATUS     => [ 'type' => 'string', 'description' => 'Universal part import/sync status.' ],
		self::UNIVERSAL_PART_CONFIDENCE => [ 'type' => 'string', 'description' => 'Universal part confidence.' ],
		self::UNIVERSAL_PART_FAMILY     => [ 'type' => 'string', 'description' => 'Universal part family.' ],
		self::UNIVERSAL_PART_SIGNATURE  => [ 'type' => 'string', 'description' => 'Canonical universal matching signature.' ],
		self::UNIVERSAL_PART_SYNCED_AT  => [ 'type' => 'string', 'description' => 'Universal-parts sync timestamp.' ],
	];

	/** Return all array-type meta keys. */
	public static function array_keys_list(): array {
		return array_keys( array_filter( self::FIELDS, static fn( $f ) => 'array' === $f['type'] ) );
	}

	/** Return all boolean-type meta keys. */
	public static function boolean_keys_list(): array {
		return array_keys( array_filter( self::FIELDS, static fn( $f ) => 'boolean' === $f['type'] ) );
	}
}

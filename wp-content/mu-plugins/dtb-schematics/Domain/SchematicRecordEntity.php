<?php
/**
 * DTB Schematics — SchematicRecordEntity
 *
 * The authoritative schematic domain record. Backed by the private
 * `dtb_schematic` custom post type (see Infrastructure/SchematicPostType.php
 * and Infrastructure/SchematicRecordRepository.php).
 *
 * This is the sole authority for "does this schematic exist" — it replaces
 * the split `_dtb_is_schematic` (admin) / `_dtb_schematic_id` (public
 * manifest) signals that previously diverged.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

class DTB_Schematic_Record_Entity {

	public int    $id;
	public string $canonical_id;
	public string $source_schema_version;
	public DTB_Schematic_Lifecycle_Status $lifecycle;
	public string $brand_id;
	public string $brand_name;
	public string $category_id;
	public string $category_name;

	/**
	 * Groups size/variant siblings of one WooCommerce parent product under a
	 * shared identifier (e.g. all of 8FFBA/10FFBA/12FFBA/14FFBA's records
	 * resolve to family_id "col-automatic-flat-box"). Empty when no family
	 * grouping is known for this record. See
	 * Data/SkuSchematicMap.php::DTB_SCHEMATIC_FAMILY_MAP and
	 * scripts/catalog/gen_sku_schematic_map.py for derivation.
	 */
	public string $family_id;

	/**
	 * Human-readable variant label (e.g. "8 in.") when this record
	 * unambiguously represents exactly one WooCommerce variation of its
	 * family. Left empty when one record represents multiple variants (the
	 * variant is then only known per-WooCommerce-variation, not per
	 * schematic record) or when no family is known at all.
	 */
	public string $variant_label;

	public string $title;
	public string $model;
	public string $description;

	/** @var string[] */
	public array $aliases;

	/** @var array[] Ordered page definitions, see dtb_schematic_page_make(). */
	public array $pages;

	/** @var array See dtb_schematic_preview_policy_make(). */
	public array $preview_policy;

	/** @var int[] Linked WooCommerce tool product/variation IDs. */
	public array $linked_products;

	/** @var array See dtb_schematic_hotspot_dataset_ref_make(). Schematic-level default; pages may carry their own. */
	public array $hotspot_dataset;

	/** @var array[] See dtb_schematic_part_relationship_make(). */
	public array $parts;

	/** @var array Source provenance: { source_path, source_manifest_row, migrated_from }. */
	public array $source_provenance;

	public string $source_version;
	public int    $publication_version;
	public string $created_at;
	public string $updated_at;

	private function __construct() {}

	public static function from_post( WP_Post $post ): self {
		$e = new self();

		$e->id                     = $post->ID;
		$e->canonical_id           = (string) get_post_meta( $post->ID, '_dtb_schematic_canonical_id', true );
		$e->source_schema_version  = (string) get_post_meta( $post->ID, '_dtb_schematic_source_schema_version', true );
		$e->lifecycle              = new DTB_Schematic_Lifecycle_Status( (string) get_post_meta( $post->ID, '_dtb_schematic_lifecycle', true ) );
		$e->brand_id               = (string) get_post_meta( $post->ID, '_dtb_schematic_brand_id', true );
		$e->brand_name             = (string) get_post_meta( $post->ID, '_dtb_schematic_brand_name', true );
		$e->category_id            = (string) get_post_meta( $post->ID, '_dtb_schematic_category_id', true );
		$e->category_name          = (string) get_post_meta( $post->ID, '_dtb_schematic_category_name', true );
		$e->family_id              = (string) get_post_meta( $post->ID, '_dtb_schematic_family_id', true );
		$e->variant_label          = (string) get_post_meta( $post->ID, '_dtb_schematic_variant_label', true );
		$e->title                  = (string) $post->post_title;
		$e->model                  = (string) get_post_meta( $post->ID, '_dtb_schematic_model', true );
		$e->description            = (string) get_post_meta( $post->ID, '_dtb_schematic_description', true );

		$aliases = get_post_meta( $post->ID, '_dtb_schematic_aliases', true );
		$e->aliases = is_array( $aliases ) ? array_values( array_map( 'strval', $aliases ) ) : [];

		$pages = get_post_meta( $post->ID, '_dtb_schematic_pages', true );
		$e->pages = is_array( $pages ) ? array_map( 'dtb_schematic_page_make', $pages ) : [];
		usort( $e->pages, fn( $a, $b ) => $a['page_number'] <=> $b['page_number'] );

		$preview_policy = get_post_meta( $post->ID, '_dtb_schematic_preview_policy', true );
		$e->preview_policy = dtb_schematic_preview_policy_make( is_array( $preview_policy ) ? $preview_policy : [] );

		$linked_products = get_post_meta( $post->ID, '_dtb_schematic_linked_products', true );
		$e->linked_products = dtb_schematic_normalize_product_ids( $linked_products );

		$hotspot_dataset = get_post_meta( $post->ID, '_dtb_schematic_hotspot_dataset', true );
		$e->hotspot_dataset = dtb_schematic_hotspot_dataset_ref_make( is_array( $hotspot_dataset ) ? $hotspot_dataset : [] );

		$parts = get_post_meta( $post->ID, '_dtb_schematic_parts', true );
		$e->parts = is_array( $parts ) ? array_map( 'dtb_schematic_part_relationship_make', $parts ) : [];

		$provenance = get_post_meta( $post->ID, '_dtb_schematic_source_provenance', true );
		$e->source_provenance = is_array( $provenance ) ? $provenance : [];

		$e->source_version       = (string) get_post_meta( $post->ID, '_dtb_schematic_source_version', true );
		$e->publication_version  = (int) get_post_meta( $post->ID, '_dtb_schematic_publication_version', true );
		$e->created_at           = $post->post_date;
		$e->updated_at           = $post->post_modified;

		return $e;
	}

	/**
	 * Full internal representation (admin/operator use). Not a public API shape.
	 */
	public function to_array(): array {
		return [
			'id'                     => $this->id,
			'canonical_id'           => $this->canonical_id,
			'source_schema_version'  => $this->source_schema_version,
			'lifecycle'              => $this->lifecycle->value(),
			'lifecycle_label'        => $this->lifecycle->label(),
			'brand_id'               => $this->brand_id,
			'brand_name'             => $this->brand_name,
			'category_id'            => $this->category_id,
			'category_name'          => $this->category_name,
			'family_id'              => $this->family_id,
			'variant_label'          => $this->variant_label,
			'title'                  => $this->title,
			'model'                  => $this->model,
			'description'            => $this->description,
			'aliases'                => $this->aliases,
			'pages'                  => $this->pages,
			'preview_policy'         => $this->preview_policy,
			'linked_products'        => $this->linked_products,
			'hotspot_dataset'        => $this->hotspot_dataset,
			'parts'                  => $this->parts,
			'source_provenance'      => $this->source_provenance,
			'source_version'         => $this->source_version,
			'publication_version'    => $this->publication_version,
			'created_at'             => $this->created_at,
			'updated_at'             => $this->updated_at,
		];
	}
}

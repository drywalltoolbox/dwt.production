<?php
/**
 * DTB Schematics — SchematicAssetDisposition value object.
 *
 * Every asset discovered by the Phase 3 reconciliation engine
 * (Application/ReconcileSchematicSource.php) must resolve to exactly one of
 * these explicit dispositions. This is a closed, deliberately small set —
 * do not represent a disposition as an ad-hoc string anywhere else.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Schematic_Asset_Disposition {

	/** Source binary, WordPress attachment, and domain page record all agree. */
	const ACTIVE_AND_SYNCHRONIZED = 'active_and_synchronized';

	/** Present in the canonical source manifest/package; no WordPress signal found yet. */
	const SOURCE_ONLY = 'source_only';

	/** A matching file exists in the configured uploads location but no attachment post references it. */
	const UPLOADED_BUT_UNATTACHED = 'uploaded_but_unattached';

	/** A WordPress attachment exists (legacy `_dtb_is_schematic` flag) but carries no resolvable schematic identity. */
	const ATTACHED_BUT_UNIDENTIFIED = 'attached_but_unidentified';

	/** The attachment is identified, but its legacy/domain identity points at a different schematic than the source manifest expects. */
	const REGISTERED_TO_WRONG_SCHEMATIC = 'registered_to_wrong_schematic';

	/** The attachment belongs to the correct schematic but the wrong page number. */
	const REGISTERED_TO_WRONG_PAGE = 'registered_to_wrong_page';

	/** Two or more source rows, or two or more attachments, claim the same schematic+page. */
	const DUPLICATE_SCHEMATIC_PAGE = 'duplicate_schematic_page';

	/** The source manifest references a filename that does not exist (or fails its checksum) in the source package. */
	const MISSING_SOURCE_BINARY = 'missing_source_binary';

	/** The domain record expects a page attachment that cannot be resolved to any WordPress attachment. */
	const MISSING_WORDPRESS_ATTACHMENT = 'missing_wordpress_attachment';

	/** The attachment exists and is correctly identified, but lacks dimensions/media-type metadata. */
	const MISSING_MEDIA_METADATA = 'missing_media_metadata';

	/** Identified only through the legacy `_dtb_is_schematic` / `_dtb_schematic_id` postmeta signals, with no current source-manifest counterpart. */
	const LEGACY = 'legacy';

	/** A legacy-identified attachment whose schematic/page is now served by a different, current attachment. */
	const SUPERSEDED = 'superseded';

	/** The owning domain record has been explicitly retired. */
	const RETIRED = 'retired';

	/** Multiple explanations are equally plausible and cannot be resolved without an operator decision. */
	const AMBIGUOUS_AND_UNRESOLVED = 'ambiguous_and_unresolved';

	public static function all(): array {
		return [
			self::ACTIVE_AND_SYNCHRONIZED,
			self::SOURCE_ONLY,
			self::UPLOADED_BUT_UNATTACHED,
			self::ATTACHED_BUT_UNIDENTIFIED,
			self::REGISTERED_TO_WRONG_SCHEMATIC,
			self::REGISTERED_TO_WRONG_PAGE,
			self::DUPLICATE_SCHEMATIC_PAGE,
			self::MISSING_SOURCE_BINARY,
			self::MISSING_WORDPRESS_ATTACHMENT,
			self::MISSING_MEDIA_METADATA,
			self::LEGACY,
			self::SUPERSEDED,
			self::RETIRED,
			self::AMBIGUOUS_AND_UNRESOLVED,
		];
	}

	public static function labels(): array {
		return [
			self::ACTIVE_AND_SYNCHRONIZED       => 'Active and synchronized',
			self::SOURCE_ONLY                   => 'Source-only',
			self::UPLOADED_BUT_UNATTACHED       => 'Uploaded but unattached',
			self::ATTACHED_BUT_UNIDENTIFIED      => 'Attached but unidentified',
			self::REGISTERED_TO_WRONG_SCHEMATIC => 'Registered to the wrong schematic',
			self::REGISTERED_TO_WRONG_PAGE      => 'Registered to the wrong page',
			self::DUPLICATE_SCHEMATIC_PAGE      => 'Duplicate schematic/page',
			self::MISSING_SOURCE_BINARY         => 'Missing source binary',
			self::MISSING_WORDPRESS_ATTACHMENT  => 'Missing WordPress attachment',
			self::MISSING_MEDIA_METADATA        => 'Missing media metadata',
			self::LEGACY                        => 'Legacy',
			self::SUPERSEDED                    => 'Superseded',
			self::RETIRED                       => 'Retired',
			self::AMBIGUOUS_AND_UNRESOLVED       => 'Ambiguous and unresolved',
		];
	}

	/** Dispositions that are eligible for an automatic write in commit mode. */
	public static function writable(): array {
		return [
			self::SOURCE_ONLY,
			self::UPLOADED_BUT_UNATTACHED,
			self::REGISTERED_TO_WRONG_PAGE,
			self::MISSING_WORDPRESS_ATTACHMENT,
			self::MISSING_MEDIA_METADATA,
		];
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}

	public static function label( string $value ): string {
		return self::labels()[ $value ] ?? $value;
	}
}

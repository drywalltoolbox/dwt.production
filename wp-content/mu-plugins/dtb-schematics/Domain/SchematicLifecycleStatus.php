<?php
/**
 * DTB Schematics — SchematicLifecycleStatus value object.
 *
 * Explicit lifecycle states for the authoritative `dtb_schematic` domain
 * record. This is the sole authority for "does this schematic exist and is
 * it usable" — it replaces the split `_dtb_is_schematic` / `_dtb_schematic_id`
 * signals that previously diverged between wp-admin and the public manifest.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Schematic_Lifecycle_Status {

	const DRAFT      = 'draft';
	const INCOMPLETE = 'incomplete';
	const READY      = 'ready';
	const PUBLISHED  = 'published';
	const RETIRED    = 'retired';

	private string $value;

	public function __construct( string $value ) {
		if ( ! in_array( $value, self::all(), true ) ) {
			$value = self::DRAFT;
		}
		$this->value = $value;
	}

	public function value(): string {
		return $this->value;
	}

	public function label(): string {
		return self::labels()[ $this->value ] ?? ucwords( str_replace( '_', ' ', $this->value ) );
	}

	public function is_published(): bool {
		return self::PUBLISHED === $this->value;
	}

	public function is_retired(): bool {
		return self::RETIRED === $this->value;
	}

	public static function all(): array {
		return [
			self::DRAFT,
			self::INCOMPLETE,
			self::READY,
			self::PUBLISHED,
			self::RETIRED,
		];
	}

	public static function labels(): array {
		return [
			self::DRAFT      => __( 'Draft', 'drywall-toolbox' ),
			self::INCOMPLETE => __( 'Incomplete', 'drywall-toolbox' ),
			self::READY      => __( 'Ready to Publish', 'drywall-toolbox' ),
			self::PUBLISHED  => __( 'Published', 'drywall-toolbox' ),
			self::RETIRED    => __( 'Retired', 'drywall-toolbox' ),
		];
	}

	/**
	 * Allowed lifecycle transitions. Kept explicit and small — no implicit
	 * jumps (e.g. draft cannot go straight to published without passing
	 * through the eligibility check applied at the "ready"/"published" edge).
	 *
	 * @return array<string,string[]>
	 */
	public static function allowed_transitions(): array {
		return [
			self::DRAFT      => [ self::INCOMPLETE, self::READY, self::RETIRED ],
			self::INCOMPLETE => [ self::DRAFT, self::READY, self::RETIRED ],
			self::READY      => [ self::DRAFT, self::INCOMPLETE, self::PUBLISHED, self::RETIRED ],
			self::PUBLISHED  => [ self::READY, self::INCOMPLETE, self::RETIRED ],
			self::RETIRED    => [ self::DRAFT ],
		];
	}

	public static function can_transition( string $from, string $to ): bool {
		if ( $from === $to ) {
			return true;
		}
		$allowed = self::allowed_transitions()[ $from ] ?? [];
		return in_array( $to, $allowed, true );
	}
}

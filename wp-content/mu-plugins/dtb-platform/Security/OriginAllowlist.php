<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OriginAllowlist' ) ) {
	return;
}

final class DTB_OriginAllowlist {
	/**
	 * @return string[]
	 */
	public static function all(): array {
		return dtb_allowed_origins();
	}

	public static function is_allowed( ?string $origin = null ): bool {
		if ( null === $origin ) {
			return dtb_check_origin();
		}

		$origin = rtrim( sanitize_text_field( $origin ), '/' );
		if ( '' === $origin ) {
			return true;
		}

		return in_array( $origin, self::all(), true );
	}
}

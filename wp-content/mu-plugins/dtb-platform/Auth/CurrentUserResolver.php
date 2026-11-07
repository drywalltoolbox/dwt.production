<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CurrentUserResolver' ) ) {
	return;
}

final class DTB_CurrentUserResolver {
	public static function resolve_user_id(): int {
		return dtb_jwt_get_user_id();
	}

	public static function resolve_user(): ?WP_User {
		$user_id = self::resolve_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );
		return ( $user instanceof WP_User ) ? $user : null;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_ApiHealthController' ) ) {
	return;
}

final class DTB_ApiHealthController {
	public static function summary(): array {
		return [
			'dependencies' => DTB_DependencyHealthCheck::run(),
			'registry'     => DTB_HealthRegistry::run_all(),
			'timestamp'    => gmdate( 'c' ),
		];
	}
}

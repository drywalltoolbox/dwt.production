<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_HealthRegistry' ) ) {
	return;
}

final class DTB_HealthRegistry {
	/** @var array<string, callable> */
	private static array $checks = [];

	public static function register( string $name, callable $callback ): void {
		self::$checks[ sanitize_key( $name ) ] = $callback;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function run_all(): array {
		$results = [];

		foreach ( self::$checks as $name => $callback ) {
			try {
				$value            = $callback();
				$results[ $name ] = is_array( $value ) ? $value : [ 'ok' => (bool) $value ];
			} catch ( Throwable $e ) {
				$results[ $name ] = [ 'ok' => false, 'error' => $e->getMessage() ];
			}
		}

		return $results;
	}
}

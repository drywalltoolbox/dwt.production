<?php
/**
 * Catalog Health issue helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogHealthIssue {
	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_INFO    = 'info';

	/**
	 * Build a normalized catalog-health issue record.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $product_name Product name.
	 * @param string $sku          Product or variation SKU display value.
	 * @param string $severity     Severity.
	 * @param string $code         Machine-readable issue code.
	 * @param string $message      Human-readable message.
	 * @return array{product_id:int,product_name:string,sku:string,severity:string,code:string,message:string}
	 */
	public static function make( int $product_id, string $product_name, string $sku, string $severity, string $code, string $message ): array {
		return [
			'product_id'   => $product_id,
			'product_name' => $product_name,
			'sku'          => '' === $sku ? '(none)' : $sku,
			'severity'     => self::normalize_severity( $severity ),
			'code'         => $code,
			'message'      => $message,
		];
	}

	/**
	 * Normalize severity values.
	 *
	 * @param string $severity Raw severity.
	 * @return string
	 */
	public static function normalize_severity( string $severity ): string {
		return match ( $severity ) {
			self::SEVERITY_ERROR,
			self::SEVERITY_WARNING,
			self::SEVERITY_INFO => $severity,
			default => self::SEVERITY_INFO,
		};
	}
}

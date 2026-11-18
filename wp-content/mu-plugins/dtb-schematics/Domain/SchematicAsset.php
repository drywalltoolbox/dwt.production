<?php
defined( 'ABSPATH' ) || exit;

/**
 * Build a normalized manifest asset payload.
 */
function dtb_schematic_asset_make( string $url, ?int $width, ?int $height ): array {
	return [
		'url'    => $url,
		'width'  => $width,
		'height' => $height,
	];
}

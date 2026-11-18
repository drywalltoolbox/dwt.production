<?php
/**
 * Registrations — TokenDefinitions: the v1 editable design-token surface.
 *
 * Every token here maps 1:1 onto a CSS custom property already defined in
 * frontend/src/styles/storefront-tokens.css. No parallel token source is
 * introduced — operators are only ever changing the *value* the frontend
 * already reads through that variable name, so there is exactly one
 * canonical repository-default source (this file) and one canonical
 * persisted-override source (the drafts/revisions tables).
 *
 * This is the initial, fully-wired token surface. Additional groups
 * (typography scale, shadows, density, z-index, transitions) are natural
 * extension points once the frontend exposes matching CSS variables — add
 * them here with dtb_vd_register_token_group() rather than inventing a
 * second token mechanism.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_vd_hex_color_schema( string $default ): array {
	return [ 'type' => 'color', 'default' => $default ];
}

function dtb_vd_px_schema( float $default, float $min, float $max ): array {
	return [ 'type' => 'spacing', 'default' => $default, 'min' => $min, 'max' => $max ];
}

add_action( 'init', function (): void {

	dtb_vd_register_token_group( [
		'id'          => 'brand-colors',
		'name'        => 'Brand Colors',
		'description' => 'Primary brand color used across CTAs, links, and active states.',
		'tokens'      => [
			'color-primary'        => array_merge( dtb_vd_hex_color_schema( '#2255ee' ), [ 'name' => 'Primary', 'css_var' => '--dtb-primary' ] ),
			'color-primary-strong' => array_merge( dtb_vd_hex_color_schema( '#2255ee' ), [ 'name' => 'Primary (Strong)', 'css_var' => '--dtb-primary-strong' ] ),
		],
	] );

	dtb_vd_register_token_group( [
		'id'          => 'surface-colors',
		'name'        => 'Surface Colors',
		'description' => 'Page, card, and chrome background colors.',
		'tokens'      => [
			'color-bg'        => array_merge( dtb_vd_hex_color_schema( '#f4f6fb' ), [ 'name' => 'Page Background', 'css_var' => '--dtb-bg' ] ),
			'color-surface'   => array_merge( dtb_vd_hex_color_schema( '#ffffff' ), [ 'name' => 'Card Surface', 'css_var' => '--dtb-surface' ] ),
			'color-shell'     => array_merge( dtb_vd_hex_color_schema( '#0a1020' ), [ 'name' => 'Shell (Header/Footer)', 'css_var' => '--dtb-shell' ] ),
			'color-shell-2'   => array_merge( dtb_vd_hex_color_schema( '#121a2f' ), [ 'name' => 'Shell Accent', 'css_var' => '--dtb-shell-2' ] ),
			'color-drawer-bg' => array_merge( dtb_vd_hex_color_schema( '#020617' ), [ 'name' => 'Drawer Background', 'css_var' => '--dtb-drawer-bg' ] ),
		],
	] );

	dtb_vd_register_token_group( [
		'id'          => 'text-colors',
		'name'        => 'Text Colors',
		'description' => 'Primary and muted text colors.',
		'tokens'      => [
			'color-text'  => array_merge( dtb_vd_hex_color_schema( '#0f172a' ), [ 'name' => 'Text', 'css_var' => '--dtb-text' ] ),
			'color-muted' => array_merge( dtb_vd_hex_color_schema( '#64748b' ), [ 'name' => 'Muted Text', 'css_var' => '--dtb-muted' ] ),
		],
	] );

	dtb_vd_register_token_group( [
		'id'          => 'borders',
		'name'        => 'Borders',
		'description' => 'Hairline border color used across storefront cards and dividers.',
		'tokens'      => [
			'color-border' => array_merge( dtb_vd_hex_color_schema( '#dbe3f1' ), [ 'name' => 'Border', 'css_var' => '--dtb-border' ] ),
		],
	] );

	dtb_vd_register_token_group( [
		'id'          => 'radii',
		'name'        => 'Corner Radius',
		'description' => 'Border-radius scale applied to cards, buttons, and sheets.',
		'tokens'      => [
			'radius-sm' => array_merge( dtb_vd_px_schema( 8, 0, 32 ), [ 'name' => 'Small', 'css_var' => '--dtb-radius-sm' ] ),
			'radius-md' => array_merge( dtb_vd_px_schema( 12, 0, 40 ), [ 'name' => 'Medium', 'css_var' => '--dtb-radius-md' ] ),
			'radius-lg' => array_merge( dtb_vd_px_schema( 16, 0, 48 ), [ 'name' => 'Large', 'css_var' => '--dtb-radius-lg' ] ),
			'radius-xl' => array_merge( dtb_vd_px_schema( 20, 0, 56 ), [ 'name' => 'Extra Large', 'css_var' => '--dtb-radius-xl' ] ),
		],
	] );

	dtb_vd_register_token_group( [
		'id'          => 'layout',
		'name'        => 'Layout',
		'description' => 'Global chrome dimensions.',
		'tokens'      => [
			'header-height'         => array_merge( dtb_vd_px_schema( 72, 48, 120 ), [ 'name' => 'Header Height', 'css_var' => '--dtb-header-height' ] ),
			'mobile-search-height'  => array_merge( dtb_vd_px_schema( 48, 32, 80 ), [ 'name' => 'Mobile Search Bar Height', 'css_var' => '--dtb-mobile-search-height' ] ),
		],
	] );

}, 5 );

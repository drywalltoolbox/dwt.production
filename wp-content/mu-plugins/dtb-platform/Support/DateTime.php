<?php
/**
 * DateTime — DTB Platform
 *
 * Date/time utility helpers (extension point).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translatable "N minutes/hours/days ago" label for a timestamp.
 *
 * A thin wrapper around human_time_diff() — that core function returns only
 * the bare interval ("3 hours"); the "ago" suffix must be added separately
 * and, unlike a hardcoded string concatenation, needs to go through a
 * translation function to be localizable.
 *
 * @param string $timestamp  Any strtotime()-parsable date/time string.
 * @param bool   $assume_utc Append " UTC" before parsing (for timestamps
 *                            stored without a timezone but known to be UTC,
 *                            e.g. current_time( 'mysql', true )).
 * @return string Empty string when $timestamp is empty/unparseable.
 */
function dtb_time_ago( string $timestamp, bool $assume_utc = false ): string {
	if ( '' === $timestamp ) {
		return '';
	}

	$ts = strtotime( $assume_utc ? $timestamp . ' UTC' : $timestamp );
	if ( ! $ts ) {
		return '';
	}

	/* translators: %s: human-readable time interval, e.g. "3 hours" */
	return sprintf( __( '%s ago', 'drywall-toolbox' ), human_time_diff( $ts ) );
}

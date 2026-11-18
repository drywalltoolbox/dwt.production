<?php
/**
 * Application — DraftService: validated draft mutation with optimistic
 * concurrency and structural sanitization.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply a partial patch to the draft: a set of {surface_id, component_id,
 * property_id, scope, breakpoint, value} operations, plus optional token
 * overrides. Every operation is validated against the live registry before
 * being merged into the stored payload — unknown/invalid operations are
 * dropped rather than failing the whole request, and are reported back so
 * the editor can show precisely what did not apply.
 *
 * @param string $draft_key
 * @param array  $ops     List of operations (see above).
 * @param array  $tokens  Map of token_id => new value (optional).
 * @param int    $expected_revision_seq
 * @param int    $editor_id
 * @return array{success:bool, draft:array|null, rejected:array, conflict:bool}
 */
function dtb_vd_apply_draft_patch( string $draft_key, array $ops, array $tokens, int $expected_revision_seq, int $editor_id ): array {
	if ( ! dtb_vd_structurally_bounded( $ops ) || ! dtb_vd_structurally_bounded( $tokens ) ) {
		return [ 'success' => false, 'draft' => null, 'rejected' => [ 'bounds' ], 'conflict' => false ];
	}

	$current = dtb_vd_get_or_create_draft( $draft_key );
	if ( $current['revision_seq'] !== $expected_revision_seq ) {
		return [ 'success' => false, 'draft' => $current, 'rejected' => [], 'conflict' => true ];
	}

	$payload = $current['payload'];
	$payload['tokens']   = (array) ( $payload['tokens'] ?? [] );
	$payload['surfaces'] = (array) ( $payload['surfaces'] ?? [] );

	$rejected = [];

	foreach ( $tokens as $token_id => $value ) {
		$token_def = dtb_vd_get_token( (string) $token_id );
		if ( ! $token_def ) {
			$rejected[] = "token:{$token_id}";
			continue;
		}
		[ $valid, $sanitized ] = dtb_vd_sanitize_value( $token_def, $value );
		if ( ! $valid ) {
			$rejected[] = "token:{$token_id}";
			continue;
		}
		$payload['tokens'][ $token_id ] = $sanitized;
	}

	foreach ( $ops as $op ) {
		$surface_id   = (string) ( $op['surfaceId'] ?? '' );
		$component_id = (string) ( $op['componentId'] ?? '' );
		$property_id  = (string) ( $op['propertyId'] ?? '' );
		$scope        = (string) ( $op['scope'] ?? 'component' );
		$breakpoint   = (string) ( $op['breakpoint'] ?? 'base' );

		$component_def = dtb_vd_get_component( $surface_id, $component_id );
		if ( ! $component_def ) {
			$rejected[] = "{$surface_id}.{$component_id}";
			continue;
		}

		if ( ! isset( $payload['surfaces'][ $surface_id ]['components'][ $component_id ] ) ) {
			$payload['surfaces'][ $surface_id ]['components'][ $component_id ] = [];
		}
		$slot = &$payload['surfaces'][ $surface_id ]['components'][ $component_id ];

		if ( 'visibility' === ( $op['op'] ?? '' ) ) {
			if ( ! $component_def['visibility_toggle'] || 'commerce_critical' === $component_def['kind'] ) {
				$rejected[] = "{$surface_id}.{$component_id}.visibility";
				unset( $slot );
				continue;
			}
			$slot['hidden'] = (bool) ( $op['hidden'] ?? false );
			unset( $slot );
			continue;
		}

		if ( 'order' === ( $op['op'] ?? '' ) ) {
			$reorder = $component_def['reorder'] ?? null;
			if ( ! $reorder ) {
				$rejected[] = "{$surface_id}.{$component_id}.order";
				unset( $slot );
				continue;
			}
			[ $valid, $sanitized ] = dtb_vd_sanitize_value( [ 'type' => 'order_index', 'min' => 0, 'max' => 200 ], $op['order'] ?? null );
			if ( ! $valid ) {
				$rejected[] = "{$surface_id}.{$component_id}.order";
				unset( $slot );
				continue;
			}
			$slot['order'] = $sanitized;
			unset( $slot );
			continue;
		}

		// Default: property value set (global/surface/component/responsive scope).
		$property_schema = dtb_vd_get_property_schema( $surface_id, $component_id, $property_id );
		if ( ! $property_schema ) {
			$rejected[] = "{$surface_id}.{$component_id}.{$property_id}";
			unset( $slot );
			continue;
		}

		[ $valid, $sanitized ] = dtb_vd_sanitize_value( $property_schema, $op['value'] ?? null );
		if ( ! $valid ) {
			$rejected[] = "{$surface_id}.{$component_id}.{$property_id}";
			unset( $slot );
			continue;
		}

		if ( 'responsive' === $scope ) {
			if ( empty( $component_def['responsive'] ) || ! in_array( $breakpoint, dtb_vd_breakpoints(), true ) || 'base' === $breakpoint ) {
				$rejected[] = "{$surface_id}.{$component_id}.{$property_id}@{$breakpoint}";
				unset( $slot );
				continue;
			}
			$slot['responsive'][ $breakpoint ][ $property_id ] = $sanitized;
		} else {
			$slot['properties'][ $property_id ] = $sanitized;
		}

		unset( $slot );
	}

	[ $success, $updated_draft ] = dtb_vd_update_draft( $draft_key, $payload, $expected_revision_seq, $editor_id );

	if ( $success ) {
		dtb_vd_audit( 'design.draft.updated', [
			'draft_key' => $draft_key,
			'ops_count' => count( $ops ),
			'rejected'  => count( $rejected ),
		] );
	}

	return [
		'success'  => $success,
		'draft'    => $updated_draft,
		'rejected' => $rejected,
		'conflict' => ! $success,
	];
}

/**
 * Discard the draft, resetting it back to the currently published revision.
 *
 * @param string $draft_key
 * @param int    $editor_id
 * @return array Updated draft.
 */
function dtb_vd_discard_draft( string $draft_key, int $editor_id ): array {
	$draft = dtb_vd_reset_draft_to_published( $draft_key, $editor_id );
	dtb_vd_revoke_preview_sessions( $draft_key );
	dtb_vd_audit( 'design.draft.discarded', [ 'draft_key' => $draft_key ] );
	return $draft;
}

/**
 * Reset a single scope (property / component / surface / token-group / all)
 * back to its inherited/repository-default value within the draft.
 *
 * @param string $draft_key
 * @param string $level 'property'|'component'|'surface'|'token_group'|'all'
 * @param array  $target {surfaceId?, componentId?, propertyId?, breakpoint?, tokenGroupId?}
 * @param int    $expected_revision_seq
 * @param int    $editor_id
 * @return array{success:bool, draft:array|null, conflict:bool}
 */
function dtb_vd_reset_scope( string $draft_key, string $level, array $target, int $expected_revision_seq, int $editor_id ): array {
	$current = dtb_vd_get_or_create_draft( $draft_key );
	if ( $current['revision_seq'] !== $expected_revision_seq ) {
		return [ 'success' => false, 'draft' => $current, 'conflict' => true ];
	}

	$payload = $current['payload'];

	switch ( $level ) {
		case 'all':
			$payload = [ 'tokens' => [], 'surfaces' => [] ];
			break;

		case 'token_group':
			$group_id = (string) ( $target['tokenGroupId'] ?? '' );
			$group    = dtb_vd_get_token_groups()[ $group_id ] ?? null;
			if ( $group ) {
				foreach ( array_keys( (array) $group['tokens'] ) as $token_id ) {
					unset( $payload['tokens'][ $token_id ] );
				}
			}
			break;

		case 'surface':
			unset( $payload['surfaces'][ (string) ( $target['surfaceId'] ?? '' ) ] );
			break;

		case 'component':
			unset( $payload['surfaces'][ (string) ( $target['surfaceId'] ?? '' ) ]['components'][ (string) ( $target['componentId'] ?? '' ) ] );
			break;

		case 'property':
		default:
			$surface_id   = (string) ( $target['surfaceId'] ?? '' );
			$component_id = (string) ( $target['componentId'] ?? '' );
			$property_id  = (string) ( $target['propertyId'] ?? '' );
			$breakpoint   = $target['breakpoint'] ?? null;
			if ( $breakpoint ) {
				unset( $payload['surfaces'][ $surface_id ]['components'][ $component_id ]['responsive'][ $breakpoint ][ $property_id ] );
			} else {
				unset( $payload['surfaces'][ $surface_id ]['components'][ $component_id ]['properties'][ $property_id ] );
			}
			break;
	}

	[ $success, $updated_draft ] = dtb_vd_update_draft( $draft_key, $payload, $expected_revision_seq, $editor_id );

	if ( $success ) {
		dtb_vd_audit( 'design.draft.reset', [ 'draft_key' => $draft_key, 'level' => $level, 'target' => $target ] );
	}

	return [ 'success' => $success, 'draft' => $updated_draft, 'conflict' => ! $success ];
}

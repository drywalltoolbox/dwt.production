<?php
/**
 * DTB Returns — ReturnService
 *
 * Business logic layer for returns workflow.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transition a return to a new status.
 *
 * @param int    $return_id
 * @param string $new_status
 * @return true|\WP_Error
 */
function dtb_return_transition_status( int $return_id, string $new_status ) {
	$entity = dtb_returns_get( $return_id );
	if ( ! $entity ) {
		return new WP_Error( 'not_found', __( 'Return not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$valid = DTB_Return_Status::all();
	if ( ! in_array( $new_status, $valid, true ) ) {
		return new WP_Error( 'invalid_status', __( 'Invalid status.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$current_status = $entity->status->value();

	// Enforce transition-map rules when the map is loaded.
	if ( function_exists( 'dtb_return_is_valid_transition' ) ) {
		if ( ! dtb_return_is_valid_transition( $current_status, $new_status ) ) {
			return new WP_Error(
				'invalid_transition',
				sprintf(
					/* translators: 1 = current status, 2 = requested status */
					__( 'Cannot transition a return from "%1$s" to "%2$s".', 'drywall-toolbox' ),
					$current_status,
					$new_status
				),
				[ 'status' => 409 ]
			);
		}
	}

	$result = dtb_returns_save( [ 'id' => $return_id, 'status' => $new_status ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	do_action( 'dtb_return_status_changed', $return_id, $current_status, $new_status );

	dtb_audit_log_write( 'return.status_changed', [
		'return_id'  => $return_id,
		'from'       => $current_status,
		'to'         => $new_status,
		'user_id'    => get_current_user_id(),
	] );

	return true;
}

/**
 * Create a new return request.
 *
 * @param array $data  { order_id, customer_name, customer_email, reason, resolution }
 * @return int|\WP_Error
 */
function dtb_return_create( array $data ) {
	$required = [ 'order_id', 'customer_name', 'customer_email', 'reason' ];
	foreach ( $required as $field ) {
		if ( empty( $data[ $field ] ) ) {
			return new WP_Error(
				'missing_field',
				/* translators: %s = field name */
				sprintf( __( 'Missing required field: %s', 'drywall-toolbox' ), $field ),
				[ 'status' => 400 ]
			);
		}
	}

	$data['status'] = DTB_Return_Status::PENDING_REVIEW;

	$id = dtb_returns_save( $data );
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	do_action( 'dtb_return_created', $id, $data );

	dtb_audit_log_write( 'return.created', [
		'return_id' => $id,
		'order_id'  => (int) $data['order_id'],
		'user_id'   => get_current_user_id(),
	] );

	return $id;
}

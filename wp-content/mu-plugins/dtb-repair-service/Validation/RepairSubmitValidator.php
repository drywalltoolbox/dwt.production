<?php
/**
 * Validation — RepairSubmitValidator: validate repair submission request body.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate a repair submission payload.
 *
 * @param array $data Raw (unsanitized) input data.
 * @return true|WP_Error True on success, WP_Error on validation failure.
 */
function dtb_validate_repair_submit( array $data ): bool|WP_Error {
$errors = new WP_Error();
$submission_text_fn = function_exists( 'dtb_repair_pick_submission_text' )
	? 'dtb_repair_pick_submission_text'
	: null;

$required = [
'customer_name'  => [
	'label' => __( 'Customer name', 'drywall-toolbox' ),
	'keys'  => [ 'customer_name', 'full_name', 'fullName' ],
],
'customer_email' => [
	'label' => __( 'Email address', 'drywall-toolbox' ),
	'keys'  => [ 'customer_email', 'email' ],
],
'description'    => [
	'label' => __( 'Repair description', 'drywall-toolbox' ),
	'keys'  => [ 'description', 'issue', 'issueDescription' ],
],
'item_type'      => [
	'label' => __( 'Item type', 'drywall-toolbox' ),
	'keys'  => [ 'item_type', 'tool_category', 'toolCategory', 'item_brand', 'tool_brand', 'toolBrand' ],
],
];

foreach ( $required as $field => $rule ) {
	$value = $submission_text_fn
		? $submission_text_fn( $data, $rule['keys'] )
		: '';

	if ( '' === $value ) {
$errors->add(
'dtb_repair_missing_' . $field,
sprintf(
/* translators: %s: field label */
__( '%s is required.', 'drywall-toolbox' ),
$rule['label']
)
);
}
}

$customer_email = $submission_text_fn
	? $submission_text_fn( $data, [ 'customer_email', 'email' ] )
	: '';

if ( '' !== $customer_email && ! is_email( $customer_email ) ) {
$errors->add(
'dtb_repair_invalid_email',
__( 'A valid email address is required.', 'drywall-toolbox' )
);
}

$customer_name = $submission_text_fn
	? $submission_text_fn( $data, [ 'customer_name', 'full_name', 'fullName' ] )
	: '';

if ( '' !== $customer_name && mb_strlen( $customer_name ) > 100 ) {
$errors->add(
'dtb_repair_name_too_long',
__( 'Customer name must be 100 characters or fewer.', 'drywall-toolbox' )
);
}

$description = $submission_text_fn
	? $submission_text_fn( $data, [ 'description', 'issue', 'issueDescription' ] )
	: '';

if ( '' !== $description && mb_strlen( $description ) > 3000 ) {
$errors->add(
'dtb_repair_description_too_long',
__( 'Repair description must be 3000 characters or fewer.', 'drywall-toolbox' )
);
}

if ( $errors->has_errors() ) {
return $errors;
}

return true;
}

<?php
/**
 * Validation — RepairMediaValidator: validate uploaded media files.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allowed MIME types for repair media uploads.
 * Primary definition lives in RepairPostType.php; guard here to avoid
 * redeclaration when both files are loaded.
 */
if ( ! defined( 'DTB_REPAIR_ALLOWED_MIME_TYPES' ) ) {
	define( 'DTB_REPAIR_ALLOWED_MIME_TYPES', [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'video/mp4',
		'video/quicktime',
	] );
}

/**
 * Maximum file size in bytes (25 MB).
 */
if ( ! defined( 'DTB_REPAIR_MAX_UPLOAD_BYTES' ) ) {
	define( 'DTB_REPAIR_MAX_UPLOAD_BYTES', 26214400 );
}

/**
 * Validate a single repair media upload.
 *
 * @param array $file  Single entry from $_FILES (name, type, size, tmp_name, error).
 * @return true|WP_Error True on success, WP_Error on validation failure.
 */
function dtb_validate_repair_media( array $file ): bool|WP_Error {
if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
return new WP_Error(
'dtb_repair_upload_error',
__( 'File upload failed. Please try again.', 'drywall-toolbox' )
);
}

if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
return new WP_Error(
'dtb_repair_upload_invalid',
__( 'Invalid file upload.', 'drywall-toolbox' )
);
}

if ( (int) $file['size'] > DTB_REPAIR_MAX_UPLOAD_BYTES ) {
return new WP_Error(
'dtb_repair_upload_too_large',
__( 'File is too large. Maximum size is 25 MB.', 'drywall-toolbox' )
);
}

$finfo     = new finfo( FILEINFO_MIME_TYPE );
$mime_type = $finfo->file( $file['tmp_name'] );

if ( ! in_array( $mime_type, DTB_REPAIR_ALLOWED_MIME_TYPES, true ) ) {
return new WP_Error(
'dtb_repair_upload_type_not_allowed',
sprintf(
/* translators: %s: mime type */
__( 'File type "%s" is not allowed.', 'drywall-toolbox' ),
esc_html( $mime_type )
)
);
}

return true;
}

<?php
/**
 * DTB Media bootstrap.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_rest_request() ) {
	return;
}

dtb_module_require( 'dtb-media/Validation/ImagePathValidator.php' );
dtb_module_require( 'dtb-media/Application/ImageSyncLock.php' );
dtb_module_require( 'dtb-media/Services/ImageUrlResolver.php' );
dtb_module_require( 'dtb-media/Validation/RemoteImageValidator.php' );
dtb_module_require( 'dtb-media/Rest/ImageSyncController.php' );
dtb_module_require( 'dtb-media/Application/SyncRemoteImage.php' );
dtb_module_require( 'dtb-media/Application/LinkImagesToProducts.php' );
dtb_module_require( 'dtb-media/Rest/ImageSyncProgressController.php' );
dtb_module_require( 'dtb-media/Services/ImageNormalizer.php' );
dtb_module_require( 'dtb-media/Services/ImageSyncService.php' );
dtb_module_require( 'dtb-media/Rest/ImageSyncStatusController.php' );
dtb_module_require( 'dtb-media/Application/ResetImageSync.php' );
dtb_module_require( 'dtb-media/Application/PurgeUnlinkedImages.php' );
dtb_module_require( 'dtb-media/Application/ReleaseImageSyncLock.php' );
dtb_module_require( 'dtb-media/Validation/ImageMimeValidator.php' );
dtb_module_require( 'dtb-media/Infrastructure/ImageSyncRepository.php' );
dtb_module_require( 'dtb-media/Services/VariationGalleryResolver.php' );
dtb_module_require( 'dtb-media/Infrastructure/RemoteImageFetcher.php' );
dtb_module_require( 'dtb-media/Application/RegisterProductImages.php' );
dtb_module_require( 'dtb-media/Services/ProductImageLinker.php' );
dtb_module_require( 'dtb-media/Infrastructure/MediaAttachmentRepository.php' );
dtb_module_require( 'dtb-media/Admin/MediaDiagnosticsPage.php' );
dtb_module_require( 'dtb-media/Admin/ImageSyncPage.php' );
dtb_module_require( 'dtb-media/Rest/VariationGalleryRestEnricher.php' );

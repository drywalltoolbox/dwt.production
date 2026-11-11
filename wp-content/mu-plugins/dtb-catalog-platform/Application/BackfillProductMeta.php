<?php
defined( 'ABSPATH' ) || exit;

/**
 * Application use case: run one page of meta backfill.
 */
function dtb_catalog_backfill_product_meta_page( int $page, int $per_page, bool $dry_run, bool $force ): array {
	if ( class_exists( 'DTB_MetaBackfillTool' ) && method_exists( 'DTB_MetaBackfillTool', 'run_page' ) ) {
		return DTB_MetaBackfillTool::run_page( $page, $per_page, $dry_run, $force );
	}

	return [ 'products' => [], 'written' => 0, 'skipped' => 0 ];
}

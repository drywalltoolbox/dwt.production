<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_RestSchema' ) ) {
	return;
}

final class DTB_RestSchema {
	/**
	 * @return array<string,mixed>
	 */
	public static function pagination( int $page, int $per_page, int $total ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		return [
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => max( 0, $total ),
			'pages'    => max( 1, (int) ceil( max( 0, $total ) / $per_page ) ),
		];
	}
}

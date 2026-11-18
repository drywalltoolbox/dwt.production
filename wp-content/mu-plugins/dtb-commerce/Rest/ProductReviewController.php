<?php
/**
 * Authoritative WooCommerce product-review transport.
 *
 * Reviews remain WordPress comments and WooCommerce remains responsible for
 * rating aggregates, moderation, verified-purchase metadata, and settings.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_ProductReviewController {
	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'comment_post', [ __CLASS__, 'invalidate' ], 20, 2 );
		add_action( 'transition_comment_status', [ __CLASS__, 'invalidate_transition' ], 20, 3 );
		add_action( 'edit_comment', [ __CLASS__, 'invalidate_by_id' ], 20 );
		add_action( 'delete_comment', [ __CLASS__, 'invalidate_by_id' ], 20 );
	}

	public static function routes(): void {
		register_rest_route( 'dtb/v1', '/products/(?P<product_id>\d+)/reviews', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'read' ],
				'permission_callback' => '__return_true',
				'args'                => [ 'page' => [ 'default' => 1, 'sanitize_callback' => 'absint' ], 'per_page' => [ 'default' => 10, 'sanitize_callback' => 'absint' ] ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create' ],
				'permission_callback' => static fn() => is_user_logged_in(),
			],
		] );
	}

	private static function product( WP_REST_Request $request ): ?WC_Product {
		$product = wc_get_product( absint( $request['product_id'] ) );
		return $product instanceof WC_Product ? $product : null;
	}

	private static function summary( WC_Product $product ): array {
		return [ 'average_rating' => (float) $product->get_average_rating(), 'rating_count' => (int) $product->get_rating_count(), 'review_count' => (int) $product->get_review_count() ];
	}

	public static function read( WP_REST_Request $request ) {
		$product = self::product( $request );
		if ( ! $product ) return new WP_Error( 'dtb_review_product_not_found', __( 'Product not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 20, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$query = new WP_Comment_Query( [ 'post_id' => $product->get_id(), 'type' => 'review', 'status' => 'approve', 'number' => $per_page + 1, 'offset' => ( $page - 1 ) * $per_page, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ] );
		$has_more = count( $query->comments ) > $per_page;
		$reviews = array_map( static function( WP_Comment $comment ) use ( $product ): array {
			$rating = max( 1, min( 5, (int) get_comment_meta( $comment->comment_ID, 'rating', true ) ) );
			$verified = function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $comment->comment_author_email, (int) $comment->user_id, $product->get_id() );
			return [ 'id' => (int) $comment->comment_ID, 'author' => get_comment_author( $comment ), 'rating' => $rating, 'content' => wp_kses_post( $comment->comment_content ), 'date' => gmdate( 'c', strtotime( $comment->comment_date_gmt . ' UTC' ) ), 'verified_purchase' => (bool) $verified ];
		}, array_slice( $query->comments, 0, $per_page ) );
		return rest_ensure_response( [ 'summary' => self::summary( $product ), 'reviews' => $reviews, 'page' => $page, 'per_page' => $per_page, 'has_more' => $has_more ] );
	}

	public static function create( WP_REST_Request $request ) {
		$product = self::product( $request );
		if ( ! $product ) return new WP_Error( 'dtb_review_product_not_found', __( 'Product not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
		if ( 'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' ) ) return new WP_Error( 'dtb_reviews_disabled', __( 'Reviews are not enabled.', 'drywall-toolbox' ), [ 'status' => 403 ] );
		if ( 'yes' !== get_option( 'woocommerce_enable_review_rating', 'yes' ) ) return new WP_Error( 'dtb_review_ratings_disabled', __( 'Product ratings are not enabled.', 'drywall-toolbox' ), [ 'status' => 403 ] );
		$user = wp_get_current_user();
		if ( 'yes' === get_option( 'woocommerce_review_rating_verification_required', 'no' ) && ! wc_customer_bought_product( $user->user_email, $user->ID, $product->get_id() ) ) return new WP_Error( 'dtb_review_verified_purchase_required', __( 'Only verified purchasers can review this product.', 'drywall-toolbox' ), [ 'status' => 403 ] );
		$key = 'dtb_review_rate_' . get_current_user_id() . '_' . $product->get_id();
		if ( get_transient( $key ) ) return new WP_Error( 'dtb_review_rate_limited', __( 'Please wait before submitting another review.', 'drywall-toolbox' ), [ 'status' => 429 ] );
		$rating = absint( $request->get_param( 'rating' ) );
		$content = sanitize_textarea_field( (string) $request->get_param( 'content' ) );
		if ( $rating < 1 || $rating > 5 || '' === $content ) return new WP_Error( 'dtb_review_invalid', __( 'A rating from 1 to 5 and review text are required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
		$comment_id = wp_new_comment( [ 'comment_post_ID' => $product->get_id(), 'comment_content' => $content, 'user_id' => $user->ID, 'comment_author' => $user->display_name, 'comment_author_email' => $user->user_email, 'comment_type' => 'review', 'comment_approved' => 0 ], true );
		if ( is_wp_error( $comment_id ) ) return $comment_id;
		if ( ! $comment_id ) return new WP_Error( 'dtb_review_create_failed', __( 'Unable to submit review.', 'drywall-toolbox' ), [ 'status' => 500 ] );
		update_comment_meta( $comment_id, 'rating', $rating );
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		self::purge_product_caches( $product->get_id() );
		return new WP_REST_Response( [ 'ok' => true, 'status' => 'pending_moderation', 'review_id' => (int) $comment_id ], 201 );
	}

	public static function invalidate( int $comment_id, int $approved ): void { $comment = get_comment( $comment_id ); if ( $comment && 'review' === $comment->comment_type ) self::purge_product_caches( (int) $comment->comment_post_ID ); }
	public static function invalidate_transition( string $new, string $old, WP_Comment $comment ): void { if ( 'review' === $comment->comment_type && $new !== $old ) self::purge_product_caches( (int) $comment->comment_post_ID ); }
	public static function invalidate_by_id( int $comment_id ): void { self::invalidate( $comment_id, 0 ); }
	private static function purge_product_caches( int $product_id ): void { wc_delete_product_transients( $product_id ); if ( function_exists( 'dtb_catalog_cache_invalidate_all' ) ) dtb_catalog_cache_invalidate_all( $product_id ); }
}

DTB_ProductReviewController::register();

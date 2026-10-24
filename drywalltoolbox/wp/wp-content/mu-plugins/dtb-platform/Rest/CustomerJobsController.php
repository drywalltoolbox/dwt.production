<?php
/**
 * Customer-owned jobsite notebook. Jobs and notes are private WordPress records,
 * not public content or a second commerce/account authority.
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', static function (): void {
	foreach ( [ 'dtb_customer_job', 'dtb_customer_note' ] as $type ) {
		register_post_type( $type, [
			'label'               => 'DTB Customer Job Records',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'rewrite'             => false,
			'query_var'          => false,
			'supports'           => [ 'title', 'editor', 'excerpt', 'author', 'page-attributes' ],
		] );
	}
}, 10 );

function dtb_jobs_user_id(): int {
	$user = DTB_CurrentUserResolver::resolve_user();
	return $user instanceof WP_User ? (int) $user->ID : 0;
}

function dtb_jobs_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, [ 'status' => $status ] );
}

function dtb_jobs_response( array $payload, int $status = 200 ): WP_REST_Response {
	$response = new WP_REST_Response( $payload, $status );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

function dtb_jobs_owned_job( int $id, int $user_id ): ?WP_Post {
	$post = get_post( $id );
	return $post instanceof WP_Post
		&& 'dtb_customer_job' === $post->post_type
		&& (int) $post->post_author === $user_id
		&& in_array( $post->post_status, [ 'private', 'draft' ], true )
		? $post : null;
}

function dtb_jobs_owned_note( int $id, int $job_id, int $user_id ): ?WP_Post {
	$post = get_post( $id );
	return $post instanceof WP_Post
		&& 'dtb_customer_note' === $post->post_type
		&& 'private' === $post->post_status
		&& (int) $post->post_parent === $job_id
		&& (int) $post->post_author === $user_id
		? $post : null;
}

function dtb_jobs_format_job( WP_Post $job ): array {
	return [
		'id'         => (int) $job->ID,
		'name'       => (string) $job->post_title,
		'location'   => (string) $job->post_excerpt,
		'archived'   => 'draft' === $job->post_status,
		'created_at' => get_post_time( 'c', true, $job ),
		'updated_at' => get_post_modified_time( 'c', true, $job ),
	];
}

function dtb_jobs_format_note( WP_Post $note ): array {
	return [
		'id'         => (int) $note->ID,
		'job_id'     => (int) $note->post_parent,
		'content'    => (string) $note->post_content,
		'created_at' => get_post_time( 'c', true, $note ),
		'updated_at' => get_post_modified_time( 'c', true, $note ),
	];
}

function dtb_jobs_validate_fields( array $data, bool $creating ) {
	$name = array_key_exists( 'name', $data ) ? sanitize_text_field( (string) $data['name'] ) : null;
	$location = array_key_exists( 'location', $data ) ? sanitize_text_field( (string) $data['location'] ) : null;
	if ( ( $creating || null !== $name ) && ( '' === trim( (string) $name ) || strlen( (string) $name ) > 120 ) ) {
		return dtb_jobs_error( 'invalid_job_name', 'Job name must be between 1 and 120 characters.', 422 );
	}
	if ( null !== $location && strlen( $location ) > 250 ) {
		return dtb_jobs_error( 'invalid_job_location', 'Location must not exceed 250 characters.', 422 );
	}
	return [ 'name' => $name, 'location' => $location ];
}

function dtb_jobs_mutation_guard( WP_REST_Request $request ) {
	if ( ! dtb_check_origin() ) {
		return dtb_jobs_error( 'origin_denied', 'Request origin not permitted.', 403 );
	}
	return dtb_rate_limit( $request, 'customer_jobs_write' );
}

function dtb_jobs_list( WP_REST_Request $request ) {
	$user_id = dtb_jobs_user_id();
	if ( ! $user_id ) return dtb_jobs_error( 'unauthorized', 'Sign in required.', 401 );
	$page = max( 1, min( 10000, (int) $request->get_param( 'page' ) ) );
	$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
	$archived = rest_sanitize_boolean( $request->get_param( 'archived' ) );
	$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) );
	$query = new WP_Query( [
		'post_type'           => 'dtb_customer_job',
		'post_status'         => $archived ? 'draft' : 'private',
		's'                   => substr( $search, 0, 120 ),
		'author'              => $user_id,
		'posts_per_page'      => $per_page,
		'paged'               => $page,
		'orderby'             => 'modified',
		'order'               => 'DESC',
		'no_found_rows'       => false,
		'ignore_sticky_posts' => true,
	] );
	return dtb_jobs_response( [
		'jobs'       => array_map( 'dtb_jobs_format_job', $query->posts ),
		'total'      => (int) $query->found_posts,
		'page'       => $page,
		'per_page'   => $per_page,
	] );
}

function dtb_jobs_item( WP_REST_Request $request ) {
	$user_id = dtb_jobs_user_id();
	$job = dtb_jobs_owned_job( absint( $request['id'] ), $user_id );
	if ( ! $job ) return dtb_jobs_error( 'job_not_found', 'Job not found.', 404 );

	if ( 'GET' === $request->get_method() ) return dtb_jobs_response( [ 'job' => dtb_jobs_format_job( $job ) ] );
	$guard = dtb_jobs_mutation_guard( $request );
	if ( $guard ) return $guard;
	if ( 'DELETE' === $request->get_method() ) {
		// Remove notes in bounded batches before deleting the parent.
		// Deleting the first page repeatedly prevents skipped rows.
		do {
			$notes = get_posts( [
				'post_type' => 'dtb_customer_note', 'post_status' => 'private',
				'author' => $user_id, 'post_parent' => $job->ID,
				'numberposts' => 100, 'fields' => 'ids',
			] );
			foreach ( $notes as $note_id ) {
				if ( ! wp_delete_post( (int) $note_id, true ) ) {
					return dtb_jobs_error( 'note_delete_failed', 'Could not remove associated notes.', 500 );
				}
			}
		} while ( count( $notes ) === 100 );
		if ( ! wp_delete_post( $job->ID, true ) ) return dtb_jobs_error( 'job_delete_failed', 'Could not delete job.', 500 );
		return dtb_jobs_response( [ 'success' => true ] );
	}
	$data = $request->get_json_params();
	$data = is_array( $data ) ? $data : [];
	$fields = dtb_jobs_validate_fields( $data, false );
	if ( is_wp_error( $fields ) ) return $fields;
	$update = [ 'ID' => $job->ID ];
	if ( null !== $fields['name'] ) $update['post_title'] = $fields['name'];
	if ( null !== $fields['location'] ) $update['post_excerpt'] = $fields['location'];
	if ( array_key_exists( 'archived', $data ) ) $update['post_status'] = rest_sanitize_boolean( $data['archived'] ) ? 'draft' : 'private';
	if ( count( $update ) === 1 ) return dtb_jobs_response( [ 'job' => dtb_jobs_format_job( $job ) ] );
	$updated = wp_update_post( wp_slash( $update ), true );
	if ( is_wp_error( $updated ) || ! $updated ) return dtb_jobs_error( 'job_update_failed', 'Could not update job.', 500 );
	return dtb_jobs_response( [ 'job' => dtb_jobs_format_job( get_post( $updated ) ) ] );
}

function dtb_jobs_create( WP_REST_Request $request ) {
	$guard = dtb_jobs_mutation_guard( $request );
	if ( $guard ) return $guard;
	$user_id = dtb_jobs_user_id();
	if ( ! $user_id ) return dtb_jobs_error( 'unauthorized', 'Sign in required.', 401 );
	$data = $request->get_json_params();
	$fields = dtb_jobs_validate_fields( is_array( $data ) ? $data : [], true );
	if ( is_wp_error( $fields ) ) return $fields;
	$id = wp_insert_post( wp_slash( [
		'post_type' => 'dtb_customer_job', 'post_status' => 'private',
		'post_author' => $user_id, 'post_title' => $fields['name'],
		'post_excerpt' => $fields['location'] ?? '',
	] ), true );
	if ( is_wp_error( $id ) || ! $id ) return dtb_jobs_error( 'job_create_failed', 'Could not create job.', 500 );
	return dtb_jobs_response( [ 'job' => dtb_jobs_format_job( get_post( $id ) ) ], 201 );
}

function dtb_jobs_notes( WP_REST_Request $request ) {
	$user_id = dtb_jobs_user_id();
	$job_id = absint( $request['id'] );
	if ( ! dtb_jobs_owned_job( $job_id, $user_id ) ) return dtb_jobs_error( 'job_not_found', 'Job not found.', 404 );
	if ( 'GET' === $request->get_method() ) {
		$page = max( 1, min( 10000, (int) $request->get_param( 'page' ) ) );
		$query = new WP_Query( [
			'post_type' => 'dtb_customer_note', 'post_status' => 'private',
			'author' => $user_id, 'post_parent' => $job_id,
			'posts_per_page' => 30, 'paged' => $page,
			'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => false,
		] );
		return dtb_jobs_response( [
			'notes' => array_map( 'dtb_jobs_format_note', $query->posts ),
			'total' => (int) $query->found_posts,
			'page' => $page,
		] );
	}
	$guard = dtb_jobs_mutation_guard( $request );
	if ( $guard ) return $guard;
	$data = $request->get_json_params();
	$content = is_array( $data ) ? sanitize_textarea_field( (string) ( $data['content'] ?? '' ) ) : '';
	if ( '' === trim( $content ) || strlen( $content ) > 5000 ) {
		return dtb_jobs_error( 'invalid_note', 'Note must be between 1 and 5000 characters.', 422 );
	}
	$id = wp_insert_post( wp_slash( [
		'post_type' => 'dtb_customer_note', 'post_status' => 'private',
		'post_author' => $user_id, 'post_parent' => $job_id,
		'post_title' => 'Job Note', 'post_content' => $content,
	] ), true );
	if ( is_wp_error( $id ) || ! $id ) return dtb_jobs_error( 'note_create_failed', 'Could not add note.', 500 );
	wp_update_post( [ 'ID' => $job_id ] ); // Advance job modified time for recent-jobs ordering.
	return dtb_jobs_response( [ 'note' => dtb_jobs_format_note( get_post( $id ) ) ], 201 );
}

function dtb_jobs_note_item( WP_REST_Request $request ) {
	$user_id = dtb_jobs_user_id();
	$job_id = absint( $request['id'] );
	if ( ! dtb_jobs_owned_job( $job_id, $user_id ) ) return dtb_jobs_error( 'job_not_found', 'Job not found.', 404 );
	$note = dtb_jobs_owned_note( absint( $request['note_id'] ), $job_id, $user_id );
	if ( ! $note ) return dtb_jobs_error( 'note_not_found', 'Note not found.', 404 );
	$guard = dtb_jobs_mutation_guard( $request );
	if ( $guard ) return $guard;
	if ( 'DELETE' === $request->get_method() ) {
		if ( ! wp_delete_post( $note->ID, true ) ) return dtb_jobs_error( 'note_delete_failed', 'Could not delete note.', 500 );
		wp_update_post( [ 'ID' => $job_id ] );
		return dtb_jobs_response( [ 'success' => true ] );
	}
	$data = $request->get_json_params();
	$content = is_array( $data ) ? sanitize_textarea_field( (string) ( $data['content'] ?? '' ) ) : '';
	if ( '' === trim( $content ) || strlen( $content ) > 5000 ) return dtb_jobs_error( 'invalid_note', 'Note must be between 1 and 5000 characters.', 422 );
	$id = wp_update_post( wp_slash( [ 'ID' => $note->ID, 'post_content' => $content ] ), true );
	if ( is_wp_error( $id ) || ! $id ) return dtb_jobs_error( 'note_update_failed', 'Could not update note.', 500 );
	wp_update_post( [ 'ID' => $job_id ] );
	return dtb_jobs_response( [ 'note' => dtb_jobs_format_note( get_post( $id ) ) ] );
}

add_action( 'rest_api_init', static function (): void {
	$auth = 'dtb_jwt_permission';
	register_rest_route( 'dtb/v1', '/account/jobs', [
		[ 'methods' => 'GET', 'callback' => 'dtb_jobs_list', 'permission_callback' => $auth,
			'args' => [
				'page' => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
				'per_page' => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
				'archived' => [ 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
				'search' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		],
		[ 'methods' => 'POST', 'callback' => 'dtb_jobs_create', 'permission_callback' => $auth ],
	] );
	register_rest_route( 'dtb/v1', '/account/jobs/(?P<id>\\d+)', [
		[ 'methods' => 'GET', 'callback' => 'dtb_jobs_item', 'permission_callback' => $auth ],
		[ 'methods' => 'PATCH', 'callback' => 'dtb_jobs_item', 'permission_callback' => $auth ],
		[ 'methods' => 'DELETE', 'callback' => 'dtb_jobs_item', 'permission_callback' => $auth ],
	] );
	register_rest_route( 'dtb/v1', '/account/jobs/(?P<id>\\d+)/notes', [
		[ 'methods' => 'GET', 'callback' => 'dtb_jobs_notes', 'permission_callback' => $auth,
			'args' => [ 'page' => [ 'default' => 1, 'sanitize_callback' => 'absint' ] ] ],
		[ 'methods' => 'POST', 'callback' => 'dtb_jobs_notes', 'permission_callback' => $auth ],
	] );
	register_rest_route( 'dtb/v1', '/account/jobs/(?P<id>\\d+)/notes/(?P<note_id>\\d+)', [
		[ 'methods' => 'PATCH', 'callback' => 'dtb_jobs_note_item', 'permission_callback' => $auth ],
		[ 'methods' => 'DELETE', 'callback' => 'dtb_jobs_note_item', 'permission_callback' => $auth ],
	] );
}, 20 );

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: DTB Coming Soon – Email Subscriber Handler
 * Description: Handles email sign-ups from the static coming-soon.html page.
 *              Saves subscriber records to the WordPress database, enforces
 *              IP-based rate limiting, sends the site admin an instant
 *              notification e-mail, and sends the subscriber a confirmation
 *              e-mail on each new sign-up.
 *
 *              Two integration paths are provided:
 *              1. REST API  — POST /wp-json/dtb/v1/subscribe  (AJAX, primary)
 *              2. admin-post.php — traditional <form> POST fallback (no-JS)
 *
 * Version: 1.1.0
 * Author:  Drywall Toolbox
 *
 * Must-use plugin: place in wp/wp-content/mu-plugins/
 * Last Updated: 2026-04-04
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Only load this endpoint when handling REST or admin requests.
if ( ! dtb_is_admin_or_rest_request() ) {
	return;
}

// ─── Constants ────────────────────────────────────────────────────────────────

/** WordPress option key used to persist the subscriber array. */
define( 'DTB_SUBSCRIBERS_OPTION', 'dtb_email_subscribers' );

/** Maximum sign-up attempts per IP address per 24 hours (rate limit). */
define( 'DTB_RATE_LIMIT', 20 );

// =============================================================================
// 1. REST API ENDPOINTS
// =============================================================================

add_action( 'rest_api_init', 'dtb_register_coming_soon_routes' );

/**
 * Register all coming-soon REST routes under the dtb/v1 namespace.
 */
function dtb_register_coming_soon_routes(): void {

	// ── POST /wp-json/dtb/v1/subscribe ──────────────────────────────────────
	// Primary AJAX endpoint called by coming-soon.html's JavaScript.
	register_rest_route(
		'dtb/v1',
		'/subscribe',
		array(
			'methods'             => 'POST',
			'callback'            => 'dtb_rest_subscribe',
			'permission_callback' => '__return_true', // Public — anyone may subscribe.
			'args'                => array(
				'name'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'The subscriber name.',
				),
				'email'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => static function ( $value ) {
						return is_email( $value );
					},
					'description'       => 'The subscriber e-mail address.',
				),
				'_dtb_hp' => array(
					'required'    => false,
					'type'        => 'string',
					'default'     => '',
					'description' => 'Honeypot — must remain empty; any value signals a bot.',
				),
			),
		)
	);

	// ── GET /wp-json/dtb/v1/subscribe-nonce ─────────────────────────────────
	// Returns a short-lived WP nonce for the admin-post.php form fallback.
	// The coming-soon.html JS fetches this on first form interaction and injects
	// it into the hidden _dtb_nonce field so the fallback POST remains CSRF-safe.
	register_rest_route(
		'dtb/v1',
		'/subscribe-nonce',
		array(
			'methods'             => 'GET',
			'callback'            => static function () {
				return rest_ensure_response(
					array( 'nonce' => wp_create_nonce( 'dtb_subscribe' ) )
				);
			},
			'permission_callback' => '__return_true',
		)
	);

	// ── GET /wp-json/dtb/v1/subscribers ─────────────────────────────────────
	// Returns the full subscriber list. Requires manage_options capability
	// (i.e. site administrator). Use this to export or view sign-ups.
	register_rest_route(
		'dtb/v1',
		'/subscribers',
		array(
			'methods'             => 'GET',
			'callback'            => static function () {
				$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );
				return rest_ensure_response( $subscribers );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// ── GET /wp-json/dtb/v1/unsubscribe ─────────────────────────────────────
	// One-click unsubscribe link included in every confirmation email.
	// Validates an HMAC token, removes the subscriber, and renders a page.
	register_rest_route(
		'dtb/v1',
		'/unsubscribe',
		array(
			'methods'             => 'GET',
			'callback'            => 'dtb_rest_unsubscribe',
			'permission_callback' => '__return_true',
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'token' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
	// ── POST /wp-json/dtb/v1/subscribe/delete ────────────────────────────────
	// Admin-only: hard-delete a subscriber by email address.
	register_rest_route(
		'dtb/v1',
		'/subscribe/delete',
		array(
			'methods'             => 'POST',
			'callback'            => 'dtb_rest_subscribe_delete',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'email' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => static function ( $value ): bool {
						return is_email( $value );
					},
				),
			),
		)
	);
}

/**
 * REST callback for POST /dtb/v1/subscribe/delete.
 *
 * Hard-deletes a subscriber record by email address.
 * Requires manage_options capability.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_rest_subscribe_delete( WP_REST_Request $request ) {
	$email = sanitize_email( $request->get_param( 'email' ) );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', 'A valid email address is required.', array( 'status' => 400 ) );
	}

	$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );

	if ( ! isset( $subscribers[ $email ] ) ) {
		return new WP_Error( 'not_found', 'Subscriber not found.', array( 'status' => 404 ) );
	}

	unset( $subscribers[ $email ] );
	update_option( DTB_SUBSCRIBERS_OPTION, $subscribers );

	return rest_ensure_response( array(
		'deleted' => true,
		'email'   => $email,
	) );
}

// dtb_rest_unsubscribe() and other callbacks below.

/**
 * REST callback for POST /wp-json/dtb/v1/subscribe.
 *
 * Validates input, enforces rate limiting, saves the record, and fires
 * the admin notification e-mail.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response|WP_Error JSON success payload, or a WP_Error.
 */
function dtb_rest_subscribe( WP_REST_Request $request ) {

	// ── Honeypot check ───────────────────────────────────────────────────────
	// Legitimate users will never fill the hidden _dtb_hp field.
	// Return a fake success so bots do not know they were detected.
	if ( '' !== (string) $request->get_param( '_dtb_hp' ) ) {
		return rest_ensure_response( array( 'success' => true, 'message' => 'Thank you!' ) );
	}

	// ── Sanitise & validate e-mail ───────────────────────────────────────────
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please enter a valid e-mail address.', 'dtb' ),
			array( 'status' => 400 )
		);
	}

	// ── IP rate limiting ─────────────────────────────────────────────────────
	$ip         = dtb_get_client_ip();
	$rate_key   = 'dtb_sub_rate_' . md5( dtb_anonymise_ip( $ip ) );
	$rate_count = (int) get_transient( $rate_key );

	if ( $rate_count >= DTB_RATE_LIMIT ) {
		return new WP_Error(
			'rate_limited',
			__( 'Too many requests. Please try again later.', 'dtb' ),
			array( 'status' => 429 )
		);
	}

	// ── Save subscriber ──────────────────────────────────────────────────────
	$result = dtb_save_subscriber( $email, $ip );

	if ( is_wp_error( $result ) ) {
		// Surface "already subscribed" as a 200 so the UX stays friendly.
		// Do NOT increment the rate counter — this is not an abuse attempt.
		if ( 'already_subscribed' === $result->get_error_code() ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'You are already on the list!', 'dtb' ),
				)
			);
		}

		return new WP_Error(
			$result->get_error_code(),
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	// Increment rate counter only on a genuine new sign-up. Window: 24 hours.
	set_transient( $rate_key, $rate_count + 1, DAY_IN_SECONDS );

	// ── Admin notification & subscriber confirmation ─────────────────────────
	dtb_notify_admin( $email );
	dtb_send_confirmation_email( $email );

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => __( 'You are on the list. We will be in touch!', 'dtb' ),
		)
	);
}

/**
 * REST callback for GET /wp-json/dtb/v1/unsubscribe?email=...&token=...
 *
 * Validates the HMAC token, removes the subscriber, and returns a
 * self-contained HTML confirmation page (not JSON) so the browser shows
 * a friendly message when the user clicks the email link.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response|WP_Error
 */
function dtb_rest_unsubscribe( WP_REST_Request $request ) {
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	$token = sanitize_text_field( (string) $request->get_param( 'token' ) );

	// Validate the HMAC token — prevents anyone from unsubscribing arbitrary addresses.
	if ( ! hash_equals( dtb_unsubscribe_token( $email ), $token ) ) {
		dtb_unsubscribe_page(
			'Invalid or expired link',
			'This unsubscribe link is invalid or has already been used. If you need help, reply to the original email.',
			false
		);
		return new WP_REST_Response( null, 200 );
	}

	// Remove from list.
	$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );
	$found       = false;
	$subscribers = array_values(
		array_filter(
			$subscribers,
			static function ( $row ) use ( $email, &$found ) {
				$match = strtolower( $row['email'] ?? '' ) === strtolower( $email );
				if ( $match ) {
					$found = true;
				}
				return ! $match;
			}
		)
	);

	if ( $found ) {
		update_option( DTB_SUBSCRIBERS_OPTION, $subscribers, false );
	}

	dtb_unsubscribe_page(
		'You have been unsubscribed',
		'Your email address <strong>' . esc_html( $email ) . '</strong> has been removed from our list. You will not receive any further emails from us.',
		true
	);
	return new WP_REST_Response( null, 200 );
}

/**
 * Generate a deterministic HMAC token for a given email address.
 * Uses WordPress AUTH_KEY as the secret so tokens are environment-specific.
 *
 * @param string $email The subscriber email address.
 * @return string 16-character hex token.
 */
function dtb_unsubscribe_token( string $email ): string {
	$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
	return substr( hash_hmac( 'sha256', strtolower( $email ), $secret ), 0, 32 );
}

/**
 * Build a self-contained HTML page response for the unsubscribe endpoint.
 *
 * Uses the `rest_pre_serve_request` filter to take full control of the HTTP
 * response before WordPress sets Content-Type to application/json, ensuring
 * the browser renders the page rather than displaying raw source.
 *
 * @param string $title   Page heading.
 * @param string $message Body message (may contain safe HTML).
 * @param bool   $success True for success styling, false for error.
 * @return void  Never returns — exits after serving the HTML page.
 */
function dtb_unsubscribe_page( string $title, string $message, bool $success ): void {
	$site_name  = get_option( 'blogname' );
	$site_url   = home_url();
	$icon       = $success ? '&#10003;' : '&#9888;';
	$icon_color = $success ? '#16a34a' : '#dc2626';
	$html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#000000">
  <title>' . esc_html( $title ) . ' &mdash; ' . esc_html( $site_name ) . '</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    html{font-size:100%;height:100%}
    body{
      font-family:\'Inter\',-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background:#000;
      display:flex;align-items:center;justify-content:center;
      min-height:100vh;min-height:100dvh;padding:1rem;
      color:rgba(255,255,255,.9);
      background-image:
        linear-gradient(rgba(37,99,235,.025) 1px,transparent 1px),
        linear-gradient(90deg,rgba(37,99,235,.025) 1px,transparent 1px);
      background-size:56px 56px;
    }
    .wrap{
      background:rgba(11,13,28,.88);
      backdrop-filter:blur(24px) saturate(160%);
      -webkit-backdrop-filter:blur(24px) saturate(160%);
      border:1px solid rgba(37,99,235,.22);
      border-radius:16px;
      max-width:460px;width:100%;
      padding:2.25rem 1.5rem;
      text-align:center;
      box-shadow:0 28px 70px rgba(0,0,0,.85),0 0 0 1px rgba(37,99,235,.08),0 0 55px rgba(37,99,235,.05);
      position:relative;
    }
    .wrap::before{
      content:"";position:absolute;inset:0;border-radius:16px;
      background:radial-gradient(ellipse 90% 55% at 50% 0%,rgba(37,99,235,.10) 0%,transparent 68%);
      pointer-events:none;
    }
    .icon{
      font-size:2.5rem;color:' . $icon_color . ';
      margin-bottom:1.125rem;
      display:flex;align-items:center;justify-content:center;
      width:64px;height:64px;border-radius:50%;margin:0 auto 1.25rem;
      background:rgba(37,99,235,.08);border:1.5px solid rgba(37,99,235,.2);
    }
    h1{font-size:1.25rem;font-weight:700;letter-spacing:-.02em;color:#fff;margin-bottom:.625rem;position:relative;}
    p{font-size:.9375rem;color:rgba(255,255,255,.52);line-height:1.65;position:relative;}
    a{
      display:inline-flex;align-items:center;gap:.375rem;
      margin-top:1.875rem;font-size:.875rem;font-weight:500;
      color:#3b82f6;text-decoration:none;
      padding:.55rem 1.25rem;border-radius:8px;
      border:1px solid rgba(37,99,235,.25);background:rgba(37,99,235,.07);
      transition:background .25s,border-color .25s,transform .2s;
      position:relative;
    }
    a:hover{background:rgba(37,99,235,.14);border-color:rgba(37,99,235,.45);transform:translateY(-1px);}
    @media(min-width:480px){
      .wrap{padding:3rem 2.5rem;}
      h1{font-size:1.375rem;}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="icon" aria-hidden="true">' . $icon . '</div>
    <h1>' . esc_html( $title ) . '</h1>
    <p>' . $message . '</p>
    <a href="' . esc_url( $site_url ) . '">&#8592; Back to ' . esc_html( $site_name ) . '</a>
  </div>
</body>
</html>';

	// Hook into the REST dispatch pipeline before headers are sent.
	// Returning `true` tells WordPress we have already served the response.
	add_filter(
		'rest_pre_serve_request',
		static function () use ( $html ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			// Remove any Content-Type header WordPress may have queued.
			header_remove( 'X-Content-Type-Options' );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true; // Signal to WP_REST_Server: response already sent.
		}
	);

	// Return a dummy WP_REST_Response — it will never be serialised because
	// the filter above returns true and exits the serve loop.
	// We still need to return *something* so the REST callback type is valid.
	// The filter fires before WP touches the response object.
	return; // dtb_rest_unsubscribe() must return after calling this function.
}

// =============================================================================
// 2. ADMIN-POST HANDLERS  (traditional HTML form fallback — no JavaScript)
// =============================================================================

// Both hooks point to the same handler function.
// admin_post_dtb_subscribe        → logged-in users (rare for a public form)
// admin_post_nopriv_dtb_subscribe → unauthenticated visitors (typical)
add_action( 'admin_post_dtb_subscribe',        'dtb_handle_subscribe_post' );
add_action( 'admin_post_nopriv_dtb_subscribe', 'dtb_handle_subscribe_post' );

/**
 * Processes the HTML form POST to /wp-admin/admin-post.php.
 *
 * Expected POST fields:
 *   action      = dtb_subscribe       (triggers this hook)
 *   dtb_name    = Jane Contractor     (the subscriber name)
 *   dtb_email   = user@example.com    (the subscriber address)
 *   _dtb_nonce  = <wp_nonce>          (generated by /wp-json/dtb/v1/subscribe-nonce)
 *   _dtb_hp     = ""                  (honeypot — must stay empty)
 *
 * On completion the visitor is redirected back to coming-soon.html with a
 * ?status= query parameter so the page can show a success / error banner.
 */
function dtb_handle_subscribe_post(): void {

	// ── CSRF / nonce verification ────────────────────────────────────────────
	// The JS pre-fetches a nonce and injects it into the hidden _dtb_nonce
	// field before the form is submitted.
	$raw_nonce = isset( $_POST['_dtb_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['_dtb_nonce'] ) )
		: '';

	if ( ! wp_verify_nonce( $raw_nonce, 'dtb_subscribe' ) ) {
		wp_safe_redirect( home_url( '/coming-soon.html?status=error&msg=invalid_token' ) );
		exit;
	}

	// ── Honeypot check ───────────────────────────────────────────────────────
	if ( ! empty( $_POST['_dtb_hp'] ) ) {
		// Fake success — do not hint to the bot that it was blocked.
		wp_safe_redirect( home_url( '/coming-soon.html?status=success' ) );
		exit;
	}

	// ── Sanitise & validate e-mail ───────────────────────────────────────────
	$raw_email = isset( $_POST['dtb_email'] )
		? wp_unslash( $_POST['dtb_email'] )  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$email     = sanitize_email( $raw_email );

	if ( ! is_email( $email ) ) {
		wp_safe_redirect( home_url( '/coming-soon.html?status=error&msg=invalid_email' ) );
		exit;
	}

	// ── IP rate limiting ─────────────────────────────────────────────────────
	$ip         = dtb_get_client_ip();
	$rate_key   = 'dtb_sub_rate_' . md5( dtb_anonymise_ip( $ip ) );
	$rate_count = (int) get_transient( $rate_key );

	if ( $rate_count >= DTB_RATE_LIMIT ) {
		wp_safe_redirect( home_url( '/coming-soon.html?status=error&msg=rate_limited' ) );
		exit;
	}

	// ── Save subscriber ──────────────────────────────────────────────────────
	$result = dtb_save_subscriber( $email, $ip );

	if ( is_wp_error( $result ) && 'already_subscribed' !== $result->get_error_code() ) {
		$code = $result->get_error_code();
		wp_safe_redirect( home_url( '/coming-soon.html?status=error&msg=' . rawurlencode( $code ) ) );
		exit;
	}

	// Increment rate counter only on genuine new sign-ups. Window: 24 hours.
	if ( ! is_wp_error( $result ) ) {
		set_transient( $rate_key, $rate_count + 1, DAY_IN_SECONDS );
	}

	// ── Admin notification & subscriber confirmation ─────────────────────────
	dtb_notify_admin( $email );
	dtb_send_confirmation_email( $email );

	wp_safe_redirect( home_url( '/coming-soon.html?status=success' ) );
	exit;
}

// =============================================================================
// 3. WP ADMIN PAGE — Subscriber List
// =============================================================================

add_action( 'admin_menu', 'dtb_add_subscribers_menu' );

/**
 * Register a "Coming-Soon Subscribers" submenu under Settings.
 * Accessible at WP Admin → Settings → Coming-Soon Subscribers.
 */
function dtb_add_subscribers_menu(): void {
	add_options_page(
		__( 'Coming-Soon Subscribers', 'dtb' ),
		__( 'Subscribers', 'dtb' ),
		'manage_options',
		'dtb-subscribers',
		'dtb_render_subscribers_page'
	);
}

/**
 * Render the Coming-Soon Subscribers admin page.
 *
 * Displays a sortable table of all captured e-mail addresses with their
 * sign-up date and anonymised IP, plus a CSV export button.
 */
function dtb_render_subscribers_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'dtb' ) );
	}

	// ── Handle CSV export ────────────────────────────────────────────────────
	if (
		isset( $_GET['dtb_export'] ) &&
		'csv' === $_GET['dtb_export'] &&
		isset( $_GET['_wpnonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dtb_export_csv' )
	) {
		dtb_export_subscribers_csv();
		exit;
	}

	// ── Handle single subscriber deletion ────────────────────────────────────
	if (
		isset( $_GET['dtb_delete'] ) &&
		isset( $_GET['_wpnonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dtb_delete_subscriber' )
	) {
		$email_to_delete = sanitize_email( wp_unslash( $_GET['dtb_delete'] ) );
		$subscribers     = get_option( DTB_SUBSCRIBERS_OPTION, array() );
		$subscribers     = array_values(
			array_filter(
				$subscribers,
				static function ( $row ) use ( $email_to_delete ) {
					return strtolower( $row['email'] ?? '' ) !== strtolower( $email_to_delete );
				}
			)
		);
		update_option( DTB_SUBSCRIBERS_OPTION, $subscribers, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=dtb-subscribers&dtb_deleted=1' ) );
		exit;
	}

	// ── Handle delete all ────────────────────────────────────────────────────
	if (
		isset( $_POST['dtb_delete_all'] ) &&
		isset( $_POST['_wpnonce_delete_all'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_delete_all'] ) ), 'dtb_delete_all_subscribers' )
	) {
		update_option( DTB_SUBSCRIBERS_OPTION, array(), false );
		wp_safe_redirect( admin_url( 'options-general.php?page=dtb-subscribers&dtb_deleted=all' ) );
		exit;
	}

	$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );
	$count       = count( $subscribers );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Coming-Soon Subscribers', 'dtb' ); ?></h1>

		<?php if ( isset( $_GET['dtb_deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					if ( 'all' === $_GET['dtb_deleted'] ) {
						esc_html_e( 'All subscribers have been deleted.', 'dtb' );
					} else {
						esc_html_e( 'Subscriber removed successfully.', 'dtb' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: %d: number of subscribers */
				esc_html( _n( '%d subscriber captured.', '%d subscribers captured.', $count, 'dtb' ) ),
				(int) $count
			);
			?>
		</p>

		<?php if ( $count > 0 ) : ?>
			<p style="display:flex;gap:8px;align-items:center;">
				<a
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=dtb-subscribers&dtb_export=csv' ), 'dtb_export_csv' ) ); ?>"
					class="button button-primary"
				>
					<?php esc_html_e( 'Export as CSV', 'dtb' ); ?>
				</a>

				<form method="post" style="margin:0;" onsubmit="return confirm('Delete ALL subscribers? This cannot be undone.');">
					<?php wp_nonce_field( 'dtb_delete_all_subscribers', '_wpnonce_delete_all' ); ?>
					<button type="submit" name="dtb_delete_all" value="1" class="button" style="color:#b32d2e;border-color:#b32d2e;">
						<?php esc_html_e( 'Delete All', 'dtb' ); ?>
					</button>
				</form>
			</p>

			<table class="widefat striped" style="margin-top:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( '#', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'Name', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'E-mail Address', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'Sign-Up Date (UTC)', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'IP (anonymised)', 'dtb' ); ?></th>
						<th><?php esc_html_e( 'Action', 'dtb' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_reverse( $subscribers ) as $index => $row ) : ?>
						<tr>
							<td><?php echo esc_html( $count - $index ); ?></td>
							<td><?php echo esc_html( $row['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['email'] ?? '' ); ?></td>
							<td><?php echo esc_html( $row['date']  ?? '' ); ?></td>
							<td><?php echo esc_html( $row['ip']    ?? '' ); ?></td>
							<td>
								<a
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=dtb-subscribers&dtb_delete=' . rawurlencode( $row['email'] ?? '' ) ), 'dtb_delete_subscriber' ) ); ?>"
									style="color:#b32d2e;"
									onclick="return confirm('Remove <?php echo esc_js( $row['email'] ?? '' ); ?> from the list?');"
								>
									<?php esc_html_e( 'Delete', 'dtb' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No subscribers yet.', 'dtb' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Stream the subscriber list as a downloadable CSV file.
 * Called when the admin clicks "Export as CSV".
 */
function dtb_export_subscribers_csv(): void {
	$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );
	$filename    = 'dtb-subscribers-' . gmdate( 'Y-m-d' ) . '.csv';

	// Output CSV headers.
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$output = fopen( 'php://output', 'w' );

	// BOM for Excel UTF-8 compatibility.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( $output, "\xEF\xBB\xBF" );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
	fputcsv( $output, array( 'Name', 'Email', 'Date (UTC)', 'IP (anonymised)' ) );

	foreach ( $subscribers as $row ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fputcsv(
			$output,
			array(
				$row['name']  ?? '',
				$row['email'] ?? '',
				$row['date']  ?? '',
				$row['ip']    ?? '',
			)
		);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $output );
}

// =============================================================================
// 4. SHARED HELPERS
// =============================================================================

/**
 * Persist a new subscriber record in the WordPress options table.
 *
 * Storage format (serialised PHP array, autoload = false):
 * [
 *   [ 'name' => 'Jane Contractor', 'email' => 'user@example.com', 'date' => '2026-04-04T01:00:00+00:00', 'ip' => '1.2.3.0' ],
 *   ...
 * ]
 *
 * @param string $email Sanitised + validated e-mail address.
 * @param string $ip    Raw client IP (will be anonymised before storage).
 * @return true|WP_Error  True on success; WP_Error on duplicate or DB failure.
 */
function dtb_save_subscriber( string $email, string $ip ) {
	$subscribers = get_option( DTB_SUBSCRIBERS_OPTION, array() );

	// Prevent duplicate e-mail entries (case-insensitive comparison).
	foreach ( $subscribers as $existing ) {
		if ( isset( $existing['email'] ) && strtolower( $existing['email'] ) === strtolower( $email ) ) {
			return new WP_Error(
				'already_subscribed',
				__( 'This e-mail address is already on the list.', 'dtb' )
			);
		}
	}

	$subscribers[] = array(
		'email' => $email,
		'date'  => gmdate( 'c' ),              // ISO 8601, e.g. 2026-04-04T01:00:00+00:00
		'ip'    => dtb_anonymise_ip( $ip ),    // GDPR-friendly: last octet zeroed
	);

	// autoload = false — this option is only read on-demand, never on every page load.
	update_option( DTB_SUBSCRIBERS_OPTION, $subscribers, false );

	return true;
}

/**
 * Send an instant notification e-mail to the site administrator.
 *
 * Uses WordPress's wp_mail() routed through WP Mail SMTP Pro.
 *
 * @param string $email The new subscriber's e-mail address.
 */
/**
 * Render an operator notice within the canonical WooCommerce/DTB HTML shell.
 * No template sends mail or creates subscriber records.
 *
 * @param array<string,string> $details Sanitized subscriber metadata.
 */
function dtb_coming_soon_admin_html( array $details, string $url ): string {
	if ( ! function_exists( 'wc_get_template_html' ) || ! function_exists( 'WC' ) || ! WC() ) {
		return '';
	}
	$email_context = (object) [ 'id' => 'dtb_coming_soon_admin', 'object' => null ];
	$template_args = [ 'email' => $email_context, 'email_heading' => '' ];
	$header = wc_get_template_html( 'emails/email-header.php', $template_args );
	$footer = wc_get_template_html( 'emails/email-footer.php', $template_args );
	if ( '' === $header || '' === $footer ) {
		return '';
	}
	$rows = '';
	foreach ( $details as $label => $value ) {
		if ( '' === trim( $value ) ) {
			continue;
		}
		$rows .= '<tr><td valign="top" style="width:36%;padding:12px 10px 12px 0;border-bottom:1px solid #e6ebf3;color:#64748b;font:600 12px/19px Arial,sans-serif;">' . esc_html( $label ) . '</td>'
			. '<td valign="top" style="padding:12px 0;border-bottom:1px solid #e6ebf3;color:#071b45;font:600 13px/20px Arial,sans-serif;overflow-wrap:anywhere;">' . esc_html( $value ) . '</td></tr>';
	}
	$body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fff;">'
		. '<tr><td style="padding:35px 38px 14px;">'
		. '<p style="margin:0 0 9px;color:#1769ff;font:700 11px/17px Arial,sans-serif;letter-spacing:1px;text-transform:uppercase;">COMING SOON / NEW SUBSCRIBER</p>'
		. '<h1 style="margin:0 0 12px;color:#071b45;font:750 27px/35px Arial,sans-serif;">Someone new joined the list.</h1>'
		. '<p style="margin:0;color:#52627e;font:400 14px/23px Arial,sans-serif;">A visitor signed up for launch updates. Here are the details they shared.</p>'
		. '</td></tr><tr><td style="padding:12px 38px 23px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
		. '</td></tr><tr><td align="center" style="padding:0 38px 34px;">'
		. '<a href="' . esc_url( $url ) . '" style="display:inline-block;border-radius:7px;padding:14px 24px;background:#1769ff;color:#ffffff!important;text-decoration:none;font:700 14px/20px Arial,sans-serif;">View subscribers</a>'
		. '<p style="margin:17px 0 0;color:#64748b;font:400 11px/17px Arial,sans-serif;">Internal notification · Drywall Toolbox</p>'
		. '</td></tr></table>';
	return $header . $body . $footer;
}

/**
 * Send one operator notification containing the actual saved subscriber metadata.
 */
function dtb_notify_admin( string $email ): void {
	$recipient = sanitize_email( (string) apply_filters( 'dtb_coming_soon_admin_notification_recipient', 'elliott.miller@drywalltoolbox.com' ) );
	if ( ! is_email( $recipient ) ) {
		return;
	}
	$context = function_exists( 'dtb_coming_soon_get_referral_context' ) ? dtb_coming_soon_get_referral_context() : [];
	if ( ! is_array( $context ) || strtolower( (string) ( $context['email'] ?? '' ) ) !== strtolower( $email ) ) {
		$context = [];
	}
	// The stored record is a reliable fallback when hooks executed without request context.
	$stored = [];
	foreach ( (array) get_option( DTB_SUBSCRIBERS_OPTION, [] ) as $subscriber ) {
		if ( is_array( $subscriber ) && strtolower( (string) ( $subscriber['email'] ?? '' ) ) === strtolower( $email ) ) {
			$stored = $subscriber;
			break;
		}
	}
	$name = sanitize_text_field( (string) ( $context['name'] ?? $stored['name'] ?? '' ) );
	$source = sanitize_text_field( (string) ( $context['label'] ?? $stored['referral_label'] ?? 'Not provided' ) );
	$detail = sanitize_text_field( (string) ( $context['detail'] ?? $stored['referral_detail'] ?? '' ) );
	$referrer = esc_url_raw( (string) ( $context['page_referrer'] ?? $stored['page_referrer'] ?? '' ) );
	$landing = esc_url_raw( (string) ( $context['landing_url'] ?? $stored['landing_url'] ?? '' ) );
	$details = [
		'Name' => $name ?: 'Not provided',
		'Email address' => $email,
		'Received' => wp_date( 'M j, Y · g:i A T' ),
		'How they heard about us' => $source ?: 'Not provided',
	];
	if ( $detail ) { $details['Referral details'] = $detail; }
	if ( $referrer ) { $details['Page referrer'] = $referrer; }
	if ( $landing ) { $details['Landing page'] = $landing; }
	$url = admin_url( 'options-general.php?page=dtb-subscribers' );
	$plain = "New Coming Soon subscriber\n\n";
	foreach ( $details as $label => $value ) {
		$plain .= $label . ': ' . $value . "\n";
	}
	$plain .= "\nView subscribers: " . $url;
	$html = dtb_coming_soon_admin_html( $details, $url );
	// Do not silently fall back to the older layout if WC is unavailable.
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$args = [
		'to' => $recipient,
		'subject' => 'New Coming Soon signup — Drywall Toolbox',
		'message' => $html ?: $plain,
		'headers' => [ 'From: Drywall Toolbox <no-reply@' . $host . '>' ],
		'content_type' => $html ? 'text/html' : 'text/plain',
		'is_html' => (bool) $html,
		'alt_body' => $html ? $plain : '',
		'context' => [ 'module' => 'dtb-marketing', 'event' => 'coming-soon-admin-notify' ],
	];
	if ( function_exists( 'dtb_send_email' ) ) {
		dtb_send_email( $args );
	} else {
		wp_mail( $recipient, $args['subject'], $args['message'], array_merge( $args['headers'], [ 'Content-Type: ' . $args['content_type'] . '; charset=UTF-8' ] ) );
	}
}

/**
 * Send a confirmation e-mail to the new subscriber.
 *
 * Delivers branded HTML email (with plain-text fallback) using the DTB branded template system.
 *
 * @param string $email The new subscriber's validated e-mail address.
 */
function dtb_send_confirmation_email( string $email ): void {
	$site_name   = get_option( 'blogname' );
	$admin_email = get_option( 'admin_email' );
	$site_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$site_host   = '' !== $site_host ? $site_host : 'elliottm4.sg-host.com';
	$from_email  = 'no-reply@' . $site_host;

	// One-click unsubscribe — signed HMAC link, no stored state needed.
	$unsub_url = add_query_arg(
		array(
			'email' => rawurlencode( $email ),
			'token' => dtb_unsubscribe_token( $email ),
		),
		home_url( '/wp-json/dtb/v1/unsubscribe' )
	);

	$subject = sprintf(
		/* translators: %s: site name */
		__( 'You are on the list - %s', 'dtb' ),
		$site_name
	);

	// ── Plain-text fallback ───────────────────────────────────────────────────
	$plain_message = sprintf(
		__(
			"Hi there,\n\n" .
			"Thanks for signing up. We're glad you're here.\n\n" .
			"When we have a launch update, we'll send it to %s. You don't need to do anything else.\n\n" .
			"We're getting things ready behind the scenes and will be in touch with launch news.\n\n" .
			"Talk soon,\n" .
			"The %s Team\n\n" .
			"Unsubscribe: %s",
			'dtb'
		),
		esc_html( $email ),
		esc_html( $site_name ),
		$unsub_url
	);

	// ── HTML body using branded template ──────────────────────────────────────
	$html_message = '';
	if ( function_exists( 'dtb_render_branded_email' ) ) {
		$html_message = dtb_render_branded_email( [
			'title'       => 'You\'re on the list.',
			'preheader'   => 'You\'ll be among the first to hear from Drywall Toolbox.',
			'greeting'    => 'Hi there,',
			'intro'       => 'Thanks for signing up. We\'re glad you\'re here.',
			'body_html'   => sprintf(
				'<p>When we have a launch update, we\'ll send it to <strong style="color:#1769ff;">%s</strong>. You don\'t need to do anything else.</p><p>Big things are on the way — the wait won\'t be long, and it\'ll be well worth the wait.</p>',
				esc_html( $email )
			),
			'signoff'     => 'The Drywall Toolbox Team',
			'footer_note' => sprintf(
				'You received this because you signed up at %1$s. <a href="%2$s" style="color:#2563eb;text-decoration:underline;">Unsubscribe</a>',
				esc_html( $site_host ),
				esc_url( $unsub_url )
			),
		] );
	}

	$headers = array(
		'From: ' . $site_name . ' <' . $from_email . '>',
		'Reply-To: ' . $site_name . ' <' . $admin_email . '>',
	);

	if ( function_exists( 'dtb_send_email' ) ) {
		$sent = dtb_send_email(
			[
				'to'           => $email,
				'subject'      => $subject,
				'message'      => $html_message ?: $plain_message,
				'headers'      => $headers,
				'is_html'      => (bool) $html_message,
				'content_type' => $html_message ? 'text/html' : 'text/plain',
				'alt_body'     => $html_message ? $plain_message : '',
				'context'      => [
					'module' => 'dtb-marketing',
					'event'  => 'coming-soon-confirmation',
				],
			]
		);
	} else {
		// Hook phpmailer_init once to inject the plain-text AltBody.
		$set_alt_body = static function ( $phpmailer ) use ( $plain_message ) {
			$phpmailer->AltBody = $plain_message;
		};
		add_action( 'phpmailer_init', $set_alt_body );

		$sent = wp_mail(
			$email,
			$subject,
			$html_message ?: $plain_message,
			array_merge( $headers, $html_message ? [ 'Content-Type: text/html; charset=UTF-8' ] : [ 'Content-Type: text/plain; charset=UTF-8' ] )
		);

		remove_action( 'phpmailer_init', $set_alt_body );
	}

	// Log confirmation send.
	if ( $sent ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[DTB Marketing] Confirmation email sent to %s', $email ) );
	} else {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[DTB Marketing] FAILED to send confirmation email to %s', $email ) );
	}
}
// dtb_get_client_ip() and dtb_anonymise_ip() are provided by dtb-utils.php.

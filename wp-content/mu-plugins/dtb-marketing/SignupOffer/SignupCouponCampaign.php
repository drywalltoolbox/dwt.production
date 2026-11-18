<?php
/**
 * New-account first-order coupon campaign.
 *
 * The campaign owns signup eligibility and delivery. WooCommerce coupon CRUD
 * remains the authority for discount calculation and redemption accounting.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_SignupCouponCampaign {
	private const CAMPAIGN_ID      = 'welcome-10-first-order';
	private const OPTION_KEY       = 'dtb_signup_coupon_campaign';
	private const USER_COUPON_KEY  = '_dtb_signup_coupon_id';
	private const COUPON_OWNER_KEY = '_dtb_signup_coupon_user_id';
	private const COUPON_CAMP_KEY  = '_dtb_signup_coupon_campaign_id';
	private const EMAIL_PENDING_KEY = '_dtb_signup_coupon_welcome_email_pending';
	private const RETRY_HOOK        = 'dtb_marketing_retry_signup_coupon_and_email';

	public static function register(): void {
		add_action( 'dtb_customer_account_created', [ self::class, 'issue_for_new_customer' ], 10, 1 );
		add_action( 'woocommerce_created_customer', [ self::class, 'issue_for_new_customer' ], 20, 1 );
		add_filter( 'dtb_signup_offer_defer_account_email', [ self::class, 'defer_account_email_until_coupon_exists' ], 10, 2 );
		add_action( self::RETRY_HOOK, [ self::class, 'retry_coupon_and_email' ], 10, 2 );
		add_action( 'admin_menu', [ self::class, 'register_admin_page' ] );
		add_action( 'admin_post_dtb_save_signup_coupon_campaign', [ self::class, 'save_admin_settings' ] );
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function defer_account_email_until_coupon_exists( bool $defer, int $user_id ): bool {
		if ( ! self::is_active() || absint( get_user_meta( $user_id, self::USER_COUPON_KEY, true ) ) > 0 ) {
			return $defer;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			update_user_meta( $user_id, self::EMAIL_PENDING_KEY, '1' );
			as_enqueue_async_action( self::RETRY_HOOK, [ $user_id, 1 ], 'dtb-marketing', true );
			return true;
		}

		self::log_failure( 'action_scheduler_unavailable', $user_id );
		return false;
	}

	public static function retry_coupon_and_email( int $user_id, int $attempt ): void {
		self::issue_for_new_customer( $user_id );
		$coupon_id = absint( get_user_meta( $user_id, self::USER_COUPON_KEY, true ) );
		if ( $coupon_id > 0 ) {
			if ( get_user_meta( $user_id, self::EMAIL_PENDING_KEY, true ) && class_exists( 'WC_Emails' ) ) {
				\WC_Emails::instance();
				do_action( 'woocommerce_created_customer_notification', $user_id, [], false );
				delete_user_meta( $user_id, self::EMAIL_PENDING_KEY );
			}
			return;
		}

		if ( $attempt < 3 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + ( 60 * $attempt ), self::RETRY_HOOK, [ $user_id, $attempt + 1 ], 'dtb-marketing', true );
			return;
		}

		update_user_meta( $user_id, '_dtb_signup_coupon_delivery_status', 'failed' );
		self::log_failure( 'coupon_delivery_terminal', $user_id );
	}

	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/marketing/signup-offer',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_public_campaign' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function get_public_campaign(): WP_REST_Response {
		$settings = self::settings();
		$active   = self::is_active( $settings );
		$expiry_days = isset( $settings['expiry_days'] ) ? absint( $settings['expiry_days'] ) : 30;
		$response = new WP_REST_Response(
			[
				'active'      => $active,
				'campaign_id' => $active ? self::CAMPAIGN_ID : null,
				'discount'    => $active ? 10 : null,
				'currency'    => $active && function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
				'terms'       => $active ? [
					'exclude_sale_items' => ! empty( $settings['exclude_sale_items'] ),
					'individual_use'     => ! empty( $settings['individual_use'] ),
					'expiry_days'        => min( $expiry_days, 365 ),
					'minimum_amount'     => (string) ( $settings['minimum_amount'] ?? '' ),
				] : null,
			],
			200
		);
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Issue one personal coupon before the existing account email is sent.
	 * The action is called only after the server has created a customer account.
	 */
	public static function issue_for_new_customer( int $user_id ): void {
		$pending_delivery = (bool) get_user_meta( $user_id, self::EMAIL_PENDING_KEY, true );
		if ( ( ! self::is_active() && ! $pending_delivery ) || ! class_exists( 'WC_Coupon' ) || $user_id <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) || ! is_email( $user->user_email ) ) {
			return;
		}

		$existing_id = absint( get_user_meta( $user_id, self::USER_COUPON_KEY, true ) );
		if ( $existing_id > 0 ) {
			$existing = new WC_Coupon( $existing_id );
			if ( $existing->get_id() && self::coupon_belongs_to_user( $existing, $user_id ) ) {
				return;
			}
			delete_user_meta( $user_id, self::USER_COUPON_KEY );
		}

		$code      = self::coupon_code_for_user( $user_id );
		$existing  = wc_get_coupon_id_by_code( $code );
		$coupon    = $existing ? new WC_Coupon( $existing ) : new WC_Coupon();
		$settings  = self::settings();

		if ( $existing && ! self::coupon_belongs_to_user( $coupon, $user_id ) ) {
			self::log_failure( 'coupon_code_collision', $user_id );
			return;
		}
		if ( $existing && self::coupon_belongs_to_user( $coupon, $user_id ) ) {
			update_user_meta( $user_id, self::USER_COUPON_KEY, absint( $existing ) );
			return;
		}

		try {
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );
			$coupon->set_email_restrictions( [ strtolower( $user->user_email ) ] );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_individual_use( ! empty( $settings['individual_use'] ) );
			$coupon->set_exclude_sale_items( ! empty( $settings['exclude_sale_items'] ) );
			$coupon->set_description( __( 'DTB new-account first-order offer.', 'drywall-toolbox' ) );
			$coupon->update_meta_data( self::COUPON_OWNER_KEY, $user_id );
			$coupon->update_meta_data( self::COUPON_CAMP_KEY, self::CAMPAIGN_ID );

			$minimum = isset( $settings['minimum_amount'] ) ? wc_format_decimal( $settings['minimum_amount'] ) : '';
			if ( '' !== $minimum && (float) $minimum > 0 ) {
				$coupon->set_minimum_amount( $minimum );
			}

			$expiry_days = isset( $settings['expiry_days'] ) ? absint( $settings['expiry_days'] ) : 30;
			if ( $expiry_days > 0 ) {
				$expires = new WC_DateTime( 'now', new DateTimeZone( 'UTC' ) );
				$expires->modify( '+' . min( $expiry_days, 365 ) . ' days' );
				$coupon->set_date_expires( $expires );
			}

			$coupon_id = $coupon->save();
			if ( ! $coupon_id ) {
				self::log_failure( 'coupon_save_failed', $user_id );
				return;
			}

			update_user_meta( $user_id, self::USER_COUPON_KEY, absint( $coupon_id ) );
		} catch ( Throwable $error ) {
			self::log_failure( 'coupon_create_exception', $user_id );
		}
	}

	public static function settings(): array {
		$settings = get_option( self::OPTION_KEY, [] );
		return array_merge(
			[
				'enabled'            => false,
				'started_at'         => 0,
				'ends_at'            => 0,
				'minimum_amount'     => '',
				'expiry_days'        => 30,
				'exclude_sale_items' => true,
				'individual_use'     => true,
			],
			is_array( $settings ) ? $settings : []
		);
	}

	public static function is_active( ?array $settings = null ): bool {
		$settings = $settings ?? self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['started_at'] ) ) {
			return false;
		}

		$now   = time();
		$start = absint( $settings['started_at'] );
		$end   = absint( $settings['ends_at'] ?? 0 );
		return $start > 0 && $now >= $start && ( 0 === $end || $now < $end );
	}

	public static function coupon_belongs_to_user( WC_Coupon $coupon, int $user_id ): bool {
		return absint( $coupon->get_meta( self::COUPON_OWNER_KEY, true ) ) === $user_id
			&& self::CAMPAIGN_ID === (string) $coupon->get_meta( self::COUPON_CAMP_KEY, true );
	}

	public static function coupon_owner_meta_key(): string {
		return self::COUPON_OWNER_KEY;
	}

	public static function campaign_meta_key(): string {
		return self::COUPON_CAMP_KEY;
	}

	private static function coupon_code_for_user( int $user_id ): string {
		$digest = hash_hmac( 'sha256', self::CAMPAIGN_ID . ':' . $user_id, wp_salt( 'auth' ) );
		return 'DTB10-' . strtoupper( substr( $digest, 0, 24 ) );
	}

	private static function log_failure( string $event, int $user_id ): void {
		if ( function_exists( 'dtb_log_event' ) ) {
			dtb_log_event( 'warning', 'Signup coupon campaign operation failed.', [ 'module' => 'dtb-marketing', 'event' => $event, 'user_id' => $user_id ] );
			return;
		}
		error_log( sprintf( '[DTB signup coupon] %s for user %d', sanitize_key( $event ), $user_id ) );
	}

	public static function register_admin_page(): void {
		add_options_page(
			__( 'Signup Coupon Campaign', 'drywall-toolbox' ),
			__( 'Signup Coupon Campaign', 'drywall-toolbox' ),
			'manage_woocommerce',
			'dtb-signup-coupon-campaign',
			[ self::class, 'render_admin_page' ]
		);
	}

	public static function save_admin_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this campaign.', 'drywall-toolbox' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'dtb_save_signup_coupon_campaign' );

		$current = self::settings();
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		$now     = time();
		$started = absint( $current['started_at'] ?? 0 );
		if ( $enabled && ! $started ) {
			$started = $now;
		}
		$end_input = isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_at'] ) ) : '';
		$ends_at   = 0;
		if ( '' !== $end_input ) {
			$end_date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $end_input, wp_timezone() );
			$date_errors = DateTimeImmutable::getLastErrors();
			if ( ! $end_date || ( is_array( $date_errors ) && ( $date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0 ) ) || $end_date->format( 'Y-m-d\TH:i' ) !== $end_input || $end_date->getTimestamp() <= $now ) {
				wp_safe_redirect( add_query_arg( [ 'page' => 'dtb-signup-coupon-campaign', 'error' => 'invalid_end' ], admin_url( 'options-general.php' ) ) );
				exit;
			}
			$ends_at = $end_date->getTimestamp();
		}
		$minimum_input = isset( $_POST['minimum_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['minimum_amount'] ) ) : '';
		$minimum       = '' !== $minimum_input && (float) $minimum_input > 0 ? $minimum_input : '';
		$expiry    = isset( $_POST['expiry_days'] ) ? absint( $_POST['expiry_days'] ) : 30;

		update_option(
			self::OPTION_KEY,
			[
				'enabled'        => $enabled,
				'started_at'     => $started,
				'ends_at'        => $ends_at,
				'minimum_amount' => $minimum,
				'expiry_days'    => min( $expiry, 365 ),
				'exclude_sale_items' => isset( $_POST['exclude_sale_items'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['exclude_sale_items'] ) ),
				'individual_use'     => isset( $_POST['individual_use'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['individual_use'] ) ),
			],
			false
		);

		wp_safe_redirect( add_query_arg( [ 'page' => 'dtb-signup-coupon-campaign', 'updated' => '1' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this campaign.', 'drywall-toolbox' ), '', [ 'response' => 403 ] );
		}
		$settings = self::settings();
		$enabled  = ! empty( $settings['enabled'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'New Account 10% First Order Offer', 'drywall-toolbox' ); ?></h1>
			<p><?php esc_html_e( 'Each newly created customer account receives one email-restricted 10% coupon. WooCommerce enforces one redemption and the checkout policy requires the account owner.', 'drywall-toolbox' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign settings saved.', 'drywall-toolbox' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) && 'invalid_end' === sanitize_key( wp_unslash( $_GET['error'] ) ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The campaign end must be a valid future date and time. No settings were changed.', 'drywall-toolbox' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dtb_save_signup_coupon_campaign">
				<?php wp_nonce_field( 'dtb_save_signup_coupon_campaign' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dtb-signup-enabled"><?php esc_html_e( 'Campaign status', 'drywall-toolbox' ); ?></label></th>
						<td><label><input id="dtb-signup-enabled" type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Active', 'drywall-toolbox' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="dtb-signup-ends"><?php esc_html_e( 'Campaign end (site time)', 'drywall-toolbox' ); ?></label></th>
						<td><input id="dtb-signup-ends" type="datetime-local" name="ends_at" value="<?php echo esc_attr( ! empty( $settings['ends_at'] ) ? wp_date( 'Y-m-d\TH:i', absint( $settings['ends_at'] ) ) : '' ); ?>"><p class="description"><?php esc_html_e( 'Leave blank for no scheduled end. Turning the campaign on starts signup eligibility immediately.', 'drywall-toolbox' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="dtb-signup-expiry"><?php esc_html_e( 'Coupon validity after signup (days)', 'drywall-toolbox' ); ?></label></th>
						<td><input id="dtb-signup-expiry" type="number" name="expiry_days" min="0" max="365" value="<?php echo esc_attr( (string) ( $settings['expiry_days'] ?? 30 ) ); ?>"><p class="description"><?php esc_html_e( 'Use 0 for no coupon expiry; maximum is 365 days.', 'drywall-toolbox' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="dtb-signup-minimum"><?php esc_html_e( 'Minimum eligible product subtotal', 'drywall-toolbox' ); ?></label></th>
						<td><input id="dtb-signup-minimum" type="number" name="minimum_amount" min="0" step="0.01" value="<?php echo esc_attr( (string) $settings['minimum_amount'] ); ?>"><p class="description"><?php esc_html_e( 'Leave blank or enter 0 for no minimum product subtotal.', 'drywall-toolbox' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coupon restrictions', 'drywall-toolbox' ); ?></th>
						<td>
							<label><input type="checkbox" name="exclude_sale_items" value="1" <?php checked( ! empty( $settings['exclude_sale_items'] ) ); ?>> <?php esc_html_e( 'Exclude sale items', 'drywall-toolbox' ); ?></label><br>
							<label><input type="checkbox" name="individual_use" value="1" <?php checked( ! empty( $settings['individual_use'] ) ); ?>> <?php esc_html_e( 'Cannot be combined with other coupons', 'drywall-toolbox' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save campaign settings', 'drywall-toolbox' ) ); ?>
			</form>
			<hr>
			<p><strong><?php esc_html_e( 'Current state:', 'drywall-toolbox' ); ?></strong> <?php echo esc_html( self::is_active() ? __( 'Active', 'drywall-toolbox' ) : __( 'Inactive', 'drywall-toolbox' ) ); ?></p>
		</div>
		<?php
	}
}

DTB_SignupCouponCampaign::register();

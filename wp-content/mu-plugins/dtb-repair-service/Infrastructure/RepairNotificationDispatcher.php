<?php
/**
 * Infrastructure — RepairNotificationDispatcher: email templates and dispatch logic.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// SECTION 1 — CONFIGURATION
// =============================================================================

/**
 * Return the from-name for repair notification emails.
 *
 * @return string
 */
function dtb_repair_email_from_name(): string {
return (string) apply_filters( 'dtb_repair_email_from_name', get_bloginfo( 'name' ) );
}

/**
 * Return the from-address for repair notification emails.
 *
 * @return string
 */
function dtb_repair_email_from_address(): string {
$default = 'info@drywalltoolbox.com';
return (string) apply_filters( 'dtb_repair_email_from_address', $default );
}

/**
 * Return the admin recipient address for repair notification emails.
 *
 * @return string
 */
function dtb_repair_admin_email(): string {
return (string) apply_filters( 'dtb_repair_admin_email', 'info@drywalltoolbox.com' );
}

/**
 * Return the base URL for the customer-facing repair tracking page.
 *
 * The React SPA handles routing at /repairs/status/{repair_id}?token={token}.
 *
 * @return string
 */
function dtb_repair_tracking_base_url(): string {
return (string) apply_filters( 'dtb_repair_tracking_base_url', home_url( '/repairs/status/' ) );
}

// =============================================================================
// SECTION 2 — TEMPLATE DEFINITIONS
// =============================================================================

/**
 * Render an email template by slug and return [subject, body] or WP_Error.
 *
 * All templates receive a $ctx array (merged from repair meta + caller overrides).
 * Context keys used across templates:
 *   repair_id, customer_name, brand, model, serial, service_tier, issue,
 *   tracking_url, public_token, site_name, admin_url.
 *
 * @param string $template  Template slug (e.g. 'repair-submitted-customer').
 * @param array  $ctx       Template context variables.
 * @return array{subject: string, body: string}|WP_Error
 */
function dtb_repair_get_email_template( string $template, array $ctx ): array|WP_Error {
$site   = sanitize_text_field( (string) ( $ctx['site_name'] ?? get_bloginfo( 'name' ) ) );
$name   = sanitize_text_field( (string) ( $ctx['customer_name'] ?? 'Customer' ) );
$rid    = (int) ( $ctx['repair_id'] ?? 0 );
$brand  = sanitize_text_field( (string) ( $ctx['brand'] ?? '' ) );
$model  = sanitize_text_field( (string) ( $ctx['model'] ?? '' ) );
$serial = sanitize_text_field( (string) ( $ctx['serial'] ?? '' ) );
$tier   = sanitize_text_field( ucfirst( (string) ( $ctx['service_tier'] ?? 'standard' ) ) );
$issue  = wp_strip_all_tags( (string) ( $ctx['issue'] ?? '' ) );
$turl   = esc_url_raw( (string) ( $ctx['tracking_url'] ?? '' ) );
$aurl   = esc_url_raw( (string) ( $ctx['admin_url'] ?? '' ) );

switch ( $template ) {

// ---- Customer: submission confirmation -----------------------------------
case 'repair-submitted-customer':
return [
'subject' => sprintf( __( '[%1$s] Your repair request #%2$d has been received', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nThank you for submitting your repair request. Here are your details:\n\nRepair ID: #%2\$d\nTool: %3\$s %4\$s\nSerial: %5\$s\nService Tier: %6\$s\n\nIssue Description:\n%7\$s\n\nTrack your repair status at any time:\n%8\$s\n\nWe'll be in touch soon.\n\n— %9\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $serial ?: __( 'N/A', 'drywall-toolbox' ), $tier, $issue, $turl, $site
),
];

// ---- Admin: new submission alert -----------------------------------------
case 'repair-submitted-admin':
return [
'subject' => sprintf( __( '[%1$s] New repair request #%2$d — %3$s %4$s (%5$s)', 'drywall-toolbox' ), $site, $rid, $brand, $model, $name ),
'body'    => sprintf(
__( "A new repair request has been submitted.\n\nRepair ID: #%1\$d\nCustomer: %2\$s\nEmail: %3\$s\nPhone: %4\$s\nTool: %5\$s %6\$s (Serial: %7\$s)\nService Tier: %8\$s\n\nIssue:\n%9\$s\n\nReview in WP-Admin:\n%10\$s", 'drywall-toolbox' ),
$rid,
$name,
esc_html( $ctx['customer_email'] ?? '' ),
esc_html( $ctx['customer_phone'] ?? '' ),
$brand, $model, $serial ?: __( 'N/A', 'drywall-toolbox' ),
$tier, $issue, $aurl
),
];

// ---- Admin: customer posted new message --------------------------------
case 'repair-customer-message-admin':
return [
'subject' => sprintf( __( '[%1$s] New customer message on repair #%2$d', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "A customer posted a new message on repair #%1\$d.\n\nCustomer: %2\$s\nTool: %3\$s %4\$s\n\nMessage:\n%5\$s\n\nOpen in WP-Admin:\n%6\$s\n\nCustomer status page:\n%7\$s", 'drywall-toolbox' ),
$rid,
$name,
$brand,
$model,
esc_html( wp_strip_all_tags( (string) ( $ctx['customer_message'] ?? '' ) ) ),
$aurl,
$turl
),
];

// ---- Customer: information requested ------------------------------------
case 'repair-info-requested':
return [
'subject' => sprintf( __( '[%1$s] We need more information for your repair #%2$d', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nOur technicians have reviewed your repair request (#%2\$d) and require some additional information before we can proceed.\n\nPlease reply to this email or visit your repair page:\n%3\$s\n\nThank you,\n— %4\$s Team", 'drywall-toolbox' ),
$name, $rid, $turl, $site
),
];

// ---- Customer: repair reviewed ------------------------------------------
case 'repair-reviewed':
return [
'subject' => sprintf( __( '[%1$s] Your repair #%2$d is under review', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nGood news! Our team has reviewed your repair request (#%2\$d — %3\$s %4\$s) and it is now under review.\n\nWe'll notify you once a decision has been made.\n\nTrack status: %5\$s\n\n— %6\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $turl, $site
),
];

// ---- Customer: repair approved ------------------------------------------
case 'repair-approved':
return [
'subject' => sprintf( __( '[%1$s] Repair #%2$d has been approved', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nWe're pleased to let you know that your repair (#%2\$d — %3\$s %4\$s) has been approved.\n\nWe will begin sourcing the necessary parts and proceed with your %5\$s service.\n\nTrack status: %6\$s\n\n— %7\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $tier, $turl, $site
),
];

// ---- Customer: quote sent -----------------------------------------------
case 'repair-quote-created':
$quote_lines = is_array( $ctx['quote_lines'] ?? null ) ? $ctx['quote_lines'] : [];
$quote_totals = is_array( $ctx['quote_totals'] ?? null ) ? $ctx['quote_totals'] : [];
$quote_currency = sanitize_text_field( (string) ( $ctx['quote_currency'] ?? dtb_repair_quote_default_currency() ) );
$quote_note = trim( wp_strip_all_tags( (string) ( $ctx['quote_customer_note'] ?? '' ) ) );
$quote_expires = sanitize_text_field( (string) ( $ctx['quote_expires_at'] ?? '' ) );

$quote_lines_text = '';
if ( ! empty( $quote_lines ) ) {
	$lines = [];
	foreach ( $quote_lines as $line ) {
		if ( ! is_array( $line ) ) {
			continue;
		}
		$label = sanitize_text_field( (string) ( $line['label'] ?? '' ) );
		if ( '' === $label ) {
			continue;
		}
		$qty = (float) ( $line['quantity'] ?? 1 );
		$unit = (float) ( $line['unit_price'] ?? 0 );
		$total = (float) ( $line['line_total'] ?? ( $qty * $unit ) );
		$lines[] = sprintf(
			'- %1$s (Qty %2$s): %3$s %4$s',
			$label,
			rtrim( rtrim( number_format( max( 0.001, $qty ), 3, '.', '' ), '0' ), '.' ),
			$quote_currency,
			number_format( $total, 2, '.', '' )
		);
	}
	if ( ! empty( $lines ) ) {
		$quote_lines_text = implode( "\n", $lines ) . "\n";
	}
}

$quote_total_text = '';
if ( isset( $quote_totals['total'] ) ) {
	$quote_total_text = sprintf(
		__( "Quote Total: %1\$s %2\$0.2f\n", 'drywall-toolbox' ),
		$quote_currency,
		(float) $quote_totals['total']
	);
}

$quote_expiry_text = '';
if ( '' !== $quote_expires ) {
	$exp_ts = strtotime( $quote_expires );
	if ( false !== $exp_ts ) {
		$quote_expiry_text = sprintf(
			__( "Quote Expires: %s\n", 'drywall-toolbox' ),
			date_i18n( 'M j, Y g:i a', $exp_ts )
		);
	}
}

$quote_note_text = '' !== $quote_note ? __( "\nNotes from our technician:\n", 'drywall-toolbox' ) . $quote_note . "\n" : '';

return [
'subject' => sprintf( __( '[%1$s] Your repair quote is ready — #%2$d', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
	__( "Hi %1\$s,\n\nWe have prepared a quote for your repair (#%2\$d — %3\$s %4\$s).\n\n%5\$s%6\$s%7\$sPlease log in to review and accept or decline your quote:\n%8\$s\n\nIf you have any questions, please reply to this email.\n\n— %9\$s Team", 'drywall-toolbox' ),
	$name,
	$rid,
	$brand,
	$model,
	$quote_lines_text,
	$quote_total_text,
	$quote_expiry_text . $quote_note_text,
	$turl,
	$site
),
];

// ---- Customer: quote accepted -------------------------------------------
case 'repair-quote-accepted':
return [
'subject' => sprintf( __( '[%1$s] Quote accepted — repair #%2$d will proceed', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nThank you for accepting the quote for repair #%2\$d (%3\$s %4\$s).\n\nWe will now begin ordering the required parts. We'll keep you updated.\n\nTrack status: %5\$s\n\n— %6\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $turl, $site
),
];

// ---- Customer: repair in progress ---------------------------------------
case 'repair-in-progress':
return [
'subject' => sprintf( __( '[%1$s] Work has started on your repair #%2$d', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nGreat news — our technicians have started work on your repair (#%2\$d — %3\$s %4\$s).\n\nWe'll notify you when it's complete and ready to ship.\n\nTrack status: %5\$s\n\n— %6\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $turl, $site
),
];

// ---- Customer: ready to ship --------------------------------------------
case 'repair-ready-to-ship':
$tracking_note = ! empty( $ctx['tracking_number'] )
? sprintf( __( "\nTracking Number: %s\n", 'drywall-toolbox' ), esc_html( $ctx['tracking_number'] ) )
: '';

return [
'subject' => sprintf( __( '[%1$s] Your repair #%2$d is ready to ship!', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nYour repaired %2\$s %3\$s (#%4\$d) is packed and ready to ship!%5\$s\nTrack status: %6\$s\n\n— %7\$s Team", 'drywall-toolbox' ),
$name, $brand, $model, $rid, $tracking_note, $turl, $site
),
];

// ---- Customer: repair completed -----------------------------------------
case 'repair-completed':
return [
'subject' => sprintf( __( '[%1$s] Your repair #%2$d is complete — earn loyalty rewards!', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nYour repair (#%2\$d — %3\$s %4\$s) has been completed and shipped.\n\nAs a thank-you, loyalty reward points have been credited to your account.\n\nTrack status and view your points balance:\n%5\$s\n\nThank you for choosing %6\$s!\n\n— %6\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $turl, $site
),
];

// ---- Customer: repair cancelled -----------------------------------------
case 'repair-cancelled':
return [
'subject' => sprintf( __( '[%1$s] Repair request #%2$d has been cancelled', 'drywall-toolbox' ), $site, $rid ),
'body'    => sprintf(
__( "Hi %1\$s,\n\nThis is to let you know that repair request #%2\$d (%3\$s %4\$s) has been cancelled.\n\nIf you believe this was in error or have questions, please contact us.\n\n— %5\$s Team", 'drywall-toolbox' ),
$name, $rid, $brand, $model, $site
),
];

default:
return new WP_Error(
'dtb_repair_template_not_found',
/* translators: %s: template slug */
sprintf( __( 'Email template "%s" not found.', 'drywall-toolbox' ), esc_html( $template ) )
);
}
}

// =============================================================================
// SECTION 3 — LOW-LEVEL EMAIL SEND
// =============================================================================

/**
 * Return whether a repair template is sent to the customer.
 *
 * @param string $template Template slug.
 * @return bool
 */
function dtb_repair_is_customer_email_template( string $template ): bool {
	return in_array(
		$template,
		[
			'repair-submitted-customer',
			'repair-info-requested',
			'repair-reviewed',
			'repair-approved',
			'repair-quote-created',
			'repair-quote-accepted',
			'repair-in-progress',
			'repair-ready-to-ship',
			'repair-completed',
			'repair-cancelled',
		],
		true
	);
}

/**
 * Render branded HTML for a customer repair notification.
 *
 * @param string $template Template slug.
 * @param array  $ctx      Template context.
 * @return string
 */
function dtb_repair_render_customer_email_html( string $template, array $ctx ): string {
	$site         = sanitize_text_field( (string) ( $ctx['site_name'] ?? get_bloginfo( 'name' ) ) );
	$name         = sanitize_text_field( (string) ( $ctx['customer_name'] ?? 'Customer' ) );
	$rid          = (int) ( $ctx['repair_id'] ?? 0 );
	$brand        = sanitize_text_field( (string) ( $ctx['brand'] ?? '' ) );
	$model        = sanitize_text_field( (string) ( $ctx['model'] ?? '' ) );
	$serial       = sanitize_text_field( (string) ( $ctx['serial'] ?? '' ) );
	$tier         = sanitize_text_field( ucfirst( (string) ( $ctx['service_tier'] ?? 'standard' ) ) );
	$issue        = trim( wp_strip_all_tags( (string) ( $ctx['issue'] ?? '' ) ) );
	$tracking_url = esc_url_raw( (string) ( $ctx['tracking_url'] ?? '' ) );
	$tracking_num = sanitize_text_field( (string) ( $ctx['tracking_number'] ?? '' ) );
	$tool         = trim( $brand . ' ' . $model );

	$title     = 'Your repair update';
	$intro     = 'There is an update on your repair request.';
	$cta_label = '' !== $tracking_url ? 'Track repair status' : '';
	$body_html = '';

	switch ( $template ) {
		case 'repair-submitted-customer':
			$title   = 'We received your repair request';
			$intro   = 'Thanks for submitting your repair request. Our service team will review the details and follow up with next steps.';
			if ( '' !== $issue ) {
				$body_html = '<p style="margin:0 0 10px;font-size:12px;font-weight:700;line-height:18px;letter-spacing:0.04em;text-transform:uppercase;">Issue description</p><div class="dtb-quote-note" style="padding:18px 20px;border:1px solid #dbe5f2;border-radius:8px;background:#f8fafc;">' . nl2br( esc_html( $issue ) ) . '</div>';
			}
			break;

		case 'repair-info-requested':
			$title   = 'We need more information';
			$intro   = 'Our technicians reviewed your repair request and need a few more details before we can continue.';
			break;

		case 'repair-reviewed':
			$title   = 'Your repair is under review';
			$intro   = 'Our team has reviewed your repair request and it is now under review. We will notify you once a decision has been made.';
			break;

		case 'repair-approved':
			$title   = 'Your repair has been approved';
			$intro   = sprintf( 'Your repair has been approved and we will proceed with your %s service.', esc_html( $tier ) );
			break;

		case 'repair-quote-created':
			$title     = 'Your repair quote is ready';
			$intro     = 'We prepared a repair quote for your review. Please review it when you have a moment.';
			$cta_label = '' !== $tracking_url ? 'Review repair quote' : '';
			$quote_lines = is_array( $ctx['quote_lines'] ?? null ) ? $ctx['quote_lines'] : [];
			$quote_totals = is_array( $ctx['quote_totals'] ?? null ) ? $ctx['quote_totals'] : [];
			$quote_currency = sanitize_text_field( (string) ( $ctx['quote_currency'] ?? dtb_repair_quote_default_currency() ) );
			$quote_note = trim( sanitize_textarea_field( (string) ( $ctx['quote_customer_note'] ?? '' ) ) );
			$quote_expires = sanitize_text_field( (string) ( $ctx['quote_expires_at'] ?? '' ) );

			$rows_html = '';
			foreach ( $quote_lines as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $line['label'] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				$qty = (float) ( $line['quantity'] ?? 1 );
				$unit = (float) ( $line['unit_price'] ?? 0 );
				$line_total = (float) ( $line['line_total'] ?? ( $qty * $unit ) );
				$rows_html .= '<tr>'
					. '<td style="padding:9px 10px;border-top:1px solid #dbe5f2;">' . esc_html( $label ) . '</td>'
					. '<td style="padding:9px 10px;border-top:1px solid #dbe5f2;text-align:right;">' . esc_html( rtrim( rtrim( number_format( max( 0.001, $qty ), 3, '.', '' ), '0' ), '.' ) ) . '</td>'
					. '<td style="padding:9px 10px;border-top:1px solid #dbe5f2;text-align:right;">' . esc_html( $quote_currency . ' ' . number_format( $unit, 2, '.', '' ) ) . '</td>'
					. '<td style="padding:9px 10px;border-top:1px solid #dbe5f2;text-align:right;font-weight:700;">' . esc_html( $quote_currency . ' ' . number_format( $line_total, 2, '.', '' ) ) . '</td>'
					. '</tr>';
			}

			$total_html = '';
			if ( isset( $quote_totals['total'] ) ) {
				$total_html = '<div class="dtb-quote-total" style="margin-top:10px;text-align:right;font-size:14px;font-weight:800;">'
					. esc_html( 'Total: ' . $quote_currency . ' ' . number_format( (float) $quote_totals['total'], 2, '.', '' ) )
					. '</div>';
			}

			$expires_html = '';
			if ( '' !== $quote_expires ) {
				$ts = strtotime( $quote_expires );
				if ( false !== $ts ) {
					$expires_html = '<p class="dtb-quote-expiry" style="margin:10px 0 0;">'
						. esc_html( 'Quote expires: ' . date_i18n( 'M j, Y g:i a', $ts ) )
						. '</p>';
				}
			}

			$body_html = '';
			if ( '' !== $rows_html ) {
				$body_html .= '<table class="dtb-quote-table" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #dbe5f2;border-radius:8px;overflow:hidden;margin:6px 0 0;">'
					. '<thead><tr>'
					. '<th style="padding:8px 10px;text-align:left;background:#f8fafc;color:#475569;font-size:12px;">Item</th>'
					. '<th style="padding:8px 10px;text-align:right;background:#f8fafc;color:#475569;font-size:12px;">Qty</th>'
					. '<th style="padding:8px 10px;text-align:right;background:#f8fafc;color:#475569;font-size:12px;">Unit</th>'
					. '<th style="padding:8px 10px;text-align:right;background:#f8fafc;color:#475569;font-size:12px;">Line Total</th>'
					. '</tr></thead><tbody>' . $rows_html . '</tbody></table>';
				$body_html .= $total_html;
				$body_html .= $expires_html;
			} else {
				$body_html = '<p style="margin:0;">This quote is ready for your review.</p>' . $expires_html;
			}
			if ( '' !== $quote_note ) {
				$body_html .= '<div class="dtb-quote-note" style="margin-top:12px;padding:14px;border:1px solid #dbe5f2;border-radius:8px;background:#f8fafc;">'
					. nl2br( esc_html( $quote_note ) )
					. '</div>';
			}
			break;

		case 'repair-quote-accepted':
			$title   = 'Quote accepted';
			$intro   = 'Thank you for accepting your repair quote. We will begin ordering the required parts and keep you updated.';
			break;

		case 'repair-in-progress':
			$title   = 'Work has started on your repair';
			$intro   = 'Our technicians have started work on your repair. We will notify you when it is complete and ready to ship.';
			break;

		case 'repair-ready-to-ship':
			$title   = 'Your repair is ready to ship';
			$intro   = 'Your repaired tool is packed and ready to ship.';
			break;

		case 'repair-completed':
			$title   = 'Your repair is complete';
			$intro   = 'Your repair has been completed and shipped. Loyalty reward points have been credited to your account as a thank-you.';
			break;

		case 'repair-cancelled':
			$title     = 'Your repair request was cancelled';
			$intro     = 'This is to let you know that your repair request has been cancelled.';
			$cta_label = '';
			break;
	}

	$details = [
		[ 'label' => 'Repair ID', 'value' => '#' . $rid ],
		[ 'label' => 'Tool', 'value' => '' !== $tool ? $tool : 'N/A' ],
		[ 'label' => 'Service tier', 'value' => '' !== $tier ? $tier : 'Standard' ],
	];

	if ( '' !== $serial ) {
		$details[] = [ 'label' => 'Serial', 'value' => $serial ];
	}

	if ( '' !== $tracking_num ) {
		$details[] = [ 'label' => 'Tracking number', 'value' => $tracking_num ];
	}

	return dtb_render_branded_email(
		[
			'title'       => $title,
			'preheader'   => sprintf( 'Repair #%d update from Drywall Toolbox.', $rid ),
			'greeting'    => sprintf( 'Hi %s,', $name ),
			'intro'       => $intro,
			'details'     => $details,
			'body_html'   => $body_html,
			'cta_url'     => $tracking_url,
			'cta_label'   => $cta_label,
			'signoff'     => $site . ' Team',
			'footer_note' => 'You can reply directly to this email if you have questions about your repair.',
		]
	);
}

/**
 * Render an operations-facing repair email through the official branded template.
 *
 * @param string              $subject Plain-text subject.
 * @param string              $plain   Plain-text body.
 * @param array<string,mixed> $ctx     Notification context.
 * @return string
 */
function dtb_repair_render_operations_email_html( string $subject, string $plain, array $ctx ): string {
	$rid       = (int) ( $ctx['repair_id'] ?? 0 );
	$admin_url = esc_url_raw( (string) ( $ctx['admin_url'] ?? '' ) );
	$plain     = trim( wp_strip_all_tags( $plain ) );

	return dtb_render_branded_email(
		[
			'title'       => $subject,
			'preheader'   => preg_replace( '/\s+/', ' ', mb_substr( $plain, 0, 140 ) ),
			'greeting'    => '',
			'intro'       => '',
			'details'     => $rid > 0 ? [ [ 'label' => 'Repair ID', 'value' => '#' . $rid ] ] : [],
			'body_html'   => function_exists( 'dtb_email_note_box' ) ? dtb_email_note_box( $plain ) : nl2br( esc_html( $plain ) ),
			'cta_url'     => $admin_url,
			'cta_label'   => $admin_url ? 'Open repair in WP-Admin' : '',
			'signoff'     => 'Drywall Toolbox Operations',
			'footer_note' => 'This message was sent by the Drywall Toolbox operations platform.',
		]
	);
}

/**
 * Send a repair notification email via wp_mail().
 *
 * @param string $to        Recipient email address.
 * @param string $template  Template slug.
 * @param array  $context   Template context variables.
 * @return bool  true if wp_mail() accepted the message.
 */
function dtb_repair_send_email( string $to, string $template, array $context ): bool {
if ( '' === $to || ! is_email( $to ) ) {
error_log( "[DTB Repairs] dtb_repair_send_email: invalid 'to' address '{$to}' for template '{$template}'." );
return false;
}

$rendered = dtb_repair_get_email_template( $template, $context );

if ( is_wp_error( $rendered ) ) {
error_log( '[DTB Repairs] ' . $rendered->get_error_message() );
return false;
}

$from    = dtb_repair_email_from_name() . ' <' . dtb_repair_email_from_address() . '>';
$is_html = function_exists( 'dtb_render_branded_email' );
$body    = $rendered['body'];

if ( $is_html ) {
	$body = dtb_repair_is_customer_email_template( $template )
		? dtb_repair_render_customer_email_html( $template, $context )
		: dtb_repair_render_operations_email_html( (string) $rendered['subject'], (string) $rendered['body'], $context );
}

if ( function_exists( 'dtb_send_email' ) ) {
return dtb_send_email(
[
'to'           => $to,
'subject'      => (string) $rendered['subject'],
'message'      => (string) $body,
'is_html'      => $is_html,
'content_type' => $is_html ? 'text/html' : 'text/plain',
'from_name'    => dtb_repair_email_from_name(),
'from_email'   => dtb_repair_email_from_address(),
'alt_body'     => $is_html ? (string) $rendered['body'] : '',
'context'      => [
'module'   => 'dtb-repair-service',
'template' => $template,
'repair_id' => (int) ( $context['repair_id'] ?? 0 ),
],
]
);
}

$headers = [
'Content-Type: ' . ( $is_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
'From: ' . $from,
];

$alt_body_hook = $is_html && function_exists( 'dtb_mail_alt_body_hook' )
? dtb_mail_alt_body_hook( (string) $rendered['body'] )
: null;

$sent = (bool) wp_mail( $to, $rendered['subject'], $body, $headers );

if ( is_callable( $alt_body_hook ) ) {
remove_action( 'phpmailer_init', $alt_body_hook );
}

return $sent;
}

// =============================================================================
// SECTION 4 — HIGH-LEVEL DISPATCH
// =============================================================================

/**
 * Build context from repair meta and dispatch a notification email.
 *
 * Logs notification.email.queued, and either notification.email.sent or
 * notification.email.failed to the event table.
 *
 * @param int    $repair_id      Post ID of the repair.
 * @param string $template       Template slug.
 * @param array  $extra_context  Additional context values to merge over defaults.
 */
function dtb_repair_dispatch_notification( int $repair_id, string $template, array $extra_context = [] ): void {
if ( '' === $template ) {
return;
}

$post = get_post( $repair_id );
if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
return;
}

// Build base context from repair meta.
$repair_id_val  = $repair_id;
$customer_email = sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
$customer_name  = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_customer_name', true ) );
$customer_phone = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_customer_phone', true ) );
$brand          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );
$model          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_model', true ) );
$serial         = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_serial', true ) );
$service_tier   = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_service_tier', true ) );
$issue          = wp_kses_post( (string) get_post_meta( $repair_id, '_repair_issue', true ) );
$public_token   = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_public_token', true ) );
$tracking_num   = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_veeqo_tracking', true ) );

$tracking_url = add_query_arg(
[ 'token' => $public_token ],
rtrim( dtb_repair_tracking_base_url(), '/' ) . '/' . $repair_id
);
$admin_url    = admin_url( 'post.php?post=' . $repair_id . '&action=edit' );

$context = array_merge(
[
'repair_id'       => $repair_id_val,
'customer_name'   => $customer_name,
'customer_email'  => $customer_email,
'customer_phone'  => $customer_phone,
'brand'           => $brand,
'model'           => $model,
'serial'          => $serial,
'service_tier'    => $service_tier,
'issue'           => $issue,
'public_token'    => $public_token,
'tracking_url'    => $tracking_url,
'tracking_number' => $tracking_num,
'admin_url'       => $admin_url,
'site_name'       => get_bloginfo( 'name' ),
],
$extra_context
);

if ( 'repair-quote-created' === $template && empty( $context['quote_lines'] ) && function_exists( 'dtb_repair_get_quote' ) ) {
	$quote = dtb_repair_get_quote( $repair_id );
	if ( is_array( $quote ) && function_exists( 'dtb_repair_quote_to_notification_context' ) ) {
		$context = array_merge( $context, dtb_repair_quote_to_notification_context( $quote ) );
	}
}

// Determine recipient: admin templates go to the admin address.
$admin_templates = [ 'repair-submitted-admin', 'repair-customer-message-admin' ];
if ( in_array( $template, $admin_templates, true ) ) {
$to = dtb_repair_admin_email();
} else {
$to = $customer_email;
}

if ( '' === $to ) {
error_log( "[DTB Repairs] dispatch_notification: no recipient for template '{$template}', repair #{$repair_id}." );
return;
}

if (
	! in_array( $template, $admin_templates, true )
	&& function_exists( 'dtb_account_email_preference' )
	&& ! dtb_account_email_preference( $to, 'repair_updates' )
) {
	return;
}

// Log queued event.
if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'notification.email.queued', [
'visibility' => 'operator',
'payload'    => [ 'template' => $template, 'to' => $to ],
] );
}

$sent = dtb_repair_send_email( $to, $template, $context );

// Log sent/failed event.
if ( function_exists( 'dtb_repair_append_event' ) ) {
$event_type = $sent ? 'notification.email.sent' : 'notification.email.failed';
dtb_repair_append_event( $repair_id, $event_type, [
'visibility' => 'operator',
'payload'    => [ 'template' => $template, 'to' => $to ],
] );
}
}

// =============================================================================
// SECTION 5 — AUTO-DISPATCH ON STATUS TRANSITION
// =============================================================================

add_action( 'dtb_repair_status_changed', 'dtb_repair_notifications_on_status_changed', 10, 4 );

/**
 * Handle proactive alerts when a customer posts a message from status page.
 *
 * Default behavior:
 * - Sends an admin email alert (queued when Action Scheduler/job queue is available).
 * - Fires an SMS integration hook for optional provider wiring.
 *
 * @param int    $repair_id
 * @param string $message
 * @param array  $context
 */
function dtb_repair_notifications_on_customer_message( int $repair_id, string $message, array $context = [] ): void {
$message = trim( wp_strip_all_tags( $message ) );
if ( $repair_id <= 0 || '' === $message ) {
return;
}

$notification_context = array_merge(
[
'customer_message' => $message,
'message_source'   => sanitize_text_field( (string) ( $context['source'] ?? 'customer_status_page' ) ),
'event_id'         => (int) ( $context['event_id'] ?? 0 ),
],
$context
);

if ( function_exists( 'dtb_repair_enqueue_job' ) ) {
dtb_repair_enqueue_job(
'dtb_repair_send_notification',
$repair_id,
[
'template' => 'repair-customer-message-admin',
'context'  => $notification_context,
]
);
} else {
dtb_repair_dispatch_notification( $repair_id, 'repair-customer-message-admin', $notification_context );
}

/**
 * Allow external SMS integrations to send operator alerts for customer messages.
 *
 * Hook this action in a site-specific mu-plugin to call Twilio/other providers.
 */
do_action( 'dtb_repair_customer_message_sms_alert', $repair_id, $message, $notification_context );
}

add_action( 'dtb_repair_customer_message_posted', 'dtb_repair_notifications_on_customer_message', 10, 3 );

/**
 * Dispatch the appropriate notification when a repair status changes.
 *
 * The queue file already schedules this via Action Scheduler through
 * dtb_repair_send_notification jobs. This hook is a direct fallback for
 * cases where Action Scheduler is unavailable.
 *
 * @param int    $repair_id
 * @param string $from_status
 * @param string $to_status
 * @param array  $context
 */
function dtb_repair_notifications_on_status_changed( int $repair_id, string $from_status, string $to_status, array $context ): void {
// When Action Scheduler is available, notifications are handled via queued jobs.
// Only dispatch directly here as a fallback.
if ( function_exists( 'as_schedule_single_action' ) ) {
return;
}

$template_map = [
'awaiting_customer' => 'repair-info-requested',
'reviewed'          => 'repair-reviewed',
'approved'          => 'repair-approved',
'quoted'            => 'repair-quote-created',
'quote_accepted'    => 'repair-quote-accepted',
'in_progress'       => 'repair-in-progress',
'ready_to_ship'     => 'repair-ready-to-ship',
'completed'         => 'repair-completed',
'cancelled'         => 'repair-cancelled',
];

if ( ! isset( $template_map[ $to_status ] ) ) {
return;
}

dtb_repair_dispatch_notification( $repair_id, $template_map[ $to_status ] );
}

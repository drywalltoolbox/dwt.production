<?php
/**
 * Shared transactional email presentation and dispatch helpers.
 *
 * This is the canonical email layer for Drywall Toolbox modules. Modules own
 * content; this file owns layout, colors, headers, AltBody, and send hygiene.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// GLOBAL FROM-ADDRESS OVERRIDE
// =============================================================================

/**
 * Return the platform-wide outbound From address.
 *
 * @return string
 */
function dtb_platform_from_email(): string {
	$email = sanitize_email( (string) apply_filters( 'dtb_platform_from_email', 'info@drywalltoolbox.com' ) );
	return is_email( $email ) ? $email : 'info@drywalltoolbox.com';
}

/**
 * Return the platform-wide outbound From name.
 *
 * @return string
 */
function dtb_platform_from_name(): string {
	$name = sanitize_text_field( (string) apply_filters( 'dtb_platform_from_name', 'Drywall Toolbox' ) );
	return '' !== $name ? $name : 'Drywall Toolbox';
}

// Priority 1 keeps the platform default below module-specific overrides.
add_filter( 'wp_mail_from', static fn( string $original ): string => dtb_platform_from_email(), 1 );
add_filter( 'wp_mail_from_name', static fn( string $original ): string => dtb_platform_from_name(), 1 );

// =============================================================================
// EMAIL TOKENS / SANITIZATION
// =============================================================================

if ( ! function_exists( 'dtb_email_logo_url' ) ) {
	/**
	 * Return the hosted PNG logo used by email clients.
	 *
	 * @return string
	 */
	function dtb_email_logo_url(): string {
		$url = esc_url_raw( (string) apply_filters( 'dtb_email_logo_url', home_url( '/logos/email-logo-white.png' ) ) );
		return '' !== $url ? $url : home_url( '/' );
	}
}

if ( ! function_exists( 'dtb_email_support_url' ) ) {
	/**
	 * Return the customer support URL for branded email footers.
	 *
	 * @return string
	 */
	function dtb_email_support_url(): string {
		return esc_url_raw( (string) apply_filters( 'dtb_email_support_url', home_url( '/contact/' ) ) );
	}
}

if ( ! function_exists( 'dtb_email_font_stack' ) ) {
	/**
	 * Return the shared email typography stack.
	 *
	 * Geist is requested by the WooCommerce email head. Arial remains the
	 * predictable fallback for clients that block remote web fonts.
	 */
	function dtb_email_font_stack(): string {
		return "'Geist',Arial,sans-serif";
	}
}

if ( ! function_exists( 'dtb_email_icon' ) ) {
	/**
	 * Render a hosted, email-client-safe DTB line icon.
	 *
	 * @param string $name Icon asset name.
	 * @param int    $size Rendered square size in pixels.
	 * @return string
	 */
	function dtb_email_icon( string $name, int $size = 24 ): string {
		$allowed = [ 'payment', 'package', 'truck', 'clipboard', 'location', 'mail', 'support', 'help', 'facebook', 'instagram' ];
		$name    = sanitize_key( $name );
		if ( ! in_array( $name, $allowed, true ) ) {
			return '';
		}
		$size = max( 16, min( 64, $size ) );
		$url  = home_url( '/logos/email-icons/' . $name . '.png' );
		return '<img src="' . esc_url( $url ) . '" width="' . esc_attr( (string) $size ) . '" height="' . esc_attr( (string) $size ) . '" alt="" role="presentation" style="display:block;width:' . esc_attr( (string) $size ) . 'px;height:' . esc_attr( (string) $size ) . 'px;border:0;outline:none;text-decoration:none;" />';
	}
}

if ( ! function_exists( 'dtb_email_clean_text' ) ) {
	/**
	 * Normalize customer-visible text for email output.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	function dtb_email_clean_text( mixed $value ): string {
		$text = sanitize_text_field( (string) $value );
		return function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $text ) : $text;
	}
}

if ( ! function_exists( 'dtb_email_clean_multiline_text' ) ) {
	/**
	 * Normalize multi-line customer-visible text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	function dtb_email_clean_multiline_text( mixed $value ): string {
		$text = sanitize_textarea_field( (string) $value );
		return function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $text, true ) : $text;
	}
}

if ( ! function_exists( 'dtb_email_clean_html' ) ) {
	/**
	 * Clean controlled HTML fragments before inserting into branded email shell.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	function dtb_email_clean_html( string $html ): string {
		$allowed = wp_kses_allowed_html( 'post' );

		foreach ( [ 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ] as $tag ) {
			$allowed[ $tag ] = [
				'align'       => true,
				'border'      => true,
				'cellpadding' => true,
				'cellspacing' => true,
				'class'       => true,
				'colspan'     => true,
				'height'      => true,
				'role'        => true,
				'rowspan'     => true,
				'style'       => true,
				'valign'      => true,
				'width'       => true,
			];
		}

		foreach ( [ 'div', 'span', 'p', 'a', 'strong', 'em', 'br', 'ul', 'ol', 'li' ] as $tag ) {
			$allowed[ $tag ] = array_merge(
				$allowed[ $tag ] ?? [],
				[
					'class' => true,
					'style' => true,
				]
			);
		}

		return wp_kses( $html, $allowed );
	}
}

// =============================================================================
// PRESENTATION TOKENS
// =============================================================================

if ( ! function_exists( 'dtb_email_palette' ) ) {
	/**
	 * Resolve shared email color palette.
	 *
	 * Email clients are inconsistent with dark mode. The base template is light
	 * with a dark logo header, and optional dark CSS is added for capable clients.
	 *
	 * @param string $theme light|dark.
	 * @return array<string,string>
	 */
	function dtb_email_palette( string $theme = 'light' ): array {
		$theme = 'dark' === strtolower( $theme ) ? 'dark' : 'light';

		if ( 'dark' === $theme ) {
			return [
				'shell_bg'       => '#05070d',
				'preheader'      => '#05070d',
				'header_bg'      => '#05070d',
				'card_bg'        => '#0b1020',
				'card_border'    => '#1d2a44',
				'accent'         => '#2255ee',
				'accent_soft_bg' => '#111f3d',
				'accent_soft_tx' => '#bfdbfe',
				'title'          => '#f8fafc',
				'greeting'       => '#e5edf7',
				'intro'          => '#c9d4e5',
				'text'           => '#9aa8bb',
				'details_bg'     => '#0f172a',
				'details_row'    => '#0a1222',
				'details_border' => '#263751',
				'details_label'  => '#9aa8bb',
				'details_value'  => '#eef4ff',
				'button_bg'      => '#2255ee',
				'button_text'    => '#ffffff',
				'footer_bg'      => '#070d1c',
				'footer_text'    => '#93a1b5',
				'footer_link'    => '#2255ee',
				'footer_sep'     => '#263751',
				'copyright'      => '#64748b',
			];
		}

		return [
			'shell_bg'       => '#f2f3f5',
			'preheader'      => '#f2f3f5',
			'header_bg'      => '#000000',
			'card_bg'        => '#ffffff',
			'card_border'    => '#e4eaf2',
			'accent'         => '#2255ee',
			'accent_soft_bg' => '#e8f1ff',
			'accent_soft_tx' => '#2255ee',
			'title'          => '#101828',
			'greeting'       => '#101828',
			'intro'          => '#344054',
			'text'           => '#667085',
			'details_bg'     => '#f8fbff',
			'details_row'    => '#ffffff',
			'details_border' => '#e4eaf2',
			'details_label'  => '#667085',
			'details_value'  => '#101828',
			'button_bg'      => '#2255ee',
			'button_text'    => '#ffffff',
			// A near-black band matching header_bg (mockup uses a true black
			// header/footer, not the earlier navy), bookending the light body
			// between a dark header and dark footer.
			'footer_bg'      => '#000000',
			'footer_text'    => '#94a3b8',
			'footer_link'    => '#2255ee',
			'footer_sep'     => '#26262b',
			'copyright'      => '#8a8f98',
		];
	}
}

if ( ! function_exists( 'dtb_email_section_label' ) ) {
	/**
	 * Render a small uppercase label inside rich email content.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	function dtb_email_section_label( string $label ): string {
		return '<p class="dtb-rich-label" style="margin:0 0 10px;color:#9aa8bb;font-family:' . dtb_email_font_stack() . ';font-size:12px;font-weight:800;line-height:18px;text-transform:uppercase;">' . esc_html( dtb_email_clean_text( $label ) ) . '</p>';
	}
}

if ( ! function_exists( 'dtb_email_note_box' ) ) {
	/**
	 * Render a reusable rich-content note box.
	 *
	 * @param string $content Plain text or safe HTML.
	 * @param bool   $preserve_lines Whether to preserve line breaks.
	 * @return string
	 */
	function dtb_email_note_box( string $content, bool $preserve_lines = true ): string {
		$content = $preserve_lines
			? nl2br( esc_html( dtb_email_clean_multiline_text( $content ) ) )
			: dtb_email_clean_html( $content );

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return '';
		}

		return '<table class="dtb-rich-box dtb-quote-note" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" bgcolor="#0a1222" style="border-collapse:separate;margin:0;border:1px solid #263751;border-radius:10px;background:#0a1222;background-color:#0a1222;color:#eef4ff;"><tr><td style="padding:18px 20px;color:#eef4ff;font-family:' . dtb_email_font_stack() . ';font-size:15px;line-height:24px;">' . $content . '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_button' ) ) {
	/**
	 * Render a resilient email CTA button.
	 *
	 * @param string              $url   Target URL.
	 * @param string              $label Button label.
	 * @param array<string,mixed> $style Optional style overrides.
	 * @return string
	 */
	function dtb_email_button( string $url, string $label, array $style = [] ): string {
		$url   = esc_url( $url );
		$label = esc_html( dtb_email_clean_text( $label ) );
		$bg    = sanitize_hex_color( (string) ( $style['bg'] ?? '#2255ee' ) ) ?: '#2255ee';
		$text  = sanitize_hex_color( (string) ( $style['text'] ?? '#ffffff' ) ) ?: '#ffffff';

		if ( '' === $url || '' === $label ) {
			return '';
		}

		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:24px 0 0;"><tr><td align="center">'
			. '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url . '" style="height:50px;v-text-anchor:middle;width:540px;" arcsize="14%" stroke="f" fillcolor="' . esc_attr( $bg ) . '"><w:anchorlock/><center style="color:' . esc_attr( $text ) . ';font-family:' . dtb_email_font_stack() . ';font-size:15px;font-weight:700;">' . $label . '</center></v:roundrect><![endif]-->'
			. '<!--[if !mso]><!--><a href="' . $url . '" class="dtb-btn" style="display:block;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $text ) . ';font-family:' . dtb_email_font_stack() . ';font-size:15px;font-weight:800;line-height:20px;text-decoration:none;text-align:center;padding:15px 24px;border-radius:8px;">' . $label . '</a><!--<![endif]-->'
			. '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_status_badge' ) ) {
	/**
	 * Render a small pill-shaped status badge for order/shipment/refund state.
	 *
	 * @param string $label Badge text.
	 * @param string $tone  One of: neutral, info, success, warning, danger.
	 * @return string
	 */
	function dtb_email_status_badge( string $label, string $tone = 'info' ): string {
		$label = esc_html( dtb_email_clean_text( $label ) );
		if ( '' === $label ) {
			return '';
		}

		$tones = [
			'neutral' => [ 'bg' => '#eef2f7', 'text' => '#475569' ],
			'info'    => [ 'bg' => '#e8f1ff', 'text' => '#2255ee' ],
			'success' => [ 'bg' => '#e3f6ea', 'text' => '#0f7a3d' ],
			'warning' => [ 'bg' => '#fef3e2', 'text' => '#a15c00' ],
			'danger'  => [ 'bg' => '#fde8e8', 'text' => '#b91c1c' ],
		];
		$colors = $tones[ $tone ] ?? $tones['info'];

		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 32px 14px;"><tr><td style="background:' . esc_attr( $colors['bg'] ) . ';background-color:' . esc_attr( $colors['bg'] ) . ';color:' . esc_attr( $colors['text'] ) . ';font-family:' . dtb_email_font_stack() . ';font-size:12px;font-weight:800;text-transform:uppercase;padding:6px 12px;border-radius:6px;">' . $label . '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_next_steps_list' ) ) {
	/**
	 * Render a compact "what happens next" checklist used across order-lifecycle emails.
	 *
	 * @param array<int,string> $steps Plain-text step descriptions, in order.
	 * @return string
	 */
	function dtb_email_next_steps_list( array $steps ): string {
		$rows = '';
		foreach ( $steps as $step ) {
			$step = dtb_email_clean_text( $step );
			if ( '' === $step ) {
				continue;
			}
			$rows .= '<tr><td width="24" valign="top" style="padding:0 10px 12px 0;color:#2255ee;font-family:' . dtb_email_font_stack() . ';font-size:15px;font-weight:800;">&#8250;</td><td valign="top" style="padding:0 0 12px;color:#334155;font-family:' . dtb_email_font_stack() . ';font-size:14px;line-height:150%;">' . esc_html( $step ) . '</td></tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:20px 0 0;">' . $rows . '</table>';
	}
}

if ( ! function_exists( 'dtb_email_details_table' ) ) {
	/**
	 * Render label/value rows for transactional email details.
	 *
	 * @param array<int,array{label:string,value:string}> $rows Detail rows.
	 * @param array<string,mixed>                          $style Optional style values.
	 * @return string
	 */
	function dtb_email_details_table( array $rows, array $style = [] ): string {
		$body        = '';
		$bg          = sanitize_hex_color( (string) ( $style['bg'] ?? '#0f172a' ) ) ?: '#0f172a';
		$row_bg      = sanitize_hex_color( (string) ( $style['row_bg'] ?? '#0a1222' ) ) ?: '#0a1222';
		$border      = sanitize_hex_color( (string) ( $style['border'] ?? '#263751' ) ) ?: '#263751';
		$label_color = sanitize_hex_color( (string) ( $style['label'] ?? '#9aa8bb' ) ) ?: '#9aa8bb';
		$value_color = sanitize_hex_color( (string) ( $style['value'] ?? '#eef4ff' ) ) ?: '#eef4ff';

		foreach ( $rows as $row ) {
			$label = dtb_email_clean_text( $row['label'] ?? '' );
			$value = dtb_email_clean_multiline_text( $row['value'] ?? '' );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$body .= '<tr>'
				. '<td class="dtb-detail-label" width="34%" valign="top" style="padding:15px 18px;background:' . esc_attr( $row_bg ) . ';background-color:' . esc_attr( $row_bg ) . ';color:' . esc_attr( $label_color ) . ';font-family:' . dtb_email_font_stack() . ';font-size:12px;line-height:18px;font-weight:800;text-transform:uppercase;border-bottom:1px solid ' . esc_attr( $border ) . ';">' . esc_html( $label ) . '</td>'
				. '<td class="dtb-detail-value" width="66%" valign="top" style="padding:15px 18px;background:' . esc_attr( $row_bg ) . ';background-color:' . esc_attr( $row_bg ) . ';color:' . esc_attr( $value_color ) . ';font-family:' . dtb_email_font_stack() . ';font-size:15px;font-weight:700;line-height:22px;border-bottom:1px solid ' . esc_attr( $border ) . ';text-align:left;">' . wp_kses_post( nl2br( esc_html( $value ) ) ) . '</td>'
				. '</tr>';
		}

		if ( '' === $body ) {
			return '';
		}

		return '<table class="dtb-details-table" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:24px 0;border-collapse:separate;border-spacing:0;background:' . esc_attr( $bg ) . ';background-color:' . esc_attr( $bg ) . ';background-image:linear-gradient(' . esc_attr( $bg ) . ',' . esc_attr( $bg ) . ');border:1px solid ' . esc_attr( $border ) . ';border-radius:16px;overflow:hidden;">' . $body . '</table>';
	}
}

if ( ! function_exists( 'dtb_email_details_table_light' ) ) {
	/**
	 * dtb_email_details_table() pre-styled for a white-card context (the
	 * WooCommerce classic email templates in dtb-commerce), instead of the
	 * dark-card defaults used by the standalone dtb_render_branded_email()
	 * shell.
	 *
	 * @param array<int,array{label:string,value:string}> $rows Detail rows.
	 * @return string
	 */
	function dtb_email_details_table_light( array $rows ): string {
		$palette = function_exists( 'dtb_email_palette' ) ? dtb_email_palette( 'light' ) : [];

		return dtb_email_details_table(
			$rows,
			[
				'bg'     => $palette['details_bg'] ?? '#f8fbff',
				'row_bg' => $palette['card_bg'] ?? '#ffffff',
				'border' => $palette['card_border'] ?? '#dce6f3',
				'label'  => $palette['details_label'] ?? '#738196',
				'value'  => $palette['details_value'] ?? '#111827',
			]
		);
	}
}

if ( ! function_exists( 'dtb_email_note_box_light' ) ) {
	/**
	 * dtb_email_note_box() pre-styled for a white-card context (WooCommerce
	 * classic email templates), instead of the dark-card defaults used by
	 * the standalone dtb_render_branded_email() shell. The original
	 * function's hardcoded dark styling was previously reused unchanged in
	 * a light-themed WooCommerce email (customer-fulfillment-updated.php's
	 * merchant note), rendering as a stray dark box against the surrounding
	 * white body.
	 *
	 * @param string $content Plain text or safe HTML.
	 * @param bool   $preserve_lines Whether to preserve line breaks.
	 * @return string
	 */
	function dtb_email_note_box_light( string $content, bool $preserve_lines = true ): string {
		$content = $preserve_lines
			? nl2br( esc_html( dtb_email_clean_multiline_text( $content ) ) )
			: dtb_email_clean_html( $content );

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return '';
		}

		return '<table class="dtb-rich-box dtb-quote-note-light" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" bgcolor="#f8fbff" style="border-collapse:separate;margin:0 0 18px;border:1px solid #dce6f3;border-radius:10px;background:#f8fbff;background-color:#f8fbff;color:#334155;"><tr><td style="padding:16px 18px;color:#334155;font-family:' . dtb_email_font_stack() . ';font-size:14px;line-height:22px;">' . $content . '</td></tr></table>';
	}
}

// =============================================================================
// LIFECYCLE HERO, PROGRESS, CARD, AND FOOTER COMPONENTS
//
// Added for the customer-facing email visual redesign. These render into the
// white body area of the WooCommerce classic email shell (email-header.php /
// email-styles.php), consumed directly by dtb-commerce's template overrides.
// Every function is 100%-inline-styled table markup, matching the rest of
// this file's existing components (dtb_email_button(), dtb_email_status_badge(),
// etc.) — no CSS class this system doesn't already inline is relied upon, so
// these render identically whether or not a client applies <style> at all.
//
// Lifecycle and support icons are hosted transparent PNG assets. Numeric
// fallback remains for callers that omit an icon so restrictive clients still
// receive a readable progression without icon fonts, SVG injection, or emoji.
// =============================================================================

if ( ! function_exists( 'dtb_email_hero' ) ) {
	/**
	 * Render the patterned hero block with an optional order-number eyebrow
	 * and the email heading. Supporting copy is intentionally omitted so the
	 * hero remains concise; lifecycle detail belongs in the body components.
	 *
	 * The heading text itself always comes from the caller (WooCommerce's
	 * own $email_heading, admin-configurable via Settings -> Emails) — this
	 * function only lays it out; it never invents or overrides heading copy,
	 * preserving WooCommerce's settings precedence for subject/heading.
	 *
	 * @param string $heading    Required. The email's heading (usually $email_heading).
	 * @param string $subheading Deprecated compatibility argument; not rendered.
	 * @param string $eyebrow    Optional small label above the heading (e.g. "Order #1234").
	 * @return string
	 */
	function dtb_email_hero( string $heading, string $subheading = '', string $eyebrow = '' ): string {
		unset( $subheading );
		$heading = dtb_email_clean_text( $heading );
		if ( '' === $heading ) {
			return '';
		}

		$font = dtb_email_font_stack();
		$background_url = esc_url( home_url( '/logos/email-background-pattern.png' ) );

		$eyebrow_html = '';
		$eyebrow      = dtb_email_clean_text( $eyebrow );
		if ( '' !== $eyebrow ) {
			$eyebrow_html = '<p style="margin:0 0 14px;color:#2255ee;font-family:' . $font . ';font-size:14px;font-weight:800;text-transform:uppercase;text-align:left;">' . esc_html( $eyebrow ) . '</p>';
		}

		return '<table class="dtb-email-hero" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" bgcolor="#030712" background="' . $background_url . '" style="margin:0 0 28px;background:#030712 url(\'' . $background_url . '\') center center/cover no-repeat;background-color:#030712;">'
			. '<tr><td class="dtb-email-hero-cell" valign="middle" style="padding:36px 48px 40px;">'
			. '<!--[if gte mso 9]><v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:680px;"><v:fill type="frame" src="' . $background_url . '" color="#030712" /><v:textbox inset="42px,34px,42px,40px"><![endif]-->'
			. $eyebrow_html
			. '<h1 style="max-width:500px;margin:0;color:#ffffff;font-family:' . $font . ';font-size:36px;font-weight:800;line-height:116%;text-align:left;">' . esc_html( $heading ) . '</h1>'
			. '<!--[if gte mso 9]></v:textbox></v:rect><![endif]-->'
			. '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_progress_marker_badge' ) ) {
	/**
	 * Render one progress-tracker step marker: a state-aware circular badge.
	 * Completed steps use a checkmark; unfinished steps use their ordinal. The
	 * active step additionally sits inside a wider, lighter halo
	 * ring so it reads as the focal point — a static stand-in for the pulsing
	 * ring animation email clients (Outlook, Gmail app) strip from `<style>`.
	 *
	 * Extracted from dtb_email_progress_steps() purely for readability; same
	 * markup, one step at a time.
	 *
	 * @param string               $glyph  Pre-rendered icon HTML or step number.
	 * @param array<string,string> $colors Resolved color set (text/soft_bg/halo_bg) for this step's state.
	 * @param string               $state  Resolved step state.
	 * @param string               $font   Email font stack.
	 * @return string
	 */
	function dtb_email_progress_marker_badge( string $glyph, array $colors, string $state, string $font ): string {
		$opacity = 'upcoming' === $state ? '0.4' : '1';
		$border  = 'upcoming' === $state ? '#dce3ed' : $colors['text'];
		$size    = 'done' === $state ? '20px' : '14px';

		$badge = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;"><tr><td class="dtb-progress-marker-badge dtb-progress-marker-badge--' . esc_attr( $state ) . '" width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:50%;background:' . esc_attr( $colors['soft_bg'] ) . ';background-color:' . esc_attr( $colors['soft_bg'] ) . ';border:2px solid ' . esc_attr( $border ) . ';color:' . esc_attr( $colors['text'] ) . ';font-family:' . $font . ';font-size:' . esc_attr( $size ) . ';font-weight:800;line-height:40px;opacity:' . esc_attr( $opacity ) . ';">' . $glyph . '</td></tr></table>';

		if ( 'active' !== $state ) {
			return $badge;
		}

		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;"><tr><td width="54" height="54" align="center" valign="middle" style="width:54px;height:54px;border-radius:50%;background:' . esc_attr( $colors['halo_bg'] ) . ';background-color:' . esc_attr( $colors['halo_bg'] ) . ';">' . $badge . '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_progress_steps' ) ) {
	/**
	 * Render a lifecycle progress tracker with semantic checkmarks and ordinals
	 * connected by a line, with a label under each. Table-based (no
	 * flexbox/grid), MSO-safe.
	 *
	 * Deliberately caller-driven, not auto-computed from order status: only
	 * a call site with authoritative knowledge of the order/shipment's real
	 * state should decide each step's tone — this function never guesses or
	 * implies a delivery stage the caller didn't explicitly assert.
	 *
	 * @param array<int,array{label:string,state?:string}> $steps Ordered
	 *        steps. `state` is one of done|active|warning|danger|upcoming
	 *        (default upcoming).
	 * @return string
	 */
	function dtb_email_progress_steps( array $steps ): string {
		$steps = array_values( array_filter( $steps, static fn( $step ): bool => '' !== dtb_email_clean_text( $step['label'] ?? '' ) ) );
		$count = count( $steps );
		if ( $count < 2 ) {
			return '';
		}

		$font   = dtb_email_font_stack();
		$colors = [
			'done'     => [ 'text' => '#ffffff', 'label' => '#101828', 'line' => '#2255ee', 'soft_bg' => '#2255ee', 'halo_bg' => '#f2f7ff' ],
			'active'   => [ 'text' => '#2255ee', 'label' => '#101828', 'line' => '#dce3ed', 'soft_bg' => '#e8f1ff', 'halo_bg' => '#f2f7ff' ],
			'warning'  => [ 'text' => '#a15c00', 'label' => '#a15c00', 'line' => '#f2b25c', 'soft_bg' => '#fef3e2', 'halo_bg' => '#fdf8ef' ],
			'danger'   => [ 'text' => '#b91c1c', 'label' => '#b91c1c', 'line' => '#f2a3a3', 'soft_bg' => '#fde8e8', 'halo_bg' => '#fdf3f3' ],
			'upcoming' => [ 'text' => '#94a3b8', 'label' => '#94a3b8', 'line' => '#dce3ed', 'soft_bg' => '#f8fafc', 'halo_bg' => '#f8fafc' ],
		];

		$marker_row = '';
		$label_row  = '';
		$marker_width = min( 22, (int) floor( 66 / $count ) );
		$line_width   = $count > 1 ? (int) floor( ( 100 - ( $marker_width * $count ) ) / ( $count - 1 ) ) : 0;

		foreach ( $steps as $i => $step ) {
			$label = dtb_email_clean_text( $step['label'] );
			$state = $step['state'] ?? 'upcoming';
			$state = isset( $colors[ $state ] ) ? $state : 'upcoming';
			$c     = $colors[ $state ];
			$glyph = 'done' === $state ? '&#10003;' : esc_html( (string) ( $i + 1 ) );

			$marker_row .= '<td class="dtb-progress-marker" width="' . $marker_width . '%" align="center" valign="middle" style="width:' . $marker_width . '%;padding:0;">'
				. dtb_email_progress_marker_badge( $glyph, $c, $state, $font )
				. '</td>';

			$label_row .= '<td class="dtb-progress-label" width="' . $marker_width . '%" align="center" valign="top" style="width:' . $marker_width . '%;padding:8px 2px 0;"><span style="display:block;width:108px;max-width:100%;color:' . esc_attr( $c['label'] ) . ';font-family:' . $font . ';font-size:12px;font-weight:700;line-height:140%;text-align:center;">' . esc_html( $label ) . '</span></td>';

			if ( $i < $count - 1 ) {
				$line_color  = $c['line'];
				$marker_row .= '<td class="dtb-progress-connector" width="' . $line_width . '%" valign="middle" style="width:' . $line_width . '%;padding:0 4px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td height="3" style="height:3px;line-height:3px;font-size:3px;background:' . esc_attr( $line_color ) . ';background-color:' . esc_attr( $line_color ) . ';">&nbsp;</td></tr></table></td>';
				$label_row  .= '<td class="dtb-progress-label-spacer" width="' . $line_width . '%" style="width:' . $line_width . '%;padding:0;">&nbsp;</td>';
			}
		}

		return '<table class="dtb-email-progress" role="presentation" cellspacing="0" cellpadding="0" border="0" width="636" align="center" style="width:calc(100% - 44px);max-width:636px;margin:0 auto 26px;"><tr>' . $marker_row . '</tr><tr>' . $label_row . '</tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_card_chrome_style' ) ) {
	/**
	 * Shared open-section declarations for email content surfaces
	 * (dtb_email_card_open(), dtb_email_support_card()), so the two stay in
	 * visual sync instead of duplicating the same inline-style fragment.
	 * Padding is intentionally excluded — callers vary there.
	 *
	 * @return string
	 */
	function dtb_email_card_chrome_style(): string {
		return 'background:#ffffff;background-color:#ffffff;border:0;border-radius:0;box-shadow:none;';
	}
}

if ( ! function_exists( 'dtb_email_card_open' ) ) {
	/**
	 * Open a white, rounded, bordered card region with an optional title
	 * row (title left, small muted meta right — e.g. an order date). Must
	 * be paired with dtb_email_card_close(); native `do_action()` output
	 * (order-details tables, address blocks, etc.) can be echoed directly
	 * between the two, so this never needs to receive pre-rendered content
	 * as a string argument.
	 *
	 * @param string $title Optional card heading.
	 * @param string $meta  Optional right-aligned meta text next to the title.
	 * @param string $icon  Optional standalone icon rendered before the title.
	 * @return string
	 */
	function dtb_email_card_open( string $title = '', string $meta = '', string $icon = '' ): string {
		$font   = dtb_email_font_stack();
		$header = '';

		$title = dtb_email_clean_text( $title );
		$meta  = dtb_email_clean_text( $meta );
		$icon  = dtb_email_clean_text( $icon );

		if ( '' !== $title ) {
			$icon_cell = '' !== $icon && function_exists( 'dtb_email_icon' )
				? '<td width="1%" valign="middle" style="padding:0 12px 0 0;">' . dtb_email_icon( $icon, 26 ) . '</td>'
				: '';
			$meta_cell = '' !== $meta
				? '<td valign="middle" align="' . ( is_rtl() ? 'left' : 'right' ) . '" style="color:#94a3b8;font-family:' . $font . ';font-size:12px;font-weight:600;white-space:nowrap;">' . esc_html( $meta ) . '</td>'
				: '';
			$header    = '<table class="dtb-email-section-heading" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 16px;border-bottom:1px solid #e4eaf2;"><tr>'
				. $icon_cell
				. '<td valign="middle" style="padding:0 0 12px;color:#101828;font-family:' . $font . ';font-size:17px;font-weight:800;line-height:1.3;">' . esc_html( $title ) . '</td>'
				. $meta_cell
				. '</tr></table>';
		}

		return '<table class="dtb-email-card" role="presentation" cellspacing="0" cellpadding="0" border="0" width="636" align="center" style="width:calc(100% - 44px);max-width:636px;margin:0 auto 26px;border-collapse:separate;"><tr><td style="padding:20px 24px 8px;' . dtb_email_card_chrome_style() . '">' . $header;
	}
}

if ( ! function_exists( 'dtb_email_card_close' ) ) {
	/**
	 * Close a card region opened by dtb_email_card_open().
	 *
	 * @return string
	 */
	function dtb_email_card_close(): string {
		return '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_next_steps_grid' ) ) {
	/**
	 * Render a compact "what happens next" card as a row of numbered
	 * mini-columns (3 per row) instead of a stacked checklist — self-
	 * contained in its own card (do not also wrap this in
	 * dtb_email_card_open()/close()).
	 *
	 * @param array<int,string|array{text:string,icon?:string}> $items Plain-text
	 *        step descriptions, or arrays with an optional `icon` glyph, in order.
	 * @return string
	 */
	function dtb_email_next_steps_grid( array $items ): string {
		$font = dtb_email_font_stack();

		$items = array_values(
			array_filter(
				array_map(
					static function ( $item ): array {
						if ( is_array( $item ) ) {
							return [
								'text' => dtb_email_clean_text( $item['text'] ?? '' ),
								'icon' => dtb_email_clean_text( $item['icon'] ?? '' ),
							];
						}
						return [ 'text' => dtb_email_clean_text( (string) $item ), 'icon' => '' ];
					},
					$items
				),
				static fn( array $item ): bool => '' !== $item['text']
			)
		);

		if ( empty( $items ) ) {
			return '';
		}

		$per_row   = 3;
		$rows_html = '';
		$step_no   = 0;

		foreach ( array_chunk( $items, $per_row ) as $chunk ) {
			$width = (int) floor( 100 / max( count( $chunk ), 1 ) );
			$row   = '';
			foreach ( $chunk as $item ) {
				++$step_no;
				$row  .= '<td class="dtb-next-step" width="' . $width . '%" valign="top" align="center" style="padding:0 8px;">'
					. '<span style="display:block;color:#475569;font-family:' . $font . ';font-size:13px;line-height:145%;text-align:center;">' . esc_html( $item['text'] ) . '</span>'
					. '</td>';
			}
			$rows_html .= '<tr>' . $row . '</tr>';
		}

		return dtb_email_card_open( __( "What's next?", 'drywall-toolbox' ) )
			. '<table class="dtb-next-steps" role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">' . $rows_html . '</table>'
			. dtb_email_card_close();
	}
}

if ( ! function_exists( 'dtb_email_support_card' ) ) {
	/**
	 * Render a compact "need help?" card: message text with an optional
	 * outlined CTA button.
	 *
	 * @param string $text      Message text.
	 * @param string $cta_url   Optional CTA URL.
	 * @param string $cta_label Optional CTA label.
	 * @param string $icon      Optional standalone icon rendered before the title.
	 * @param string $title     Optional bold title above the message text.
	 * @return string
	 */
	function dtb_email_support_card( string $text, string $cta_url = '', string $cta_label = '', string $icon = 'support', string $title = '' ): string {
		$font  = dtb_email_font_stack();
		$text  = dtb_email_clean_text( $text );
		$title = dtb_email_clean_text( $title );

		if ( '' === $text ) {
			return '';
		}

		$cta_url   = esc_url_raw( $cta_url );
		$cta_label = dtb_email_clean_text( $cta_label );
		$button    = '';

		if ( '' !== $cta_url && '' !== $cta_label ) {
			$button = '<td class="dtb-support-action" valign="middle" align="' . ( is_rtl() ? 'left' : 'right' ) . '" width="154" style="padding:' . ( is_rtl() ? '0 12px 0 0' : '0 0 0 16px' ) . ';">'
				. '<a href="' . esc_url( $cta_url ) . '" style="display:block;padding:12px 20px;border:2px solid #2255ee;border-radius:7px;color:#2255ee;font-family:' . $font . ';font-size:13px;font-weight:800;text-align:center;text-decoration:none;white-space:nowrap;">' . esc_html( $cta_label ) . '</a>'
				. '</td>';
		}

		$icon_html = '' !== $icon && function_exists( 'dtb_email_icon' )
			? '<td width="1%" valign="middle" style="padding:0 14px 0 0;">' . dtb_email_icon( $icon, 34 ) . '</td>'
			: '';

		$text_html = '' !== $title
			? '<span style="display:block;color:#101828;font-family:' . $font . ';font-size:15px;font-weight:800;margin:0 0 2px;">' . esc_html( $title ) . '</span><span style="display:block;color:#344054;font-family:' . $font . ';font-size:13px;font-weight:500;line-height:145%;">' . esc_html( $text ) . '</span>'
			: '<span style="display:block;color:#334155;font-family:' . $font . ';font-size:14px;font-weight:600;line-height:145%;">' . esc_html( $text ) . '</span>';

		return '<table class="dtb-support-card" role="presentation" cellspacing="0" cellpadding="0" border="0" width="636" align="center" style="width:calc(100% - 44px);max-width:636px;margin:0 auto 18px;border-collapse:separate;"><tr><td style="padding:20px 22px;' . dtb_email_card_chrome_style() . '">'
			. '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr>'
			. '<td class="dtb-support-message" valign="middle" style="padding:0;"><table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr>'
			. $icon_html
			. '<td valign="middle" style="padding:0;">' . $text_html . '</td></tr></table></td>'
			. $button
			. '</tr></table>'
			. '</td></tr></table>';
	}
}

if ( ! function_exists( 'dtb_email_footer_links' ) ) {
	/**
	 * Return settings-backed legal/support footer links.
	 *
	 * Terms and Privacy are omitted when their authoritative WooCommerce or
	 * WordPress page is not configured; presentation must not invent a route.
	 *
	 * @return array<int,array{label:string,url:string}>
	 */
	function dtb_email_footer_links(): array {
		$links = [];
		if ( function_exists( 'wc_get_page_id' ) ) {
			$terms_page_id = (int) wc_get_page_id( 'terms' );
			$terms_url     = $terms_page_id > 0 ? get_permalink( $terms_page_id ) : '';
			if ( $terms_url ) {
				$links[] = [ 'label' => __( 'Terms', 'drywall-toolbox' ), 'url' => $terms_url ];
			}
		}

		$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( $privacy_url ) {
			$links[] = [ 'label' => __( 'Privacy', 'drywall-toolbox' ), 'url' => $privacy_url ];
		}

		$links[] = [ 'label' => __( 'Contact Us', 'drywall-toolbox' ), 'url' => dtb_email_support_url() ];
		return (array) apply_filters( 'dtb_email_footer_links', $links );
	}
}

if ( ! function_exists( 'dtb_email_footer_link_html' ) ) {
	/** Return compact settings-backed footer navigation. */
	function dtb_email_footer_link_html(): string {
		$parts = [];
		foreach ( dtb_email_footer_links() as $link ) {
			$label = dtb_email_clean_text( $link['label'] ?? '' );
			$url   = esc_url_raw( (string) ( $link['url'] ?? '' ) );
			if ( '' !== $label && '' !== $url ) {
				$parts[] = '<a href="' . esc_url( $url ) . '" style="color:#2255ee;text-decoration:underline;">' . esc_html( $label ) . '</a>';
			}
		}

		return implode( '<span aria-hidden="true" style="padding:0 8px;color:#667085;">|</span>', $parts );
	}
}

if ( ! function_exists( 'dtb_email_social_links' ) ) {
	/**
	 * Return the store's confirmed social profile links for the email
	 * footer. Only accounts already established elsewhere in this codebase
	 * (frontend/src/utils/schema.js's structured-data `sameAs`, the closest
	 * thing to a canonical/approved social link list this repo has) are
	 * included — never fabricate a profile URL that isn't confirmed real.
	 * Add real, confirmed links via the filter, not by editing this default.
	 *
	 * @return array<int,array{label:string,url:string}>
	 */
	function dtb_email_social_links(): array {
		return (array) apply_filters(
			'dtb_email_social_links',
			[
				[ 'label' => 'Instagram', 'url' => 'https://www.instagram.com/drywalltoolbox' ],
				[ 'label' => 'Facebook', 'url' => 'https://www.facebook.com/Drywalltoolbox/' ],
				[ 'label' => 'X', 'url' => 'https://twitter.com' ],
			]
		);
	}
}

if ( ! function_exists( 'dtb_email_social_icons' ) ) {
	/**
	 * Render small circular social icon links for the dark email footer
	 * band.
	 *
	 * @return string
	 */
	function dtb_email_social_icons(): string {
		$links = dtb_email_social_links();
		if ( ! is_array( $links ) || [] === $links ) {
			return '';
		}

		// Rasterized from the same Instagram / Facebook Lucide v1.9-stroke
		// geometry and custom XLogo path used in frontend/src/components/shell/Footer.jsx.
		// Image-backed output is intentional: inline SVG is unreliable in email clients.
		$assets = [
			'Instagram' => 'frontend-instagram.png',
			'Facebook'  => 'frontend-facebook.png',
			'X'         => 'frontend-x.png',
		];
		$cells = '';
		foreach ( $links as $link ) {
			$label = dtb_email_clean_text( $link['label'] ?? '' );
			$url = esc_url_raw( (string) ( $link['url'] ?? '' ) );
			if ( ! isset( $assets[ $label ] ) || '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$src = home_url( '/logos/email-icons/' . $assets[ $label ] );
			$cells .= '<td align="center" valign="middle" style="padding:0 9px;text-align:center;vertical-align:middle;">'
				. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '" style="display:inline-block;padding:0;border:0;background:none;text-decoration:none;">'
				. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $label ) . '" width="21" height="21" style="display:block;width:21px;height:21px;border:0;background:transparent;outline:none;text-decoration:none;" />'
				. '</a></td>';
		}

		return '' === $cells ? '' : '<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="margin:0 auto;border-collapse:collapse;"><tr>' . $cells . '</tr></table>';
	}
}

// =============================================================================
// CANONICAL BRANDED RENDERER
// =============================================================================

if ( ! function_exists( 'dtb_render_branded_email' ) ) {
	/**
	 * Render the official shared Drywall Toolbox email layout.
	 *
	 * The static HTML template is the single source of truth for email UI. Callers
	 * provide content only; this renderer owns layout, colors, responsiveness, and
	 * email-client compatibility.
	 *
	 * @param array<string,mixed> $args Template args.
	 * @return string
	 */
	function dtb_render_branded_email( array $args ): string {
		$site        = dtb_email_clean_text( get_bloginfo( 'name' ) );
		$title       = dtb_email_clean_text( $args['title'] ?? $site );
		$preheader   = dtb_email_clean_text( $args['preheader'] ?? '' );
		$greeting    = dtb_email_clean_text( $args['greeting'] ?? 'Hi there,' );
		$intro       = dtb_email_clean_html( (string) ( $args['intro'] ?? '' ) );
		$body_html   = dtb_email_clean_html( (string) ( $args['body_html'] ?? '' ) );
		$details     = is_array( $args['details'] ?? null ) ? $args['details'] : [];
		$cta_url     = esc_url_raw( (string) ( $args['cta_url'] ?? '' ) );
		$cta_label   = dtb_email_clean_text( $args['cta_label'] ?? '' );
		$signoff     = dtb_email_clean_text( $args['signoff'] ?? 'Drywall Toolbox Team' );
		$footer_note = dtb_email_clean_html( (string) ( $args['footer_note'] ?? 'You can reply directly to this email if you need help.' ) );
		$logo_url    = esc_url( dtb_email_logo_url() );
		$home_url    = esc_url( home_url( '/' ) );
		$home_host   = esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$support_url = esc_url( dtb_email_support_url() );
		$palette     = dtb_email_palette( 'dark' );
		$template_id = 'dtb-email-template-v20260708-table-inline';

		$details_html = dtb_email_details_table(
			$details,
			[
				'border' => $palette['details_border'],
				'bg'     => $palette['details_bg'],
				'row_bg' => $palette['details_row'],
				'label'  => $palette['details_label'],
				'value'  => $palette['details_value'],
			]
		);

		$button_html = dtb_email_button(
			$cta_url,
			$cta_label,
			[
				'bg'   => $palette['button_bg'],
				'text' => $palette['button_text'],
			]
		);

		$template_path = __DIR__ . '/Templates/branded-email.html';
		$template_html = is_readable( $template_path ) ? file_get_contents( $template_path ) : false;

		if ( false !== $template_html ) {
			$greeting_block = '' !== $greeting
				? '<p class="dtb-greeting" style="margin:0 0 10px;color:' . esc_attr( $palette['greeting'] ) . ';font-family:' . dtb_email_font_stack() . ';font-size:20px;line-height:28px;font-weight:800;">' . esc_html( $greeting ) . '</p>'
				: '';
			$intro_block    = '' !== $intro
				? '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0;border-collapse:collapse;"><tr><td class="dtb-intro dtb-rich" style="padding:0;color:' . esc_attr( $palette['intro'] ) . ';font-family:' . dtb_email_font_stack() . ';font-size:16px;line-height:26px;">' . $intro . '</td></tr></table>'
				: '';
			$body_block     = '' !== $body_html
				? '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:24px 0 0;border-collapse:separate;"><tr><td class="dtb-rich-box dtb-rich" bgcolor="' . esc_attr( $palette['details_row'] ) . '" style="padding:18px 20px;border:1px solid ' . esc_attr( $palette['details_border'] ) . ';border-radius:10px;background:' . esc_attr( $palette['details_row'] ) . ';background-color:' . esc_attr( $palette['details_row'] ) . ';color:' . esc_attr( $palette['intro'] ) . ';font-family:' . dtb_email_font_stack() . ';font-size:15px;line-height:24px;">' . $body_html . '</td></tr></table>'
				: '';
			$footer_note_block = '' !== $footer_note
				? '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 12px;border-collapse:collapse;"><tr><td style="padding:0;text-align:center;color:' . esc_attr( $palette['footer_text'] ) . ';font-family:' . dtb_email_font_stack() . ';font-size:13px;line-height:20px;">' . $footer_note . '</td></tr></table>'
				: '';

			$replacements = [
				'{{template_id}}'       => esc_html( $template_id ),
				'{{title}}'             => esc_html( $title ),
				'{{heading}}'           => esc_html( $title ),
				'{{preheader}}'         => esc_html( $preheader ),
				'{{site_name}}'         => esc_attr( $site ),
				'{{logo_url}}'          => $logo_url,
				'{{home_url}}'          => $home_url,
				'{{home_host}}'         => $home_host,
				'{{support_url}}'       => $support_url,
				'{{shell_bg}}'          => esc_attr( $palette['shell_bg'] ),
				'{{header_bg}}'         => esc_attr( $palette['header_bg'] ),
				'{{card_bg}}'           => esc_attr( $palette['card_bg'] ),
				'{{footer_bg}}'         => esc_attr( $palette['footer_bg'] ),
				'{{accent}}'            => esc_attr( $palette['accent'] ),
				'{{title_color}}'       => esc_attr( $palette['title'] ),
				'{{intro_color}}'       => esc_attr( $palette['intro'] ),
				'{{details_border}}'    => esc_attr( $palette['details_border'] ),
				'{{footer_link}}'       => esc_attr( $palette['footer_link'] ),
				'{{footer_sep}}'        => esc_attr( $palette['footer_sep'] ),
				'{{copyright}}'         => esc_attr( $palette['copyright'] ),
				'{{greeting_block}}'    => $greeting_block,
				'{{intro_block}}'       => $intro_block,
				'{{details_html}}'      => $details_html,
				'{{body_block}}'        => $body_block,
				'{{button_html}}'       => $button_html,
				'{{signoff}}'           => esc_html( $signoff ),
				'{{footer_note_block}}' => $footer_note_block,
				'{{year}}'              => esc_html( gmdate( 'Y' ) ),
			];

			return strtr( $template_html, $replacements );
		}

		do_action( 'dtb_email_template_missing', $template_path, $args );
		return '';
	}
}

// =============================================================================
// SEND PIPELINE
// =============================================================================

if ( ! function_exists( 'dtb_mail_alt_body_hook' ) ) {
	/**
	 * Attach a one-shot PHPMailer AltBody hook and return the closure to remove.
	 *
	 * @param string $plain_message Plain-text email body.
	 * @return callable
	 */
	function dtb_mail_alt_body_hook( string $plain_message ): callable {
		$plain_message = wp_strip_all_tags( $plain_message );

		$set_alt_body = static function ( $phpmailer ) use ( $plain_message ): void {
			$phpmailer->AltBody = $plain_message;
		};

		add_action( 'phpmailer_init', $set_alt_body );

		return $set_alt_body;
	}
}

if ( ! function_exists( 'dtb_email_normalize_header_lines' ) ) {
	/**
	 * Normalize raw header lines and drop unsafe values.
	 *
	 * @param mixed $headers Raw headers.
	 * @return string[]
	 */
	function dtb_email_normalize_header_lines( mixed $headers ): array {
		$raw = is_array( $headers ) ? $headers : ( is_string( $headers ) && '' !== $headers ? [ $headers ] : [] );

		$normalized = [];
		foreach ( $raw as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header || str_contains( $header, "\n" ) || str_contains( $header, "\r" ) ) {
				continue;
			}

			if ( ! preg_match( '/^(content-type|from|reply-to|cc|bcc):\s*.+$/i', $header ) ) {
				continue;
			}

			$normalized[] = $header;
		}

		return array_values( array_unique( $normalized ) );
	}
}

if ( ! function_exists( 'dtb_email_headers' ) ) {
	/**
	 * Build normalized email headers.
	 *
	 * @param array<string,mixed> $args Header args.
	 * @return string[]
	 */
	function dtb_email_headers( array $args = [] ): array {
		$content_type = sanitize_text_field( (string) ( $args['content_type'] ?? 'text/plain' ) );
		$content_type = in_array( $content_type, [ 'text/plain', 'text/html' ], true ) ? $content_type : 'text/plain';
		$from_name    = dtb_email_clean_text( $args['from_name'] ?? '' );
		$from_email   = sanitize_email( (string) ( $args['from_email'] ?? '' ) );
		$reply_to     = sanitize_email( (string) ( $args['reply_to'] ?? '' ) );
		$headers      = [];

		$headers[] = 'Content-Type: ' . $content_type . '; charset=UTF-8';

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
		}

		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return $headers;
	}
}

if ( ! function_exists( 'dtb_send_email' ) ) {
	/**
	 * Send outbound email through the shared pathway.
	 *
	 * @param array<string,mixed> $args Send args.
	 * @return bool
	 */
	function dtb_send_email( array $args ): bool {
		$to      = sanitize_email( (string) ( $args['to'] ?? '' ) );
		$subject = dtb_email_clean_text( $args['subject'] ?? '' );
		$message = (string) ( $args['message'] ?? '' );

		if ( '' === $to || ! is_email( $to ) || '' === $subject || '' === $message ) {
			do_action( 'dtb_email_send_invalid', $args );
			return false;
		}

		$is_html      = ! empty( $args['is_html'] );
		$content_type = sanitize_text_field( (string) ( $args['content_type'] ?? ( $is_html ? 'text/html' : 'text/plain' ) ) );
		$content_type = in_array( $content_type, [ 'text/html', 'text/plain' ], true ) ? $content_type : ( $is_html ? 'text/html' : 'text/plain' );
		$headers      = dtb_email_normalize_header_lines( $args['headers'] ?? [] );

		if ( empty( $headers ) ) {
			$headers = dtb_email_headers(
				[
					'content_type' => $content_type,
					'from_name'    => (string) ( $args['from_name'] ?? '' ),
					'from_email'   => (string) ( $args['from_email'] ?? '' ),
					'reply_to'     => (string) ( $args['reply_to'] ?? '' ),
				]
			);
		} elseif ( ! array_filter( $headers, static fn( string $h ): bool => 0 === stripos( $h, 'Content-Type:' ) ) ) {
			array_unshift( $headers, 'Content-Type: ' . $content_type . '; charset=UTF-8' );
		}

		$alt_body = isset( $args['alt_body'] ) ? (string) $args['alt_body'] : '';
		$alt_hook = ( '' !== $alt_body && function_exists( 'dtb_mail_alt_body_hook' ) )
			? dtb_mail_alt_body_hook( $alt_body )
			: null;

		do_action( 'dtb_email_before_send', $to, $subject, $message, $headers, $args );

		$sent = (bool) wp_mail( $to, $subject, $message, $headers );

		if ( is_callable( $alt_hook ) ) {
			remove_action( 'phpmailer_init', $alt_hook );
		}

		do_action( 'dtb_email_after_send', $sent, $to, $subject, $args );

		return $sent;
	}
}

<?php
/** DTB v4 WooCommerce HTML email footer. @package DrywalltoolboxCommerce */
defined( 'ABSPATH' ) || exit;
$logo_url=function_exists('dtb_email_logo_url')?dtb_email_logo_url():'';
$home_url=home_url('/');
$home_host=(string)wp_parse_url($home_url,PHP_URL_HOST);
$home_host=preg_replace('/^www\./i','',$home_host);
$order = isset( $email ) && is_object( $email ) && isset( $email->object ) && $email->object instanceof WC_Order ? $email->object : null;
$tracking_url = $order && function_exists( 'dtb_order_tracking_url' ) ? dtb_order_tracking_url( $order ) : '';
$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
$support_url = function_exists( 'dtb_email_support_url' ) ? dtb_email_support_url() : home_url( '/contact/' );
$links = [];
if ( $tracking_url ) { $links[] = [ 'Track Order', $tracking_url ]; }
if ( $account_url ) { $links[] = [ 'My Account', $account_url ]; }
if ( $shop_url ) { $links[] = [ 'Shop', $shop_url ]; }
if ( $support_url ) { $links[] = [ 'Contact Us', $support_url ]; }
$links = apply_filters( 'dtb_email_customer_footer_links', $links, $order, $email ?? null );
$link_html = [];
foreach ( (array) $links as $link ) {
    if ( ! is_array( $link ) || ! isset( $link[0], $link[1] ) || ! is_string( $link[0] ) || ! is_string( $link[1] ) || ! wp_http_validate_url( $link[1] ) ) { continue; }
    $link_html[] = '<a href="' . esc_url( $link[1] ) . '" style="display:inline-block;margin:5px 10px;color:#d7e6fb;font-size:12px;font-weight:600;line-height:20px;text-decoration:underline;text-underline-offset:3px;">' . esc_html( $link[0] ) . '</a>';
}
$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
$font=function_exists('dtb_email_font_stack')?dtb_email_font_stack():"'Geist',Arial,sans-serif";
?>
</div></td></tr></table></td></tr></table></td></tr></table></td></tr></table></td></tr>
<tr><td align="center" valign="top" bgcolor="#061524" style="background-color:#061524;">
<table id="template_footer" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#061524" style="width:100%;border-collapse:collapse;background-color:#061524;">
<tr><td id="credit" align="center" bgcolor="#061524" style="padding:31px 22px 30px;text-align:center;background-color:#061524;color:#c4cfdd;font-family:<?php echo esc_attr( $font ); ?>;">
<?php if ( $logo_url ) : ?>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;border-collapse:collapse;"><tr><td align="center" style="padding:0;">
<a href="<?php echo esc_url( $home_url ); ?>" style="display:inline-block;text-decoration:none;">
<img src="<?php echo esc_url( $logo_url ); ?>" width="152" alt="Drywall Toolbox" style="display:block;width:152px;max-width:100%;height:auto;border:0;">
</a></td></tr></table>
<?php endif; ?>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:12px auto 0;border-collapse:collapse;"><tr><td align="center" style="padding:0;">
<?php echo function_exists( 'dtb_email_social_icons' ) ? dtb_email_social_icons() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</td></tr></table>
<?php if ( $link_html ) : ?>
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;margin:18px auto 0;border-collapse:collapse;"><tr><td align="center" style="padding:0;text-align:center;font-family:<?php echo esc_attr( $font ); ?>;">
<?php echo implode( '', $link_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</td></tr></table>
<?php endif; ?>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:19px auto 0;border-collapse:collapse;border-top:1px solid #34475e;">
<tr><td align="center" style="padding:18px 0 0;text-align:center;color:#aebdd0;font-family:<?php echo esc_attr( $font ); ?>;font-size:11px;line-height:19px;">
&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Drywall Toolbox. All rights reserved.
<?php if ( $privacy_url ) : ?><br><a href="<?php echo esc_url( $privacy_url ); ?>" style="color:#aebdd0;font-size:11px;text-decoration:underline;">Privacy Policy</a><?php endif; ?>
</td></tr></table>
</td></tr></table>
</td></tr></table>
</td></tr></table></div></td></tr></table></td></tr></table>
</body></html>

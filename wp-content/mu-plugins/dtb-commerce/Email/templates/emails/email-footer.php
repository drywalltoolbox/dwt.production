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
    $link_html[] = '<a href="' . esc_url( $link[1] ) . '" style="display:inline-block;margin:5px 12px 7px;padding:0 2px 4px;color:#d7e6fb;font-size:12px;font-weight:600;line-height:20px;text-decoration:none;border-bottom:1px solid rgba(34,85,238,0.22);background-image:linear-gradient(90deg,rgba(34,85,238,0) 0%,rgba(34,85,238,0.18) 14%,#2255EE 40%,#2255EE 60%,rgba(34,85,238,0.18) 86%,rgba(34,85,238,0) 100%);background-repeat:no-repeat;background-position:center bottom;background-size:100% 1px;">' . esc_html( $link[0] ) . '</a>';
}
$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
$font=function_exists('dtb_email_font_stack')?dtb_email_font_stack():"'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
$footer_artwork=home_url('/assets/email/dtb-email-footer-background.jpg');
?>
</div></td></tr></table></td></tr></table></td></tr></table></td></tr></table></td></tr>
<tr><td align="center" valign="top" bgcolor="#061524" style="background-color:#061524;">
<table id="template_footer" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" background="<?php echo esc_url( $footer_artwork ); ?>" bgcolor="#061524" style="width:100%;border-collapse:collapse;background-color:#061524;background-image:url('<?php echo esc_url( $footer_artwork ); ?>');background-position:center center;background-repeat:no-repeat;background-size:cover;">
<tr><td id="credit" align="center" bgcolor="#061524" style="padding:31px 22px 30px;text-align:center;background-color:#061524;background-image:url('<?php echo esc_url( $footer_artwork ); ?>');background-position:center center;background-repeat:no-repeat;background-size:cover;color:#c4cfdd;font-family:<?php echo esc_attr( $font ); ?>;">
<?php echo function_exists( 'dtb_email_global_footer_content_html' ) ? dtb_email_global_footer_content_html( $links ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</td></tr></table>
</td></tr></table>
</td></tr></table></div></td></tr></table></td></tr></table>
</body></html>

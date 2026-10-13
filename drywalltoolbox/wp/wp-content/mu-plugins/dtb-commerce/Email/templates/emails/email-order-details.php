<?php
/** DTB canonical order-details projection. WooCommerce remains authoritative for items and totals. @package DrywalltoolboxCommerce */
use Automattic\WooCommerce\Utilities\FeaturesUtil;
defined( 'ABSPATH' ) || exit;
$email_improvements_enabled=FeaturesUtil::feature_is_enabled('email_improvements');
if($email_improvements_enabled){add_filter('woocommerce_order_shipping_to_display_shipped_via','__return_false');}
do_action('woocommerce_email_before_order_table',$order,$sent_to_admin,$plain_text,$email);
$font=function_exists('dtb_email_font_stack')?dtb_email_font_stack():"'Geist',Arial,sans-serif";
$is_order_family=function_exists('DTB\\Commerce\\Email\\dtb_email_v4_is_order_family')?DTB\Commerce\Email\dtb_email_v4_is_order_family($email):false;
if($is_order_family):
?>
<table class="dtb-v4-order" role="presentation" border="0" cellpadding="0" cellspacing="0" width="87%" align="center" style="width:87%;max-width:592px;margin:0 auto 24px;border-collapse:collapse;">
<tr><td align="center" style="padding:18px 0 20px;border-top:1px solid #d7dee8;border-bottom:1px solid #d7dee8;color:#071b45;font-family:<?php echo esc_attr($font); ?>;">
<span style="display:block;margin:0 0 6px;color:#52627e;font-size:9px;font-weight:750;line-height:13px;letter-spacing:2px;text-transform:uppercase;"><?php esc_html_e('Order details','drywall-toolbox'); ?></span>
<strong style="display:block;font-size:23px;font-weight:650;line-height:29px;letter-spacing:-.35px;"><?php esc_html_e('Order number:','drywall-toolbox'); ?></strong>
<span style="display:block;margin-top:2px;color:#65738f;font-size:20px;font-weight:450;line-height:27px;">DTB-<?php echo esc_html($order->get_order_number()); ?></span>
</td></tr>
<tr><td style="padding:15px 18px 3px;">
<table class="td font-family email-order-details" cellspacing="0" cellpadding="0" width="100%" style="width:100%;border-collapse:collapse;" border="0" role="presentation"><tbody>
<?php echo wc_get_email_order_items($order,['show_sku'=>true,'show_image'=>true,'image_size'=>[72,72],'plain_text'=>$plain_text,'sent_to_admin'=>$sent_to_admin]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</tbody></table>
</td></tr>
<tr><td style="padding:15px 18px 20px;border-top:1px solid #d7dee8;">
<table class="td font-family email-order-details email-order-totals" cellspacing="0" cellpadding="0" width="100%" style="width:100%;border-collapse:collapse;" border="0" role="presentation">
<?php foreach($order->get_order_item_totals() as $total): $type=sanitize_html_class((string)($total['type']??'unknown')); $is_total='total'===$type; ?>
<tr class="order-totals order-totals-<?php echo esc_attr($type); ?><?php echo $is_total?' order-totals-total':''; ?>"><th scope="row" colspan="2" style="padding:8px 0;color:<?php echo $is_total?'#071b45':'#65738f'; ?>;font-family:<?php echo esc_attr($font); ?>;font-size:<?php echo $is_total?'19':'13'; ?>px;font-weight:<?php echo $is_total?'750':'450'; ?>;line-height:22px;text-align:left;<?php echo $is_total?'border-top:1px solid #d7dee8;padding-top:13px;':''; ?>"><?php echo wp_kses_post($total['label']??''); ?></th><td align="right" style="padding:8px 0;color:<?php echo $is_total?'#071b45':'#65738f'; ?>;font-family:<?php echo esc_attr($font); ?>;font-size:<?php echo $is_total?'20':'13'; ?>px;font-weight:<?php echo $is_total?'800':'500'; ?>;line-height:22px;white-space:nowrap;<?php echo $is_total?'border-top:1px solid #d7dee8;padding-top:13px;':''; ?>"><?php echo wp_kses_post($total['value']??''); ?></td></tr>
<?php endforeach; ?>
</table></td></tr>
</table>
<?php
else:
$order_date=$order->get_date_created()?$order->get_date_created()->date_i18n('F j, Y'):'';
echo function_exists('DTB\\Commerce\\Email\\dtb_email_v3_card_open')?DTB\Commerce\Email\dtb_email_v3_card_open(__('Order summary','drywall-toolbox'),$order_date):'<div>';
?>
<table class="td font-family email-order-details" cellspacing="0" cellpadding="0" style="width:100%;" border="0" role="presentation"><tbody><?php echo wc_get_email_order_items($order,['show_sku'=>true,'show_image'=>true,'image_size'=>[68,68],'plain_text'=>$plain_text,'sent_to_admin'=>$sent_to_admin]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></tbody></table>
<table class="td font-family email-order-details email-order-totals" cellspacing="0" cellpadding="0" style="width:100%;" border="0" role="presentation"><?php foreach($order->get_order_item_totals() as $total): ?><tr><th colspan="2" scope="row"><?php echo wp_kses_post($total['label']??''); ?></th><td align="right"><?php echo wp_kses_post($total['value']??''); ?></td></tr><?php endforeach; ?></table>
<?php echo function_exists('DTB\\Commerce\\Email\\dtb_email_v3_card_close')?DTB\Commerce\Email\dtb_email_v3_card_close():'</div>'; endif;
if($email_improvements_enabled){remove_filter('woocommerce_order_shipping_to_display_shipped_via','__return_false');}
do_action('woocommerce_email_after_order_table',$order,$sent_to_admin,$plain_text,$email);

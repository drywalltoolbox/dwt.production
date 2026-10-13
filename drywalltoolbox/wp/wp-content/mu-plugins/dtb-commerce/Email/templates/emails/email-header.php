<?php
/** DTB WooCommerce HTML email shell header. @package DrywalltoolboxCommerce */
defined( 'ABSPATH' ) || exit;
$store_name      = $store_name ?? get_bloginfo( 'name', 'display' );
$logo_url        = function_exists( 'dtb_email_logo_url' ) ? dtb_email_logo_url() : '';
$font_stack      = function_exists( 'dtb_email_font_stack' ) ? dtb_email_font_stack() : "'Geist',Arial,sans-serif";
$is_order_family = function_exists( 'DTB\\Commerce\\Email\\dtb_email_v4_is_order_family' )
	? DTB\Commerce\Email\dtb_email_v4_is_order_family( $email ?? '' )
	: false;
$shell_width     = 680;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<style type="text/css">:root{color-scheme:light only;supported-color-schemes:light only}</style>
<title><?php echo esc_html( $store_name ); ?></title>
</head>
<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="margin:0;padding:0;background-color:#eef3f7;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table width="100%" id="outer_wrapper" border="0" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#eef3f7" style="width:100%;margin:0;padding:0;border-collapse:collapse;background-color:#eef3f7;"><tr><td class="dtb-email-outer-cell" align="center" valign="top" bgcolor="#eef3f7" style="padding:20px 12px;background-color:#eef3f7;">
<table class="dtb-email-shell-cell<?php echo $is_order_family ? ' dtb-v4-order-shell' : ''; ?>" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" align="center" bgcolor="#ffffff" style="width:100%;max-width:680px;border-radius:10px;border-collapse:separate;background-color:#ffffff;overflow:hidden;">
<tr><td align="center" valign="top" bgcolor="#ffffff" style="background-color:#ffffff;"><div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" style="width:100%;max-width:680px;margin:0 auto;background-color:#ffffff;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="inner_wrapper" role="presentation" bgcolor="#ffffff" style="width:100%;border-collapse:separate;background-color:#ffffff;">
<tr><td align="center" valign="top" bgcolor="#ffffff" style="background-color:#ffffff;">
<?php // Shared navy brand header is always visible on customer and operator notifications. ?>
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation" bgcolor="#061524" style="width:100%;background-color:#061524;border-collapse:collapse;"><tr><td id="template_header_image" align="center" valign="middle" bgcolor="#061524" style="padding:20px 34px;background-color:#061524;">
<?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" width="184" style="display:block;width:184px;max-width:100%;height:auto;margin:0 auto;border:0;"><?php else : ?><span style="color:#fff;font-family:<?php echo esc_attr( $font_stack ); ?>;font-size:20px;font-weight:800;"><?php echo esc_html( $store_name ); ?></span><?php // End shared header. ?>
</td></tr></table>
<?php endif; ?>
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation" bgcolor="#ffffff" style="width:100%;border-collapse:collapse;background-color:#ffffff;"><tr><td align="center" valign="top" bgcolor="#ffffff" style="background-color:#ffffff;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation" bgcolor="#ffffff" style="width:100%;border-collapse:collapse;background-color:#ffffff;"><tr><td valign="top" id="body_content" bgcolor="#ffffff" style="width:100%;background-color:#ffffff;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" bgcolor="#ffffff" style="width:100%;border-collapse:collapse;background-color:#ffffff;"><tr><td valign="top" id="body_content_inner_cell" bgcolor="#ffffff" style="background-color:#ffffff;"><div id="body_content_inner">

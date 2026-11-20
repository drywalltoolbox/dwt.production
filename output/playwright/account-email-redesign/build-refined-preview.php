<?php
define( 'ABSPATH', __DIR__ );
function home_url( $path = '' ) { return str_starts_with( (string) $path, '/assets/email/' ) ? '../../../frontend/public/assets/email/account-welcome-hero.jpeg' : 'https://drywalltoolbox.com' . $path; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function __( $value, $domain = '' ) { return $value; }
function dtb_email_font_stack() { return "'Geist',Arial,sans-serif"; }
function dtb_email_palette( $theme = 'light' ) { return [ 'accent' => '#2255ee' ]; }
require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Email/AccountPresentation.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DTB New Account Email · Refined Concept</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#e9edf2;color:#10213a;font-family:Arial,sans-serif}.preview{width:min(100%,680px);margin:24px auto;background:#fff;box-shadow:0 10px 36px #12243a1a}.mail-header{height:82px;display:flex;align-items:center;justify-content:center;background:#071827}.mail-header img{width:180px;height:auto}.mail-content{padding:18px 0 22px}.dtb-account-welcome-copy{width:74%}.dtb-account-welcome-copy h1{margin:0}.dtb-account-welcome-copy h1 span{display:block}.dtb-account-identity{width:92%}.dtb-account-offer{width:92%}.dtb-account-benefits{width:92%}.dtb-account-benefit{display:table-cell}.footer{padding:24px;text-align:center;background:#071827;color:#dce7f4;font-size:12px}
@media(max-width:600px){.preview{margin:0;width:100%;box-shadow:none}.mail-header{height:70px}.mail-header img{width:165px}.dtb-account-welcome-copy{display:block!important;width:100%!important;padding:30px 24px!important}.dtb-account-welcome-copy h1{font-size:32px!important;line-height:36px!important}.dtb-account-welcome-copy h1 span{display:block!important}.dtb-account-welcome-copy h1 .dtb-account-welcome-brand{display:inline-block!important;max-width:100%!important;font-size:26px!important;line-height:31px!important;white-space:normal!important}.dtb-account-welcome-spacer{display:none!important;width:0!important}.dtb-account-identity-copy{display:block!important;width:100%!important;padding:16px 18px 18px!important}.dtb-account-offer-copy,.dtb-account-offer-code{display:block!important;width:100%!important;box-sizing:border-box!important}.dtb-account-offer-copy{padding:18px!important}.dtb-account-offer-code{padding:17px 18px!important;border-top:3px solid #2255ee!important;border-left:0!important}.dtb-account-benefits{width:92%!important}}
</style>
</head>
<body><main class="preview">
<header class="mail-header"><img src="../../../drywalltoolbox/logos/email-logo-white.png" alt="Drywall Toolbox"></header>
<?php echo \DTB\Commerce\Email\dtb_email_new_account_hero( 'https://drywalltoolbox.com/my-account', 'Go to your account' ); ?>
<section class="mail-content">
<table class="dtb-account-identity" role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" bgcolor="#ffffff" style="max-width:600px;margin:0 auto 20px;border-collapse:collapse;border-top:2px solid #2255ee;border-bottom:1px solid #dbe2ea;background:#fff;font-family:Arial,sans-serif"><tr><td class="dtb-account-identity-copy" style="padding:17px 20px 19px">
<p style="margin:0 0 7px;color:#2255ee;font-size:10px;line-height:15px;font-weight:700;letter-spacing:1.7px;text-transform:uppercase">Account details</p><p style="margin:0;color:#10213a;font-size:20px;line-height:27px;font-weight:700">Welcome, Preview Customer.</p><p style="margin:4px 0 13px;color:#45566c;font-size:14px;line-height:21px">Your account is ready whenever you need it.</p><p style="margin:0;color:#64748b;font-size:10px;line-height:15px;font-weight:650;letter-spacing:1px;text-transform:uppercase">Username</p><p style="margin:3px 0 0;color:#10213a;font:600 14px/20px Consolas,monospace;overflow-wrap:anywhere">preview-account</p>
</td></tr></table>
<?php echo \DTB\Commerce\Email\dtb_email_new_account_offer( 'PREVIEW10' ); ?>
<?php echo \DTB\Commerce\Email\dtb_email_new_account_benefits(); ?>
<p style="margin:0 22px 20px;color:#64748b;text-align:center;font:400 11px/17px Arial,sans-serif">Preview only. Real welcome codes are generated for eligible new accounts.</p>
</section><footer class="footer">Drywall Toolbox · Tools, parts, schematics, and repair support.</footer>
</main></body></html>

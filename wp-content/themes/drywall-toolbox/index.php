<?php
/**
 * React SPA Shell - Drywall Toolbox Headless Theme
 *
 * This is the single HTML entry point served for every public-facing request.
 * The template_include filter in functions.php routes all frontend routes here
 * so React Router can handle client-side navigation.
 *
 * WordPress enqueues the React build assets (from dist/asset-manifest.json)
 * via wp_head() and wp_footer(), so there are no hardcoded bundle filenames here.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
	<meta name="description" content="Professional drywall tools and equipment from top brands. Shop automatic taping tools, mud boxes, finishing tools, and more.">
	<meta name="keywords" content="drywall tools, taping tools, finishing tools, construction equipment, TapeTech, Level5">
	<meta name="theme-color" content="#0f172a">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="Drywall Toolbox">
	<link rel="icon" href="<?php echo esc_url( home_url( '/logos/drywall-logo-black.png' ) ); ?>" />
	<link rel="apple-touch-icon" href="<?php echo esc_url( home_url( '/logos/apple-touch-icon.png' ) ); ?>" />
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300..900&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'dtb-react-app' ); ?>>
<noscript>You need to enable JavaScript to run this app.</noscript>
<div id="root"></div>
<?php wp_footer(); ?>
</body>
</html>

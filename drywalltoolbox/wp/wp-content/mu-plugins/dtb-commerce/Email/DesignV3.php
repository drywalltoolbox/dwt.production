<?php
/** DTB canonical WooCommerce email presentation primitives. @package DrywalltoolboxCommerce */
namespace DTB\Commerce\Email;
defined( 'ABSPATH' ) || exit;

function dtb_email_v3_tokens(): array {
	return [
		'shell_width'=>680,'content_width'=>592,'shell_percent'=>100,'content_percent'=>87,
		'header_pad_y'=>0,'header_pad_x'=>0,'logo_width'=>190,'rail_x'=>48,
		'hero_pad_top'=>24,'hero_pad_bottom'=>22,'progress_diameter'=>54,
		'card_radius'=>8,'card_border'=>'#dce3ed','outer_bg'=>'#eef3f7','shell_bg'=>'#ffffff',
		'header_bg'=>'#061524','panel_bg'=>'#ffffff','panel_bg_alt'=>'#f4f7fa','text'=>'#071b45',
		'text_muted'=>'#65738f','text_dim'=>'#78859d','accent'=>'#1769ff','accent_soft'=>'#edf4ff','divider'=>'#d7dee8',
	];
}

function dtb_email_v3_font(): string {
	return function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',Arial,sans-serif";
}

function dtb_email_v4_is_order_family( $email ): bool {
	$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : (string) $email;
	return in_array( $id, [
		'customer_processing_order','customer_completed_order','customer_on_hold_order','customer_cancelled_order',
		'customer_failed_order','customer_refunded_order','customer_invoice','customer_fulfillment_created','customer_fulfillment_updated',
	], true );
}

/**
 * Canonical DTB order progress bar.
 *
 * The component intentionally mirrors the approved mockup: large milestone nodes sit directly on a
 * thick continuous rail, the active milestone receives a double-ring treatment, and each milestone
 * has a primary label plus optional secondary context. Nested tables keep the geometry stable in
 * Gmail/Outlook without flexbox, grid, absolute positioning, or pseudo-elements.
 */
function dtb_email_v4_progress_bar( array $steps, ?int $active_index = null ): string {
	if ( empty( $steps ) ) { return ''; }
	$font  = dtb_email_v3_font();
	$count = count( $steps );
	if ( null === $active_index ) {
		$active_index = 0;
		foreach ( $steps as $i => $step ) {
			if ( in_array( (string) ( $step['state'] ?? '' ), [ 'done', 'active', 'warning' ], true ) ) {
				$active_index = (int) $i;
			}
		}
	}

	$milestone_cells = '';
	$label_cells     = '';
	foreach ( $steps as $i => $step ) {
		$state  = (string) ( $step['state'] ?? 'upcoming' );
		$glyph  = (string) ( $step['glyph'] ?? ( 'done' === $state ? '&#10003;' : (string) ( $i + 1 ) ) );
		$label  = (string) ( $step['label'] ?? '' );
		$detail = (string) ( $step['detail'] ?? '' );

		if ( 'done' === $state ) {
			$node_bg='#1769ff'; $node_border='#1769ff'; $node_fg='#ffffff'; $label_fg='#ffffff'; $detail_fg='#c9d6e7';
		} elseif ( 'active' === $state ) {
			$node_bg='#071827'; $node_border='#7aa8ff'; $node_fg='#ffffff'; $label_fg='#ffffff'; $detail_fg='#d8e1ec';
		} elseif ( 'warning' === $state ) {
			$node_bg='#2b1c08'; $node_border='#d58a20'; $node_fg='#ffd38a'; $label_fg='#ffd38a'; $detail_fg='#e4bf84';
		} else {
			$node_bg='#263746'; $node_border='#697886'; $node_fg='#9aa8b5'; $label_fg='#ffffff'; $detail_fg='#b8c3cf';
		}

		if ( 'active' === $state ) {
			$node = '<table class="dtb-progress-active-ring" role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" width="58" height="58" style="width:58px;height:58px;border:3px solid #1769ff;border-radius:50%;border-collapse:separate;background-color:#071827;"><tr><td align="center" valign="middle"><table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"><tr><td class="dtb-progress-marker dtb-progress-marker-active" width="44" height="44" align="center" valign="middle" bgcolor="'.esc_attr($node_bg).'" style="width:44px;height:44px;border:2px solid '.esc_attr($node_border).';border-radius:50%;background-color:'.esc_attr($node_bg).';color:'.esc_attr($node_fg).';font-family:'.esc_attr($font).';font-size:20px;font-weight:800;line-height:40px;box-sizing:border-box;">'.$glyph.'</td></tr></table></td></tr></table>';
		} else {
			$node = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center"><tr><td class="dtb-progress-marker" width="54" height="54" align="center" valign="middle" bgcolor="'.esc_attr($node_bg).'" style="width:54px;height:54px;border:2px solid '.esc_attr($node_border).';border-radius:50%;background-color:'.esc_attr($node_bg).';color:'.esc_attr($node_fg).';font-family:'.esc_attr($font).';font-size:20px;font-weight:800;line-height:50px;box-sizing:border-box;">'.$glyph.'</td></tr></table>';
		}

		$milestone_cells .= '<td class="dtb-progress-step" width="18%" align="center" valign="middle" style="width:18%;padding:0;">'.$node.'</td>';
		if ( $i < $count - 1 ) {
			$next_state = (string) ( $steps[$i+1]['state'] ?? 'upcoming' );
			$filled = in_array( $state, [ 'done', 'active' ], true ) && in_array( $next_state, [ 'done', 'active' ], true );
			$rail_color = $filled ? '#1769ff' : '#7f8c99';
			$milestone_cells .= '<td class="dtb-progress-connector" width="23%" valign="middle" style="width:23%;padding:0;"><table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;"><tr><td class="dtb-progress-rail-segment" height="8" bgcolor="'.esc_attr($rail_color).'" style="height:8px;font-size:1px;line-height:1px;background-color:'.esc_attr($rail_color).';">&nbsp;</td></tr></table></td>';
		}

		$label_cells .= '<td class="dtb-progress-label-cell" width="'.esc_attr((string)(100/$count)).'%" align="center" valign="top" style="width:'.esc_attr((string)(100/$count)).'%;padding:10px 5px 0;">'
			.'<span class="dtb-progress-label" style="display:block;color:'.esc_attr($label_fg).';font-family:'.esc_attr($font).';font-size:13px;font-weight:750;line-height:17px;">'.esc_html($label).'</span>'
			.(''!==$detail?'<span class="dtb-progress-detail" style="display:block;margin-top:4px;color:'.esc_attr($detail_fg).';font-family:'.esc_attr($font).';font-size:11px;font-weight:400;line-height:15px;">'.esc_html($detail).'</span>':'')
			.'</td>';
	}

	return '<table class="dtb-v4-progress-bar" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">'
		.'<tr><td class="dtb-progress-track-wrap" style="padding:0 8px;">'
		.'<table class="dtb-progress-track" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;table-layout:fixed;border-collapse:collapse;"><tr>'.$milestone_cells.'</tr></table>'
		.'</td></tr>'
		.'<tr><td style="padding:0;">'
		.'<table class="dtb-progress-labels" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;table-layout:fixed;border-collapse:collapse;"><tr>'.$label_cells.'</tr></table>'
		.'</td></tr></table>';
}

function dtb_email_v4_progress_rows( array $steps ): string { return dtb_email_v4_progress_bar( $steps ); }

function dtb_email_v4_order_hero( $order, $email, array $options = [] ): string {
	if ( ! $order instanceof \WC_Order ) { return ''; }
	$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : (string) $email;
	$paid_detail = '';
	if ( $order->get_date_paid() ) {
		$paid_detail = wp_date( 'M j, Y · g:i A', $order->get_date_paid()->getTimestamp() );
	}
	$defaults = [
		'customer_processing_order'=>['title'=>__('Thanks for your order!','drywall-toolbox'),'body'=>__('We’ve received your payment and your order is being prepared. We’ll send you another email as soon as it ships.','drywall-toolbox'),'steps'=>[[ 'label'=>__('Payment received','drywall-toolbox'),'detail'=>$paid_detail,'state'=>'done' ],[ 'label'=>__('Being prepared','drywall-toolbox'),'detail'=>__('We’re packing your order','drywall-toolbox'),'state'=>'active' ],[ 'label'=>__('On the way soon','drywall-toolbox'),'detail'=>__('Tracking details coming soon','drywall-toolbox'),'state'=>'upcoming' ]]],
		'customer_on_hold_order'=>['title'=>__('Your order is on hold.','drywall-toolbox'),'body'=>__('We have your order, but payment or order details still need to be confirmed before preparation can continue.','drywall-toolbox'),'steps'=>[[ 'label'=>__('Payment pending','drywall-toolbox'),'detail'=>__('Awaiting confirmation','drywall-toolbox'),'state'=>'warning' ],[ 'label'=>__('Being prepared','drywall-toolbox'),'detail'=>__('Starts after confirmation','drywall-toolbox'),'state'=>'upcoming' ],[ 'label'=>__('Shipping update','drywall-toolbox'),'detail'=>__('Tracking follows shipment','drywall-toolbox'),'state'=>'upcoming' ]]],
		'customer_completed_order'=>['title'=>__('Your order is complete.','drywall-toolbox'),'body'=>__('This order has been marked complete. Shipment and tracking updates are sent separately when fulfillment information is available.','drywall-toolbox'),'steps'=>[[ 'label'=>__('Payment received','drywall-toolbox'),'detail'=>$paid_detail,'state'=>'done' ],[ 'label'=>__('Prepared','drywall-toolbox'),'detail'=>__('Order preparation complete','drywall-toolbox'),'state'=>'done' ],[ 'label'=>__('Order complete','drywall-toolbox'),'detail'=>__('Order lifecycle complete','drywall-toolbox'),'state'=>'done' ]]],
		'customer_cancelled_order'=>['title'=>__('Your order was cancelled.','drywall-toolbox'),'body'=>__('This order has been cancelled. If you weren’t expecting this update, our support team can help.','drywall-toolbox'),'steps'=>[]],
		'customer_failed_order'=>['title'=>__('Payment needs attention.','drywall-toolbox'),'body'=>__('We couldn’t complete payment for this order. You can securely retry payment below.','drywall-toolbox'),'steps'=>[]],
		'customer_refunded_order'=>['title'=>__('Your refund has been updated.','drywall-toolbox'),'body'=>__('A refund update has been issued for this order. Details are shown below.','drywall-toolbox'),'steps'=>[]],
		'customer_invoice'=>['title'=>__('Your order details.','drywall-toolbox'),'body'=>__('Review the order details below. If payment is still due, use the secure payment action provided in this email.','drywall-toolbox'),'steps'=>[]],
		'customer_fulfillment_created'=>['title'=>__('Your order is on the way!','drywall-toolbox'),'body'=>__('Your shipment has been created from authoritative fulfillment information. Tracking details are included below when available.','drywall-toolbox'),'steps'=>[[ 'label'=>__('Payment received','drywall-toolbox'),'detail'=>$paid_detail,'state'=>'done' ],[ 'label'=>__('Prepared','drywall-toolbox'),'detail'=>__('Packed and ready','drywall-toolbox'),'state'=>'done' ],[ 'label'=>__('On the way','drywall-toolbox'),'detail'=>__('Tracking is available below','drywall-toolbox'),'state'=>'active' ]]],
		'customer_fulfillment_updated'=>['title'=>__('Your shipment has an update.','drywall-toolbox'),'body'=>__('We received new fulfillment information for your order. Updated shipment details are shown below.','drywall-toolbox'),'steps'=>[[ 'label'=>__('Payment received','drywall-toolbox'),'detail'=>$paid_detail,'state'=>'done' ],[ 'label'=>__('Prepared','drywall-toolbox'),'detail'=>__('Packed and ready','drywall-toolbox'),'state'=>'done' ],[ 'label'=>__('Shipment update','drywall-toolbox'),'detail'=>__('Latest tracking shown below','drywall-toolbox'),'state'=>'active' ]]],
	];
	$config = array_merge( $defaults[$id] ?? [ 'title'=>'','body'=>'','steps'=>[] ], $options );
	$font = dtb_email_v3_font();
	$bg = esc_url('https://drywalltoolbox.com/wp/wp-content/uploads/2026/email/email-background-pattern.webp');
	$logo = function_exists('dtb_email_logo_url') ? esc_url(\dtb_email_logo_url()) : '';
	$logo_html = $logo ? '<img class="dtb-v4-hero-logo" src="'.$logo.'" width="190" alt="'.esc_attr(get_bloginfo('name','display')).'" style="display:block;width:190px;max-width:100%;height:auto;border:0;">' : '';
	$progress = dtb_email_v4_progress_bar((array)($config['steps']??[]));
	$progress_row = ''!==$progress ? '<tr><td class="dtb-v4-hero-progress" style="padding:20px 44px 24px;">'.$progress.'</td></tr>' : '<tr><td class="dtb-v4-hero-progress dtb-v4-hero-progress-empty" style="padding:0 48px 20px;font-size:1px;line-height:1px;">&nbsp;</td></tr>';
	return '<table class="dtb-v4-processing-hero dtb-v4-order-hero" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#071827" background="'.$bg.'" style="width:100%;border-collapse:collapse;background-color:#071827;background-image:linear-gradient(to bottom,rgba(2,14,27,.15) 0%,rgba(2,14,27,.18) 55%,rgba(2,12,23,.70) 100%),url('.$bg.');background-position:center top;background-repeat:no-repeat;background-size:100% 100%,cover;">'
	.'<tr><td class="dtb-v4-hero-copy" style="padding:24px 48px 8px;">'
	.'<table class="dtb-v4-hero-brand-row" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr><td valign="top">'.$logo_html.'</td><td class="dtb-v4-hero-tagline" width="190" align="right" valign="top" style="padding-left:16px;color:#e7edf5;font-family:'.esc_attr($font).';font-size:10px;font-weight:650;line-height:15px;letter-spacing:1.35px;text-transform:uppercase;">TOOLS YOU TRUST.<br>RESULTS THAT LAST.</td></tr></table>'
	.'<p class="dtb-v4-order-number" style="margin:24px 0 8px;color:#4f8cff;font-family:'.esc_attr($font).';font-size:11px;font-weight:800;line-height:16px;letter-spacing:.45px;text-transform:uppercase;">'.esc_html(sprintf(__('Order #%s','drywall-toolbox'),$order->get_order_number())).'</p>'
	.'<h1 class="dtb-v4-hero-title" style="max-width:430px;margin:0 0 10px;color:#fff;font-family:'.esc_attr($font).';font-size:39px;font-weight:800;line-height:42px;letter-spacing:-1px;">'.esc_html((string)$config['title']).'</h1>'
	.'<p class="dtb-v4-hero-body" style="max-width:455px;margin:0;color:#fff;font-family:'.esc_attr($font).';font-size:15px;font-weight:450;line-height:22px;">'.esc_html((string)$config['body']).'</p></td></tr>'.$progress_row.'</table>';
}

function dtb_email_v3_processing_progress_rows(): string { return dtb_email_v4_progress_bar([[ 'label'=>__('Payment received','drywall-toolbox'),'state'=>'done' ],[ 'label'=>__('Being prepared','drywall-toolbox'),'state'=>'active' ],[ 'label'=>__('On the way soon','drywall-toolbox'),'state'=>'upcoming' ]]); }
function dtb_email_v3_processing_hero( $order ): string { return dtb_email_v4_order_hero($order,'customer_processing_order'); }
function dtb_email_v3_processing_progress(): string { return '<table role="presentation" width="100%"><tr><td>'.dtb_email_v3_processing_progress_rows().'</td></tr></table>'; }

function dtb_email_v3_card_open(string $title='',string $meta=''):string{
	$font=dtb_email_v3_font();$title=sanitize_text_field($title);$meta=sanitize_text_field($meta);$header='';
	if(''!==$title){$header='<table class="dtb-v3-card-heading" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td style="padding:0 0 12px;color:#071b45;font-family:'.esc_attr($font).';font-size:18px;font-weight:750;line-height:24px;">'.esc_html($title).'</td>'.(''!==$meta?'<td align="right" style="padding:0 0 12px;color:#78859d;font-family:'.esc_attr($font).';font-size:10px;line-height:16px;">'.esc_html($meta).'</td>':'').'</tr></table>';}
	return '<table class="dtb-v3-card" role="presentation" border="0" cellpadding="0" cellspacing="0" width="87%" align="center" bgcolor="#fff" style="width:87%;max-width:592px;margin:0 auto 22px;border-collapse:collapse;background-color:#fff;"><tr><td class="dtb-v3-card-cell" style="padding:0;">'.$header;
}
function dtb_email_v3_card_close():string{return '</td></tr></table>';}
function dtb_email_v3_primary_button(string $url,string $label):string{
	if ( '' === trim( $url ) || '' === trim( $label ) ) { return ''; }
	$font = dtb_email_v3_font();
	return '<table class="dtb-v4-action" role="presentation" border="0" cellpadding="0" cellspacing="0" width="87%" align="center" style="width:87%;max-width:592px;margin:14px auto 22px;border-collapse:collapse;"><tr><td align="center" bgcolor="#1769ff" style="background-color:#1769ff;border-radius:6px;padding:0;">'
		. '<a href="'.esc_url($url).'" style="display:block;padding:14px 20px;font-family:'.esc_attr($font).';font-size:14px;font-weight:700;line-height:20px;text-align:center;color:#ffffff!important;text-decoration:none;">'.esc_html($label).'</a>'
		. '</td></tr></table>';
}
function dtb_email_v3_support_panel(string $heading='',string $copy=''):string{
	$font=dtb_email_v3_font();$url=function_exists('dtb_email_support_url')?\dtb_email_support_url():home_url('/contact/');
	$heading=''!==$heading?$heading:__('Questions about your order?','drywall-toolbox');$copy=''!==$copy?$copy:__('Our support team is here to help.','drywall-toolbox');
	return '<table class="dtb-v4-support" role="presentation" border="0" cellpadding="0" cellspacing="0" width="87%" align="center" style="width:87%;max-width:592px;margin:16px auto 20px;"><tr><td align="center" style="padding:0 20px;color:#071b45;font-family:'.esc_attr($font).';"><strong style="display:block;margin:0 0 5px;font-size:16px;font-weight:750;line-height:22px;">'.esc_html($heading).'</strong><span style="display:block;color:#65738f;font-size:13px;font-weight:400;line-height:20px;">'.esc_html($copy).' <a href="'.esc_url($url).'" style="color:#1769ff;font-weight:600;text-decoration:none;">'.esc_html__('Contact support','drywall-toolbox').'</a>.</span></td></tr></table>';
}

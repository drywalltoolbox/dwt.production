<?php
/**
 * Drywall Toolbox branded invoice template for WP Overnight PDF Invoices & Packing Slips.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/template-functions.php';

$order         = $this->order ?? null;
$document_type = dtb_pdf_document_type( $this, 'invoice' );
$invoice_no    = dtb_pdf_document_number( $this, $order, 'invoice_number' );
$order_no      = dtb_pdf_document_number( $this, $order, 'order_number' );
$invoice_date  = dtb_pdf_document_date( $this, 'invoice_date' );
$order_date    = dtb_pdf_document_date( $this, 'order_date' );
$payment       = dtb_pdf_clean_text( dtb_pdf_capture_document_method( $this, 'payment_method' ) );
$billing       = dtb_pdf_address_html( $this, $order, 'billing' );
$shipping      = dtb_pdf_address_html( $this, $order, 'shipping' );
$contact       = dtb_pdf_contact_lines( $order );
$items         = method_exists( $this, 'get_order_items' ) ? (array) $this->get_order_items() : [];
$totals        = method_exists( $this, 'get_woocommerce_totals' ) ? (array) $this->get_woocommerce_totals() : [];

if ( '' === $payment && $order instanceof WC_Order ) {
	$payment = dtb_pdf_clean_text( $order->get_payment_method_title() );
}

if ( '' === $order_no && $order instanceof WC_Order ) {
	$order_no = (string) $order->get_order_number();
}

$status_label = 'Invoice';
$status_class = 'dtb-pdf-status--open';
if ( $order instanceof WC_Order ) {
	if ( 'refunded' === $order->get_status() ) {
		$status_label = 'Refunded';
		$status_class = 'dtb-pdf-status--muted';
	} elseif ( $order->is_paid() ) {
		$status_label = 'Paid';
		$status_class = 'dtb-pdf-status--paid';
	} elseif ( $order->needs_payment() ) {
		$status_label = 'Payment due';
		$status_class = 'dtb-pdf-status--due';
	}
}

do_action( 'wpo_wcpdf_before_document', $document_type, $order );
?>

<div class="dtb-pdf dtb-pdf--invoice">
	<header class="dtb-pdf-hero">
		<table class="dtb-pdf-hero__table" role="presentation">
			<tr>
				<td class="dtb-pdf-brand-cell">
					<div class="dtb-pdf-brand">
						<?php if ( method_exists( $this, 'has_header_logo' ) && $this->has_header_logo() ) : ?>
							<div class="dtb-pdf-brand__logo"><?php $this->header_logo(); ?></div>
						<?php else : ?>
							<div class="dtb-pdf-brand__wordmark"><span>Drywall</span> Toolbox</div>
						<?php endif; ?>
						<div class="dtb-pdf-brand__meta">Professional drywall tools, parts, and repair service</div>
						<div class="dtb-pdf-brand__url"><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></div>
					</div>
				</td>
				<td class="dtb-pdf-title-cell">
					<div class="dtb-pdf-document-title">Invoice</div>
					<div class="dtb-pdf-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></div>
					<div class="dtb-pdf-document-number">#<?php echo esc_html( $invoice_no ?: $order_no ); ?></div>
				</td>
			</tr>
		</table>
	</header>

	<?php do_action( 'wpo_wcpdf_after_document_label', $document_type, $order ); ?>

	<section class="dtb-pdf-info-grid">
		<table class="dtb-pdf-info-grid__table" role="presentation">
			<tr>
				<td class="dtb-pdf-card dtb-pdf-card--address">
					<div class="dtb-pdf-card__label">Bill To</div>
					<div class="dtb-pdf-address"><?php echo $billing; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php if ( '' !== $contact ) : ?>
						<div class="dtb-pdf-contact"><?php echo $contact; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				</td>
				<td class="dtb-pdf-card dtb-pdf-card--address">
					<div class="dtb-pdf-card__label">Ship To</div>
					<div class="dtb-pdf-address"><?php echo $shipping; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</td>
				<td class="dtb-pdf-card dtb-pdf-card--details">
					<div class="dtb-pdf-card__label">Order Details</div>
					<table class="dtb-pdf-meta-table" role="presentation">
						<?php if ( '' !== $invoice_no ) : ?><tr><th>Invoice #</th><td><?php echo esc_html( $invoice_no ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $invoice_date ) : ?><tr><th>Invoice date</th><td><?php echo esc_html( $invoice_date ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $order_no ) : ?><tr><th>Order #</th><td><?php echo esc_html( $order_no ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $order_date ) : ?><tr><th>Order date</th><td><?php echo esc_html( $order_date ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $payment ) : ?><tr><th>Payment</th><td><?php echo esc_html( $payment ); ?></td></tr><?php endif; ?>
					</table>
				</td>
			</tr>
		</table>
	</section>

	<?php do_action( 'wpo_wcpdf_before_order_details', $document_type, $order ); ?>

	<section class="dtb-pdf-section dtb-pdf-section--items">
		<div class="dtb-pdf-section__heading">Purchased items</div>
		<table class="dtb-pdf-items dtb-pdf-items--invoice">
			<thead>
				<tr>
					<th class="dtb-pdf-items__product">Product</th>
					<th class="dtb-pdf-items__qty">Qty</th>
					<th class="dtb-pdf-items__price">Line total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item_id => $item ) : ?>
					<?php
					$product_name = wp_kses_post( $item['name'] ?? '' );
					$quantity     = wp_kses_post( $item['quantity'] ?? '' );
					$line_total   = wp_kses_post( $item['order_price'] ?? $item['price'] ?? '' );
					$identity     = dtb_pdf_item_identity( $order, $item_id, (array) $item );
					$item_meta    = dtb_pdf_item_meta_html( (array) $item );
					?>
					<tr class="dtb-pdf-item-row">
						<td class="dtb-pdf-items__product">
							<div class="dtb-pdf-product-name"><?php echo $product_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php if ( ! empty( $identity ) ) : ?>
								<div class="dtb-pdf-product-meta"><?php echo esc_html( implode( ' · ', $identity ) ); ?></div>
							<?php endif; ?>
							<?php if ( '' !== trim( wp_strip_all_tags( $item_meta ) ) ) : ?>
								<div class="dtb-pdf-product-variation"><?php echo $item_meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<?php endif; ?>
							<?php do_action( 'wpo_wcpdf_after_item_meta', $document_type, $item, $order ); ?>
						</td>
						<td class="dtb-pdf-items__qty"><?php echo $quantity; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="dtb-pdf-items__price"><?php echo $line_total; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<table class="dtb-pdf-bottom" role="presentation">
		<tr>
			<td class="dtb-pdf-bottom__note">
				<div class="dtb-pdf-thank-you">Thank you for choosing Drywall Toolbox.</div>
				<div class="dtb-pdf-muted">For order support, tracking, returns, or repairs, visit <?php echo esc_html( dtb_pdf_support_url( $order ) ); ?>.</div>
			</td>
			<td class="dtb-pdf-bottom__totals">
				<table class="dtb-pdf-totals">
					<?php foreach ( $totals as $key => $total ) : ?>
						<?php
						$label = wp_kses_post( $total['label'] ?? '' );
						$value = wp_kses_post( $total['value'] ?? '' );
						$class = false !== stripos( (string) $key, 'total' ) ? ' dtb-pdf-total-row--final' : '';
						?>
						<tr class="dtb-pdf-total-row<?php echo esc_attr( $class ); ?>">
							<th><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
							<td><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		</tr>
	</table>

	<?php do_action( 'wpo_wcpdf_after_order_details', $document_type, $order ); ?>

	<footer class="dtb-pdf-footer">
		<div>Drywall Toolbox · Professional drywall tools, parts, and repair service</div>
		<div><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></div>
	</footer>
</div>

<?php do_action( 'wpo_wcpdf_after_document', $document_type, $order ); ?>
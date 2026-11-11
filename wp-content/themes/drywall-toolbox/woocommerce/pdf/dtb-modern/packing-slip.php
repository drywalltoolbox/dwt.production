<?php
/**
 * Drywall Toolbox branded packing slip template for WP Overnight PDF Invoices & Packing Slips.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/template-functions.php';

$order           = $this->order ?? null;
$document_type   = dtb_pdf_document_type( $this, 'packing-slip' );
$order_no        = dtb_pdf_document_number( $this, $order, 'order_number' );
$order_date      = dtb_pdf_document_date( $this, 'order_date' );
$shipping_method = dtb_pdf_clean_text( dtb_pdf_capture_document_method( $this, 'shipping_method' ) );
$shipping        = dtb_pdf_address_html( $this, $order, 'shipping' );
$billing         = dtb_pdf_address_html( $this, $order, 'billing' );
$items           = method_exists( $this, 'get_order_items' ) ? (array) $this->get_order_items() : [];
$customer_note   = dtb_pdf_formatted_order_note( $order );

if ( '' === $shipping_method && $order instanceof WC_Order ) {
	$shipping_method = dtb_pdf_clean_text( $order->get_shipping_method() );
}
if ( '' === wp_strip_all_tags( $shipping ) ) {
	$shipping = $billing;
}
if ( '' === $order_no && $order instanceof WC_Order ) {
	$order_no = (string) $order->get_order_number();
}

do_action( 'wpo_wcpdf_before_document', $document_type, $order );
?>

<div class="dtb-pdf dtb-pdf--packing-slip">
	<header class="dtb-pdf-hero dtb-pdf-hero--packing">
		<table class="dtb-pdf-hero__table" role="presentation">
			<tr>
				<td class="dtb-pdf-brand-cell">
					<div class="dtb-pdf-brand">
						<?php if ( method_exists( $this, 'has_header_logo' ) && $this->has_header_logo() ) : ?>
							<div class="dtb-pdf-brand__logo"><?php $this->header_logo(); ?></div>
						<?php else : ?>
							<div class="dtb-pdf-brand__wordmark"><span>Drywall</span> Toolbox</div>
						<?php endif; ?>
						<div class="dtb-pdf-brand__meta">Pack accurately. Ship clean. Support contractors.</div>
						<div class="dtb-pdf-brand__url"><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></div>
					</div>
				</td>
				<td class="dtb-pdf-title-cell">
					<div class="dtb-pdf-document-title">Packing Slip</div>
					<div class="dtb-pdf-status dtb-pdf-status--fulfillment">Fulfillment</div>
					<div class="dtb-pdf-document-number">Order #<?php echo esc_html( $order_no ); ?></div>
				</td>
			</tr>
		</table>
	</header>

	<?php do_action( 'wpo_wcpdf_after_document_label', $document_type, $order ); ?>

	<section class="dtb-pdf-ship-panel">
		<table class="dtb-pdf-ship-panel__table" role="presentation">
			<tr>
				<td class="dtb-pdf-ship-panel__address">
					<div class="dtb-pdf-card__label">Ship To</div>
					<div class="dtb-pdf-ship-to"><?php echo $shipping; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</td>
				<td class="dtb-pdf-ship-panel__meta">
					<table class="dtb-pdf-meta-table dtb-pdf-meta-table--packing" role="presentation">
						<?php if ( '' !== $order_no ) : ?><tr><th>Order #</th><td><?php echo esc_html( $order_no ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $order_date ) : ?><tr><th>Order date</th><td><?php echo esc_html( $order_date ); ?></td></tr><?php endif; ?>
						<?php if ( '' !== $shipping_method ) : ?><tr><th>Shipping</th><td><?php echo esc_html( $shipping_method ); ?></td></tr><?php endif; ?>
					</table>
					<div class="dtb-pdf-pack-status">
						<span>Picked by</span><span class="dtb-pdf-write-line"></span>
						<span>Packed by</span><span class="dtb-pdf-write-line"></span>
					</div>
				</td>
			</tr>
		</table>
	</section>

	<?php do_action( 'wpo_wcpdf_before_order_details', $document_type, $order ); ?>

	<section class="dtb-pdf-section dtb-pdf-section--items">
		<div class="dtb-pdf-section__heading">Pick / pack list</div>
		<table class="dtb-pdf-items dtb-pdf-items--packing">
			<thead>
				<tr>
					<th class="dtb-pdf-items__product">Product</th>
					<th class="dtb-pdf-items__qty">Qty ordered</th>
					<th class="dtb-pdf-items__packed">Qty packed</th>
					<th class="dtb-pdf-items__check">Check</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item_id => $item ) : ?>
					<?php
					$product_name = wp_kses_post( $item['name'] ?? '' );
					$quantity     = wp_kses_post( $item['quantity'] ?? '' );
					$identity     = dtb_pdf_item_identity( $order, $item_id, (array) $item );
					$item_meta    = dtb_pdf_item_meta_html( (array) $item );
					$thumbnail    = dtb_pdf_item_thumbnail_html( (array) $item );
					?>
					<tr class="dtb-pdf-item-row dtb-pdf-item-row--packing">
						<td class="dtb-pdf-items__product">
							<table class="dtb-pdf-product-line" role="presentation">
								<tr>
									<?php if ( '' !== $thumbnail ) : ?>
										<td class="dtb-pdf-product-line__thumb"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<?php endif; ?>
									<td class="dtb-pdf-product-line__body">
										<div class="dtb-pdf-product-name"><?php echo $product_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
										<?php if ( ! empty( $identity ) ) : ?>
											<div class="dtb-pdf-product-meta"><?php echo esc_html( implode( ' · ', $identity ) ); ?></div>
										<?php endif; ?>
										<?php if ( '' !== trim( wp_strip_all_tags( $item_meta ) ) ) : ?>
											<div class="dtb-pdf-product-variation"><?php echo $item_meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
										<?php endif; ?>
										<?php do_action( 'wpo_wcpdf_after_item_meta', $document_type, $item, $order ); ?>
									</td>
								</tr>
							</table>
						</td>
						<td class="dtb-pdf-items__qty"><?php echo $quantity; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="dtb-pdf-items__packed"><span class="dtb-pdf-pack-blank"></span></td>
						<td class="dtb-pdf-items__check"><span class="dtb-pdf-checkbox"></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="dtb-pdf-pack-notes">
		<table role="presentation">
			<tr>
				<td>
					<div class="dtb-pdf-section__heading dtb-pdf-section__heading--small">Packing controls</div>
					<ul>
						<li>Verify SKU, quantity, and visible condition before sealing.</li>
						<li>Include all kit components, accessories, and repair/service inserts when applicable.</li>
						<li>Use order #<?php echo esc_html( $order_no ); ?> for support or carrier exception handling.</li>
					</ul>
				</td>
				<td>
					<div class="dtb-pdf-section__heading dtb-pdf-section__heading--small">Customer note</div>
					<div class="dtb-pdf-note-box"><?php echo '' !== trim( $customer_note ) ? $customer_note : 'No customer note.'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</td>
			</tr>
		</table>
	</section>

	<?php do_action( 'wpo_wcpdf_after_order_details', $document_type, $order ); ?>

	<footer class="dtb-pdf-footer">
		<div>Drywall Toolbox · Professional drywall tools, parts, and repair service</div>
		<div>Tracking / support: <?php echo esc_html( dtb_pdf_support_url( $order ) ); ?></div>
	</footer>
</div>

<?php do_action( 'wpo_wcpdf_after_document', $document_type, $order ); ?>
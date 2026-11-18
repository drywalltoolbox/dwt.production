<?php
/**
 * DTB branded email order item rows.
 *
 * Traced against WooCommerce core emails/email-order-items.php v10.8.0
 * (wp-content/plugins/woocommerce/templates/emails/email-order-items.php).
 * DTB customization: presentation only. Every order-item filter
 * (woocommerce_order_item_visible, woocommerce_order_item_class,
 * woocommerce_order_item_thumbnail, woocommerce_order_item_name,
 * woocommerce_order_item_meta_start/end, woocommerce_email_order_item_quantity)
 * fires with the exact same name/argument order as upstream, satisfying
 * "preserve order-item filters."
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

foreach ( $items as $item_id => $item ) :
	$product       = $item->get_product();
	$sku           = '';
	$purchase_note = '';

	/**
	 * Email Order Item Visibility hook.
	 *
	 * @param bool                  $visible Whether the item is visible in the email.
	 * @param WC_Order_Item_Product $item    The order item object.
	 */
	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		continue;
	}

	if ( is_object( $product ) ) {
		$sku           = $product->get_sku();
		$purchase_note = $product->get_purchase_note();
	}

	$order_item_class = apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order );
	$thumbnail = '';
	if ( $show_image ) {
		$thumbnail = function_exists( 'dtb_email_render_item_thumbnail' ) ? dtb_email_render_item_thumbnail( $item ) : '';
		$thumbnail = (string) apply_filters( 'woocommerce_order_item_thumbnail', $thumbnail, $item );
	}

	?>
	<tr class="<?php echo esc_attr( $order_item_class ); ?>">
		<td class="td font-family text-align-left" width="68%" style="width:68%;padding:18px 10px 18px 0;vertical-align:middle;word-wrap:break-word;overflow-wrap:break-word;text-align:left;">
			<table class="order-item-data" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">
				<tr>
					<?php if ( $show_image && '' !== trim( $thumbnail ) ) : ?>
						<td class="dtb-order-item-image" width="82" valign="middle" style="width:82px;padding:0 14px 0 0;">
							<?php echo wp_kses_post( $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					<?php endif; ?>
					<td valign="middle" style="padding:0;text-align:left;overflow-wrap:anywhere;">
						<?php
						$item_name = function_exists( 'dtb_email_render_item_name' ) ? dtb_email_render_item_name( $item->get_name() ) : esc_html( $item->get_name() );
						/**
						 * Order Item Name hook.
						 *
						 * @param string                $item_name The item name HTML.
						 * @param WC_Order_Item_Product $item      The item being displayed.
						 */
						echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item_name, $item, false ) );

						if ( $show_sku && $sku ) {
							echo '<span class="dtb-order-item-sku" style="display:block;margin-top:4px;color:#667085;font-size:12px;line-height:17px;overflow-wrap:anywhere;word-break:break-word;">' . esc_html__( 'SKU:', 'drywall-toolbox' ) . ' ' . esc_html( $sku ) . '</span>';
						}

						/**
						 * Allow other plugins to add additional product information.
						 *
						 * @param int                   $item_id    The item ID.
						 * @param WC_Order_Item_Product $item       The item object.
						 * @param WC_Order              $order      The order object.
						 * @param bool                  $plain_text Whether the email is plain text or not.
						 */
						do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );

						$item_meta = wc_display_item_meta(
							$item,
							[
								'before'       => '',
								'after'        => '',
								'separator'    => '<br>',
								'echo'         => false,
								'label_before' => '<span>',
								'label_after'  => ':</span> ',
							]
						);
						echo '<div class="email-order-item-meta" style="margin-top:4px;color:#667085;font-size:12px;line-height:145%;">';
						echo wp_kses(
							$item_meta,
							[
								'br'   => [],
								'span' => [],
								'a'    => [
									'href'   => true,
									'target' => true,
									'rel'    => true,
									'title'  => true,
								],
							]
						);
						echo '</div>';

						/**
						 * Allow other plugins to add additional product information.
						 *
						 * @param int                   $item_id    The item ID.
						 * @param WC_Order_Item_Product $item       The item object.
						 * @param WC_Order              $order      The order object.
						 * @param bool                  $plain_text Whether the email is plain text or not.
						 */
						do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
						?>
					</td>
				</tr>
			</table>
		</td>
		<td class="td font-family text-align-right" width="10%" style="width:10%;padding:18px 5px;vertical-align:middle;text-align:center;white-space:nowrap;">
			<?php
			$qty          = $item->get_quantity();
			$refunded_qty = $order->get_qty_refunded_for_item( $item_id );
			$qty_display  = $refunded_qty
				? '<del>' . esc_html( $qty ) . '</del> <ins>' . esc_html( $qty - ( $refunded_qty * -1 ) ) . '</ins>'
				: esc_html( $qty );
			/**
			 * Email Order Item Quantity hook.
			 */
			$quantity = apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item );
			if ( '' !== $quantity ) {
				echo '<span style="white-space:nowrap;"><span class="dtb-mobile-label">' . esc_html__( 'Qty:', 'drywall-toolbox' ) . ' </span>' . wp_kses_post( $quantity ) . '</span>';
			}
			?>
		</td>
		<td class="td font-family text-align-right" width="22%" style="width:22%;padding:18px 0 18px 6px;vertical-align:middle;text-align:right;white-space:nowrap;">
			<span class="dtb-mobile-label"><?php esc_html_e( 'Price:', 'drywall-toolbox' ); ?> </span><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?>
		</td>
	</tr>
	<?php if ( $show_purchase_note && $purchase_note ) : ?>
		<tr>
			<td colspan="3" class="font-family text-align-left" style="vertical-align:middle;">
				<?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?>
			</td>
		</tr>
	<?php endif; ?>
<?php endforeach; ?>

<?php
/**
 * DTB branded email fulfillment item rows.
 *
 * Traced against WooCommerce core emails/email-fulfillment-items.php v10.8.0
 * (wp-content/plugins/woocommerce/templates/emails/email-fulfillment-items.php).
 * DTB customization: presentation only. Every order-item filter fires with
 * the exact same name/argument order as upstream (see email-order-items.php).
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

foreach ( $items as $item_id => $item ) :
	$product       = $item->item->get_product();
	$sku           = '';
	$purchase_note = '';

	/**
	 * Email Order Item Visibility hook.
	 *
	 * @param bool                  $visible Whether the item is visible in the email.
	 * @param WC_Order_Item_Product $item    The order item object.
	 */
	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item->item ) ) {
		continue;
	}

	if ( is_object( $product ) ) {
		$sku           = $product->get_sku();
		$purchase_note = $product->get_purchase_note();
	}

	$order_item_class = apply_filters( 'woocommerce_order_item_class', 'order_item', $item->item, $order );
	?>
	<tr class="<?php echo esc_attr( $order_item_class ); ?>">
		<td class="td font-family text-align-left" style="vertical-align:middle;word-wrap:break-word;">
			<table class="order-item-data" role="presentation" width="100%">
				<tr>
					<?php if ( $show_image ) : ?>
						<td class="dtb-order-item-image" width="82" valign="middle" style="padding:0 14px 0 0;">
							<?php
							$thumbnail = function_exists( 'dtb_email_render_item_thumbnail' ) ? dtb_email_render_item_thumbnail( $item->item ) : '';
							echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $thumbnail, $item->item ) );
							?>
						</td>
					<?php endif; ?>
					<td valign="middle">
						<?php
						$item_name = function_exists( 'dtb_email_render_item_name' ) ? dtb_email_render_item_name( $item->item->get_name() ) : esc_html( $item->item->get_name() );
						echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item_name, $item->item, false ) );

						if ( $show_sku && $sku ) {
							echo '<span class="dtb-order-item-sku" style="display:block;margin-top:4px;color:#667085;font-size:12px;line-height:17px;overflow-wrap:anywhere;word-break:break-word;">' . esc_html__( 'SKU:', 'drywall-toolbox' ) . ' ' . esc_html( $sku ) . '</span>';
						}

						do_action( 'woocommerce_order_item_meta_start', $item_id, $item->item, $order, $plain_text );

						$item_meta = wc_display_item_meta(
							$item->item,
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

						do_action( 'woocommerce_order_item_meta_end', $item_id, $item->item, $order, $plain_text );
						?>
					</td>
				</tr>
			</table>
		</td>
		<td class="td font-family text-align-right" style="vertical-align:middle;">
			<?php
			$qty_display = esc_html( $item->qty );
			$quantity    = apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item->item );
			if ( '' !== $quantity ) {
				echo '<span style="white-space:nowrap;"><span class="dtb-mobile-label">' . esc_html__( 'Qty:', 'drywall-toolbox' ) . ' </span>' . wp_kses_post( $quantity ) . '</span>';
			}
			?>
		</td>
		<td class="td font-family text-align-right" style="vertical-align:middle;">
			<span class="dtb-mobile-label"><?php esc_html_e( 'Price:', 'drywall-toolbox' ); ?> </span><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item->item ) ); ?>
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

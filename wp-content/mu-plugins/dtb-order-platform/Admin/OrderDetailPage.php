<?php
/**
 * DTB Order Detail Page — renders the DTB Operator Actions metabox.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_admin_metabox_actions( $post_or_order ): void {
	$order_id = $post_or_order instanceof WC_Order ? (int) $post_or_order->get_id() : (int) $post_or_order->ID;
	$nonce    = wp_create_nonce( 'dtb_order_admin_' . $order_id );

	$actions = [
		'retry_veeqo'       => __( 'Retry Veeqo Sync', 'drywall-toolbox' ),
		'retry_quickbooks'  => __( 'Retry QuickBooks Sync', 'drywall-toolbox' ),
		'refresh_tracking'  => __( 'Refresh Tracking Projection', 'drywall-toolbox' ),
		'resend_confirm'    => __( 'Resend Order Confirmation Email', 'drywall-toolbox' ),
		'resend_shipped'    => __( 'Resend Shipping Email', 'drywall-toolbox' ),
		'recalc_rewards'    => __( 'Recalculate Rewards', 'drywall-toolbox' ),
	];

	echo '<style>.dtb-op-btn{display:block;width:100%;margin:4px 0;padding:5px 8px;font-size:12px;cursor:pointer;}</style>';

	foreach ( $actions as $action => $label ) {
		echo '<button type="button" class="button dtb-op-btn dtb-op-action"'
			. ' data-action="' . esc_attr( $action ) . '"'
			. ' data-order-id="' . esc_attr( (string) $order_id ) . '"'
			. ' data-nonce="' . esc_attr( $nonce ) . '">'
			. esc_html( $label )
			. '</button>';
	}

	echo '<div id="dtb-op-result-' . esc_attr( (string) $order_id ) . '" style="margin-top:8px;font-size:12px;"></div>';

	// Inline JS for AJAX actions.
	?>
	<script>
	(function(){
		document.querySelectorAll('.dtb-op-action').forEach(function(btn){
			btn.addEventListener('click', function(){
				var action  = btn.dataset.action;
				var orderId = btn.dataset.orderId;
				var nonce   = btn.dataset.nonce;
				var result  = document.getElementById('dtb-op-result-' + orderId);
				btn.disabled = true;
				result.textContent = '<?php echo esc_js( __( 'Working…', 'drywall-toolbox' ) ); ?>';

				var formData = new FormData();
				formData.append('action', 'dtb_order_operator_action');
				formData.append('dtb_action', action);
				formData.append('order_id', orderId);
				formData.append('nonce', nonce);

				fetch(window.ajaxurl || '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					body:   formData,
					credentials: 'same-origin',
				})
				.then(function(r){ return r.json(); })
				.then(function(d){
					result.style.color = d.success ? '#228B22' : '#c00';
					result.textContent = d.data ? d.data.message : '<?php echo esc_js( __( 'Done.', 'drywall-toolbox' ) ); ?>';
				})
				.catch(function(){
					result.style.color = '#c00';
					result.textContent = '<?php echo esc_js( __( 'Request failed.', 'drywall-toolbox' ) ); ?>';
				})
				.finally(function(){
					btn.disabled = false;
				});
			});
		});
	})();
	</script>
	<?php
}

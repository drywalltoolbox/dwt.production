<?php
/**
 * Catalog Health rendering helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the issues table HTML.
 *
 * @param array[] $issues Issue records.
 * @return string
 */
function dtb_catalog_health_render_results( array $issues ): string {
	if ( empty( $issues ) ) {
		return '<div style="padding:16px;background:#edfcf2;border:1px solid #86efac;border-radius:6px;color:#14532d;">✅ <strong>No issues found.</strong> All scanned variable products passed the health check.</div>';
	}

	$error_count   = count( array_filter( $issues, static fn( $issue ) => 'error' === $issue['severity'] ) );
	$warning_count = count( array_filter( $issues, static fn( $issue ) => 'warning' === $issue['severity'] ) );

	$badge = static fn( string $severity ) => match ( $severity ) {
		'error'   => '<span style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:2px 8px;border-radius:4px;font-size:0.7rem;font-weight:700;">ERROR</span>',
		'warning' => '<span style="background:#fffbeb;color:#d97706;border:1px solid #fcd34d;padding:2px 8px;border-radius:4px;font-size:0.7rem;font-weight:700;">WARNING</span>',
		default   => '<span style="background:#f0f9ff;color:#0284c7;border:1px solid #7dd3fc;padding:2px 8px;border-radius:4px;font-size:0.7rem;font-weight:700;">INFO</span>',
	};

	ob_start();
	?>
	<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
		Found <strong><?php echo esc_html( count( $issues ) ); ?> issue(s)</strong>:
		<?php if ( $error_count ) : ?>
			<strong style="color:#dc2626;"><?php echo esc_html( $error_count ); ?> error(s)</strong>
		<?php endif; ?>
		<?php if ( $warning_count ) : ?>
			<strong style="color:#d97706;"><?php echo esc_html( $warning_count ); ?> warning(s)</strong>
		<?php endif; ?>
	</div>

	<table class="wp-list-table widefat fixed striped" style="margin-top:0;">
		<thead>
			<tr>
				<th style="width:40px;">ID</th>
				<th>Product</th>
				<th style="width:110px;">SKU</th>
				<th style="width:80px;">Severity</th>
				<th style="width:200px;">Code</th>
				<th>Message</th>
				<th style="width:60px;">Action</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $issues as $issue ) : ?>
				<tr>
					<td><?php echo esc_html( $issue['product_id'] ); ?></td>
					<td><strong><?php echo esc_html( $issue['product_name'] ); ?></strong></td>
					<td><code><?php echo esc_html( $issue['sku'] ); ?></code></td>
					<td><?php echo wp_kses_post( $badge( $issue['severity'] ) ); ?></td>
					<td><code style="font-size:0.72rem;"><?php echo esc_html( $issue['code'] ); ?></code></td>
					<td><?php echo esc_html( $issue['message'] ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( (int) $issue['product_id'] ) ); ?>" class="button button-small" target="_blank" rel="noopener">Edit</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return ob_get_clean();
}

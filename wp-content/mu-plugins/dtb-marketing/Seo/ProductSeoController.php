<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB SEO — WooCommerce Product SEO Meta Fields
 *
 * Registers a meta box on the WooCommerce product editor that exposes five
 * SEO fields:
 *   _dtb_seo_title        — custom page title (max 60 chars)
 *   _dtb_seo_description  — custom meta description (max 160 chars)
 *   _dtb_seo_focus_kw     — focus keyword (informational, not exposed in output)
 *   _dtb_seo_canonical    — canonical URL override
 *   _dtb_seo_noindex      — noindex flag (checkbox)
 *
 * All five fields are exposed via the WooCommerce / WordPress REST API so the
 * React frontend can read them from product.meta_data[] and apply SEO overrides
 * without an extra API call.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ─── Register meta for REST API exposure ──────────────────────────────────────

add_action( 'init', 'dtb_seo_register_meta' );
add_action( 'wp_head', 'dtb_seo_output_head_tags', 1 );

function dtb_seo_register_meta(): void {
	$shared = [
		'object_subtype' => 'product',
		'single'         => true,
		'show_in_rest'   => true,
	];

	register_post_meta( 'product', '_dtb_seo_title', array_merge( $shared, [
		'type'              => 'string',
		'description'       => 'Custom SEO title (max 60 characters)',
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => 'dtb_seo_meta_auth',
	] ) );

	register_post_meta( 'product', '_dtb_seo_description', array_merge( $shared, [
		'type'              => 'string',
		'description'       => 'Custom SEO meta description (max 160 characters)',
		'sanitize_callback' => 'sanitize_textarea_field',
		'auth_callback'     => 'dtb_seo_meta_auth',
	] ) );

	register_post_meta( 'product', '_dtb_seo_focus_kw', array_merge( $shared, [
		'type'              => 'string',
		'description'       => 'Focus keyword for this product',
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => 'dtb_seo_meta_auth',
	] ) );

	register_post_meta( 'product', '_dtb_seo_canonical', array_merge( $shared, [
		'type'              => 'string',
		'description'       => 'Canonical URL override',
		'sanitize_callback' => 'esc_url_raw',
		'auth_callback'     => 'dtb_seo_meta_auth',
	] ) );

	register_post_meta( 'product', '_dtb_seo_noindex', array_merge( $shared, [
		'type'              => 'boolean',
		'description'       => 'Prevent indexing of this product page',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'auth_callback'     => 'dtb_seo_meta_auth',
	] ) );
}

/**
 * Only administrators and shop managers may write these meta fields.
 *
 * @return bool
 */
function dtb_seo_meta_auth(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

// ─── Frontend head tag output ─────────────────────────────────────────────────

/**
 * Output SEO meta tags into <head> for singular product pages.
 *
 * Hooked to wp_head at priority 1 so it fires before theme meta tags.
 */
function dtb_seo_output_head_tags(): void {
	if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
		return;
	}

	$post_id     = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$title       = (string) get_post_meta( $post_id, '_dtb_seo_title',       true );
	$description = (string) get_post_meta( $post_id, '_dtb_seo_description', true );
	$canonical   = (string) get_post_meta( $post_id, '_dtb_seo_canonical',   true );
	$noindex     = (bool)   get_post_meta( $post_id, '_dtb_seo_noindex',     true );

	if ( '' !== $title ) {
		echo '<title>' . esc_html( $title ) . '</title>' . "\n";
	}

	if ( '' !== $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	$canonical_url = '' !== $canonical ? $canonical : get_permalink( $post_id );
	if ( $canonical_url ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical_url ) . '" />' . "\n";
	}

	if ( $noindex ) {
		echo '<meta name="robots" content="noindex" />' . "\n";
	}
}

// ─── Meta box ─────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', 'dtb_seo_add_meta_box' );

function dtb_seo_add_meta_box(): void {
	add_meta_box(
		'dtb_seo_meta_box',
		__( 'SEO Settings', 'drywall-toolbox' ),
		'dtb_seo_render_meta_box',
		'product',
		'normal',
		'high'
	);
}

function dtb_seo_render_meta_box( \WP_Post $post ): void {
	wp_nonce_field( 'dtb_seo_save_' . $post->ID, 'dtb_seo_nonce' );

	$title       = (string) get_post_meta( $post->ID, '_dtb_seo_title',       true );
	$description = (string) get_post_meta( $post->ID, '_dtb_seo_description', true );
	$focus_kw    = (string) get_post_meta( $post->ID, '_dtb_seo_focus_kw',    true );
	$canonical   = (string) get_post_meta( $post->ID, '_dtb_seo_canonical',   true );
	$noindex     = (bool)   get_post_meta( $post->ID, '_dtb_seo_noindex',     true );
	?>
	<style>
		.dtb-seo-field { margin-bottom: 14px; }
		.dtb-seo-field label { display: block; font-weight: 600; margin-bottom: 4px; }
		.dtb-seo-field input[type="text"],
		.dtb-seo-field input[type="url"],
		.dtb-seo-field textarea { width: 100%; box-sizing: border-box; }
		.dtb-seo-counter { font-size: 11px; color: #666; text-align: right; margin-top: 2px; }
		.dtb-seo-counter.over { color: #c00; font-weight: 600; }
	</style>
	<script>
		function dtbSeoCounter(inputId, counterId, max) {
			var el   = document.getElementById(inputId);
			var ctr  = document.getElementById(counterId);
			if (!el || !ctr) return;
			function update() {
				var len = el.value.length;
				ctr.textContent = len + ' / ' + max;
				ctr.className   = 'dtb-seo-counter' + (len > max ? ' over' : '');
			}
			el.addEventListener('input', update);
			update();
		}
		document.addEventListener('DOMContentLoaded', function () {
			dtbSeoCounter('dtb_seo_title',       'dtb_seo_title_count',       60);
			dtbSeoCounter('dtb_seo_description', 'dtb_seo_description_count', 160);
		});
	</script>

	<div class="dtb-seo-field">
		<label for="dtb_seo_title"><?php esc_html_e( 'SEO Title', 'drywall-toolbox' ); ?></label>
		<input
			type="text"
			id="dtb_seo_title"
			name="dtb_seo_title"
			value="<?php echo esc_attr( $title ); ?>"
			maxlength="60"
			placeholder="<?php echo esc_attr( get_the_title( $post->ID ) ); ?>"
		/>
		<div id="dtb_seo_title_count" class="dtb-seo-counter"></div>
	</div>

	<div class="dtb-seo-field">
		<label for="dtb_seo_description"><?php esc_html_e( 'Meta Description', 'drywall-toolbox' ); ?></label>
		<textarea
			id="dtb_seo_description"
			name="dtb_seo_description"
			rows="3"
			maxlength="160"
			placeholder="<?php esc_attr_e( 'Enter a concise description for search engine results…', 'drywall-toolbox' ); ?>"
		><?php echo esc_textarea( $description ); ?></textarea>
		<div id="dtb_seo_description_count" class="dtb-seo-counter"></div>
	</div>

	<div class="dtb-seo-field">
		<label for="dtb_seo_focus_kw"><?php esc_html_e( 'Focus Keyword', 'drywall-toolbox' ); ?></label>
		<input
			type="text"
			id="dtb_seo_focus_kw"
			name="dtb_seo_focus_kw"
			value="<?php echo esc_attr( $focus_kw ); ?>"
			placeholder="<?php esc_attr_e( 'Main keyword for this product', 'drywall-toolbox' ); ?>"
		/>
	</div>

	<div class="dtb-seo-field">
		<label for="dtb_seo_canonical"><?php esc_html_e( 'Canonical URL Override', 'drywall-toolbox' ); ?></label>
		<input
			type="url"
			id="dtb_seo_canonical"
			name="dtb_seo_canonical"
			value="<?php echo esc_attr( $canonical ); ?>"
			placeholder="https://elliottm4.sg-host.com/products/…"
		/>
	</div>

	<div class="dtb-seo-field">
		<label>
			<input
				type="checkbox"
				name="dtb_seo_noindex"
				value="1"
				<?php checked( $noindex, true ); ?>
			/>
			<?php esc_html_e( 'No-index this product (hide from search engines)', 'drywall-toolbox' ); ?>
		</label>
	</div>
	<?php
}

// ─── Save handler ─────────────────────────────────────────────────────────────

add_action( 'save_post_product', 'dtb_seo_save_meta', 10, 2 );

function dtb_seo_save_meta( int $post_id, \WP_Post $post ): void {
	// Skip autosaves, revisions, and AJAX bulk-edits.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Verify nonce.
	if (
		! isset( $_POST['dtb_seo_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['dtb_seo_nonce'] ) ),
			'dtb_seo_save_' . $post_id
		)
	) {
		return;
	}

	// Check capability.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// SEO Title (max 60 chars).
	if ( isset( $_POST['dtb_seo_title'] ) ) {
		$title = sanitize_text_field( wp_unslash( $_POST['dtb_seo_title'] ) );
		$title = mb_substr( $title, 0, 60 );
		update_post_meta( $post_id, '_dtb_seo_title', $title );
	}

	// Meta Description (max 160 chars).
	if ( isset( $_POST['dtb_seo_description'] ) ) {
		$desc = sanitize_textarea_field( wp_unslash( $_POST['dtb_seo_description'] ) );
		$desc = mb_substr( $desc, 0, 160 );
		update_post_meta( $post_id, '_dtb_seo_description', $desc );
	}

	// Focus Keyword.
	if ( isset( $_POST['dtb_seo_focus_kw'] ) ) {
		update_post_meta(
			$post_id,
			'_dtb_seo_focus_kw',
			sanitize_text_field( wp_unslash( $_POST['dtb_seo_focus_kw'] ) )
		);
	}

	// Canonical URL.
	if ( isset( $_POST['dtb_seo_canonical'] ) ) {
		update_post_meta(
			$post_id,
			'_dtb_seo_canonical',
			esc_url_raw( wp_unslash( $_POST['dtb_seo_canonical'] ) )
		);
	}

	// Noindex flag.
	$noindex = ! empty( $_POST['dtb_seo_noindex'] );
	update_post_meta( $post_id, '_dtb_seo_noindex', $noindex );
}

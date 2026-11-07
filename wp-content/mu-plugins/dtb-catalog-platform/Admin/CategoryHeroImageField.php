<?php
/**
 * Category hero header image field for the `product_cat` term edit screen.
 *
 * Deliberately separate from WooCommerce's own "Thumbnail" field
 * (`thumbnail_id` term meta, rendered by `WC_Admin_Taxonomies`) — that field
 * feeds the small "Shop by Tool Type" subcategory tiles on the storefront.
 * This field (`dtb_hero_image_id` term meta) feeds the large full-width hero
 * banner at the top of a category/subcategory page. Editors pick different
 * images for each purpose from the same edit screen.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_CATEGORY_HERO_IMAGE_META_KEY = 'dtb_hero_image_id';

add_action( 'product_cat_add_form_fields', 'dtb_category_hero_image_add_field' );
add_action( 'product_cat_edit_form_fields', 'dtb_category_hero_image_edit_field', 10, 2 );
add_action( 'created_product_cat', 'dtb_category_hero_image_save_field' );
add_action( 'edited_product_cat', 'dtb_category_hero_image_save_field' );
add_action( 'admin_enqueue_scripts', 'dtb_category_hero_image_enqueue_media' );

/**
 * Ensure the media library modal is available on the category list/edit
 * screens. WooCommerce's own Thumbnail field already loads this on the same
 * screens, but this field must not depend on that staying true.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function dtb_category_hero_image_enqueue_media( string $hook_suffix ): void {
	if ( ! in_array( $hook_suffix, [ 'edit-tags.php', 'term.php' ], true ) ) {
		return;
	}
	if ( 'product_cat' !== ( $_GET['taxonomy'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	wp_enqueue_media();
}

/**
 * Render the field on the "Add new category" screen.
 */
function dtb_category_hero_image_add_field(): void {
	?>
	<div class="form-field term-dtb-hero-image-wrap">
		<label><?php esc_html_e( 'Hero header image', 'drywall-toolbox' ); ?></label>
		<div id="dtb_hero_image_preview" style="float: left; margin-right: 10px;">
			<img src="<?php echo esc_url( wc_placeholder_img_src() ); ?>" width="120" height="60" style="object-fit:cover;" />
		</div>
		<div style="line-height: 60px;">
			<input type="hidden" id="dtb_hero_image_id" name="dtb_hero_image_id" value="" />
			<button type="button" class="dtb-hero-image-upload button"><?php esc_html_e( 'Upload/Add image', 'drywall-toolbox' ); ?></button>
			<button type="button" class="dtb-hero-image-remove button" style="display:none;"><?php esc_html_e( 'Remove image', 'drywall-toolbox' ); ?></button>
		</div>
		<p class="description"><?php esc_html_e( 'Full-width banner shown at the top of this category\'s page. Separate from the Thumbnail field below, which is used for subcategory tiles.', 'drywall-toolbox' ); ?></p>
		<p class="description"><?php esc_html_e( 'For best results: a wide photo at least 1920px on its longest side (e.g. 1920×720 or wider), JPG or WebP. It crops responsively — center the important part of the photo, since edges may be trimmed on some screens.', 'drywall-toolbox' ); ?></p>
		<div class="clear"></div>
	</div>
	<?php
	dtb_category_hero_image_script();
}

/**
 * Render the field on the "Edit category" screen.
 *
 * @param WP_Term $term     Term being edited.
 * @param string  $taxonomy Taxonomy slug.
 */
function dtb_category_hero_image_edit_field( WP_Term $term, string $taxonomy ): void {
	unset( $taxonomy );
	$hero_image_id = absint( get_term_meta( $term->term_id, DTB_CATEGORY_HERO_IMAGE_META_KEY, true ) );
	$preview_url   = $hero_image_id ? wp_get_attachment_image_url( $hero_image_id, 'medium' ) : '';
	$preview_url   = $preview_url ?: wc_placeholder_img_src();
	?>
	<tr class="form-field term-dtb-hero-image-wrap">
		<th scope="row" valign="top"><label><?php esc_html_e( 'Hero header image', 'drywall-toolbox' ); ?></label></th>
		<td>
			<div id="dtb_hero_image_preview" style="float: left; margin-right: 10px;">
				<img src="<?php echo esc_url( $preview_url ); ?>" width="120" height="60" style="object-fit:cover;" />
			</div>
			<div style="line-height: 60px;">
				<input type="hidden" id="dtb_hero_image_id" name="dtb_hero_image_id" value="<?php echo esc_attr( (string) $hero_image_id ); ?>" />
				<button type="button" class="dtb-hero-image-upload button"><?php esc_html_e( 'Upload/Add image', 'drywall-toolbox' ); ?></button>
				<button type="button" class="dtb-hero-image-remove button" style="<?php echo $hero_image_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove image', 'drywall-toolbox' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Full-width banner shown at the top of this category\'s page. Separate from the Thumbnail field above, which is used for subcategory tiles.', 'drywall-toolbox' ); ?></p>
			<div class="clear"></div>
		</td>
	</tr>
	<?php
	dtb_category_hero_image_script();
}

/**
 * Shared media-frame JS for both the add and edit screens. WooCommerce's own
 * Thumbnail field ships the same pattern independently — this is a second,
 * distinct instance scoped to `.dtb-hero-image-*` classes so the two fields
 * never cross-wire.
 */
function dtb_category_hero_image_script(): void {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<script type="text/javascript">
	jQuery(function ($) {
		var dtbHeroFrame;
		$(document).on('click', '.dtb-hero-image-upload', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.form-field, td');

			if (dtbHeroFrame) {
				dtbHeroFrame.open();
				return;
			}

			dtbHeroFrame = wp.media({
				title: '<?php echo esc_js( __( 'Choose a hero header image', 'drywall-toolbox' ) ); ?>',
				button: { text: '<?php echo esc_js( __( 'Use image', 'drywall-toolbox' ) ); ?>' },
				multiple: false,
			});

			dtbHeroFrame.on('select', function () {
				var attachment = dtbHeroFrame.state().get('selection').first().toJSON();
				var preview = (attachment.sizes && (attachment.sizes.medium || attachment.sizes.full)) || { url: attachment.url };
				$wrap.find('#dtb_hero_image_id').val(attachment.id);
				$wrap.find('#dtb_hero_image_preview img').attr('src', preview.url);
				$wrap.find('.dtb-hero-image-remove').show();
			});

			dtbHeroFrame.open();
		});

		$(document).on('click', '.dtb-hero-image-remove', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.form-field, td');
			$wrap.find('#dtb_hero_image_id').val('');
			$wrap.find('#dtb_hero_image_preview img').attr('src', '<?php echo esc_js( wc_placeholder_img_src() ); ?>');
			$(this).hide();
		});
	});
	</script>
	<?php
}

/**
 * Persist the hero image on category create/edit. WP core has already
 * verified the edit-tags.php nonce before either hook fires, so no
 * additional nonce check is needed here — matches WooCommerce's own
 * `save_category_fields` handler for the Thumbnail field.
 *
 * @param int $term_id Term being saved.
 */
function dtb_category_hero_image_save_field( int $term_id ): void {
	if ( ! isset( $_POST['dtb_hero_image_id'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return;
	}

	$hero_image_id = absint( $_POST['dtb_hero_image_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $hero_image_id > 0 ) {
		update_term_meta( $term_id, DTB_CATEGORY_HERO_IMAGE_META_KEY, $hero_image_id );
	} else {
		delete_term_meta( $term_id, DTB_CATEGORY_HERO_IMAGE_META_KEY );
	}
}

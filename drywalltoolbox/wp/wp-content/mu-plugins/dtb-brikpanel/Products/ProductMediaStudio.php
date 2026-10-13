<?php
/**
 * DTB BrikPanel product media UI. Feature gated by DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO.
 * No new persistence endpoint and no storefront gallery override.
 */
defined( 'ABSPATH' ) || exit;
if ( get_option( 'brikpanel_simple_product_editor', 'yes' ) !== 'yes' ) {
    return;
}

/**
 * Resolve the complete editable image list for one variation.
 *
 * DTB's canonical resolver is authoritative for the storefront gallery. The
 * BrikPanel compatibility meta is merged as a safety net so an existing larger
 * manually-managed gallery is never discarded. BrikPanel's editor requires
 * WordPress attachment IDs for remove/reorder/save operations, therefore URL
 * entries from the resolver are mapped back to attachment IDs when possible.
 *
 * @param WC_Product_Variation $variation Variation product.
 * @return array<int,array{id:int,url:string}>
 */
function dtb_brikpanel_media_studio_resolve_variation_images($variation) {
    if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
        return [];
    }

    $variation_id = (int) $variation->get_id();
    $sku          = trim((string) $variation->get_sku());
    $candidates   = [];

    if ($sku !== '' && class_exists('DTB_VariationGalleryResolver')) {
        $resolved = DTB_VariationGalleryResolver::resolve($sku, $variation_id);
        if (is_array($resolved)) {
            $candidates = array_merge($candidates, $resolved);
        }
    }

    // Preserve the explicit Woo/BrikPanel media contract even when the DTB
    // resolver is unavailable or returns a partial projection.
    $primary_id = (int) $variation->get_image_id();
    if ($primary_id > 0) {
        $candidates[] = ['id' => $primary_id];
    }

    $native_gallery = method_exists($variation, 'get_gallery_image_ids')
        ? (array) $variation->get_gallery_image_ids('edit')
        : [];
    $compat_gallery = !empty($native_gallery)
        ? $native_gallery
        : get_post_meta($variation_id, '_brikpanel_variation_gallery', true);
    if (is_array($compat_gallery)) {
        foreach ($compat_gallery as $gallery_id) {
            $gallery_id = (int) $gallery_id;
            if ($gallery_id > 0) {
                $candidates[] = ['id' => $gallery_id];
            }
        }
    }

    // Some WooCommerce builds/extensions expose native variation gallery IDs.
    // Include them when available without assuming the method exists.
    if (method_exists($variation, 'get_gallery_image_ids')) {
        foreach ((array) $variation->get_gallery_image_ids() as $gallery_id) {
            $gallery_id = (int) $gallery_id;
            if ($gallery_id > 0) {
                $candidates[] = ['id' => $gallery_id];
            }
        }
    }

    $images = [];
    $seen   = [];

    foreach ($candidates as $candidate) {
        if (is_string($candidate)) {
            $candidate = ['src' => $candidate];
        }
        if (!is_array($candidate)) {
            continue;
        }

        $attachment_id = isset($candidate['id']) ? (int) $candidate['id'] : 0;
        $src = trim((string) ($candidate['src'] ?? $candidate['url'] ?? $candidate['full'] ?? ''));

        if ($attachment_id <= 0 && $src !== '' && function_exists('attachment_url_to_postid')) {
            $attachment_id = (int) attachment_url_to_postid($src);
        }
        if ($attachment_id <= 0 || isset($seen[$attachment_id])) {
            continue;
        }

        $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (!$url) {
            $url = wp_get_attachment_url($attachment_id);
        }
        if (!$url && $src !== '') {
            $url = $src;
        }
        if (!$url) {
            continue;
        }

        $seen[$attachment_id] = true;
        $images[] = [
            'id'  => $attachment_id,
            'url' => (string) $url,
        ];
    }

    return $images;
}

/**
 * Hydrate BrikPanel's existing variation editor state with the same canonical
 * galleries used by the DTB storefront.
 *
 * render_page() prints window.brikpanelProductData before admin-footer.php.
 * admin_footer runs before WordPress prints footer-enqueued scripts, so this
 * bridge executes after the data object exists but before
 * brikpanel-product-editor.js copies it into its private state. This keeps the
 * existing BrikPanel dialog, ordering behavior and single product-save
 * transaction authoritative; no parallel media mutation endpoint is created.
 */
add_action('admin_footer', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'admin_page_brikpanel-product-editor' || !current_user_can('edit_products')) {
        return;
    }

    $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
    if ($product_id <= 0 || !current_user_can('edit_post', $product_id)) {
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    $gallery_map = [];
    foreach ((array) $product->get_children() as $variation_id) {
        $variation = wc_get_product((int) $variation_id);
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            continue;
        }

        $images = dtb_brikpanel_media_studio_resolve_variation_images($variation);
        if (!empty($images)) {
            $gallery_map[(string) $variation->get_id()] = $images;
        }
    }

    if (empty($gallery_map)) {
        return;
    }

    $json = wp_json_encode($gallery_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!$json) {
        return;
    }
    ?>
    <script id="brikpanel-media-studio-variation-hydration">
    (function () {
        'use strict';
        var map = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX encoded above. ?>;
        var data = window.brikpanelProductData;
        if (!data || !Array.isArray(data.variations)) return;

        data.variations.forEach(function (variation) {
            if (!variation) return;
            var resolved = map[String(variation.id || '')];
            if (!Array.isArray(resolved) || !resolved.length) return;

            // Canonical resolved order wins, while retaining any explicit
            // BrikPanel images that are not already present.
            var existing = Array.isArray(variation.images) ? variation.images : [];
            var merged = [];
            var seen = Object.create(null);

            resolved.concat(existing).forEach(function (image) {
                if (!image) return;
                var id = parseInt(image.id, 10) || 0;
                if (!id || seen[id]) return;
                var url = image.url || image.src || '';
                if (!url) return;
                seen[id] = true;
                merged.push({ id: id, url: url });
            });

            if (merged.length) variation.images = merged;
        });
    })();
    </script>
    <?php
}, 1);

/**
 * Load the Product Media Studio only on BrikPanel's product editor.
 *
 * The studio is a progressive enhancement over the existing editor DOM. It
 * deliberately reuses the current parent-gallery and variation-gallery event
 * handlers so WooCommerce remains the persistence authority and no parallel
 * media save path is introduced.
 */
add_action('admin_enqueue_scripts', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'admin_page_brikpanel-product-editor') {
        return;
    }

    $base_url = plugin_dir_url(__FILE__);
    $base_dir = plugin_dir_path(__FILE__);
    $css_file = $base_dir . 'brikpanel-product-media-studio.css';
    $js_file  = $base_dir . 'brikpanel-product-media-studio.js';

    if (is_readable($css_file)) {
        wp_enqueue_style(
            'brikpanel-product-media-studio',
            $base_url . 'brikpanel-product-media-studio.css',
            [],
            (string) filemtime($css_file)
        );
    }

    if (is_readable($js_file)) {
        wp_enqueue_script(
            'brikpanel-product-media-studio',
            $base_url . 'brikpanel-product-media-studio.js',
            ['jquery', 'jquery-ui-sortable', 'media-editor'],
            (string) filemtime($js_file),
            true
        );

        wp_localize_script(
            'brikpanel-product-media-studio',
            'brikpanelMediaStudio',
            [
                'variationGalleryEnabled' => get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes',
                'i18n' => [
                    'studio_label'             => __('Product media studio', 'brikpanel'),
                    'eyebrow'                  => __('Product media', 'brikpanel'),
                    'title'                    => __('Product Media Studio', 'brikpanel'),
                    'description'              => __('Order primary and gallery images, then manage each variation gallery from the same workspace.', 'brikpanel'),
                    'tabs_label'               => __('Media editor views', 'brikpanel'),
                    'product_gallery'          => __('Product gallery', 'brikpanel'),
                    'variations'               => __('Variations', 'brikpanel'),
                    'variation_galleries'      => __('Variation galleries', 'brikpanel'),
                    'product_hint'             => __('Drag images to reorder. The first image is the product primary image.', 'brikpanel'),
                    'variation_hint'           => __('Open a variation to add, remove, preview, and reorder its images.', 'brikpanel'),
                    'dialog_hint'              => __('Drag to set gallery order. The first image becomes the variation primary image.', 'brikpanel'),
                    'variation_gallery_order'  => __('Variation image order', 'brikpanel'),
                    'add_images'               => __('Add images', 'brikpanel'),
                    'manage'                   => __('Manage', 'brikpanel'),
                    'manage_variation_images'  => __('Manage variation images', 'brikpanel'),
                    'primary_image'            => __('Primary product image', 'brikpanel'),
                    'gallery_image'            => __('Gallery image', 'brikpanel'),
                    'variation'                => __('Variation', 'brikpanel'),
                    'images'                   => __('images', 'brikpanel'),
                    'one_image'                => __('1 image', 'brikpanel'),
                    'no_sku'                   => __('No SKU', 'brikpanel'),
                    'no_variations'            => __('No variations yet', 'brikpanel'),
                    'no_variations_hint'       => __('Generate product variations first, then their image galleries will appear here.', 'brikpanel'),
                ],
            ]
        );

        // The Media Studio's native product count intentionally describes only
        // the parent/simple gallery. The header summary, however, represents all
        // media owned by this variable product, so aggregate the parent count and
        // every variation gallery count and keep it synchronized as galleries
        // are edited in the existing BrikPanel dialog.
        wp_add_inline_script(
            'brikpanel-product-media-studio',
            "(function($){'use strict';$(function(){var root=document.querySelector('.brikpanel-media-studio');if(!root)return;function update(){var productEl=root.querySelector('[data-product-count]');var totalEl=root.querySelector('[data-media-total]');if(!totalEl)return;var total=parseInt(productEl&&productEl.textContent||'0',10)||0;root.querySelectorAll('.brikpanel-media-variation__count').forEach(function(el){total+=parseInt(el.textContent||'0',10)||0;});if(totalEl.textContent!==String(total))totalEl.textContent=String(total);}update();if(window.MutationObserver){var observer=new MutationObserver(update);observer.observe(root,{childList:true,subtree:true,characterData:true});}});})(jQuery);",
            'after'
        );
    }
}, 30);


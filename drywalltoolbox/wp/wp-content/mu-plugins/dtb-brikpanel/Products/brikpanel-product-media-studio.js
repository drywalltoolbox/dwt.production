/*
 * BrikPanel Product Media Studio
 *
 * Progressive enhancement for the existing BrikPanel product editor.
 * Keeps WooCommerce/BrikPanel persistence intact while presenting parent and
 * variation image galleries as one focused workspace.
 */
(function ($) {
    'use strict';

    var CFG = window.brikpanelMediaStudio || {};
    var SELECTORS = {
        gallery: '#bpe-gallery',
        dropzone: '#bpe-dropzone',
        addImages: '#bpe-add-images',
        variationBody: '#bpe-var-table-body'
    };

    var studio = {
        $root: null,
        $productPanel: null,
        $variationPanel: null,
        $variationList: null,
        $dialog: null,
        activeTab: 'product',
        activeVariationIndex: null,
        variationImages: {},
        productObserver: null,
        variationObserver: null,
        refreshTimer: null
    };

    function t(key, fallback) {
        return CFG.i18n && CFG.i18n[key] ? CFG.i18n[key] : fallback;
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function cloneImages(images) {
        return (Array.isArray(images) ? images : []).filter(function (image) {
            return image && parseInt(image.id, 10) > 0 && image.url;
        }).map(function (image) {
            return { id: parseInt(image.id, 10), url: String(image.url) };
        });
    }

    function getVariationRows() {
        return $(SELECTORS.variationBody).find('tr.var-main-row');
    }

    function productImageCount() {
        return $(SELECTORS.gallery).find('.brikpanel-pe-gallery-item').not('.is-uploading').length;
    }

    function seedVariationImages() {
        var productData = window.brikpanelProductData || {};
        var variations = Array.isArray(productData.variations) ? productData.variations : [];
        variations.forEach(function (variation, index) {
            studio.variationImages[index] = cloneImages(variation && variation.images);
        });
    }

    function variationImages(index) {
        if (!Object.prototype.hasOwnProperty.call(studio.variationImages, index)) {
            var productData = window.brikpanelProductData || {};
            var variations = Array.isArray(productData.variations) ? productData.variations : [];
            studio.variationImages[index] = cloneImages(variations[index] && variations[index].images);
        }
        return studio.variationImages[index];
    }

    function aggregateMediaCount() {
        var total = productImageCount();
        Object.keys(studio.variationImages).forEach(function (key) {
            total += cloneImages(studio.variationImages[key]).length;
        });
        return total;
    }

    function buildStudio() {
        var $gallery = $(SELECTORS.gallery);
        var $dropzone = $(SELECTORS.dropzone);
        if (!$gallery.length || !$dropzone.length || $gallery.closest('.brikpanel-media-studio').length) return false;

        var $card = $gallery.closest('.brikpanel-pe-card');
        if (!$card.length) return false;

        $card.addClass('brikpanel-media-card');
        $card.children('label').first().addClass('brikpanel-media-card__legacy-label');

        studio.$root = $('<section class="brikpanel-media-studio" aria-label="' + esc(t('studio_label', 'Product media studio')) + '"></section>');
        var $header = $('<div class="brikpanel-media-studio__header"></div>');
        var $heading = $('<div class="brikpanel-media-studio__heading"></div>');
        $heading.append('<span class="brikpanel-media-studio__eyebrow">' + esc(t('eyebrow', 'PRODUCT MEDIA')) + '</span>');
        $heading.append('<h2>' + esc(t('title', 'Product Media Studio')) + '</h2>');
        $heading.append('<p>' + esc(t('description', 'Order primary and gallery images, then manage each variation gallery from the same workspace.')) + '</p>');
        $header.append($heading);
        $header.append('<div class="brikpanel-media-studio__summary" aria-live="polite"><strong data-media-total>0</strong><span>' + esc(t('images', 'images')) + '</span></div>');
        studio.$root.append($header);

        var $tabs = $('<div class="brikpanel-media-studio__tabs" role="tablist" aria-label="' + esc(t('tabs_label', 'Media editor views')) + '"></div>');
        $tabs.append('<button type="button" class="brikpanel-media-studio__tab is-active" role="tab" aria-selected="true" data-media-tab="product">' + esc(t('product_gallery', 'Product gallery')) + '<span class="brikpanel-media-studio__tab-count" data-product-count>0</span></button>');
        $tabs.append('<button type="button" class="brikpanel-media-studio__tab" role="tab" aria-selected="false" data-media-tab="variations">' + esc(t('variations', 'Variations')) + '<span class="brikpanel-media-studio__tab-count" data-variation-count>0</span></button>');
        studio.$root.append($tabs);

        studio.$productPanel = $('<div class="brikpanel-media-studio__panel is-active" role="tabpanel" data-media-panel="product"></div>');
        studio.$variationPanel = $('<div class="brikpanel-media-studio__panel" role="tabpanel" data-media-panel="variations" hidden></div>');

        var $productToolbar = $('<div class="brikpanel-media-studio__toolbar"></div>');
        $productToolbar.append('<div><strong>' + esc(t('product_gallery', 'Product gallery')) + '</strong><span>' + esc(t('product_hint', 'Drag images to reorder. The first image is the product primary image.')) + '</span></div>');
        var $add = $('<button type="button" class="brikpanel-media-studio__add">' + esc(t('add_images', 'Add images')) + '</button>');
        $add.on('click', function () { $(SELECTORS.addImages).trigger('click'); });
        $productToolbar.append($add);
        studio.$productPanel.append($productToolbar);

        var $productCanvas = $('<div class="brikpanel-media-studio__canvas"></div>');
        $dropzone.addClass('brikpanel-media-studio__dropzone');
        $gallery.addClass('brikpanel-media-studio__gallery');
        $productCanvas.append($dropzone, $gallery);
        studio.$productPanel.append($productCanvas);

        var $variationToolbar = $('<div class="brikpanel-media-studio__toolbar"></div>');
        $variationToolbar.append('<div><strong>' + esc(t('variation_galleries', 'Variation galleries')) + '</strong><span>' + esc(t('variation_hint', 'Open a variation to add, remove, preview, and reorder its images.')) + '</span></div>');
        studio.$variationPanel.append($variationToolbar);
        studio.$variationList = $('<div class="brikpanel-media-studio__variations"></div>');
        studio.$variationPanel.append(studio.$variationList);

        studio.$root.append(studio.$productPanel, studio.$variationPanel);
        $card.append(studio.$root);

        $tabs.on('click', '[data-media-tab]', function () { setTab($(this).data('media-tab')); });

        seedVariationImages();
        decorateProductGallery();
        renderVariationMedia();
        installObservers();
        return true;
    }

    function setTab(tab) {
        if (tab !== 'product' && tab !== 'variations') return;
        studio.activeTab = tab;
        studio.$root.find('[data-media-tab]').each(function () {
            var active = $(this).data('media-tab') === tab;
            $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
        });
        studio.$root.find('[data-media-panel]').each(function () {
            var active = $(this).data('media-panel') === tab;
            $(this).toggleClass('is-active', active).prop('hidden', !active);
        });
    }

    function decorateProductGallery() {
        if (!studio.$root) return;
        var $gallery = $(SELECTORS.gallery);
        var count = productImageCount();
        studio.$root.find('[data-product-count]').text(count);
        studio.$root.find('[data-media-total]').text(aggregateMediaCount());

        $gallery.find('.brikpanel-pe-gallery-item').not('.is-uploading').each(function (index) {
            var $item = $(this);
            $item.attr('data-media-position', index + 1);
            $item.find('.brikpanel-media-position').remove();
            $item.append('<span class="brikpanel-media-position" aria-hidden="true">' + (index + 1) + '</span>');
            $item.toggleClass('is-primary', index === 0);
            $item.attr('aria-label', index === 0 ? t('primary_image', 'Primary product image') : t('gallery_image', 'Gallery image') + ' ' + (index + 1));
        });

        studio.$root.toggleClass('has-product-images', count > 0);
    }

    function variationMeta($row) {
        var idx = parseInt($row.data('idx'), 10);
        var name = $.trim($row.find('.var-name-text').text()) || t('variation', 'Variation');
        var sku = $.trim($row.find('.var-sku').val() || '');
        var images = Number.isInteger(idx) ? variationImages(idx) : [];
        var src = images.length ? images[0].url : ($row.find('.var-image-btn img').attr('src') || '');
        return { idx: idx, name: name, sku: sku, count: images.length, src: src };
    }

    function renderVariationMedia() {
        if (!studio.$variationList) return;
        var $rows = getVariationRows();
        var total = $rows.length;
        studio.$root.find('[data-variation-count]').text(total);
        studio.$root.find('[data-media-tab="variations"]').prop('disabled', total === 0);
        studio.$root.find('[data-media-total]').text(aggregateMediaCount());

        if (!total) {
            studio.$variationList.html('<div class="brikpanel-media-studio__empty"><strong>' + esc(t('no_variations', 'No variations yet')) + '</strong><span>' + esc(t('no_variations_hint', 'Generate product variations first, then their image galleries will appear here.')) + '</span></div>');
            if (studio.activeTab === 'variations') setTab('product');
            return;
        }

        var fragment = document.createDocumentFragment();
        $rows.each(function () {
            var meta = variationMeta($(this));
            var card = document.createElement('article');
            card.className = 'brikpanel-media-variation';
            card.setAttribute('data-variation-index', meta.idx);

            var thumb = document.createElement('button');
            thumb.type = 'button';
            thumb.className = 'brikpanel-media-variation__thumb' + (meta.src ? ' has-image' : '');
            thumb.setAttribute('aria-label', t('manage_variation_images', 'Manage variation images') + ': ' + meta.name);
            if (meta.src) {
                var img = document.createElement('img');
                img.src = meta.src;
                img.alt = '';
                thumb.appendChild(img);
            } else {
                thumb.innerHTML = '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>';
            }

            var body = document.createElement('div');
            body.className = 'brikpanel-media-variation__body';
            body.innerHTML = '<strong>' + esc(meta.name) + '</strong><span class="brikpanel-media-variation__sku">' + esc(meta.sku ? 'SKU ' + meta.sku : t('no_sku', 'No SKU')) + '</span><span class="brikpanel-media-variation__count">' + esc(meta.count === 1 ? t('one_image', '1 image') : meta.count + ' ' + t('images', 'images')) + '</span>';

            var action = document.createElement('button');
            action.type = 'button';
            action.className = 'brikpanel-media-variation__manage';
            action.textContent = meta.count ? t('manage', 'Manage') : t('add_images', 'Add images');

            var open = function () { openVariationGalleryManager(meta.idx, meta.name); };
            $(thumb).on('click', open);
            $(action).on('click', open);

            card.appendChild(thumb);
            card.appendChild(body);
            card.appendChild(action);
            fragment.appendChild(card);
        });

        studio.$variationList.empty().append(fragment);
    }

    function findVariationImageHandler(index) {
        var $button = getVariationRows().filter('[data-idx="' + index + '"]').find('.var-image-btn').first();
        var button = $button.get(0);
        if (!button || typeof $._data !== 'function') return null;
        var events = $._data(button, 'events');
        var clicks = events && Array.isArray(events.click) ? events.click : [];
        var found = null;
        clicks.some(function (entry) {
            if (!entry || typeof entry.handler !== 'function') return false;
            var source = '';
            try { source = Function.prototype.toString.call(entry.handler); } catch (e) { return false; }
            if (source.indexOf('openVarImagePicker') === -1) return false;
            found = { button: button, handler: entry.handler };
            return true;
        });
        return found;
    }

    /*
     * Commit an ordered image array back into BrikPanel's private variation
     * state without exposing or duplicating that state. The current BrikPanel
     * editor writes state.variations[idx].images inside openVarImagePicker()'s
     * wp.media "select" callback. We temporarily provide a tiny wp.media facade
     * whose selection is the manager's working copy, invoke only that exact
     * BrikPanel handler, and restore wp.media immediately. No WordPress media
     * modal is opened during this commit.
     */
    function commitVariationImages(index, images) {
        if (!window.wp || typeof window.wp.media !== 'function') return false;
        var binding = findVariationImageHandler(index);
        if (!binding) return false;

        var originalMedia = window.wp.media;
        var selected = cloneImages(images).map(function (image) {
            return { id: image.id, url: image.url, sizes: { thumbnail: { url: image.url } } };
        });
        var handlers = {};
        var selection = {
            toJSON: function () { return selected.slice(); },
            reset: function () {},
            add: function () {}
        };
        var frame = {
            on: function (name, callback) {
                if (!handlers[name]) handlers[name] = [];
                handlers[name].push(callback);
                return frame;
            },
            state: function () {
                return { get: function (key) { return key === 'selection' ? selection : null; } };
            },
            open: function () {
                (handlers.select || []).forEach(function (callback) { callback(); });
                (handlers.close || []).forEach(function (callback) { callback(); });
                return frame;
            }
        };
        var facade = function () { return frame; };
        Object.keys(originalMedia).forEach(function (key) {
            try { facade[key] = originalMedia[key]; } catch (e) {}
        });

        window.wp.media = facade;
        try {
            binding.handler.call(binding.button, $.Event('click'));
        } finally {
            window.wp.media = originalMedia;
        }

        studio.variationImages[index] = cloneImages(images);
        if (window.brikpanelProductData && Array.isArray(window.brikpanelProductData.variations) && window.brikpanelProductData.variations[index]) {
            window.brikpanelProductData.variations[index].images = cloneImages(images);
        }
        getVariationRows().filter('[data-idx="' + index + '"]').find('.var-sku').first().trigger('change');
        scheduleRefresh();
        return true;
    }

    function attachmentImage(att) {
        if (!att) return null;
        var id = parseInt(att.id, 10);
        if (!id) return null;
        var url = att.url || '';
        if (att.sizes) {
            if (att.sizes.thumbnail) url = att.sizes.thumbnail.url;
            else if (att.sizes.medium) url = att.sizes.medium.url;
        }
        return url ? { id: id, url: url } : null;
    }

    function updateDialogCount($dialog, working) {
        $dialog.find('[data-vargal-count]').text(working.length === 1 ? t('one_image', '1 image') : working.length + ' ' + t('images', 'images'));
    }

    function openMediaForVariation($dialog, working) {
        if (!window.wp || typeof window.wp.media !== 'function') return;
        var frame = window.wp.media({
            title: t('select_images', 'Select images'),
            multiple: true,
            library: { type: 'image' },
            button: { text: t('select', 'Select') }
        });
        frame.on('select', function () {
            var seen = {};
            working.forEach(function (image) { seen[image.id] = true; });
            frame.state().get('selection').toJSON().forEach(function (att) {
                var image = attachmentImage(att);
                if (!image || seen[image.id]) return;
                seen[image.id] = true;
                working.push(image);
            });
            renderVariationDialogGrid($dialog, working);
            updateDialogCount($dialog, working);
        });
        frame.open();
    }

    function renderVariationDialogGrid($dialog, working) {
        var $grid = $dialog.find('.brikpanel-pe-vargal-grid').empty();
        if (!working.length) {
            $grid.append('<div class="brikpanel-media-vargal__empty">' + esc(t('no_variation_images', 'No variation images yet.')) + '</div>');
        } else {
            working.forEach(function (image, index) {
                var $item = $('<div class="brikpanel-pe-vargal-item" data-id="' + image.id + '"></div>');
                $item.append('<img src="' + esc(image.url) + '" alt="">');
                if (index === 0) $item.append('<span class="brikpanel-pe-vargal-badge">' + esc(t('primary', 'Primary')) + '</span>');
                var $remove = $('<button type="button" class="brikpanel-pe-vargal-remove" aria-label="' + esc(t('remove_image', 'Remove image')) + '">&times;</button>');
                $remove.on('click', function () {
                    var id = parseInt($item.attr('data-id'), 10);
                    for (var i = working.length - 1; i >= 0; i--) {
                        if (working[i].id === id) working.splice(i, 1);
                    }
                    renderVariationDialogGrid($dialog, working);
                    updateDialogCount($dialog, working);
                });
                $item.append($remove);
                $grid.append($item);
            });
        }

        if (typeof $grid.sortable === 'function') {
            try { $grid.sortable('destroy'); } catch (e) {}
            $grid.sortable({
                items: '.brikpanel-pe-vargal-item',
                tolerance: 'pointer',
                cursor: 'grabbing',
                placeholder: 'brikpanel-pe-vargal-item ui-sortable-placeholder',
                update: function () {
                    var byId = {};
                    working.forEach(function (image) { byId[image.id] = image; });
                    var ordered = [];
                    $grid.find('.brikpanel-pe-vargal-item').each(function () {
                        var id = parseInt($(this).attr('data-id'), 10);
                        if (byId[id]) ordered.push(byId[id]);
                    });
                    working.splice.apply(working, [0, working.length].concat(ordered));
                    renderVariationDialogGrid($dialog, working);
                    updateDialogCount($dialog, working);
                }
            });
        }
    }

    function buildVariationDialog(index, name) {
        if (studio.$dialog) studio.$dialog.remove();
        var working = cloneImages(variationImages(index));
        studio.activeVariationIndex = index;

        var html = '' +
            '<div class="brikpanel-pe-linkdlg brikpanel-pe-vargal-dlg brikpanel-media-vargal">' +
                '<div class="brikpanel-pe-linkdlg-backdrop"></div>' +
                '<div class="brikpanel-pe-linkdlg-box brikpanel-pe-vargal-box" role="dialog" aria-modal="true" aria-label="' + esc(t('variation_images', 'Variation images')) + '">' +
                    '<h3 class="brikpanel-pe-linkdlg-title">' + esc(t('variation_images', 'Variation images')) + '</h3>' +
                    '<p class="brikpanel-media-vargal__help">' + esc(t('dialog_hint', 'Drag to set gallery order. The first image becomes the variation primary image.')) + '</p>' +
                    '<div class="brikpanel-media-vargal__meta"><strong>' + esc(name || t('variation', 'Variation')) + '</strong><span data-vargal-count></span></div>' +
                    '<div class="brikpanel-pe-vargal-grid" aria-label="' + esc(t('variation_gallery_order', 'Variation image order')) + '"></div>' +
                    '<div class="brikpanel-pe-linkdlg-actions brikpanel-media-vargal__actions">' +
                        '<button type="button" class="brikpanel-pe-btn secondary brikpanel-media-vargal__add">' + esc(t('add_images', 'Add images')) + '</button>' +
                        '<span class="brikpanel-media-vargal__actions-spacer"></span>' +
                        '<button type="button" class="brikpanel-pe-btn secondary brikpanel-media-vargal__cancel">' + esc(t('cancel', 'Cancel')) + '</button>' +
                        '<button type="button" class="brikpanel-pe-btn primary brikpanel-media-vargal__done">' + esc(t('done', 'Done')) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        var $dialog = $(html).appendTo('body');
        studio.$dialog = $dialog;

        function close() {
            $dialog.remove();
            if (studio.$dialog && studio.$dialog[0] === $dialog[0]) studio.$dialog = null;
        }

        $dialog.find('.brikpanel-media-vargal__add').on('click', function () { openMediaForVariation($dialog, working); });
        $dialog.find('.brikpanel-media-vargal__cancel, .brikpanel-pe-linkdlg-backdrop').on('click', close);
        $dialog.find('.brikpanel-media-vargal__done').on('click', function () {
            if (commitVariationImages(index, working)) close();
        });
        $dialog.on('keydown', function (e) { if (e.key === 'Escape') close(); });
        renderVariationDialogGrid($dialog, working);
        updateDialogCount($dialog, working);
        return $dialog;
    }

    function openVariationGalleryManager(index, name) {
        if (!Number.isInteger(index) || index < 0) return false;
        buildVariationDialog(index, name);
        return true;
    }

    function scheduleRefresh() {
        window.clearTimeout(studio.refreshTimer);
        studio.refreshTimer = window.setTimeout(function () {
            decorateProductGallery();
            renderVariationMedia();
        }, 50);
    }

    function installObservers() {
        if (window.MutationObserver) {
            var gallery = document.querySelector(SELECTORS.gallery);
            if (gallery) {
                studio.productObserver = new MutationObserver(scheduleRefresh);
                studio.productObserver.observe(gallery, { childList: true });
            }
            var variationBody = document.querySelector(SELECTORS.variationBody);
            if (variationBody) {
                studio.variationObserver = new MutationObserver(function () {
                    var rowCount = getVariationRows().length;
                    Object.keys(studio.variationImages).forEach(function (key) {
                        if (parseInt(key, 10) >= rowCount) delete studio.variationImages[key];
                    });
                    scheduleRefresh();
                });
                studio.variationObserver.observe(variationBody, { childList: true, subtree: true });
            }
        }
        $(document).on('change input', '#bpe-var-table-body .var-sku', scheduleRefresh);
        $(document).on('sortupdate', '#bpe-gallery', scheduleRefresh);
    }

    function init() {
        if (!$('body').hasClass('brikpanel-product-editor-page')) return;
        buildStudio();
    }

    $(init);
})(jQuery);

(function ($) {
  'use strict';

  var cfg = window.DtbPartsManager || {};
  var ajaxUrl = cfg.ajaxUrl || window.ajaxurl;
  var nonce = cfg.nonce || '';
  var partsPage = 1;
  var partsPages = 1;
  var universalPage = 1;
  var universalPages = 1;

  function esc(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function post(action, payload) {
    return $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, payload || {}));
  }

  function setMessage(selector, message, kind) {
    var $target = $(selector);
    $target.removeClass('is-error is-success is-warning');
    if (kind) {
      $target.addClass('is-' + kind);
    }
    $target.text(message || '');
  }

  function downloadFile(content, mime, filename) {
    var blob = new Blob([content || ''], { type: mime || 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || 'download.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function badge(value, fallback) {
    var text = value || fallback || 'none';
    var slug = String(text).toLowerCase().replace(/[^a-z0-9_-]/g, '');
    return '<span class="dtb-pm-badge dtb-pm-badge--' + slug + '">' + esc(text) + '</span>';
  }

  function loadSummary() {
    post('dtb_parts_universal_summary').done(function (res) {
      var data = (res && res.success && res.data) ? res.data : {};
      var counts = data.counts || {};
      $('[data-dtb-metric="universal_parts"]').text(counts.parts || 0);
      $('[data-dtb-metric="active_universal"]').text(counts.active || 0);
      $('[data-dtb-metric="review_universal"]').text((counts.review || 0) + (counts.quarantine || 0));
    });
  }

  function loadParts(page) {
    partsPage = page || 1;
    $('#dtb-pm-loading').show();
    $('#dtb-pm-table,#dtb-pm-empty').hide();

    post('dtb_parts_list', {
      search: $('#dtb-pm-search').val(),
      brand: $('#dtb-pm-brand').val(),
      status: $('#dtb-pm-status').val(),
      universal_status: $('#dtb-pm-universal-status').val(),
      paged: partsPage
    }).done(function (res) {
      $('#dtb-pm-loading').hide();
      if (!res || !res.success) {
        $('#dtb-pm-empty').text('Unable to load parts.').show();
        return;
      }

      var data = res.data || {};
      var items = data.items || [];
      partsPages = data.pages || 1;
      $('#dtb-pm-count').text((data.total || 0) + ' parts');
      $('[data-dtb-metric="parts_total"]').text(data.total || 0);

      if (!items.length) {
        $('#dtb-pm-body').empty();
        $('#dtb-pm-empty').text('No parts found.').show();
        $('#dtb-pm-pagination').empty();
        return;
      }

      var html = '';
      $.each(items, function (_, item) {
        html += '<tr>';
        html += '<td><strong>' + esc(item.title) + '</strong><div class="dtb-pm-subline"><code>' + esc(item.sku) + '</code> · ID ' + esc(item.id) + '</div></td>';
        html += '<td>' + esc(item.brand_label || '—') + '<div class="dtb-pm-subline">Mfr: ' + esc(item.manufacturer_sku || '—') + '</div></td>';
        html += '<td>' + (item.universal_part_id ? '<code>' + esc(item.universal_part_id) + '</code>' : '<span class="dtb-pm-muted">Not mapped</span>');
        html += '<div class="dtb-pm-subline">' + badge(item.universal_part_status, 'none') + ' ' + badge(item.universal_part_confidence, 'unscored') + '</div></td>';
        html += '<td>' + badge(item.status, 'draft') + '<div class="dtb-pm-subline">$' + esc(item.price || '0') + '</div></td>';
        html += '<td><button type="button" class="dtb-pm-btn dtb-pm-btn-secondary dtb-pm-edit" data-id="' + esc(item.id) + '">Edit</button></td>';
        html += '</tr>';
      });

      $('#dtb-pm-body').html(html);
      $('#dtb-pm-table').show();
      renderPartsPagination();
    }).fail(function () {
      $('#dtb-pm-loading').hide();
      $('#dtb-pm-empty').text('Unable to load parts.').show();
    });
  }

  function renderPartsPagination() {
    if (partsPages <= 1) {
      $('#dtb-pm-pagination').empty();
      return;
    }
    $('#dtb-pm-pagination').html(
      '<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-parts-prev ' + (partsPage <= 1 ? 'disabled' : '') + '>Prev</button>' +
      '<span>Page ' + partsPage + ' of ' + partsPages + '</span>' +
      '<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-parts-next ' + (partsPage >= partsPages ? 'disabled' : '') + '>Next</button>'
    );
  }

  function loadUniversal(page) {
    universalPage = page || 1;
    post('dtb_parts_universal_list', {
      search: $('#dtb-pm-universal-search').val(),
      status: $('#dtb-pm-universal-filter').val(),
      paged: universalPage
    }).done(function (res) {
      if (!res || !res.success) {
        $('#dtb-pm-universal-empty').text('Unable to load universal seed rows.').show();
        return;
      }

      var data = res.data || {};
      var items = data.items || [];
      universalPages = data.pages || 1;
      $('#dtb-pm-universal-count').text((data.total || 0) + ' seed rows');

      if (!items.length) {
        $('#dtb-pm-universal-body').empty();
        $('#dtb-pm-universal-empty').show();
        $('#dtb-pm-universal-pagination').empty();
        return;
      }

      var html = '';
      $.each(items, function (_, item) {
        html += '<tr>';
        html += '<td><code>' + esc(item.universal_part_id) + '</code><div class="dtb-pm-subline">' + esc(item.canonical_name) + '</div></td>';
        html += '<td>' + esc(item.part_family || '—') + '<div class="dtb-pm-subline">' + esc(item.thread || item.nominal_size || '—') + (item.length ? ' × ' + esc(item.length) : '') + '</div></td>';
        html += '<td>' + badge(item.status, 'review') + '<div class="dtb-pm-subline">' + badge(item.confidence, 'review') + '</div></td>';
        html += '<td>' + esc(item.brands || '—') + '<div class="dtb-pm-subline">' + esc(item.catalog_skus || 'No catalog SKU') + '</div></td>';
        html += '</tr>';
      });
      $('#dtb-pm-universal-body').html(html);
      $('#dtb-pm-universal-empty').hide();
      renderUniversalPagination();
    });
  }

  function renderUniversalPagination() {
    if (universalPages <= 1) {
      $('#dtb-pm-universal-pagination').empty();
      return;
    }
    $('#dtb-pm-universal-pagination').html(
      '<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-prev ' + (universalPage <= 1 ? 'disabled' : '') + '>Prev</button>' +
      '<span>Page ' + universalPage + ' of ' + universalPages + '</span>' +
      '<button type="button" class="dtb-pm-btn dtb-pm-btn-secondary" data-dtb-universal-next ' + (universalPage >= universalPages ? 'disabled' : '') + '>Next</button>'
    );
  }

  function resetForm() {
    $('#dtb-pm-id').val('0');
    $('#dtb-pm-title,#dtb-pm-sku,#dtb-pm-brand-label,#dtb-pm-msku,#dtb-pm-price,#dtb-pm-description').val('');
    $('#dtb-pm-post-status').val('draft');
    $('#dtb-pm-sku,#dtb-pm-msku').prop('readOnly', false);
    $('[data-protected-hint]').prop('hidden', true);
    $('#dtb-pm-modal-title').text('Add Part');
    $('#dtb-pm-trash').hide();
    setMessage('#dtb-pm-msg', '');
  }

  function openModal() {
    $('#dtb-pm-modal-wrap').addClass('open').attr('aria-hidden', 'false');
  }

  function closeModal() {
    $('#dtb-pm-modal-wrap').removeClass('open').attr('aria-hidden', 'true');
  }

  function importCsv(inputId, action, spinnerId, messageId, errorsId, successCallback) {
    var input = document.getElementById(inputId);
    if (!input || !input.files || !input.files.length) {
      alert('Select a CSV file first.');
      return;
    }
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce);
    fd.append('file', input.files[0]);
    $('#' + spinnerId).show();
    setMessage('#' + messageId, '');
    $('#' + errorsId).hide().text('');
    $.ajax({ url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false }).done(function (res) {
      $('#' + spinnerId).hide();
      if (!res || !res.success) {
        setMessage('#' + messageId, (res && res.data && res.data.message) || 'Import failed.', 'error');
        return;
      }
      var data = res.data || {};
      setMessage('#' + messageId, '✓ ' + (data.message || 'Import completed.'), 'success');
      if (data.errors && data.errors.length) {
        $('#' + errorsId).show().text(data.errors.join('\n'));
      }
      if (typeof successCallback === 'function') successCallback();
    }).fail(function () {
      $('#' + spinnerId).hide();
      setMessage('#' + messageId, 'Import failed.', 'error');
    });
  }

  function runUniversalSync(mode) {
    if (mode === 'apply' && !window.confirm('Apply resolved universal-part metadata to matching Woo part products?')) {
      return;
    }
    setMessage('#dtb-pm-sync-msg', mode === 'apply' ? 'Applying sync…' : 'Running dry run…', 'warning');
    $('#dtb-pm-sync-output').text('');
    post('dtb_parts_universal_sync', { mode: mode }).done(function (res) {
      if (!res || !res.success) {
        setMessage('#dtb-pm-sync-msg', (res && res.data && res.data.message) || 'Sync failed.', 'error');
        return;
      }
      setMessage('#dtb-pm-sync-msg', (res.data || {}).message || 'Sync completed.', 'success');
      $('#dtb-pm-sync-output').text(JSON.stringify(res.data || {}, null, 2));
      loadSummary();
      loadParts(partsPage);
    }).fail(function () {
      setMessage('#dtb-pm-sync-msg', 'Sync request failed.', 'error');
    });
  }

  $(function () {
    $('.dtb-pm-tabs button').on('click', function () {
      var tab = $(this).data('dtb-tab');
      $('.dtb-pm-tabs button').removeClass('active');
      $(this).addClass('active');
      $('.dtb-pm-panel').removeClass('active');
      $('#dtb-pm-tab-' + tab).addClass('active');
      if (tab === 'universal') loadUniversal(1);
    });

    $('[data-dtb-pm-refresh]').on('click', function () { loadSummary(); loadParts(partsPage); });
    $('[data-dtb-pm-search]').on('click', function () { loadParts(1); });
    $('#dtb-pm-search').on('keydown', function (e) { if (e.key === 'Enter') loadParts(1); });
    $('#dtb-pm-brand,#dtb-pm-status,#dtb-pm-universal-status').on('change', function () { loadParts(1); });
    $(document).on('click', '[data-dtb-parts-prev]', function () { if (partsPage > 1) loadParts(partsPage - 1); });
    $(document).on('click', '[data-dtb-parts-next]', function () { if (partsPage < partsPages) loadParts(partsPage + 1); });

    $('[data-dtb-pm-add]').on('click', function () { resetForm(); openModal(); });
    $('#dtb-pm-close,#dtb-pm-close-x').on('click', closeModal);
    $('#dtb-pm-modal-wrap').on('click', function (e) { if (e.target === this) closeModal(); });

    $(document).on('click', '.dtb-pm-edit', function () {
      var id = $(this).data('id');
      post('dtb_parts_get', { id: id }).done(function (res) {
        if (!res || !res.success) return;
        var p = res.data || {};
        $('#dtb-pm-id').val(p.id || 0);
        $('#dtb-pm-title').val(p.title || '');
        $('#dtb-pm-sku').val(p.sku || '');
        $('#dtb-pm-sku').prop('readOnly', true);
        $('#dtb-pm-brand-label').val(p.brand_label || '');
        $('#dtb-pm-msku').val(p.manufacturer_sku || '');
        $('#dtb-pm-msku').prop('readOnly', true);
        $('[data-protected-hint]').prop('hidden', false);
        $('#dtb-pm-price').val(p.price || '');
        $('#dtb-pm-description').val(p.description || '');
        $('#dtb-pm-post-status').val(p.status || 'draft');
        $('#dtb-pm-modal-title').text('Edit Part #' + p.id);
        $('#dtb-pm-trash').show();
        setMessage('#dtb-pm-msg', '');
        openModal();
      });
    });

    $('#dtb-pm-save').on('click', function () {
      var title = ($('#dtb-pm-title').val() || '').trim();
      var sku = ($('#dtb-pm-sku').val() || '').trim();
      if (!title || !sku) {
        alert('Title and SKU are required.');
        return;
      }
      $('#dtb-pm-spinner').show();
      $('#dtb-pm-save').prop('disabled', true);
      post('dtb_parts_save', {
        id: $('#dtb-pm-id').val(),
        title: title,
        sku: sku,
        brand_label: $('#dtb-pm-brand-label').val(),
        manufacturer_sku: $('#dtb-pm-msku').val(),
        price: $('#dtb-pm-price').val(),
        description: $('#dtb-pm-description').val(),
        status: $('#dtb-pm-post-status').val()
      }).done(function (res) {
        $('#dtb-pm-spinner').hide();
        $('#dtb-pm-save').prop('disabled', false);
        if (!res || !res.success) {
          setMessage('#dtb-pm-msg', (res && res.data && res.data.message) || 'Save failed.', 'error');
          return;
        }
        setMessage('#dtb-pm-msg', 'Saved.', 'success');
        loadParts(partsPage);
        loadSummary();
        setTimeout(closeModal, 450);
      }).fail(function () {
        $('#dtb-pm-spinner').hide();
        $('#dtb-pm-save').prop('disabled', false);
        setMessage('#dtb-pm-msg', 'Save failed.', 'error');
      });
    });

    $('#dtb-pm-trash').on('click', function () {
      var id = parseInt($('#dtb-pm-id').val(), 10);
      if (!id || !window.confirm('Move this part to trash?')) return;
      post('dtb_parts_delete', { id: id }).done(function (res) {
        if (!res || !res.success) {
          setMessage('#dtb-pm-msg', 'Delete failed.', 'error');
          return;
        }
        closeModal();
        loadParts(1);
        loadSummary();
      });
    });

    $('#dtb-pm-import').on('click', function () {
      importCsv('dtb-pm-import-file', 'dtb_parts_import_csv', 'dtb-pm-import-spinner', 'dtb-pm-import-msg', 'dtb-pm-import-errors', function () {
        loadParts(1);
        loadSummary();
      });
    });

    $('#dtb-pm-map-import').on('click', function () {
      importCsv('dtb-pm-map-import-file', 'dtb_parts_import_schematic_map', 'dtb-pm-map-import-spinner', 'dtb-pm-map-import-msg', 'dtb-pm-map-import-errors');
    });

    $('[data-dtb-export]').on('click', function () {
      post('dtb_parts_export', { format: $(this).data('dtb-export') }).done(function (res) {
        if (!res || !res.success) {
          alert('Export failed.');
          return;
        }
        downloadFile((res.data || {}).content, (res.data || {}).mime, (res.data || {}).filename);
      });
    });

    $('[data-dtb-universal-search]').on('click', function () { loadUniversal(1); });
    $('#dtb-pm-universal-search').on('keydown', function (e) { if (e.key === 'Enter') loadUniversal(1); });
    $('#dtb-pm-universal-filter').on('change', function () { loadUniversal(1); });
    $(document).on('click', '[data-dtb-universal-prev]', function () { if (universalPage > 1) loadUniversal(universalPage - 1); });
    $(document).on('click', '[data-dtb-universal-next]', function () { if (universalPage < universalPages) loadUniversal(universalPage + 1); });

    $('[data-dtb-universal-export]').on('click', function () {
      post('dtb_parts_universal_export', { type: $(this).data('dtb-universal-export') }).done(function (res) {
        if (!res || !res.success) {
          alert((res && res.data && res.data.message) || 'Universal export failed.');
          return;
        }
        downloadFile((res.data || {}).content, (res.data || {}).mime, (res.data || {}).filename);
      });
    });

    $('[data-dtb-sync]').on('click', function () { runUniversalSync($(this).data('dtb-sync')); });

    loadSummary();
    loadParts(1);
  });
})(jQuery);

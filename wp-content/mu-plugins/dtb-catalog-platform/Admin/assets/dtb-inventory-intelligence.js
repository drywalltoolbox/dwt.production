(function ($) {
  'use strict';

  var cfg = window.DtbInventoryIntelligence || {};
  var ajaxUrl = cfg.ajaxUrl || window.ajaxurl;
  var nonce = cfg.nonce || '';
  var rollupPage = 1;
  var rollupPages = 1;

  function esc(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function post(action, payload) {
    return $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, payload || {}));
  }

  function setMessage(message, kind) {
    var $target = $('#dtb-ii-message');
    $target.removeClass('is-success is-error is-warning').text(message || '');
    if (kind) {
      $target.addClass('is-' + kind);
    }
  }

  function badge(value) {
    var text = value || 'none';
    return '<span class="dtb-ii-badge dtb-ii-badge--' + esc(String(text).toLowerCase()) + '">' + esc(text) + '</span>';
  }

  function formatDate(value) {
    if (!value) return '—';
    return String(value).replace('T', ' ').replace('+00:00', '');
  }

  function setBusy(selector, busy) {
    $(selector).prop('disabled', !!busy);
  }

  function loadHealth() {
    post('dtb_inventory_health').done(function (res) {
      if (!res || !res.success) return;
      var d = res.data || {};
      $('[data-dtb-ii-metric="stock_rows"]').text(d.stock_rows || 0);
      $('[data-dtb-ii-metric="rollup_rows"]').text(d.rollup_rows || 0);
      $('[data-dtb-ii-metric="critical_rollups"]').text(d.critical_rollups || 0);
      var latest = d.latest_stock_sync || {};
      $('[data-dtb-ii-metric="latest_sync"]').text(latest.finished_at ? formatDate(latest.finished_at) : 'Never');

      var seed = d.universal_seed || {};
      var counts = seed.counts || {};
      var files = seed.files || {};
      var readable = ['parts', 'members', 'compatibility'].filter(function (key) { return !!files[key]; }).length;
      $('[data-dtb-ii-metric="seed_parts"]').text(counts.parts || 0);
      $('[data-dtb-ii-metric="seed_members"]').text(counts.members || 0);
      $('[data-dtb-ii-metric="seed_compatibility"]').text(counts.compatibility || 0);
      $('[data-dtb-ii-metric="seed_files"]').text(readable + '/3');
    });
  }

  function loadRollups(page) {
    rollupPage = page || 1;
    post('dtb_inventory_list_rollups', {
      page: rollupPage,
      signal: $('#dtb-ii-signal').val(),
      search: $('#dtb-ii-search').val()
    }).done(function (res) {
      if (!res || !res.success) {
        $('#dtb-ii-rollup-empty').text('Unable to load inventory rollups.').show();
        return;
      }
      var d = res.data || {};
      var items = d.items || [];
      rollupPages = d.pages || 1;
      $('#dtb-ii-rollup-count').text((d.total || 0) + ' rollups');

      if (!items.length) {
        $('#dtb-ii-rollup-body').empty();
        $('#dtb-ii-rollup-empty').show();
        $('#dtb-ii-rollup-pagination').empty();
        return;
      }

      var html = '';
      $.each(items, function (_, item) {
        var breakdown = item.brand_breakdown || [];
        var breakdownHtml = '';
        $.each(breakdown.slice(0, 4), function (_, b) {
          breakdownHtml += '<div class="dtb-ii-breakdown-row"><code>' + esc(b.sku) + '</code><span>' + esc(b.brand || '—') + '</span><strong>' + esc(b.qty_available || 0) + '</strong></div>';
        });
        if (breakdown.length > 4) {
          breakdownHtml += '<div class="dtb-ii-muted">+' + (breakdown.length - 4) + ' more SKUs</div>';
        }

        html += '<tr>';
        html += '<td><code>' + esc(item.universal_part_id) + '</code><div class="dtb-ii-subline">' + esc(item.canonical_name || '—') + '</div><div class="dtb-ii-subline">' + esc(item.part_family || 'unclassified') + '</div></td>';
        html += '<td><strong class="dtb-ii-stock-number">' + esc(item.effective_qty_available || 0) + '</strong><div class="dtb-ii-subline">Total available: ' + esc(item.total_qty_available || 0) + ' · Members: ' + esc(item.active_member_count || 0) + '</div></td>';
        html += '<td>' + (breakdownHtml || '<span class="dtb-ii-muted">No stock rows</span>') + '</td>';
        html += '<td>' + badge(item.reorder_signal || 'none') + '<div class="dtb-ii-subline">Computed: ' + esc(formatDate(item.last_computed_at)) + '</div></td>';
        html += '</tr>';
      });
      $('#dtb-ii-rollup-body').html(html);
      $('#dtb-ii-rollup-empty').hide();
      renderRollupPagination();
    });
  }

  function renderRollupPagination() {
    if (rollupPages <= 1) {
      $('#dtb-ii-rollup-pagination').empty();
      return;
    }
    $('#dtb-ii-rollup-pagination').html(
      '<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-prev ' + (rollupPage <= 1 ? 'disabled' : '') + '>Prev</button>' +
      '<span>Page ' + rollupPage + ' of ' + rollupPages + '</span>' +
      '<button type="button" class="dtb-ii-btn dtb-ii-btn-secondary" data-dtb-ii-next ' + (rollupPage >= rollupPages ? 'disabled' : '') + '>Next</button>'
    );
  }

  function loadStockouts() {
    post('dtb_inventory_true_stockouts').done(function (res) {
      if (!res || !res.success) return;
      var items = (res.data || {}).items || [];
      if (!items.length) {
        $('#dtb-ii-stockout-list').html('<div class="dtb-ii-empty-inline">No true universal stockouts detected.</div>');
        return;
      }
      var html = '';
      $.each(items, function (_, item) {
        html += '<div class="dtb-ii-stockout-card">';
        html += '<code>' + esc(item.universal_part_id) + '</code>';
        html += '<strong>' + esc(item.canonical_name || 'Unnamed universal part') + '</strong>';
        html += '<span>' + esc(item.part_family || 'unclassified') + ' · ' + badge(item.reorder_signal || 'critical') + '</span>';
        html += '</div>';
      });
      $('#dtb-ii-stockout-list').html(html);
    });
  }

  function projectUniversal(mode) {
    var apply = mode === 'apply';
    if (apply && !window.confirm('Apply universal seed projection to matching WooCommerce part products? This writes _dtb_universal_part_* metadata.')) {
      return;
    }
    setMessage(apply ? 'Applying universal seed projection…' : 'Running universal seed dry run…', 'warning');
    $('#dtb-ii-projection-output').text('');
    setBusy('[data-dtb-ii-project-universal]', true);
    post('dtb_inventory_project_universal_parts', { mode: mode }).done(function (res) {
      setBusy('[data-dtb-ii-project-universal]', false);
      if (!res || !res.success) {
        setMessage((res && res.data && res.data.message) || 'Universal projection failed.', 'error');
        return;
      }
      setMessage((res.data || {}).message || 'Universal projection complete.', 'success');
      $('#dtb-ii-projection-output').text(JSON.stringify(res.data || {}, null, 2));
      loadHealth();
    }).fail(function () {
      setBusy('[data-dtb-ii-project-universal]', false);
      setMessage('Universal projection request failed.', 'error');
    });
  }

  function runStockSync() {
    setMessage('Syncing WooCommerce stock projection into the local inventory cache…', 'warning');
    setBusy('[data-dtb-ii-sync-stock]', true);
    post('dtb_inventory_sync_stock').done(function (res) {
      setBusy('[data-dtb-ii-sync-stock]', false);
      if (!res || !res.success) {
        setMessage((res && res.data && res.data.message) || 'Stock sync failed.', 'error');
        return;
      }
      setMessage((res.data || {}).message || 'Stock cache synced.', 'success');
      loadHealth();
    }).fail(function () {
      setBusy('[data-dtb-ii-sync-stock]', false);
      setMessage('Stock sync request failed.', 'error');
    });
  }

  function recomputeRollups() {
    setMessage('Recomputing universal inventory rollups…', 'warning');
    setBusy('[data-dtb-ii-recompute]', true);
    post('dtb_inventory_recompute_rollups').done(function (res) {
      setBusy('[data-dtb-ii-recompute]', false);
      if (!res || !res.success) {
        setMessage((res && res.data && res.data.message) || 'Rollup recompute failed.', 'error');
        return;
      }
      setMessage((res.data || {}).message || 'Rollups recomputed.', 'success');
      loadHealth();
      loadRollups(1);
      loadStockouts();
    }).fail(function () {
      setBusy('[data-dtb-ii-recompute]', false);
      setMessage('Rollup recompute request failed.', 'error');
    });
  }

  function fullRebuild() {
    if (!window.confirm('Run full rebuild now? This applies universal projection, syncs stock cache, and recomputes rollups.')) {
      return;
    }
    setMessage('Running full inventory intelligence rebuild…', 'warning');
    $('#dtb-ii-projection-output').text('');
    setBusy('[data-dtb-ii-full-rebuild]', true);
    post('dtb_inventory_full_rebuild').done(function (res) {
      setBusy('[data-dtb-ii-full-rebuild]', false);
      if (!res || !res.success) {
        setMessage((res && res.data && res.data.message) || 'Full rebuild failed.', 'error');
        return;
      }
      setMessage((res.data || {}).message || 'Full rebuild complete.', 'success');
      $('#dtb-ii-projection-output').text(JSON.stringify(res.data || {}, null, 2));
      loadHealth();
      loadRollups(1);
      loadStockouts();
    }).fail(function () {
      setBusy('[data-dtb-ii-full-rebuild]', false);
      setMessage('Full rebuild request failed.', 'error');
    });
  }

  function previewSubstitutes() {
    var sku = ($('#dtb-ii-substitute-sku').val() || '').trim();
    if (!sku) {
      alert('Enter a SKU.');
      return;
    }
    $('#dtb-ii-substitute-output').text('Loading…');
    post('dtb_inventory_substitute_preview', { sku: sku }).done(function (res) {
      if (!res || !res.success) {
        $('#dtb-ii-substitute-output').text((res && res.data && res.data.message) || 'Substitution preview failed.');
        return;
      }
      $('#dtb-ii-substitute-output').text(JSON.stringify(res.data || {}, null, 2));
    }).fail(function () {
      $('#dtb-ii-substitute-output').text('Substitution preview request failed.');
    });
  }

  $(function () {
    $('[data-dtb-ii-project-universal]').on('click', function () { projectUniversal($(this).data('dtb-ii-project-universal')); });
    $('[data-dtb-ii-sync-stock]').on('click', runStockSync);
    $('[data-dtb-ii-recompute]').on('click', recomputeRollups);
    $('[data-dtb-ii-full-rebuild]').on('click', fullRebuild);
    $('[data-dtb-ii-load-rollups]').on('click', function () { loadRollups(1); });
    $('#dtb-ii-search').on('keydown', function (e) { if (e.key === 'Enter') loadRollups(1); });
    $('#dtb-ii-signal').on('change', function () { loadRollups(1); });
    $(document).on('click', '[data-dtb-ii-prev]', function () { if (rollupPage > 1) loadRollups(rollupPage - 1); });
    $(document).on('click', '[data-dtb-ii-next]', function () { if (rollupPage < rollupPages) loadRollups(rollupPage + 1); });
    $('[data-dtb-ii-substitute]').on('click', previewSubstitutes);
    $('#dtb-ii-substitute-sku').on('keydown', function (e) { if (e.key === 'Enter') previewSubstitutes(); });

    loadHealth();
    loadRollups(1);
    loadStockouts();
  });
})(jQuery);

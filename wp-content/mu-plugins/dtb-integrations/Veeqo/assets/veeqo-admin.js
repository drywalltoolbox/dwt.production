/* global wp, DTBVeeqoAdmin */
(() => {
  'use strict';

  const root = document.getElementById('dtb-veeqo-admin-root');
  const config = window.DTBVeeqoAdmin || {};
  const apiFetch = window.wp && window.wp.apiFetch;

  if (!root) return;
  if (!apiFetch) {
    root.innerHTML = '<div class="dtb-veeqo-notice is-error"><strong>WordPress REST client is unavailable.</strong><span>Verify that wp-api-fetch and its dependencies are not being removed or reordered by host optimization.</span></div>';
    return;
  }

  const state = {
    view: 'overview',
    overview: null,
    inventory: { page: 1, per_page: Number(config.pageSize || 50), search: '', stock_status: '', mapping: '', type: '', orderby: 'sku', order: 'asc' },
    orders: { page: 1, per_page: 25, search: '', status: '' },
    fulfillment: { page: 1, per_page: 25, search: '', status: '' },
    pollTimer: null,
    pollCount: 0,
    busy: false,
  };

  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  const qs = (selector, context = document) => context.querySelector(selector);
  const qsa = (selector, context = document) => Array.from(context.querySelectorAll(selector));
  const fmtNumber = (value) => new Intl.NumberFormat(config.locale || undefined).format(Number(value || 0));
  const fmtMoney = (value, currency) => {
    const amount = Number(value || 0);
    try { return new Intl.NumberFormat(config.locale || undefined, { style: 'currency', currency: currency || config.currency || 'USD' }).format(amount); }
    catch (_) { return `${currency || config.currency || 'USD'} ${amount.toFixed(2)}`; }
  };
  const fmtDate = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? esc(value) : new Intl.DateTimeFormat(config.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };
  const path = (suffix) => `${config.basePath || '/dtb/v1/veeqo/admin/control-center'}${suffix}`;
  const request = (suffix, options = {}) => apiFetch({ path: path(suffix), ...options });
  const queryString = (params) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
    });
    const encoded = query.toString();
    return encoded ? `?${encoded}` : '';
  };

  function statusBadge(label, tone = '') {
    return `<span class="dtb-veeqo-status${tone ? ` is-${tone}` : ''}">${esc(label)}</span>`;
  }

  function toneForStatus(status) {
    const value = String(status || '').toLowerCase();
    if (['ready', 'completed', 'synced', 'already_synced', 'instock', 'shipped', 'processing'].includes(value)) return 'success';
    if (['queued', 'running', 'on-hold', 'onbackorder', 'in_veeqo'].includes(value)) return 'info';
    if (['warning', 'lowstock', 'not_projected', 'not_started'].includes(value)) return 'warning';
    if (['failed', 'outofstock', 'cancelled', 'not_configured'].includes(value)) return 'danger';
    return '';
  }

  function setBusy(busy) {
    state.busy = Boolean(busy);
    qsa('[data-write-action]').forEach((button) => { button.disabled = state.busy; });
  }

  function notice(message, type = 'success') {
    const holder = qs('#dtb-veeqo-notices');
    if (!holder) return;
    holder.innerHTML = `<div class="dtb-veeqo-notice is-${esc(type)}"><strong>${esc(type === 'error' ? 'Action failed' : type === 'warning' ? 'Attention' : 'Success')}</strong><span>${esc(message)}</span></div>`;
    holder.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearNotice() {
    const holder = qs('#dtb-veeqo-notices');
    if (holder) holder.innerHTML = '';
  }

  const VIEWS = [
    ['overview', 'Overview'],
    ['orders', 'Orders'],
    ['inventory', 'Inventory'],
    ['fulfillment', 'Fulfillment'],
    ['operations', 'Operations'],
    ['settings', 'Settings'],
  ];

  // Single top hero + pill-tab navigation idiom — matches the shared
  // dtb-admin.css / dtb-brikpanel-components tab and page-header grammar used
  // by QuickBooks (.dtb-qbo-tabs) and the Orders status pills, replacing the
  // previous bespoke dark sidebar sub-navigation.
  function shell() {
    root.innerHTML = `
      <header class="dtb-veeqo-header">
        <div class="dtb-veeqo-header__identity">
          <span class="dtb-veeqo-mark" aria-hidden="true">V</span>
          <div>
            <h1>Veeqo Control Center</h1>
            <p class="dtb-veeqo-header__status"><span class="dtb-veeqo-nav-dot" id="dtb-veeqo-nav-dot"></span><span id="dtb-veeqo-nav-label">Checking readiness…</span></p>
          </div>
        </div>
        <div class="dtb-veeqo-actions" id="dtb-veeqo-context-actions"></div>
      </header>
      <nav class="dtb-tabs dtb-veeqo-tabs" role="tablist" aria-label="Veeqo Control Center sections">
        ${VIEWS.map(([view, label]) => navButton(view, label)).join('')}
      </nav>
      <h2 class="dtb-veeqo-section-title" id="dtb-veeqo-page-title">Overview</h2>
      <div id="dtb-veeqo-notices" class="dtb-veeqo-notices" aria-live="polite"></div>
      <main id="dtb-veeqo-view" aria-live="polite"></main>`;
    qsa('[data-view]').forEach((button) => button.addEventListener('click', () => navigate(button.dataset.view)));
    const tabs = qs('.dtb-veeqo-tabs');
    if (tabs) tabs.addEventListener('keydown', handleTabKeys);
  }

  function navButton(view, label) {
    const active = state.view === view;
    return `<button type="button" role="tab" id="dtb-veeqo-tab-${esc(view)}" data-view="${esc(view)}" class="dtb-tab${active ? ' is-active' : ''}" aria-selected="${active ? 'true' : 'false'}" tabindex="${active ? '0' : '-1'}">${esc(label)}</button>`;
  }

  function handleTabKeys(event) {
    const tab = event.target.closest('[data-view]');
    if (!tab) return;
    const tabs = qsa('[data-view]');
    const current = tabs.indexOf(tab);
    let next = current;
    switch (event.key) {
      case 'ArrowRight': next = (current + 1) % tabs.length; break;
      case 'ArrowLeft': next = (current - 1 + tabs.length) % tabs.length; break;
      case 'Home': next = 0; break;
      case 'End': next = tabs.length - 1; break;
      default: return;
    }
    event.preventDefault();
    const target = tabs[next];
    target.focus();
    navigate(target.dataset.view);
  }

  function setContext(title, actions = '') {
    const titleEl = qs('#dtb-veeqo-page-title');
    const actionsEl = qs('#dtb-veeqo-context-actions');
    if (titleEl) titleEl.textContent = title;
    if (actionsEl) actionsEl.innerHTML = actions;
  }

  function updateNav() {
    qsa('[data-view]').forEach((button) => {
      const active = button.dataset.view === state.view;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
      button.setAttribute('tabindex', active ? '0' : '-1');
    });
  }

  async function navigate(view) {
    if (!VIEWS.some(([id]) => id === view)) return;
    state.view = view;
    updateNav();
    clearNotice();
    stopPolling();
    await loadCurrentView();
  }

  function renderLoading() {
    const view = qs('#dtb-veeqo-view');
    if (view) view.innerHTML = '<div class="dtb-veeqo-loading"><span class="spinner is-active" aria-hidden="true"></span><span>Loading current data…</span></div>';
  }

  function renderFatal(error) {
    const message = error && error.message ? error.message : 'The Veeqo control center could not load.';
    const view = qs('#dtb-veeqo-view');
    if (view) view.innerHTML = `<div class="dtb-veeqo-notice is-error"><strong>Unable to load</strong><span>${esc(message)}</span></div>`;
  }

  async function loadCurrentView() {
    renderLoading();
    try {
      if (state.view === 'overview') await loadOverview();
      if (state.view === 'inventory') await loadInventory();
      if (state.view === 'orders') await loadOrders(false);
      if (state.view === 'fulfillment') await loadOrders(true);
      if (state.view === 'operations') await loadOperations();
      if (state.view === 'settings') await loadSettings();
    } catch (error) {
      renderFatal(error);
    }
  }

  async function ensureOverview() {
    state.overview = await request('/overview');
    updateReadiness(state.overview);
    return state.overview;
  }

  function updateReadiness(data) {
    const readiness = data && data.readiness ? data.readiness : {};
    const ready = Boolean(readiness.order_projection_ready && readiness.inventory_ready);
    const dot = qs('#dtb-veeqo-nav-dot');
    const label = qs('#dtb-veeqo-nav-label');
    if (dot) dot.className = `dtb-veeqo-nav-dot ${ready ? 'is-ready' : 'is-warning'}`;
    if (label) label.textContent = ready ? 'Connected and syncing' : 'Setup needed';
  }

  async function loadOverview() {
    setContext('Overview', '<button type="button" class="dtb-veeqo-button" data-action="refresh-current">Refresh</button><button type="button" class="dtb-veeqo-button is-primary" data-action="test-connection" data-write-action>Check connection</button>');
    const data = await ensureOverview();
    const inv = data.inventory || {};
    const sync = data.sync || {};
    const orders = data.orders || {};
    const readiness = data.readiness || {};
    const view = qs('#dtb-veeqo-view');
    view.innerHTML = `
      <div class="dtb-veeqo-kpis">
        ${kpi('Inventory SKUs', inv.total, `${fmtNumber(inv.in_stock)} in stock`, '')}
        ${kpi('Low stock', inv.low_stock, 'At or below threshold', inv.low_stock > 0 ? 'warning' : 'success')}
        ${kpi('Out of stock', inv.out_of_stock, 'Currently unavailable at checkout', inv.out_of_stock > 0 ? 'danger' : 'success')}
        ${kpi('Needs Veeqo link', inv.unmapped, 'Products not yet connected to Veeqo', inv.unmapped > 0 ? 'warning' : 'success')}
        ${kpi('Processing orders', orders.processing, `${fmtNumber(orders.on_hold || 0)} on hold`, '')}
      </div>
      <div class="dtb-veeqo-grid">
        <div>
          <section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Inventory sync</h2><p>Keeping your website's stock levels in sync with Veeqo.</p></div><div class="dtb-veeqo-actions"><button class="dtb-veeqo-button" data-action="queue-dry-run" data-write-action>Preview sync</button><button class="dtb-veeqo-button is-primary" data-action="queue-reconcile" data-write-action>Sync now</button></div></div><div class="dtb-veeqo-panel-body">
            <div class="dtb-veeqo-detail-grid">
              ${detail('Last synced', sync.last_inventory_sync_at ? fmtDate(sync.last_inventory_sync_at) : 'Not yet run')}
              ${detail('Status', sync.stale ? 'Needs attention' : 'Up to date')}
            </div>
          </div></section>
          <section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Recent activity</h2><p>Your latest inventory sync history.</p></div><button class="dtb-veeqo-button is-small" data-view-jump="operations">View all</button></div><div class="dtb-veeqo-panel-body is-flush">${operationsTable(data.recent_operations || [], 6)}</div></section>
        </div>
        <aside>
          <section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Connection status</h2><p>Your Veeqo integration at a glance.</p></div></div><div class="dtb-veeqo-panel-body">${connectionSummary(readiness, sync)}</div></section>
          <section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Look up a product</h2><p>Check a product's live stock directly from Veeqo.</p></div></div><div class="dtb-veeqo-panel-body"><form id="dtb-veeqo-overview-sku" class="dtb-veeqo-inline-search"><label class="screen-reader-text" for="dtb-veeqo-overview-sku-input">Product SKU</label><input id="dtb-veeqo-overview-sku-input" maxlength="100" placeholder="Enter a product SKU" required><button class="dtb-veeqo-button" type="submit">Look up</button></form></div></section>
        </aside>
      </div>`;
    bindCommonActions();
    const form = qs('#dtb-veeqo-overview-sku');
    if (form) form.addEventListener('submit', (event) => { event.preventDefault(); inspectSku(qs('#dtb-veeqo-overview-sku-input').value); });
    if (data.active_operation) pollOperation(data.active_operation.operation_id, true);
  }

  // Overview-only, plain-language connection summary. Deliberately takes no
  // raw configuration (API key/constant names, numeric channel/warehouse/
  // delivery-method IDs) — that detail belongs on the Settings tab, where an
  // operator is actually there to configure something. Overview should read
  // like a business dashboard, not a backend diagnostics dump.
  function connectionSummary(readiness, sync) {
    const rows = [
      ['Veeqo connection', Boolean(readiness.order_projection_ready && readiness.inventory_ready), readiness.order_projection_ready && readiness.inventory_ready ? 'Connected' : 'Needs setup — see Settings'],
      ['Inventory sync', readiness.inventory_ready, sync.stale ? 'Needs attention' : 'Up to date'],
      ['Order sync', readiness.order_projection_ready, readiness.order_projection_ready ? 'Active' : 'Paused until setup is complete'],
      ['Real-time updates', readiness.webhooks_enabled, readiness.webhooks_enabled ? 'Enabled' : 'Using scheduled checks'],
    ];
    return `<div class="dtb-veeqo-readiness">${rows.map(([label, ok, value]) => `<div class="dtb-veeqo-readiness-row"><span class="dtb-veeqo-state-icon ${ok ? 'is-success' : 'is-warning'}"></span><div><span class="dtb-veeqo-state-label">${esc(label)}</span><span class="dtb-veeqo-state-detail">${esc(value)}</span></div><span class="dtb-veeqo-state-value">${ok ? 'Good' : 'Check Settings'}</span></div>`).join('')}</div>`;
  }

  function kpi(label, value, detailText, tone) {
    return `<div class="dtb-veeqo-kpi${tone ? ` is-${tone}` : ''}"><span class="dtb-veeqo-kpi-label">${esc(label)}</span><strong class="dtb-veeqo-kpi-value">${fmtNumber(value)}</strong><span class="dtb-veeqo-kpi-detail">${esc(detailText)}</span></div>`;
  }

  function detail(label, value) {
    return `<div class="dtb-veeqo-detail"><span class="dtb-veeqo-detail-label">${esc(label)}</span><span class="dtb-veeqo-detail-value">${esc(value)}</span></div>`;
  }

  function readinessRows(readiness, settings, sync) {
    const rows = [
      ['API credential', settings.api_key_configured, settings.api_key_configured ? 'Server-side credential configured' : 'Missing DTB_VEEQO_API_KEY'],
      ['Order export channel', Number(settings.channel_id) > 0, settings.channel_id ? `#${settings.channel_id}` : 'Not selected'],
      ['Warehouse', Number(settings.warehouse_id) > 0, settings.warehouse_id ? `#${settings.warehouse_id}` : 'Not selected'],
      ['Delivery method', Number(settings.delivery_method_id) > 0, settings.delivery_method_id ? `#${settings.delivery_method_id}` : 'Not selected'],
      ['Inventory projection', readiness.inventory_ready, sync.stale ? 'Stale or not initialized' : 'Current'],
      ['Order projection', readiness.order_projection_ready, readiness.order_projection_ready ? 'Ready' : 'Blocked until configuration is valid'],
      ['Inbound webhooks', readiness.webhooks_enabled, readiness.webhooks_enabled ? 'Verified contract enabled' : 'Fail-closed; polling fallback'],
    ];
    return `<div class="dtb-veeqo-readiness">${rows.map(([label, ok, value]) => `<div class="dtb-veeqo-readiness-row"><span class="dtb-veeqo-state-icon ${ok ? 'is-success' : 'is-warning'}"></span><div><span class="dtb-veeqo-state-label">${esc(label)}</span><span class="dtb-veeqo-state-detail">${esc(value)}</span></div><span class="dtb-veeqo-state-value">${ok ? 'Ready' : 'Action required'}</span></div>`).join('')}</div>`;
  }

  async function loadInventory() {
    setContext('Inventory', '<button type="button" class="dtb-veeqo-button" data-action="queue-dry-run" data-write-action>Dry run</button><button type="button" class="dtb-veeqo-button is-primary" data-action="queue-reconcile" data-write-action>Reconcile inventory</button>');
    const data = await request(`/inventory${queryString(state.inventory)}`);
    const view = qs('#dtb-veeqo-view');
    view.innerHTML = `
      <form class="dtb-veeqo-toolbar" id="dtb-veeqo-inventory-filters">
        <label class="dtb-veeqo-search"><span class="screen-reader-text">Search inventory</span><input name="search" value="${esc(state.inventory.search)}" placeholder="Search product name or SKU"></label>
        ${select('stock_status', state.inventory.stock_status, [['','All stock'],['instock','In stock'],['lowstock','Low stock'],['outofstock','Out of stock'],['onbackorder','Backorder']])}
        ${select('mapping', state.inventory.mapping, [['','All mappings'],['mapped','Mapped'],['unmapped','Unmapped']])}
        ${select('type', state.inventory.type, [['','All product types'],['simple','Simple'],['variation','Variation']])}
        <button type="submit" class="dtb-veeqo-button">Filter</button><button type="button" class="dtb-veeqo-button" data-action="clear-inventory-filters">Clear</button>
        <span class="dtb-veeqo-results-meta">${fmtNumber(data.total)} variants</span>
      </form>
      <div class="dtb-veeqo-table-wrap"><table class="dtb-veeqo-table"><thead><tr><th>Product</th><th>SKU</th><th>Location</th><th class="dtb-veeqo-number">On hand</th><th class="dtb-veeqo-number">Committed</th><th class="dtb-veeqo-number">Available</th><th class="dtb-veeqo-number">Incoming</th><th>Mapping</th><th>Updated</th><th></th></tr></thead><tbody>${inventoryRows(data.items || [])}</tbody></table></div>
      ${pagination(data, 'inventory')}`;
    bindCommonActions();
    bindPagination('inventory');
    const form = qs('#dtb-veeqo-inventory-filters');
    if (form) form.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      ['search','stock_status','mapping','type'].forEach((key) => { state.inventory[key] = String(formData.get(key) || '').trim(); });
      state.inventory.page = 1;
      loadInventory().catch(renderFatal);
    });
    qsa('[data-inspect-sku]').forEach((button) => button.addEventListener('click', () => inspectSku(button.dataset.inspectSku)));
  }

  function select(name, current, options) {
    return `<select class="dtb-veeqo-filter" name="${esc(name)}" aria-label="${esc(name.replace('_',' '))}">${options.map(([value,label]) => `<option value="${esc(value)}" ${String(current) === String(value) ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
  }

  function inventoryRows(items) {
    if (!items.length) return '<tr><td colspan="10"><div class="dtb-veeqo-empty"><strong>No matching inventory</strong>Adjust filters or run a reconciliation after validating configuration.</div></td></tr>';
    return items.map((item) => {
      const image = item.image_url ? `<img class="dtb-veeqo-thumb" src="${esc(item.image_url)}" alt="">` : '<span class="dtb-veeqo-thumb is-empty">No image</span>';
      const mapTone = item.mapping_status === 'mapped' ? 'success' : 'warning';
      return `<tr><td><div class="dtb-veeqo-product-cell">${image}<div><a class="dtb-veeqo-product-name" href="${esc(item.edit_url || '#')}">${esc(item.name || '(untitled product)')}</a><span class="dtb-veeqo-secondary">${esc(item.type)} · ${esc(item.publish_status)}</span></div></div></td><td><button type="button" class="button-link" data-inspect-sku="${esc(item.sku)}">${esc(item.sku)}</button></td><td>${esc((state.overview && state.overview.configuration && state.overview.configuration.warehouse_id) ? `Warehouse #${state.overview.configuration.warehouse_id}` : 'Configured warehouse')}</td><td class="dtb-veeqo-number">${item.on_hand == null ? '—' : fmtNumber(item.on_hand)}</td><td class="dtb-veeqo-number">${item.committed == null ? '—' : fmtNumber(item.committed)}</td><td class="dtb-veeqo-number">${item.available == null ? '—' : fmtNumber(item.available)}</td><td class="dtb-veeqo-number">${item.incoming == null ? '—' : fmtNumber(item.incoming)}</td><td>${statusBadge(item.mapping_status, mapTone)}</td><td>${fmtDate(item.updated_at)}</td><td><button type="button" class="dtb-veeqo-button is-small" data-inspect-sku="${esc(item.sku)}">Inspect</button></td></tr>`;
    }).join('');
  }

  async function loadOrders(fulfillment) {
    const key = fulfillment ? 'fulfillment' : 'orders';
    const title = fulfillment ? 'Fulfillment' : 'Orders';
    setContext(title, '<button type="button" class="dtb-veeqo-button" data-action="refresh-current">Refresh</button>');
    const data = await request(`/${key}${queryString(state[key])}`);
    const view = qs('#dtb-veeqo-view');
    view.innerHTML = `
      <form class="dtb-veeqo-toolbar" id="dtb-veeqo-order-filters">
        <label class="dtb-veeqo-search"><span class="screen-reader-text">Search orders</span><input name="search" value="${esc(state[key].search)}" placeholder="Search order ID, email, or customer"></label>
        ${select('status', state[key].status, [['','All statuses'],['processing','Processing'],['on-hold','On hold'],['shipped','Shipped'],['completed','Completed'],['failed','Failed'],['cancelled','Cancelled']])}
        <button type="submit" class="dtb-veeqo-button">Filter</button><button type="button" class="dtb-veeqo-button" data-action="clear-order-filters" data-list="${key}">Clear</button>
        <span class="dtb-veeqo-results-meta">${fmtNumber(data.total)} orders</span>
      </form>
      <div class="dtb-veeqo-table-wrap"><table class="dtb-veeqo-table"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Veeqo</th><th>Fulfillment</th><th>Tracking</th><th></th></tr></thead><tbody>${orderRows(data.items || [])}</tbody></table></div>
      ${pagination(data, key)}`;
    bindCommonActions();
    bindPagination(key);
    const form = qs('#dtb-veeqo-order-filters');
    if (form) form.addEventListener('submit', (event) => {
      event.preventDefault(); const formData = new FormData(form);
      state[key].search = String(formData.get('search') || '').trim(); state[key].status = String(formData.get('status') || ''); state[key].page = 1;
      loadOrders(fulfillment).catch(renderFatal);
    });
    qsa('[data-retry-order]').forEach((button) => button.addEventListener('click', () => retryOrder(Number(button.dataset.retryOrder))));
  }

  function orderRows(items) {
    if (!items.length) return '<tr><td colspan="9"><div class="dtb-veeqo-empty"><strong>No matching orders</strong>Adjust the filters or search by WooCommerce order ID.</div></td></tr>';
    return items.map((item) => `<tr><td><a class="dtb-veeqo-product-name" href="${esc(item.edit_url || '#')}">#${esc(item.order_number)}</a><span class="dtb-veeqo-secondary">${fmtNumber(item.item_count)} items</span></td><td>${fmtDate(item.created_at)}</td><td>${esc(item.customer || 'Guest')}<span class="dtb-veeqo-secondary">${esc(item.email)}</span></td><td>${statusBadge(item.status, toneForStatus(item.status))}</td><td>${esc(fmtMoney(item.total, item.currency))}</td><td>${item.veeqo_order_id ? `<strong>#${esc(item.veeqo_order_id)}</strong>` : '—'}<span class="dtb-veeqo-secondary">${esc(item.sync_status)}</span>${item.sync_error ? `<span class="dtb-veeqo-secondary">${esc(item.sync_error)}</span>` : ''}</td><td>${statusBadge(item.fulfillment_status.replaceAll('_',' '), toneForStatus(item.fulfillment_status))}</td><td>${item.tracking_number ? `${esc(item.tracking_number)}<span class="dtb-veeqo-secondary">${esc(item.carrier)}</span>` : '—'}</td><td><button type="button" class="dtb-veeqo-button is-small" data-retry-order="${Number(item.order_id)}" data-write-action>Retry Veeqo</button></td></tr>`).join('');
  }

  function pagination(data, key) {
    const page = Number(data.page || 1); const pages = Number(data.pages || 1);
    return `<div class="dtb-veeqo-pagination" data-pagination="${esc(key)}"><span>Page ${page} of ${pages}</span><button type="button" class="dtb-veeqo-button is-small" data-page="${Math.max(1,page-1)}" ${page <= 1 ? 'disabled' : ''}>Previous</button><button type="button" class="dtb-veeqo-button is-small" data-page="${Math.min(pages,page+1)}" ${page >= pages ? 'disabled' : ''}>Next</button></div>`;
  }

  function bindPagination(key) {
    qsa(`[data-pagination="${key}"] [data-page]`).forEach((button) => button.addEventListener('click', () => {
      state[key].page = Number(button.dataset.page || 1);
      if (key === 'inventory') loadInventory().catch(renderFatal); else loadOrders(key === 'fulfillment').catch(renderFatal);
    }));
  }

  async function loadOperations() {
    setContext('Operations', '<button type="button" class="dtb-veeqo-button" data-action="queue-dry-run" data-write-action>Queue dry run</button><button type="button" class="dtb-veeqo-button is-primary" data-action="queue-reconcile" data-write-action>Queue reconciliation</button>');
    const data = await request('/operations');
    const view = qs('#dtb-veeqo-view');
    view.innerHTML = `<div class="dtb-veeqo-grid"><div><section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Active operation</h2><p>Single-flight Action Scheduler state with bounded continuation and retries.</p></div></div><div class="dtb-veeqo-panel-body">${data.active ? operationCard(data.active, true) : '<div class="dtb-veeqo-empty"><strong>No active operation</strong>Queue a dry run before applying inventory changes.</div>'}</div></section><section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Operation history</h2><p>Most recent durable inventory operations.</p></div></div><div class="dtb-veeqo-panel-body is-flush">${operationsTable(data.items || [], 20)}</div></section></div><aside><section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Execution policy</h2></div></div><div class="dtb-veeqo-panel-body"><div class="dtb-veeqo-readiness">${policyRow('Owner','dtb-integrations / Veeqo')}${policyRow('Queue group','dtb-integrations')}${policyRow('Concurrency','One active inventory operation')}${policyRow('Retries','3 transient retries')}${policyRow('Authority','Configured Veeqo warehouse')}</div></div></section></aside></div>`;
    bindCommonActions();
    if (data.active) pollOperation(data.active.operation_id, true);
  }

  function modeLabel(mode) {
    const value = String(mode || '').toLowerCase();
    if (value === 'dry_run') return 'Preview';
    if (value === 'reconcile') return 'Sync';
    return mode ? esc(String(mode).replaceAll('_', ' ')) : '—';
  }

  function operationCard(operation, full) {
    const counts = operation.counts || {};
    const progress = Math.min(100, Math.max(3, Number(counts.pages || 0) * 10));
    return `<div class="dtb-veeqo-operation-card"><div class="dtb-veeqo-operation-head"><strong>${modeLabel(operation.mode)}</strong>${statusBadge(operation.status, toneForStatus(operation.status))}</div><span class="dtb-veeqo-secondary">${esc(operation.operation_id || '')}</span>${full ? `<div class="dtb-veeqo-progress"><span style="width:${progress}%"></span></div><div class="dtb-veeqo-detail-grid" style="margin-top:12px">${detail('Pages',counts.pages||0)}${detail('Sellables',counts.sellables_seen||0)}${detail('Updated',counts.updated||0)}${detail('Unmapped',counts.unmapped||0)}${detail('Next page',operation.next_page||1)}${detail('Attempt',operation.attempt||0)}</div>` : ''}${operation.error ? `<p class="dtb-veeqo-secondary">${esc(operation.error)}</p>` : ''}</div>`;
  }

  function operationsTable(items, limit) {
    const rows = items.slice(0, limit);
    if (!rows.length) return '<div class="dtb-veeqo-empty"><strong>No sync activity yet</strong>Your inventory sync history will appear here.</div>';
    return `<table class="dtb-veeqo-table"><thead><tr><th>Date</th><th>Type</th><th>Status</th><th>Products checked</th><th>Updated</th><th>Needs review</th></tr></thead><tbody>${rows.map((op) => `<tr><td>${fmtDate(op.created_at)}</td><td>${modeLabel(op.mode)}</td><td>${statusBadge(op.status,toneForStatus(op.status))}</td><td>${fmtNumber(op.counts && op.counts.sellables_seen)}</td><td>${fmtNumber(op.counts && op.counts.updated)}</td><td>${fmtNumber((op.counts&&op.counts.unmapped||0)+(op.counts&&op.counts.duplicates||0)+(op.counts&&op.counts.missing_stock||0))}</td></tr>`).join('')}</tbody></table>`;
  }

  function policyRow(label, value) {
    return `<div class="dtb-veeqo-readiness-row"><span class="dtb-veeqo-state-icon is-success"></span><div><span class="dtb-veeqo-state-label">${esc(label)}</span><span class="dtb-veeqo-state-detail">${esc(value)}</span></div></div>`;
  }

  async function loadSettings() {
    setContext('Settings', '<button type="button" class="dtb-veeqo-button" data-action="test-connection" data-write-action>Discover and validate</button><button type="submit" form="dtb-veeqo-settings-form" class="dtb-veeqo-button is-primary" data-write-action>Save settings</button>');
    const settings = await request('/settings');
    const view = qs('#dtb-veeqo-view');
    const candidates = settings.candidates || {};
    const missing = (settings.readiness && settings.readiness.missing) || [];
    const setupSummary = !settings.api_key_configured
      ? 'Server-side API credential is missing.'
      : missing.includes('channel_id') && !(candidates.channels || []).length
        ? 'API credential detected. Veeqo channels were found, but none is verified for DTB API order creation. Your WooCommerce channel remains visible below.'
        : missing.length ? 'Select missing operational resources, save, and validate.'
          : 'Configured resources are present. Run validation to verify them.';
    view.innerHTML = `<div class="dtb-veeqo-grid"><div><div class="dtb-veeqo-notice is-warning" role="status"><strong>Connection setup</strong><span>${esc(setupSummary)}</span></div><section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Operational mappings</h2><p>Credentials remain server-side. Only non-secret resource identities are editable here.</p></div></div><div class="dtb-veeqo-panel-body"><form id="dtb-veeqo-settings-form" class="dtb-veeqo-form">${settingsField('channel_id','Order export channel',settings.channel_id,candidates.channels,settings.sources&&settings.sources.channel_id,'Only Direct channels are eligible for the existing API order-creation contract. WooCommerce channels are shown for reference, not selectable.')}${settingsField('warehouse_id','Fulfillment warehouse',settings.warehouse_id,candidates.warehouses,settings.sources&&settings.sources.warehouse_id,'Authoritative inventory and fulfillment location.')}${settingsField('delivery_method_id','Default delivery method',settings.delivery_method_id,candidates.delivery_methods,settings.sources&&settings.sources.delivery_method_id,'Order projection mapping; not live checkout carrier rating.')}<div class="dtb-veeqo-actions"><button type="submit" class="dtb-veeqo-button is-primary" data-write-action>Save operational settings</button><button type="button" class="dtb-veeqo-button" data-action="test-connection" data-write-action>Discover and validate</button></div></form></div></section></div><aside><section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><div><h2>Security boundary</h2></div></div><div class="dtb-veeqo-panel-body">${readinessRows({ order_projection_ready: settings.readiness&&settings.readiness.ready, inventory_ready: settings.readiness&&settings.readiness.ready, webhooks_enabled: false }, settings, { stale:false })}<p class="dtb-veeqo-secondary">The API key is never rendered, returned by REST, or stored by this form. Configure DTB_VEEQO_API_KEY on the server.</p></div></section>${settings.last_validation&&settings.last_validation.errors&&settings.last_validation.errors.length?`<section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><h2>Validation findings</h2></div><div class="dtb-veeqo-panel-body">${settings.last_validation.errors.map((error)=>`<div class="dtb-veeqo-notice is-warning"><span>${esc(error)}</span></div>`).join('')}</div></section>`:''}</aside></div>`;
    bindCommonActions();
    const form = qs('#dtb-veeqo-settings-form');
    if (form) form.addEventListener('submit', saveSettings);
  }

  function settingsField(name, label, value, candidates, source, description) {
    const locked = source === 'server_constant';
    const rows = Array.isArray(candidates) ? candidates : [];
    const channel = name === 'channel_id';
    const options = rows.map((item) => {
      const eligible = !channel || item.order_export_eligible === true;
      const type = channel ? ' · ' + (item.type_code || 'unknown') + (eligible ? '' : ' · view only') : '';
      return `<option value="${Number(item.id)}" ${Number(value)===Number(item.id)?'selected':''} ${!eligible?'disabled':''}>${esc(item.name)} (#${Number(item.id)})${esc(type)}</option>`;
    }).join('');
    const input = rows.length
      ? `<select id="dtb-${esc(name)}" name="${esc(name)}" ${locked?'disabled':''}><option value="0">Select a Veeqo resource</option>${options}</select>`
      : channel
        ? `<div class="dtb-veeqo-field-description">No validated Direct order-export channel is available. WooCommerce channels do not authorize API order creation.</div>`
        : `<input id="dtb-${esc(name)}" type="number" min="0" name="${esc(name)}" value="${Number(value||0)}" ${locked?'disabled':''}>`;
    return `<div class="dtb-veeqo-field"><label for="dtb-${esc(name)}">${esc(label)}<span class="dtb-veeqo-field-description">${esc(description)}</span></label><div>${input}<span class="dtb-veeqo-field-description">Source: ${esc(source||'wordpress_option')}${locked?' — change the server constant to modify.':''}</span></div></div>`;
  }

  async function saveSettings(event) {
    event.preventDefault();
    const form = event.currentTarget; const formData = new FormData(form); const data = {};
    ['channel_id','warehouse_id','delivery_method_id'].forEach((key)=>{ if (formData.has(key)) data[key]=Number(formData.get(key)||0); });
    setBusy(true);
    try { const response = await request('/settings',{method:'POST',data}); notice(response.message||'Settings saved.'); await loadSettings(); }
    catch(error){ notice(error.message||'Settings could not be saved.','error'); }
    finally{ setBusy(false); }
  }

  function bindCommonActions() {
    qsa('[data-action="refresh-current"]').forEach((button)=>button.addEventListener('click',()=>loadCurrentView()));
    qsa('[data-action="test-connection"]').forEach((button)=>button.addEventListener('click',testConnection));
    qsa('[data-action="queue-dry-run"]').forEach((button)=>button.addEventListener('click',()=>queueInventory(true)));
    qsa('[data-action="queue-reconcile"]').forEach((button)=>button.addEventListener('click',()=>queueInventory(false)));
    qsa('[data-action="clear-inventory-filters"]').forEach((button)=>button.addEventListener('click',()=>{state.inventory={...state.inventory,page:1,search:'',stock_status:'',mapping:'',type:''};loadInventory().catch(renderFatal);}));
    qsa('[data-action="clear-order-filters"]').forEach((button)=>button.addEventListener('click',()=>{const key=button.dataset.list||'orders';state[key]={...state[key],page:1,search:'',status:''};loadOrders(key==='fulfillment').catch(renderFatal);}));
    qsa('[data-view-jump]').forEach((button)=>button.addEventListener('click',()=>navigate(button.dataset.viewJump)));
  }

  async function testConnection() {
    setBusy(true); clearNotice();
    try {
      const result = await request('/connection/test', { method: 'POST' });
      await ensureOverview();
      if (state.view === 'settings') await loadSettings();
      else if (state.view === 'overview') await loadOverview();
      if (result.success) {
        notice('Veeqo connection and operational resource mappings validated.');
      } else {
        const diagnostics = result.diagnostics || {};
        const errors = Array.isArray(diagnostics.errors) ? diagnostics.errors : [];
        const missing = (diagnostics.readiness && diagnostics.readiness.missing) || [];
        notice(errors[0] || (missing.length ? 'Missing required settings: ' + missing.join(', ') : 'Veeqo configuration is incomplete.'), 'warning');
      }
    } catch (error) {
      notice(error.message || 'Veeqo validation could not complete.', 'error');
    } finally { setBusy(false); }
  }

  async function queueInventory(dryRun) {
    if (!dryRun && !(state.overview && state.overview.readiness && state.overview.readiness.inventory_ready)) {
      notice('Inventory configuration is not ready. Complete setup before applying stock changes.', 'warning');
      return;
    }
    if (!dryRun && !window.confirm((config.labels&&config.labels.confirmReconcile)||'Apply Veeqo inventory to WooCommerce?')) return;
    setBusy(true); clearNotice();
    try { const response=await request('/inventory/reconcile',{method:'POST',data:{dry_run:dryRun}}); notice(response.message||'Inventory operation queued.'); const operation=response.operation; if(operation&&operation.operation_id) pollOperation(operation.operation_id,true); if(state.view==='operations') await loadOperations(); }
    catch(error){ notice(error.message||'Inventory operation could not be queued.','error'); }
    finally{ setBusy(false); }
  }

  async function retryOrder(orderId) {
    if (!orderId || !window.confirm((config.labels&&config.labels.confirmRetry)||'Queue Veeqo retry?')) return;
    setBusy(true);
    try { const response=await request(`/orders/${orderId}/retry`,{method:'POST',data:{reason:'operator_retry'}}); notice(response.message||'Order retry queued.'); await loadOrders(state.view==='fulfillment'); }
    catch(error){ notice(error.message||'Order retry could not be queued.','error'); }
    finally{ setBusy(false); }
  }

  async function inspectSku(sku) {
    const clean = String(sku||'').trim(); if(!clean) return;
    openDrawer('<div class="dtb-veeqo-loading"><span class="spinner is-active"></span><span>Inspecting exact SKU…</span></div>', `SKU ${clean}`);
    try { const data=await request(`/sku?sku=${encodeURIComponent(clean)}`); renderSkuDrawer(data); }
    catch(error){ renderDrawerBody(`<div class="dtb-veeqo-notice is-error"><span>${esc(error.message||'SKU inspection failed.')}</span></div>`); }
  }

  function openDrawer(body,title) {
    closeDrawer();
    const drawer=document.createElement('div'); drawer.className='dtb-veeqo-drawer-backdrop'; drawer.id='dtb-veeqo-drawer'; drawer.innerHTML=`<aside class="dtb-veeqo-drawer" role="dialog" aria-modal="true" aria-labelledby="dtb-veeqo-drawer-title"><header class="dtb-veeqo-drawer-header"><h2 id="dtb-veeqo-drawer-title">${esc(title)}</h2><button type="button" class="dtb-veeqo-drawer-close" aria-label="Close">×</button></header><div class="dtb-veeqo-drawer-body" id="dtb-veeqo-drawer-body">${body}</div></aside>`; document.body.appendChild(drawer);
    qs('.dtb-veeqo-drawer-close',drawer).addEventListener('click',closeDrawer); drawer.addEventListener('click',(event)=>{if(event.target===drawer)closeDrawer();}); document.addEventListener('keydown',drawerEscape);
  }
  function drawerEscape(event){if(event.key==='Escape')closeDrawer();}
  function closeDrawer(){const drawer=qs('#dtb-veeqo-drawer');if(drawer)drawer.remove();document.removeEventListener('keydown',drawerEscape);}
  function renderDrawerBody(html){const body=qs('#dtb-veeqo-drawer-body');if(body)body.innerHTML=html;}

  function renderSkuDrawer(data) {
    const woo=data.woo||null; const veeqo=Array.isArray(data.veeqo)?data.veeqo:[]; const warehouse=(state.overview&&state.overview.configuration&&state.overview.configuration.warehouse_id)||0;
    let html=`<section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><h2>WooCommerce projection</h2></div><div class="dtb-veeqo-panel-body">${woo?`<div class="dtb-veeqo-detail-grid">${detail('Product',woo.name)}${detail('Product ID',woo.product_id)}${detail('Stock quantity',woo.stock_quantity==null?'—':woo.stock_quantity)}${detail('Stock status',woo.stock_status)}${detail('Manage stock',woo.manage_stock?'Yes':'No')}${detail('Veeqo sellable ID',woo.veeqo_sellable_id||'Unmapped')}</div><p><a class="dtb-veeqo-button is-small" href="${esc(woo.edit_url||'#')}">Edit Woo product</a></p>`:'<div class="dtb-veeqo-empty"><strong>No exact WooCommerce SKU</strong>The Veeqo sellable will not be projected until an exact unique Woo SKU exists.</div>'}</div></section>`;
    html+=`<section class="dtb-veeqo-panel"><div class="dtb-veeqo-panel-header"><h2>Live Veeqo result</h2></div><div class="dtb-veeqo-panel-body">${veeqo.length?veeqo.map((row)=>`<div class="dtb-veeqo-operation-card"><strong>${esc(row.product_name)}</strong><span class="dtb-veeqo-secondary">Sellable #${esc(row.sellable_id)}</span><div class="dtb-veeqo-table-wrap" style="margin-top:10px"><table class="dtb-veeqo-table"><thead><tr><th>Warehouse</th><th>On hand</th><th>Committed</th><th>Available</th><th>Authority</th></tr></thead><tbody>${(row.stock_entries||[]).map((entry)=>`<tr><td>${esc(entry.warehouse_name||`#${entry.warehouse_id}`)}</td><td>${entry.on_hand==null?'—':fmtNumber(entry.on_hand)}</td><td>${entry.committed==null?'—':fmtNumber(entry.committed)}</td><td>${entry.available==null?'—':fmtNumber(entry.available)}</td><td>${Number(entry.warehouse_id)===Number(warehouse)?statusBadge('Configured','success'):'—'}</td></tr>`).join('')}</tbody></table></div></div>`).join(''):'<div class="dtb-veeqo-empty"><strong>No exact Veeqo sellable found</strong>Verify the SKU in Veeqo and the configured credential scope.</div>'}</div></section>`;
    renderDrawerBody(html);
  }

  function stopPolling(){if(state.pollTimer)window.clearTimeout(state.pollTimer);state.pollTimer=null;state.pollCount=0;}
  function pollOperation(operationId,reset=false){if(!operationId)return;if(reset){stopPolling();}if(state.pollCount>=Number(config.pollLimit||100)){notice('Operation polling timed out. Durable execution may continue; refresh Operations to check status.','warning');return;}state.pollCount++;state.pollTimer=window.setTimeout(async()=>{try{const operation=await request(`/operations/${encodeURIComponent(operationId)}`);if(operation.status==='queued'||operation.status==='running'){pollOperation(operationId);}else{stopPolling();notice(`Inventory operation ${operation.status}.`,operation.status==='completed'?'success':'error');if(state.view==='operations')await loadOperations();if(state.view==='overview')await loadOverview();}}catch(error){stopPolling();notice(error.message||'Operation status polling failed.','error');}},Number(config.pollMs||3000));}

  shell();
  loadOverview().catch(renderFatal);
})();

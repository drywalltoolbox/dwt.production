/* global wp, DTBVeeqoAdmin */
(() => {
  'use strict';

  const config = window.DTBVeeqoAdmin || {};
  const apiFetch = window.wp && window.wp.apiFetch;
  if (!apiFetch) return;

  const base = config.basePath || '/dtb/v1/veeqo/admin/control-center';
  const state = {
    page: 1,
    per_page: Number(config.pageSize || 50),
    search: '',
    stock_status: '',
    mapping: '',
    type: '',
    orderby: 'sku',
    order: 'asc',
    selected: new Set(),
    data: null,
    overview: null,
    mounted: false,
    requestId: 0,
    searchTimer: null,
  };

  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  const fmt = (value) => value == null ? '—' : new Intl.NumberFormat(config.locale || undefined).format(Number(value));
  const fmtDate = (value) => {
    if (!value) return '—';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? esc(value) : new Intl.DateTimeFormat(config.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
  };
  const request = (suffix, options = {}) => apiFetch({ path: `${base}${suffix}`, ...options });
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  function isInventoryView() {
    const active = qs('.dtb-veeqo-tabs [data-view].is-active, .dtb-veeqo-tabs [aria-selected="true"]');
    return Boolean(active && /inventory/i.test(active.textContent || ''));
  }

  function queryString() {
    const params = new URLSearchParams();
    ['page','per_page','search','stock_status','mapping','type','orderby','order'].forEach((key) => {
      const value = state[key];
      if (value !== '' && value != null) params.set(key, String(value));
    });
    return `?${params.toString()}`;
  }

  async function load() {
    const view = qs('#dtb-veeqo-view');
    if (!view || !isInventoryView()) return;
    const requestId = ++state.requestId;
    view.innerHTML = loadingMarkup('Loading inventory workspace…');
    try {
      const [data, overview] = await Promise.all([
        request(`/inventory${queryString()}`),
        state.overview ? Promise.resolve(state.overview) : request('/overview').catch(() => null),
      ]);
      if (requestId !== state.requestId || !isInventoryView()) return;
      state.data = data;
      state.overview = overview;
      render();
    } catch (error) {
      if (requestId !== state.requestId) return;
      view.innerHTML = `<div class="dtb-veeqo-notice is-error"><strong>Unable to load inventory</strong><span>${esc(error.message || 'The inventory workspace could not load.')}</span></div>`;
    }
  }

  function loadingMarkup(label) {
    return `<div class="dtb-veeqo-workspace-loading"><span class="spinner is-active" aria-hidden="true"></span><span>${esc(label)}</span></div>`;
  }

  function inventorySummary(items) {
    const overview = state.overview && state.overview.inventory ? state.overview.inventory : {};
    const visible = Array.isArray(items) ? items : [];
    return {
      total: Number(overview.total ?? state.data?.total ?? 0),
      inStock: Number(overview.in_stock ?? visible.filter((item) => item.stock_status === 'instock').length),
      lowStock: Number(overview.low_stock ?? visible.filter((item) => Number(item.available) > 0 && Number(item.available) <= 3).length),
      outOfStock: Number(overview.out_of_stock ?? visible.filter((item) => Number(item.available) <= 0).length),
      unmapped: Number(overview.unmapped ?? visible.filter((item) => item.mapping_status !== 'mapped').length),
      anomalies: visible.filter((item) => Array.isArray(item.anomalies) && item.anomalies.length).length,
    };
  }

  function metric(label, value, detail, tone = '', filter = '') {
    return `<button type="button" class="dtb-veeqo-inventory-metric ${tone ? `is-${tone}` : ''}" ${filter ? `data-metric-filter="${esc(filter)}"` : ''}>
      <span class="dtb-veeqo-inventory-metric-label">${esc(label)}</span>
      <strong>${fmt(value)}</strong>
      <span>${esc(detail)}</span>
    </button>`;
  }

  function render() {
    const view = qs('#dtb-veeqo-view');
    if (!view || !state.data || !isInventoryView()) return;
    const items = Array.isArray(state.data.items) ? state.data.items : [];
    const summary = inventorySummary(items);
    const allSelected = items.length > 0 && items.every((item) => state.selected.has(Number(item.product_id)));

    view.innerHTML = `
      <section class="dtb-veeqo-inventory-workspace">
        <div class="dtb-veeqo-inventory-summary" aria-label="Inventory summary">
          ${metric('Sellable SKUs', summary.total, `${fmt(summary.inStock)} in stock`, 'info')}
          ${metric('In stock', summary.inStock, 'Available for checkout', 'success', 'instock')}
          ${metric('Low stock', summary.lowStock, 'At or below threshold', 'warning', 'lowstock')}
          ${metric('Out of stock', summary.outOfStock, 'Unavailable for checkout', 'danger', 'outofstock')}
          ${metric('Unmapped', summary.unmapped, 'Missing or mismatched mapping', 'warning', 'unmapped')}
          ${metric('Visible anomalies', summary.anomalies, 'On current page', summary.anomalies ? 'danger' : 'success', 'negative')}
        </div>

        <div class="dtb-veeqo-inventory-commandbar">
          <form id="dtb-veeqo-workspace-filters" class="dtb-veeqo-workspace-toolbar">
            <label class="dtb-veeqo-search dtb-veeqo-inventory-search"><span class="screen-reader-text">Search inventory</span><input name="search" value="${esc(state.search)}" placeholder="Search product name or SKU" autocomplete="off"></label>
            ${select('stock_status', state.stock_status, [['','All stock'],['instock','In stock'],['lowstock','Low stock'],['outofstock','Out of stock'],['onbackorder','Backorder'],['negative','Negative available'],['committed_exceeds_on_hand','Committed exceeds on hand']])}
            ${select('mapping', state.mapping, [['','All mappings'],['mapped','Mapped'],['unmapped','Unmapped'],['mismatch','Mismatch']])}
            ${select('type', state.type, [['','All product types'],['simple','Simple'],['variation','Variation']])}
            <button class="dtb-veeqo-button is-primary" type="submit">Apply filters</button>
            <button class="dtb-veeqo-button" type="button" data-workspace-action="clear">Clear</button>
          </form>
          <div class="dtb-veeqo-inventory-viewbar">
            <div class="dtb-veeqo-sort-control"><span>Sort</span>${select('sort_control', `${state.orderby}:${state.order}`, [
              ['sku:asc','SKU A–Z'],['sku:desc','SKU Z–A'],['name:asc','Product A–Z'],['name:desc','Product Z–A'],['available:desc','Available high to low'],['available:asc','Available low to high'],['updated:desc','Recently updated'],['mapping:asc','Mapping status']
            ])}</div>
            <span class="dtb-veeqo-results-meta">${fmt(state.data.total)} sellable SKUs</span>
            ${select('per_page', state.per_page, [[25,'25 rows'],[50,'50 rows'],[100,'100 rows']])}
          </div>
        </div>

        <div class="dtb-veeqo-selection-bar ${state.selected.size ? 'is-visible' : ''}">
          <strong>${fmt(state.selected.size)} selected</strong>
          <button type="button" class="dtb-veeqo-button is-primary" data-workspace-action="bulk-edit">Bulk edit fields</button>
          <button type="button" class="dtb-veeqo-button" data-workspace-action="export">Export selected</button>
          <button type="button" class="dtb-veeqo-button" data-workspace-action="clear-selection">Clear</button>
          <span>Inventory quantities and immutable identifiers remain read-only.</span>
        </div>

        <div class="dtb-veeqo-table-wrap dtb-veeqo-inventory-table-wrap">
          <table class="dtb-veeqo-table dtb-veeqo-workspace-table">
            <thead><tr>
              <th class="dtb-veeqo-select-column"><input type="checkbox" data-select-page ${allSelected ? 'checked' : ''} aria-label="Select current page"></th>
              ${sortHeader('Product','name')}${sortHeader('SKU','sku')}<th>Location</th>${sortHeader('On hand','on_hand',true)}${sortHeader('Committed','committed',true)}${sortHeader('Available','available',true)}${sortHeader('Incoming','incoming',true)}${sortHeader('Mapping','mapping')}${sortHeader('Updated','updated')}<th class="dtb-veeqo-actions-column"></th>
            </tr></thead>
            <tbody>${rows(items)}</tbody>
          </table>
        </div>
        ${pagination()}
      </section>
      <div id="dtb-veeqo-workspace-overlay"></div>`;
    bind();
  }

  function select(name, current, options) {
    return `<select class="dtb-veeqo-filter" name="${esc(name)}" aria-label="${esc(name.replaceAll('_',' '))}">${options.map(([value, label]) => `<option value="${esc(value)}" ${String(value) === String(current) ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
  }

  function sortHeader(label, key, number = false) {
    const active = state.orderby === key;
    const direction = active ? state.order : 'none';
    return `<th class="${number ? 'dtb-veeqo-number' : ''}" aria-sort="${active ? (state.order === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" class="dtb-veeqo-sort ${active ? 'is-active' : ''}" data-sort="${esc(key)}"><span>${esc(label)}</span><span class="dtb-veeqo-sort-icon" aria-hidden="true">${direction === 'asc' ? '↑' : direction === 'desc' ? '↓' : '↕'}</span></button></th>`;
  }

  function anomalyBadges(item) {
    const anomalies = Array.isArray(item.anomalies) ? item.anomalies : [];
    if (!anomalies.length) return '<span class="dtb-veeqo-row-state is-normal">Normal</span>';
    return anomalies.map((value) => `<span class="dtb-veeqo-row-state is-danger">${esc(String(value).replaceAll('_',' '))}</span>`).join('');
  }

  function rows(items) {
    if (!items.length) return '<tr><td colspan="11"><div class="dtb-veeqo-empty"><strong>No matching inventory</strong><span>Adjust filters, search a different SKU, or clear the current view.</span></div></td></tr>';
    return items.map((item) => {
      const selected = state.selected.has(Number(item.product_id));
      const image = item.image_url ? `<img class="dtb-veeqo-thumb" src="${esc(item.image_url)}" alt="" loading="lazy">` : '<span class="dtb-veeqo-thumb is-empty">No image</span>';
      const available = Number(item.available);
      return `<tr class="${selected ? 'is-selected' : ''}" data-product-row="${Number(item.product_id)}">
        <td class="dtb-veeqo-select-column"><input type="checkbox" data-select-product="${Number(item.product_id)}" ${selected ? 'checked' : ''} aria-label="Select ${esc(item.name)}"></td>
        <td><div class="dtb-veeqo-product-cell">${image}<div><button type="button" class="dtb-veeqo-product-name" data-inspect-product="${Number(item.product_id)}">${esc(item.name || '(untitled product)')}</button><span class="dtb-veeqo-secondary">${esc(item.type)} · ${esc(item.publish_status)}</span></div></div></td>
        <td><button type="button" class="dtb-veeqo-sku-link" data-inspect-product="${Number(item.product_id)}">${esc(item.sku)}</button></td>
        <td><span class="dtb-veeqo-location">${esc(item.location || 'Configured warehouse')}</span></td>
        <td class="dtb-veeqo-number">${fmt(item.on_hand)}</td>
        <td class="dtb-veeqo-number">${fmt(item.committed)}</td>
        <td class="dtb-veeqo-number ${available < 0 ? 'is-negative' : available === 0 ? 'is-zero' : 'is-positive'}"><strong>${fmt(item.available)}</strong></td>
        <td class="dtb-veeqo-number">${fmt(item.incoming)}</td>
        <td><span class="dtb-veeqo-status is-${item.mapping_status === 'mapped' ? 'success' : item.mapping_status === 'mismatch' ? 'danger' : 'warning'}">${esc(item.mapping_status)}</span></td>
        <td><span class="dtb-veeqo-updated">${fmtDate(item.updated_at)}</span></td>
        <td class="dtb-veeqo-row-actions"><button type="button" class="dtb-veeqo-icon-button" data-inspect-product="${Number(item.product_id)}" aria-label="Inspect ${esc(item.name)}">•••</button><div class="dtb-veeqo-row-anomalies">${anomalyBadges(item)}</div></td>
      </tr>`;
    }).join('');
  }

  function pagination() {
    const page = Number(state.data.page || 1);
    const pages = Number(state.data.pages || 1);
    const start = state.data.total ? ((page - 1) * Number(state.data.per_page || state.per_page)) + 1 : 0;
    const end = Math.min(Number(state.data.total || 0), page * Number(state.data.per_page || state.per_page));
    const pageButtons = [];
    const first = Math.max(1, page - 2);
    const last = Math.min(pages, page + 2);
    for (let i = first; i <= last; i += 1) pageButtons.push(`<button type="button" class="dtb-veeqo-page-button ${i === page ? 'is-active' : ''}" data-page="${i}" ${i === page ? 'aria-current="page"' : ''}>${i}</button>`);
    return `<div class="dtb-veeqo-inventory-pagination"><span>Showing ${fmt(start)}–${fmt(end)} of ${fmt(state.data.total)} sellable SKUs</span><div class="dtb-veeqo-page-controls"><button type="button" class="dtb-veeqo-page-button" data-page="${Math.max(1,page-1)}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>${pageButtons.join('')}<button type="button" class="dtb-veeqo-page-button" data-page="${Math.min(pages,page+1)}" ${page >= pages ? 'disabled' : ''} aria-label="Next page">›</button></div></div>`;
  }

  function bind() {
    const form = qs('#dtb-veeqo-workspace-filters');
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      const data = new FormData(form);
      ['search','stock_status','mapping','type'].forEach((key) => { state[key] = String(data.get(key) || '').trim(); });
      state.page = 1;
      state.selected.clear();
      load();
    });
    qs('input[name="search"]', form)?.addEventListener('input', (event) => {
      window.clearTimeout(state.searchTimer);
      state.searchTimer = window.setTimeout(() => {
        state.search = String(event.target.value || '').trim();
        state.page = 1;
        state.selected.clear();
        load();
      }, 350);
    });
    qs('select[name="sort_control"]')?.addEventListener('change', (event) => {
      const [orderby, order] = String(event.target.value).split(':');
      state.orderby = orderby;
      state.order = order;
      state.page = 1;
      load();
    });
    qs('select[name="per_page"]')?.addEventListener('change', (event) => {
      state.per_page = Number(event.target.value || 50);
      state.page = 1;
      state.selected.clear();
      load();
    });
    qsa('[data-sort]').forEach((button) => button.addEventListener('click', () => {
      const key = button.dataset.sort;
      if (state.orderby === key) state.order = state.order === 'asc' ? 'desc' : 'asc';
      else { state.orderby = key; state.order = 'asc'; }
      state.page = 1;
      load();
    }));
    qsa('[data-page]').forEach((button) => button.addEventListener('click', () => { state.page = Number(button.dataset.page || 1); load(); }));
    qs('[data-select-page]')?.addEventListener('change', (event) => {
      (state.data.items || []).forEach((item) => event.target.checked ? state.selected.add(Number(item.product_id)) : state.selected.delete(Number(item.product_id)));
      render();
    });
    qsa('[data-select-product]').forEach((box) => box.addEventListener('change', () => {
      const id = Number(box.dataset.selectProduct);
      box.checked ? state.selected.add(id) : state.selected.delete(id);
      render();
    }));
    qsa('[data-inspect-product]').forEach((button) => button.addEventListener('click', () => inspect(Number(button.dataset.inspectProduct))));
    qsa('[data-workspace-action]').forEach((button) => button.addEventListener('click', () => action(button.dataset.workspaceAction)));
    qsa('[data-metric-filter]').forEach((button) => button.addEventListener('click', () => {
      const value = button.dataset.metricFilter;
      if (value === 'unmapped') { state.mapping = 'unmapped'; state.stock_status = ''; }
      else { state.stock_status = value; state.mapping = ''; }
      state.page = 1;
      state.selected.clear();
      load();
    }));
  }

  async function inspect(id) {
    const overlay = qs('#dtb-veeqo-workspace-overlay');
    if (!overlay) return;
    overlay.innerHTML = `<div class="dtb-veeqo-drawer-backdrop" data-close-drawer></div><aside class="dtb-veeqo-drawer dtb-veeqo-product-inspector" role="dialog" aria-modal="true" aria-label="Product inspector">${loadingMarkup('Loading product…')}</aside>`;
    try {
      const item = await request(`/inventory/${id}`);
      const image = item.image_url ? `<img src="${esc(item.image_url)}" alt="" class="dtb-veeqo-inspector-image">` : '<span class="dtb-veeqo-inspector-image is-empty">No image</span>';
      const anomalyList = Array.isArray(item.anomalies) && item.anomalies.length ? item.anomalies.map((value) => `<span class="dtb-veeqo-row-state is-danger">${esc(String(value).replaceAll('_',' '))}</span>`).join('') : '<span class="dtb-veeqo-row-state is-normal">No anomalies</span>';
      overlay.innerHTML = `<div class="dtb-veeqo-drawer-backdrop" data-close-drawer></div><aside class="dtb-veeqo-drawer dtb-veeqo-product-inspector" role="dialog" aria-modal="true" aria-label="Product inspector">
        <header class="dtb-veeqo-inspector-header"><div class="dtb-veeqo-inspector-title">${image}<div><div class="dtb-veeqo-inspector-badges"><span class="dtb-veeqo-status is-${item.mapping_status === 'mapped' ? 'success' : 'warning'}">${esc(item.mapping_status)}</span><span class="dtb-veeqo-status is-${item.stock_status === 'instock' ? 'success' : 'danger'}">${esc(item.stock_status)}</span></div><h2>${esc(item.name)}</h2><span>${esc(item.type)} · ${esc(item.publish_status)} · SKU ${esc(item.sku)}</span></div></div><button type="button" class="dtb-veeqo-drawer-close" data-close-drawer aria-label="Close">×</button></header>
        <nav class="dtb-veeqo-inspector-tabs" aria-label="Product inspector sections"><button class="is-active" type="button">Summary</button><button type="button" data-scroll-inspector="inventory">Inventory</button><button type="button" data-scroll-inspector="mapping">Mapping</button><button type="button" data-scroll-inspector="history">History</button></nav>
        <div class="dtb-veeqo-drawer-body">
          <section id="dtb-inspector-inventory"><div class="dtb-veeqo-section-heading"><div><h3>Inventory</h3><p>WooCommerce projection and configured Veeqo warehouse state.</p></div></div><div class="dtb-veeqo-inspector-stock-grid">${stockCard('On hand',item.on_hand)}${stockCard('Committed',item.committed)}${stockCard('Available',item.available,Number(item.available)<0?'danger':'success')}${stockCard('Incoming',item.incoming)}</div><div class="dtb-veeqo-detail-grid">${detail('Warehouse',item.location)}${detail('Stock status',item.stock_status)}${detail('Low-stock threshold',item.low_stock_amount == null ? 'Inherited' : item.low_stock_amount)}${detail('Backorders',item.backorders)}</div></section>
          <section><div class="dtb-veeqo-section-heading"><div><h3>At a glance</h3></div></div><div class="dtb-veeqo-inspector-anomalies">${anomalyList}</div></section>
          <section id="dtb-inspector-mapping"><div class="dtb-veeqo-section-heading"><div><h3>Mapping</h3><p>Read-only identity links between WooCommerce and Veeqo.</p></div></div><div class="dtb-veeqo-detail-grid">${detail('Mapping status',item.mapping_status)}${detail('Veeqo sellable ID',item.veeqo_sellable_id || '—')}${detail('Mapped SKU',item.veeqo_mapped_sku || '—')}${detail('WooCommerce ID',item.product_id)}${detail('Parent ID',item.parent ? item.parent.id : '—')}${detail('Catalog visibility',item.catalog_visibility)}</div></section>
          <section id="dtb-inspector-history"><div class="dtb-veeqo-section-heading"><div><h3>History</h3></div></div>${(item.history || []).map((entry) => `<div class="dtb-veeqo-history-row"><strong>${esc(entry.label)}</strong><span>${fmtDate(entry.at)}</span></div>`).join('') || '<p class="description">No history is available.</p>'}</section>
        </div>
        <footer><a class="dtb-veeqo-button is-primary" href="${esc(item.edit_url)}">Open WooCommerce product</a>${item.parent ? `<a class="dtb-veeqo-button" href="${esc(item.parent.url)}">Open parent product</a>` : ''}</footer>
      </aside>`;
      qsa('[data-close-drawer]', overlay).forEach((element) => element.addEventListener('click', () => { overlay.innerHTML = ''; }));
      qsa('[data-scroll-inspector]', overlay).forEach((button) => button.addEventListener('click', () => {
        qs(`#dtb-inspector-${button.dataset.scrollInspector}`, overlay)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }));
    } catch (error) {
      overlay.innerHTML = '';
      window.alert(error.message || 'Unable to inspect product.');
    }
  }

  function stockCard(label, value, tone = '') {
    return `<div class="dtb-veeqo-inspector-stock ${tone ? `is-${tone}` : ''}"><span>${esc(label)}</span><strong>${fmt(value)}</strong></div>`;
  }
  function detail(label, value) { return `<div class="dtb-veeqo-detail"><span class="dtb-veeqo-detail-label">${esc(label)}</span><span class="dtb-veeqo-detail-value">${esc(value)}</span></div>`; }

  function action(name) {
    if (name === 'clear') { Object.assign(state, { page:1, search:'', stock_status:'', mapping:'', type:'', orderby:'sku', order:'asc' }); state.selected.clear(); load(); }
    if (name === 'clear-selection') { state.selected.clear(); render(); }
    if (name === 'bulk-edit') bulkEditor();
    if (name === 'export') exportSelected();
  }

  function bulkEditor() {
    if (!state.selected.size) return;
    const overlay = qs('#dtb-veeqo-workspace-overlay');
    overlay.innerHTML = `<div class="dtb-veeqo-modal-backdrop" data-close-modal></div><section class="dtb-veeqo-modal" role="dialog" aria-modal="true" aria-label="Bulk edit products"><header><div><span class="dtb-veeqo-modal-eyebrow">Bulk operation</span><h2>Edit ${fmt(state.selected.size)} products</h2><p>Only WooCommerce-owned merchandising fields can be changed. Inventory and identifiers remain read-only.</p></div><button type="button" data-close-modal aria-label="Close">×</button></header><form id="dtb-veeqo-bulk-form"><div class="dtb-veeqo-bulk-grid"><label>Status<select name="status"><option value="">No change</option><option value="publish">Publish</option><option value="draft">Draft</option><option value="private">Private</option><option value="pending">Pending review</option></select></label><label>Catalog visibility<select name="catalog_visibility"><option value="">No change</option><option value="visible">Visible</option><option value="catalog">Catalog only</option><option value="search">Search only</option><option value="hidden">Hidden</option></select></label><label>Backorders<select name="backorders"><option value="">No change</option><option value="no">Do not allow</option><option value="notify">Allow, notify customer</option><option value="yes">Allow</option></select></label><label>Low-stock threshold<input type="number" name="low_stock_amount" min="0" max="100000" placeholder="No change"></label></div><div id="dtb-veeqo-bulk-preview"></div><footer><button type="button" class="dtb-veeqo-button" data-close-modal>Cancel</button><button type="submit" class="dtb-veeqo-button is-primary">Preview changes</button></footer></form></section>`;
    qsa('[data-close-modal]', overlay).forEach((element) => element.addEventListener('click', () => { overlay.innerHTML = ''; }));
    qs('#dtb-veeqo-bulk-form', overlay).addEventListener('submit', previewBulk);
  }

  async function previewBulk(event) {
    event.preventDefault();
    const raw = Object.fromEntries(new FormData(event.currentTarget).entries());
    const changes = Object.fromEntries(Object.entries(raw).filter(([, value]) => value !== ''));
    const payload = { product_ids: Array.from(state.selected), changes };
    const holder = qs('#dtb-veeqo-bulk-preview');
    holder.innerHTML = loadingMarkup('Validating preview…');
    try {
      const preview = await request('/inventory/bulk-preview', { method: 'POST', data: payload });
      holder.innerHTML = `<div class="dtb-veeqo-preview-summary"><strong>${fmt(preview.items.length)} products ready</strong><span>Review the affected fields before applying.</span></div><div class="dtb-veeqo-preview-list">${preview.items.slice(0,30).map((item) => `<div><strong>${esc(item.sku)}</strong><span>${esc(item.name)}</span><small>${esc(Object.keys(changes).join(', '))}</small></div>`).join('')}</div><button type="button" class="dtb-veeqo-button is-primary" id="dtb-veeqo-apply-bulk">Apply confirmed changes</button>`;
      qs('#dtb-veeqo-apply-bulk').addEventListener('click', async () => {
        if (!window.confirm(`Apply these changes to ${preview.items.length} products?`)) return;
        const result = await request('/inventory/bulk-apply', { method: 'POST', data: { ...payload, preview_hash: preview.preview_hash } });
        window.alert(result.message || 'Bulk update completed.');
        qs('#dtb-veeqo-workspace-overlay').innerHTML = '';
        state.selected.clear();
        load();
      });
    } catch (error) {
      holder.innerHTML = `<div class="dtb-veeqo-notice is-error"><strong>Preview failed</strong><span>${esc(error.message || 'Unable to validate changes.')}</span></div>`;
    }
  }

  function exportSelected() {
    const selectedRows = (state.data.items || []).filter((item) => state.selected.has(Number(item.product_id)));
    const csv = [['Product ID','SKU','Product','On hand','Committed','Available','Incoming','Mapping'], ...selectedRows.map((item) => [item.product_id,item.sku,item.name,item.on_hand,item.committed,item.available,item.incoming,item.mapping_status])].map((row) => row.map((value) => `"${String(value == null ? '' : value).replaceAll('"','""')}"`).join(',')).join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = `veeqo-inventory-selection-${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  const observer = new MutationObserver(() => {
    const inventoryActive = isInventoryView();
    const baseTable = qs('#dtb-veeqo-view .dtb-veeqo-table');
    if (!state.mounted && inventoryActive && baseTable) {
      state.mounted = true;
      load();
    }
    if (!inventoryActive) state.mounted = false;
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();

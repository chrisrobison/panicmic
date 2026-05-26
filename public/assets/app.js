/* NextUp client. Vanilla JS, no build step. */

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const appConfig = {
  csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  page: document.querySelector('meta[name="app-page"]')?.content || '',
  basePath: document.querySelector('meta[name="app-base-path"]')?.content || ''
};
const basePath = appConfig.basePath.replace(/\/$/, '');

function url(path) {
  const normalized = `/${String(path || '').replace(/^\/+/, '')}`;
  return normalized === '/' ? (basePath || '/') : `${basePath}${normalized}`;
}

async function api(path, options = {}) {
  const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
  if (!['GET', undefined].includes(options.method)) headers['X-CSRF-Token'] = appConfig.csrf;
  const response = await fetch(url(path), { ...options, headers });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
}

function setStatus(target, message) {
  const el = typeof target === 'string' ? $(target) : target;
  if (el) el.textContent = message;
}

/* ---------- Public queue + display ---------- */

async function loadQueue() {
  const data = await api('/api/queue');
  renderPublicQueue(data.queue);
  renderAdminQueue(data.queue);
  renderDisplay(data.queue, data.display);
  renderAdminStats(data.queue);
  return data;
}

function renderPublicQueue(queue) {
  $$('[data-public-queue]').forEach(container => {
    container.innerHTML = queue.filter(item => !['completed', 'skipped', 'canceled'].includes(item.queue_status)).map((item, index) => `
      <div class="queue-item status-${escapeHtml(item.queue_status)}">
        <div><strong>${index + 1}. ${escapeHtml(item.singer_name)}</strong><br>${escapeHtml(item.title)} - ${escapeHtml(item.artist)}</div>
        <span>${escapeHtml(item.queue_status.replace('_', ' '))}</span>
      </div>
    `).join('') || '<p>No singers in queue yet.</p>';
  });
}

function renderAdminQueue(queue) {
  const container = $('[data-admin-queue]');
  if (!container) return;
  container.innerHTML = queue.map(item => `
    <article class="queue-item status-${escapeHtml(item.queue_status)}" draggable="true" data-request-id="${item.request_id}">
      <div>
        <strong>${escapeHtml(item.position)}. ${escapeHtml(item.singer_name)}</strong>
        <p>${escapeHtml(item.title)} - ${escapeHtml(item.artist)} ${item.song_source === 'shared' ? '<span class="badge shared">shared</span>' : ''} ${item.notes ? `<br><small>${escapeHtml(item.notes)}</small>` : ''}</p>
        ${item.youtube_url ? `<a class="youtube-link" href="${escapeHtml(item.youtube_url)}" target="_blank" rel="noreferrer">YouTube: ${escapeHtml(item.youtube_title || 'karaoke video')}</a>` : '<small class="muted">No YouTube match attached</small>'}
      </div>
      <div class="queue-actions">
        ${['up_next', 'now_singing', 'completed', 'skipped', 'canceled'].map(status => `<button data-status="${status}" data-id="${item.request_id}">${status.replace('_', ' ')}</button>`).join('')}
        <button data-youtube="${item.request_id}">Find video</button>
      </div>
    </article>
  `).join('') || '<p class="muted">Queue is empty.</p>';
  enableDrag(container);
}

function renderAdminStats(queue) {
  const root = $('[data-admin-stats]');
  if (!root) return;
  const counts = { queue: 0, up_next: 0, now_singing: 0, completed: 0 };
  for (const item of queue) {
    if (item.queue_status === 'up_next') counts.up_next++;
    else if (item.queue_status === 'now_singing') counts.now_singing++;
    else if (item.queue_status === 'completed') counts.completed++;
    if (['pending', 'up_next', 'now_singing'].includes(item.queue_status)) counts.queue++;
  }
  for (const [key, value] of Object.entries(counts)) {
    const el = $(`[data-stat="${key}"]`, root);
    if (el) el.textContent = value;
  }
}

function renderDisplay(queue, display = {}) {
  const now = $('[data-display-now]');
  if (!now) return;
  const current = queue.find(item => item.request_id === display.now_request_id) || queue.find(item => item.queue_status === 'now_singing');
  const next = queue.find(item => item.queue_status === 'up_next') || queue.find(item => item.queue_status === 'pending');
  now.innerHTML = current ? `${escapeHtml(current.singer_name)}<small>${escapeHtml(current.title)} - ${escapeHtml(current.artist)}</small>` : '<span>Ready for requests</span>';
  $('[data-up-next]').innerHTML = next ? `<div class="queue-item"><strong>${escapeHtml(next.singer_name)}</strong><span>${escapeHtml(next.title)}</span></div>` : '<p>Queue is open.</p>';
  $('[data-display-queue]').innerHTML = queue.filter(item => item.queue_status === 'pending').slice(0, 8).map(item => `<div class="queue-item"><strong>${escapeHtml(item.singer_name)}</strong><span>${escapeHtml(item.title)}</span></div>`).join('');
  const announcement = $('[data-display-announcement]');
  if (announcement) {
    announcement.hidden = display.mode !== 'announcement' || !display.announcement;
    announcement.textContent = display.announcement || '';
  }
  const qr = $('[data-qr]');
  if (qr && !qr.dataset.done) {
    qr.dataset.done = '1';
    qr.innerHTML = qrSvg(location.origin + url('/'));
  }
}

function qrSvg(text) {
  return `<svg viewBox="0 0 120 120" role="img" aria-label="Request QR placeholder"><rect width="120" height="120" fill="#fff"/><path fill="#111" d="M8 8h32v32H8zM80 8h32v32H80zM8 80h32v32H8zM50 50h10v10H50zM70 50h10v10H70zM50 70h30v10H50zM90 60h10v40H90zM58 88h20v12H58z"/></svg><small>${escapeHtml(text)}</small>`;
}

/* ---------- Catalog (public + admin) ---------- */

const catalogState = { page: 1, size: 50, total: 0, lastFilters: {} };

function readCatalogFilters() {
  return {
    query: $('[data-song-query]')?.value || $('[name="song_search"]')?.value || '',
    genre: $('[data-song-genre]')?.value || '',
    decade: $('[data-song-decade]')?.value || ''
  };
}

async function searchSongs(reset = true) {
  if (reset) catalogState.page = 1;
  const filters = { ...readCatalogFilters(), page: catalogState.page, size: catalogState.size };
  catalogState.lastFilters = filters;
  const isAdmin = appConfig.page === 'admin-songs';
  const endpoint = isAdmin ? '/api/admin/songs' : '/api/catalog';
  const params = new URLSearchParams(filters);
  const data = await api(`${endpoint}?${params.toString()}`);
  catalogState.total = data.total || 0;
  renderCatalog(data, isAdmin);
  renderCatalogMeta(data);
}

function renderCatalog(data, isAdmin) {
  const target = $('[data-song-results]') || $('[data-song-table]');
  if (!target) return;
  const html = (data.songs || []).map(song => isAdmin ? adminSongCard(song) : publicSongButton(song)).join('');
  target.innerHTML = html || '<p class="muted">No songs found.</p>';
}

function renderCatalogMeta(data) {
  const meta = $('[data-catalog-meta]');
  if (meta) {
    const total = data.total ?? (data.songs?.length ?? 0);
    const showing = data.songs?.length ?? 0;
    const start = total ? ((data.page - 1) * data.size) + 1 : 0;
    const end = start + showing - 1;
    const breakdown = (data.local_total !== undefined && data.shared_total !== undefined)
      ? ` (${data.local_total} tenant + ${data.shared_total} shared)`
      : '';
    meta.textContent = total
      ? `Showing ${start}-${end} of ${total}${breakdown}`
      : 'No songs match the filter.';
  }
  const indicator = $('[data-page-indicator]');
  if (indicator) {
    const pages = Math.max(1, Math.ceil((data.total || 0) / (data.size || 50)));
    indicator.textContent = `Page ${data.page || 1} / ${pages}`;
  }
  const prev = $('[data-page-prev]');
  const next = $('[data-page-next]');
  if (prev) prev.disabled = (data.page || 1) <= 1;
  if (next) next.disabled = (data.page || 1) * (data.size || 50) >= (data.total || 0);
}

function publicSongButton(song) {
  const link = song.video_url || song.provider_url || song.lyrics_url || '';
  const badge = song.source === 'shared' ? '<span class="badge shared">shared</span>' : '<span class="badge local">tenant</span>';
  return `
    <button class="song-result" type="button"
            data-song-pick="1"
            data-song-source="${escapeHtml(song.source || 'local')}"
            data-song-id="${escapeHtml(song.id)}"
            data-song-label="${escapeHtml(song.title)} - ${escapeHtml(song.artist)}">
      <strong>${escapeHtml(song.title)}</strong> ${badge}<br>
      <span>${escapeHtml(song.artist)}</span>
      ${link ? `<small>${escapeHtml(song.video_provider || 'video')} available</small>` : ''}
    </button>
  `;
}

function providerOptions(selected) {
  return ['', 'youtube', 'karafun', 'stingray', 'singa', 'local'].map(provider => {
    const label = provider === '' ? 'Custom / none' : provider;
    return `<option value="${provider}" ${provider === (selected || '') ? 'selected' : ''}>${label}</option>`;
  }).join('');
}

function adminSongCard(song) {
  return `
    <form class="song-card song-catalog-editor" data-song-update="${song.id}">
      <div class="song-editor-grid">
        <label>Title<input name="title" value="${escapeHtml(song.title)}" required></label>
        <label>Artist<input name="artist" value="${escapeHtml(song.artist)}" required></label>
        <label>Genre<input name="genre" value="${escapeHtml(song.genre || '')}"></label>
        <label>Decade<input name="decade" type="number" min="1900" max="2090" step="10" value="${escapeHtml(song.decade || '')}"></label>
        <label>Popularity<input name="popularity" type="number" min="0" value="${escapeHtml(song.popularity || 0)}"></label>
        <label>Video URL<input name="video_url" type="url" value="${escapeHtml(song.video_url || '')}"></label>
        <label>Provider<select name="video_provider">${providerOptions(song.video_provider)}</select></label>
        <label>Provider track ID<input name="provider_track_id" value="${escapeHtml(song.provider_track_id || '')}"></label>
        <label>Provider URL<input name="provider_url" type="url" value="${escapeHtml(song.provider_url || '')}"></label>
        <label>Lyrics URL<input name="lyrics_url" type="url" value="${escapeHtml(song.lyrics_url || '')}"></label>
      </div>
      <div class="song-card-actions">
        ${song.video_url ? `<a href="${escapeHtml(song.video_url)}" target="_blank" rel="noreferrer">Open video</a>` : ''}
        ${song.provider_url ? `<a href="${escapeHtml(song.provider_url)}" target="_blank" rel="noreferrer">Open provider</a>` : ''}
        <button class="primary">Update</button>
        <button type="button" class="link danger" data-song-delete="${song.id}">Delete</button>
        <span data-status></span>
      </div>
    </form>
  `;
}

function enableDrag(container) {
  let dragged = null;
  container.addEventListener('dragstart', event => { dragged = event.target.closest('[data-request-id]'); });
  container.addEventListener('dragover', event => {
    event.preventDefault();
    const item = event.target.closest('[data-request-id]');
    if (item && dragged && item !== dragged) container.insertBefore(dragged, item);
  });
  container.addEventListener('drop', async () => {
    const ids = $$('[data-request-id]', container).map(item => Number(item.dataset.requestId));
    await api('/api/queue/reorder', { method: 'PATCH', body: JSON.stringify({ request_ids: ids }) });
  });
}

/* ---------- Tenant settings (KJ) ---------- */

async function loadSettings() {
  const form = $('[data-settings-form]');
  if (!form) return;
  try {
    const data = await api('/api/admin/settings');
    const settings = data.settings || {};
    for (const [name, value] of Object.entries(settings)) {
      const field = form.elements.namedItem(name);
      if (!field) continue;
      if (field.type === 'checkbox') field.checked = !!value;
      else field.value = value ?? '';
    }
    const yt = $('[data-youtube-status]', form);
    if (yt) {
      yt.textContent = data.youtube_enabled
        ? 'YouTube API key is configured.'
        : 'YouTube auto-attach is disabled until YOUTUBE_API_KEY is set in .env.';
    }
  } catch (error) {
    setStatus($('[data-status]', form), error.message);
  }
}

async function loadBranding() {
  const form = $('[data-branding-form]');
  if (!form) return;
  try {
    const data = await api('/api/admin/branding');
    for (const [name, value] of Object.entries(data.branding || {})) {
      const field = form.elements.namedItem(name);
      if (field) field.value = value || '';
    }
  } catch (error) {
    setStatus($('[data-status]', form), error.message);
  }
}

/* ---------- Content uploads ---------- */

async function loadContentFiles() {
  const container = $('[data-content-files]');
  if (!container) return;
  try {
    const data = await api('/api/admin/content');
    container.innerHTML = data.files.map(file => `
      <article class="content-card">
        <strong>${escapeHtml(file.name)}</strong>
        <span>${Math.ceil(Number(file.size || 0) / 1024)} KB</span>
        <a href="${escapeHtml(file.url)}" target="_blank" rel="noreferrer">Open</a>
      </article>
    `).join('') || '<p class="muted">No content uploaded yet.</p>';
  } catch (error) {
    container.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
  }
}

/* ---------- Super-admin tenants ---------- */

const tenantState = { byId: new Map() };

async function loadTenants() {
  const rows = $('[data-tenant-rows]');
  if (!rows) return;
  try {
    const data = await api('/api/super/tenants');
    const list = data.tenants || [];
    tenantState.byId = new Map(list.map(t => [String(t.id), t]));
    rows.innerHTML = list.length
      ? list.map(renderTenantRow).join('')
      : '<tr><td colspan="6" class="muted">No tenants yet.</td></tr>';
  } catch (error) {
    rows.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`;
  }
}

function renderTenantRow(t) {
  const primary = (t.domains || []).find(d => d.is_primary) || (t.domains || [])[0];
  const viewUrl = tenantViewUrl(t);
  return `
    <tr data-tenant-row="${t.id}" tabindex="0" role="button" aria-label="Edit ${escapeHtml(t.venue_name)}">
      <td>
        <strong>${escapeHtml(t.venue_name)}</strong>
        <div class="muted">${escapeHtml(t.night_name)}</div>
      </td>
      <td>${escapeHtml(t.slug)}</td>
      <td>${primary ? `<code>${escapeHtml(primary.domain)}</code>` : '<span class="muted">—</span>'}</td>
      <td><code>${escapeHtml(t.database_name)}</code></td>
      <td><span class="badge status-${escapeHtml(t.status)}">${escapeHtml(t.status)}</span></td>
      <td class="row-actions">
        <button class="icon-btn" data-tenant-kj="${t.id}" title="Open KJ console" aria-label="Open KJ console for ${escapeHtml(t.venue_name)}">${iconHeadphones()}</button>
        ${viewUrl
          ? `<a class="icon-btn" href="${escapeHtml(viewUrl)}" target="_blank" rel="noreferrer" title="Open tenant site" data-tenant-view aria-label="Open ${escapeHtml(primary ? primary.domain : t.slug)} in new tab">${iconExternal()}</a>`
          : `<span class="icon-btn disabled" title="No domain attached" aria-disabled="true">${iconExternal()}</span>`}
      </td>
    </tr>
  `;
}

function tenantViewUrl(t) {
  if (t.public_request_url) return t.public_request_url;
  const primary = (t.domains || []).find(d => d.is_primary) || (t.domains || [])[0];
  if (!primary) return '';
  const port = location.port ? `:${location.port}` : '';
  return `${location.protocol}//${primary.domain}${port}${basePath || '/'}`;
}

function iconExternal() {
  return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7"/><path d="M21 3l-9 9"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>';
}

function iconHeadphones() {
  return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/><path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
}

function openTenantEditor(id) {
  const tenant = tenantState.byId.get(String(id));
  const drawer = $('[data-tenant-editor]');
  const form = $('[data-tenant-edit-form]');
  if (!tenant || !drawer || !form) return;
  form.dataset.tenantId = String(tenant.id);
  $('[data-editor-title]').textContent = `Edit ${tenant.venue_name}`;
  const fields = {
    slug: tenant.slug,
    venue_name: tenant.venue_name,
    night_name: tenant.night_name,
    database_name: tenant.database_name,
    timezone: tenant.timezone,
    signup_mode: tenant.signup_mode || 'both',
    status: tenant.status || 'active',
    public_request_url: tenant.public_request_url || '',
    projection_url: tenant.projection_url || ''
  };
  for (const [name, value] of Object.entries(fields)) {
    const field = form.elements.namedItem(name);
    if (field) field.value = value ?? '';
  }
  renderEditorDomains(tenant);
  setStatus($('[data-status]', form), '');
  drawer.hidden = false;
  requestAnimationFrame(() => drawer.classList.add('open'));
  document.body.classList.add('drawer-open');
}

function closeTenantEditor() {
  const drawer = $('[data-tenant-editor]');
  if (!drawer) return;
  drawer.classList.remove('open');
  document.body.classList.remove('drawer-open');
  setTimeout(() => { drawer.hidden = true; }, 220);
}

function renderEditorDomains(tenant) {
  const list = $('[data-editor-domains]');
  if (!list) return;
  const domains = tenant.domains || [];
  list.innerHTML = domains.length
    ? domains.map(d => `
        <li data-domain-id="${d.id}">
          <span class="domain-name">${escapeHtml(d.domain)}</span>
          ${d.is_primary
            ? '<span class="badge primary">primary</span>'
            : `<button type="button" class="link" data-domain-primary="${d.id}">Set primary</button>`}
          <button type="button" class="link danger" data-domain-remove="${d.id}">Remove</button>
        </li>
      `).join('')
    : '<li class="muted">No domains attached yet.</li>';
}

async function refreshEditorTenant(id) {
  try {
    const data = await api(`/api/super/tenants/${id}`);
    if (data.tenant) {
      tenantState.byId.set(String(id), data.tenant);
      renderEditorDomains(data.tenant);
    }
  } catch (error) { /* list reload handles fallback */ }
  await loadTenants();
}

/* ---------- Super-admin shared catalog (infinite scroll) ---------- */

const sharedState = { page: 1, size: 50, total: 0, loading: false, hasMore: true, loaded: 0 };
let sharedObserver = null;

function sharedRowsHtml(songs) {
  return songs.map(song => `
    <tr data-shared-row="${song.id}">
      <td><strong>${escapeHtml(song.title)}</strong></td>
      <td>${escapeHtml(song.artist)}</td>
      <td>${escapeHtml(song.year || '')}</td>
      <td>${escapeHtml(song.genre || '')}</td>
      <td>${escapeHtml(Array.isArray(song.languages) ? song.languages.join(', ') : (song.languages || ''))}</td>
      <td class="row-actions"><button type="button" class="link danger" data-shared-delete="${song.id}">Remove</button></td>
    </tr>
  `).join('');
}

function setSharedStatus(message) {
  const el = $('[data-shared-status]');
  if (el) el.textContent = message || '';
}

function updateSharedMeta() {
  const meta = $('[data-shared-meta]');
  if (!meta) return;
  if (!sharedState.total) {
    meta.textContent = 'Catalog is empty. Import songs.csv below.';
    return;
  }
  meta.textContent = `Showing ${sharedState.loaded.toLocaleString()} of ${sharedState.total.toLocaleString()}`;
}

async function loadSharedCatalog(reset = true) {
  if (sharedState.loading) return;
  const rows = $('[data-shared-rows]');
  if (!rows) return;
  if (reset) {
    sharedState.page = 1;
    sharedState.total = 0;
    sharedState.loaded = 0;
    sharedState.hasMore = true;
    rows.innerHTML = '';
  }
  if (!sharedState.hasMore) return;

  sharedState.loading = true;
  rows.classList.add('loading-more');
  setSharedStatus('Loading…');

  const query = $('[data-shared-query]')?.value || '';
  try {
    const params = new URLSearchParams({ query, page: sharedState.page, size: sharedState.size });
    const data = await api(`/api/super/catalog?${params.toString()}`);
    sharedState.total = data.total || 0;
    const songs = data.songs || [];

    if (songs.length === 0 && sharedState.loaded === 0) {
      rows.innerHTML = '<tr><td colspan="6" class="muted">No songs match.</td></tr>';
      sharedState.hasMore = false;
    } else {
      rows.insertAdjacentHTML('beforeend', sharedRowsHtml(songs));
      sharedState.loaded += songs.length;
    }

    sharedState.hasMore = sharedState.loaded < sharedState.total && songs.length > 0;
    sharedState.page++;
    updateSharedMeta();
    setSharedStatus(sharedState.hasMore ? '' : (sharedState.total ? 'End of catalog.' : ''));
  } catch (error) {
    setSharedStatus(error.message);
  } finally {
    sharedState.loading = false;
    rows.classList.remove('loading-more');
  }

  const totalEl = $('[data-shared-total]');
  if (totalEl) {
    try {
      const stats = await api('/api/super/catalog/stats');
      totalEl.textContent = (stats.total || 0).toLocaleString();
    } catch { /* ignore */ }
  }

  // If the viewport is taller than the loaded content, the sentinel will still
  // be in view — keep pulling pages until either the viewport is filled or
  // we run out. Defer to a microtask so the DOM has settled.
  if (sharedState.hasMore) {
    requestAnimationFrame(() => {
      const sentinel = $('[data-shared-sentinel]');
      if (sentinel) {
        const rect = sentinel.getBoundingClientRect();
        if (rect.top < window.innerHeight + 300) {
          loadSharedCatalog(false);
        }
      }
    });
  }
}

function setupSharedObserver() {
  if (sharedObserver || !window.IntersectionObserver) return;
  const sentinel = $('[data-shared-sentinel]');
  if (!sentinel) return;
  sharedObserver = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && sharedState.hasMore && !sharedState.loading) {
      loadSharedCatalog(false);
    }
  }, { rootMargin: '300px 0px' });
  sharedObserver.observe(sentinel);
}

async function streamSharedImport(form) {
  const status = $('[data-status]', form);
  const progress = $('[data-import-progress]');
  const fill = $('[data-import-fill]');
  const summary = $('[data-import-summary]');
  const fileInput = form.querySelector('input[type="file"]');
  if (!fileInput || !fileInput.files.length) {
    if (status) status.textContent = 'Pick a CSV first.';
    return;
  }
  const file = fileInput.files[0];
  const fileSize = file.size;
  if (status) status.textContent = 'Uploading…';
  if (progress) progress.hidden = false;
  if (fill) fill.style.width = '0%';
  if (summary) summary.textContent = '';

  const body = new FormData();
  body.append('file', file);
  const response = await fetch(url('/api/super/catalog/import'), {
    method: 'POST',
    headers: { 'X-CSRF-Token': appConfig.csrf, Accept: 'application/x-ndjson' },
    body
  });

  if (!response.ok || !response.body) {
    const errBody = await response.json().catch(() => ({}));
    if (status) status.textContent = errBody.error || `Import failed (${response.status})`;
    return;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let bytesProcessed = 0;
  let lastEvent = null;

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    bytesProcessed += value.length;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';
    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed) continue;
      try {
        const event = JSON.parse(trimmed);
        lastEvent = event;
        if (event.error) {
          if (status) status.textContent = event.error;
          continue;
        }
        if (fill) fill.style.width = Math.min(99, Math.round((bytesProcessed / Math.max(fileSize, 1)) * 100)) + '%';
        if (summary) summary.textContent = `Seen ${event.seen ?? 0}, imported ${event.imported ?? 0}, skipped ${event.skipped ?? 0}.`;
      } catch { /* ignore non-JSON line */ }
    }
  }
  if (fill) fill.style.width = '100%';
  if (status) status.textContent = lastEvent?.done ? 'Import complete.' : 'Import finished.';
  await loadSharedCatalog(true);
}

/* ---------- Tenants list (called from public-side admin pages too — harmless) ---------- */

/* ---------- Event wiring ---------- */

function bindEvents() {
  // Auth
  $('[data-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/admin/dashboard');
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  $('[data-super-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/super/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/super/tenants');
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  // Request submission
  $('[data-request-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    if (!form.elements.song_id.value && !form.elements.shared_song_id.value) {
      setStatus(status, 'Pick a song from the search results first.');
      return;
    }
    try {
      const payload = formData(form);
      await api('/api/requests', { method: 'POST', body: JSON.stringify(payload) });
      setStatus(status, 'Request added.');
      form.elements.song_id.value = '';
      form.elements.shared_song_id.value = '';
      $$('.song-result.selected').forEach(b => b.classList.remove('selected'));
      await loadQueue();
    } catch (error) { setStatus(status, error.message); }
  });

  // Catalog search
  const queryInput = $('[data-song-query]') || $('[name="song_search"]');
  let queryDebounce = null;
  queryInput?.addEventListener('input', () => {
    clearTimeout(queryDebounce);
    queryDebounce = setTimeout(() => searchSongs(true).catch(() => {}), 200);
  });
  $('[data-song-search]')?.addEventListener('click', () => searchSongs(true).catch(() => {}));
  $('[data-song-genre]')?.addEventListener('change', () => searchSongs(true).catch(() => {}));
  $('[data-song-decade]')?.addEventListener('change', () => searchSongs(true).catch(() => {}));
  $('[data-page-prev]')?.addEventListener('click', () => {
    if (catalogState.page > 1) { catalogState.page--; searchSongs(false).catch(() => {}); }
  });
  $('[data-page-next]')?.addEventListener('click', () => {
    if (catalogState.page * catalogState.size < catalogState.total) { catalogState.page++; searchSongs(false).catch(() => {}); }
  });

  // Song pick (public-facing form)
  document.addEventListener('click', async event => {
    const pick = event.target.closest('[data-song-pick]');
    if (pick) {
      const form = $('[data-request-form]');
      if (form) {
        const source = pick.dataset.songSource || 'local';
        form.elements.song_id.value = source === 'local' ? pick.dataset.songId : '';
        form.elements.shared_song_id.value = source === 'shared' ? pick.dataset.songId : '';
      }
      $$('.song-result.selected').forEach(b => b.classList.remove('selected'));
      pick.classList.add('selected');
      return;
    }

    const status = event.target.closest('[data-status][data-id]');
    if (status) {
      await api(`/api/requests/${status.dataset.id}/status`, { method: 'PATCH', body: JSON.stringify({ status: status.dataset.status }) });
      return;
    }
    const mode = event.target.closest('[data-display-mode]');
    if (mode) {
      await api('/api/display/state', { method: 'POST', body: JSON.stringify({ mode: mode.dataset.displayMode }) });
      return;
    }
    if (event.target.closest('[data-next-singer]')) {
      const data = await loadQueue();
      const next = data.queue.find(item => item.queue_status === 'pending' || item.queue_status === 'up_next');
      if (next) await api(`/api/requests/${next.request_id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'now_singing' }) });
      return;
    }
    const youtube = event.target.closest('[data-youtube]');
    if (youtube) {
      await api(`/api/requests/${youtube.dataset.youtube}/youtube`, { method: 'POST', body: JSON.stringify({}) });
      await loadQueue();
      return;
    }
    const provision = event.target.closest('[data-provision]');
    if (provision) {
      const card = provision.closest('.tenant-card');
      const statusEl = card ? $('[data-status]', card) : null;
      try {
        provision.disabled = true;
        if (statusEl) statusEl.textContent = 'Provisioning…';
        await api(`/super/tenants/${provision.dataset.provision}/provision`, { method: 'POST', body: JSON.stringify({}) });
        if (statusEl) statusEl.textContent = 'Provisioned.';
        await loadTenants();
      } catch (error) {
        if (statusEl) statusEl.textContent = error.message;
      } finally {
        provision.disabled = false;
      }
      return;
    }
    const deleteSong = event.target.closest('[data-song-delete]');
    if (deleteSong) {
      event.preventDefault();
      if (!confirm('Remove this song from the catalog?')) return;
      try {
        await api(`/api/admin/songs/${deleteSong.dataset.songDelete}`, { method: 'DELETE' });
        await searchSongs(false);
      } catch (error) { alert(error.message); }
      return;
    }
    const deleteShared = event.target.closest('[data-shared-delete]');
    if (deleteShared) {
      if (!confirm('Remove this song from the shared catalog?')) return;
      try {
        await api(`/api/super/catalog/${deleteShared.dataset.sharedDelete}`, { method: 'DELETE' });
        await loadSharedCatalog(false);
      } catch (error) { alert(error.message); }
      return;
    }
    const kj = event.target.closest('[data-tenant-kj]');
    if (kj) {
      event.preventDefault();
      event.stopPropagation();
      try {
        const data = await api(`/api/super/tenants/${kj.dataset.tenantKj}/handoff`, { method: 'POST', body: JSON.stringify({}) });
        if (data.url) window.open(data.url, '_blank', 'noopener');
      } catch (error) { alert('Could not open KJ console: ' + error.message); }
      return;
    }
  });

  // Announcements + admin queue actions
  $('[data-announcement-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    await api('/api/announcements', { method: 'POST', body: JSON.stringify(formData(event.target)) });
    event.target.reset();
  });

  // Admin song create (admin-songs page)
  $('[data-song-create]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    try {
      await api('/api/admin/songs', { method: 'POST', body: JSON.stringify(formData(form)) });
      setStatus($('[data-status]', form), 'Song saved.');
      form.reset();
      await searchSongs(true);
    } catch (error) { setStatus($('[data-status]', form), error.message); }
  });

  // Admin song update (delegated; any data-song-update form)
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-song-update]');
    if (!form) return;
    event.preventDefault();
    try {
      await api(`/api/admin/songs/${form.dataset.songUpdate}`, { method: 'PATCH', body: JSON.stringify(formData(form)) });
      setStatus($('[data-status]', form), 'Updated.');
    } catch (error) { setStatus($('[data-status]', form), error.message); }
  });

  // Toggle inline forms
  $('[data-toggle-add]')?.addEventListener('click', () => $('[data-add-song-panel]')?.toggleAttribute('open'));
  $('[data-toggle-playlist]')?.addEventListener('click', () => $('[data-playlist-panel]')?.toggleAttribute('open'));

  // Playlist import
  $('[data-playlist-import]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    setStatus(status, 'Fetching playlist…');
    try {
      const data = await api('/api/admin/songs/import-playlist', { method: 'POST', body: JSON.stringify(formData(form)) });
      setStatus(status, `Imported ${data.imported}, skipped ${data.skipped} of ${data.total_seen} entries.`);
      form.reset();
      await searchSongs(true);
    } catch (error) { setStatus(status, error.message); }
  });

  // Settings save
  $('[data-settings-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    const payload = {};
    for (const field of form.elements) {
      if (!field.name) continue;
      payload[field.name] = field.type === 'checkbox' ? field.checked : field.value;
    }
    try {
      await api('/api/admin/settings', { method: 'POST', body: JSON.stringify(payload) });
      setStatus(status, 'Saved.');
    } catch (error) { setStatus(status, error.message); }
  });

  // Branding save
  $('[data-branding-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/branding', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      setStatus($('[data-status]', event.target), 'Branding saved. Refreshing…');
      setTimeout(() => location.reload(), 400);
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  // Content upload
  $('[data-content-upload]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const body = new FormData(form);
    try {
      await api('/api/admin/content', { method: 'POST', body });
      setStatus($('[data-status]', form), 'File uploaded.');
      form.reset();
      await loadContentFiles();
    } catch (error) { setStatus($('[data-status]', form), error.message); }
  });
}

function bindSuperEvents() {
  const tenantTable = $('[data-tenants]');
  if (tenantTable) {
    tenantTable.addEventListener('click', event => {
      if (event.target.closest('[data-tenant-view]') || event.target.closest('[data-tenant-kj]')) return;
      const row = event.target.closest('[data-tenant-row]');
      if (row) openTenantEditor(row.dataset.tenantRow);
    });
    tenantTable.addEventListener('keydown', event => {
      const row = event.target.closest('[data-tenant-row]');
      if (row && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        openTenantEditor(row.dataset.tenantRow);
      }
    });
  }

  $$('[data-editor-close]').forEach(el => el.addEventListener('click', closeTenantEditor));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      const drawer = $('[data-tenant-editor]');
      if (drawer && !drawer.hidden) closeTenantEditor();
    }
  });

  $('[data-tenant-edit-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const id = form.dataset.tenantId;
    if (!id) return;
    const status = $('[data-status]', form);
    try {
      const payload = formData(form);
      delete payload.database_name;
      const data = await api(`/api/super/tenants/${id}`, { method: 'PATCH', body: JSON.stringify(payload) });
      setStatus(status, 'Saved.');
      if (data.tenant) {
        tenantState.byId.set(String(id), data.tenant);
        renderEditorDomains(data.tenant);
      }
      await loadTenants();
    } catch (error) {
      setStatus(status, error.message);
    }
  });

  $('[data-editor-provision]')?.addEventListener('click', async () => {
    const form = $('[data-tenant-edit-form]');
    const id = form?.dataset.tenantId;
    const status = $('[data-status]', form);
    if (!id) return;
    try {
      setStatus(status, 'Provisioning…');
      await api(`/super/tenants/${id}/provision`, { method: 'POST', body: JSON.stringify({}) });
      setStatus(status, 'Provisioned.');
      await loadTenants();
    } catch (error) {
      setStatus(status, error.message);
    }
  });

  $('[data-editor-handoff]')?.addEventListener('click', async () => {
    const form = $('[data-tenant-edit-form]');
    const id = form?.dataset.tenantId;
    const status = $('[data-status]', form);
    if (!id) return;
    try {
      setStatus(status, 'Creating handoff…');
      const data = await api(`/api/super/tenants/${id}/handoff`, { method: 'POST', body: JSON.stringify({}) });
      if (data.url) window.open(data.url, '_blank', 'noopener');
      setStatus(status, 'KJ console opened in new tab.');
    } catch (error) {
      setStatus(status, error.message);
    }
  });

  $('[data-domain-add]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const id = $('[data-tenant-edit-form]')?.dataset.tenantId;
    if (!id) return;
    try {
      const payload = {
        domain: form.elements.domain.value.trim(),
        is_primary: form.elements.is_primary.checked ? 1 : 0
      };
      await api(`/super/tenants/${id}/domains`, { method: 'POST', body: JSON.stringify(payload) });
      form.reset();
      await refreshEditorTenant(id);
    } catch (error) {
      setStatus($('[data-status]', $('[data-tenant-edit-form]')), error.message);
    }
  });

  $('[data-editor-domains]')?.addEventListener('click', async event => {
    const id = $('[data-tenant-edit-form]')?.dataset.tenantId;
    if (!id) return;
    const makePrimary = event.target.closest('[data-domain-primary]');
    const remove = event.target.closest('[data-domain-remove]');
    if (makePrimary) {
      await api(`/api/super/tenants/${id}/domains/${makePrimary.dataset.domainPrimary}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_primary: true })
      });
      await refreshEditorTenant(id);
    } else if (remove) {
      if (!confirm('Remove this domain?')) return;
      await api(`/api/super/tenants/${id}/domains/${remove.dataset.domainRemove}`, { method: 'DELETE' });
      await refreshEditorTenant(id);
    }
  });

  $('[data-tenant-create]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/super/tenants', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      event.target.reset();
      event.target.closest('details')?.removeAttribute('open');
      await loadTenants();
    } catch (error) { alert(error.message); }
  });

  $('[data-super-logout]')?.addEventListener('click', async () => {
    try {
      await api('/super/logout', { method: 'POST', body: JSON.stringify({}) });
    } catch { /* ignore */ }
    location.href = url('/super/login');
  });

  // Shared catalog page
  $('[data-shared-search]')?.addEventListener('click', () => loadSharedCatalog(true));
  let sharedQueryDebounce = null;
  $('[data-shared-query]')?.addEventListener('input', () => {
    clearTimeout(sharedQueryDebounce);
    sharedQueryDebounce = setTimeout(() => loadSharedCatalog(true), 250);
  });
  $('[data-shared-query]')?.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); loadSharedCatalog(true); }
  });
  $('[data-shared-import]')?.addEventListener('submit', async event => {
    event.preventDefault();
    await streamSharedImport(event.target);
  });
}

/* ---------- Realtime ---------- */

function startEvents() {
  if (!window.EventSource || location.pathname.startsWith(url('/super'))) return;
  const source = new EventSource(url('/api/events'));
  ['queue:updated', 'request:created', 'request:status_changed', 'display:state_changed', 'announcement:shown'].forEach(name => {
    source.addEventListener(name, () => loadQueue().catch(() => {}));
  });
}

/* ---------- Init ---------- */

bindEvents();
bindSuperEvents();
loadQueue().catch(() => {});
loadTenants();
loadContentFiles();
loadBranding();
loadSettings();
if (appConfig.page === 'songs' || appConfig.page === 'admin-songs') {
  searchSongs(true).catch(() => {});
}
if (appConfig.page === 'super-catalog') {
  loadSharedCatalog(true).then(setupSharedObserver);
}
startEvents();

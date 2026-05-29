/* pages/super-catalog.js — super-admin pages (tenants list + shared catalog).
 *
 * Two distinct surfaces share this module:
 *   - /super/tenants: tenant list, editor drawer, domain management, signup hooks
 *   - /super/catalog: shared catalog browse + streaming CSV import
 * Both run from /super/, gated to super_admin sessions.
 */

import { $, $$, setStatus, formData, escapeHtml } from '../lib/dom.js';
import { api, url, appConfig } from '../lib/api.js';

/* ---------- Tenants list ---------- */

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
  const basePath = appConfig.basePath.replace(/\/$/, '');
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
    projection_url: tenant.projection_url || '',
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
  } catch (_) { /* list reload handles fallback */ }
  await loadTenants();
}

/* ---------- Shared catalog (infinite scroll + streaming import) ---------- */

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
    body,
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

/* ---------- Init ---------- */

export function init() {
  // Super login.
  $('[data-super-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/super/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/super/tenants');
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  // Tenant row click → open editor.
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

  // Tenant edit form (drawer).
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
        is_primary: form.elements.is_primary.checked ? 1 : 0,
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
        body: JSON.stringify({ is_primary: true }),
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

  // Tenant action click delegation (provision, headphones, etc.).
  document.addEventListener('click', async event => {
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
    }
  });

  // Logout.
  $('[data-super-logout]')?.addEventListener('click', async () => {
    try {
      await api('/super/logout', { method: 'POST', body: JSON.stringify({}) });
    } catch { /* ignore */ }
    location.href = url('/super/login');
  });

  // Shared catalog search wiring.
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

  // Initial paint.
  loadTenants();
  if (appConfig.page === 'super-catalog') {
    loadSharedCatalog(true).then(setupSharedObserver);
  }
}

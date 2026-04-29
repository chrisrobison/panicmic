const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const basePath = (window.NEXTUP?.basePath || '').replace(/\/$/, '');

function url(path) {
  const normalized = `/${String(path || '').replace(/^\/+/, '')}`;
  return normalized === '/' ? (basePath || '/') : `${basePath}${normalized}`;
}

async function api(path, options = {}) {
  const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
  if (!['GET', undefined].includes(options.method)) headers['X-CSRF-Token'] = window.NEXTUP.csrf;
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

async function loadQueue() {
  const data = await api('/api/queue');
  renderPublicQueue(data.queue);
  renderAdminQueue(data.queue);
  renderDisplay(data.queue, data.display);
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
        <p>${escapeHtml(item.title)} - ${escapeHtml(item.artist)} ${item.notes ? `<br><small>${escapeHtml(item.notes)}</small>` : ''}</p>
        ${item.youtube_url ? `<a class="youtube-link" href="${escapeHtml(item.youtube_url)}" target="_blank" rel="noreferrer">YouTube: ${escapeHtml(item.youtube_title || 'karaoke video')}</a>` : '<small class="muted">No YouTube match attached</small>'}
      </div>
      <div class="queue-actions">
        ${['up_next', 'now_singing', 'completed', 'skipped', 'canceled'].map(status => `<button data-status="${status}" data-id="${item.request_id}">${status.replace('_', ' ')}</button>`).join('')}
        <button data-youtube="${item.request_id}">Find video</button>
      </div>
    </article>
  `).join('');
  enableDrag(container);
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

async function searchSongs(targetResults) {
  const query = $('[data-song-query]')?.value || $('[name="song_search"]')?.value || '';
  const genre = $('[data-song-genre]')?.value || '';
  const decade = $('[data-song-decade]')?.value || '';
  const data = await api(`/api/songs?query=${encodeURIComponent(query)}&genre=${encodeURIComponent(genre)}&decade=${encodeURIComponent(decade)}`);
  const html = data.songs.map(song => `
    <button class="song-result" type="button" data-song-id="${song.id}" data-song-label="${escapeHtml(song.title)} - ${escapeHtml(song.artist)}">
      <strong>${escapeHtml(song.title)}</strong><br><span>${escapeHtml(song.artist)}</span>
    </button>
  `).join('') || '<p>No songs found.</p>';
  targetResults.innerHTML = html;
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

function bindEvents() {
  $('[data-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/admin/dashboard');
    } catch (error) { $('[data-status]', event.target).textContent = error.message; }
  });

  $('[data-super-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/super/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/super/tenants');
    } catch (error) { $('[data-status]', event.target).textContent = error.message; }
  });

  $('[data-request-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const data = formData(event.target);
      await api('/api/requests', { method: 'POST', body: JSON.stringify(data) });
      $('[data-status]', event.target).textContent = 'Request added.';
      await loadQueue();
    } catch (error) { $('[data-status]', event.target).textContent = error.message; }
  });

  $('[name="song_search"]')?.addEventListener('input', event => searchSongs($('[data-song-results]')));
  $('[data-song-search]')?.addEventListener('click', () => searchSongs($('[data-song-table]')));
  document.addEventListener('click', async event => {
    const song = event.target.closest('[data-song-id]');
    if (song) {
      $('[name="song_id"]') && ($('[name="song_id"]').value = song.dataset.songId);
      $$('.song-result').forEach(item => item.classList.remove('selected'));
      song.classList.add('selected');
    }
    const status = event.target.closest('[data-status]');
    if (status) {
      await api(`/api/requests/${status.dataset.id}/status`, { method: 'PATCH', body: JSON.stringify({ status: status.dataset.status }) });
    }
    const mode = event.target.closest('[data-display-mode]');
    if (mode) {
      await api('/api/display/state', { method: 'POST', body: JSON.stringify({ mode: mode.dataset.displayMode }) });
    }
    if (event.target.closest('[data-next-singer]')) {
      const data = await loadQueue();
      const next = data.queue.find(item => item.queue_status === 'pending' || item.queue_status === 'up_next');
      if (next) await api(`/api/requests/${next.request_id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'now_singing' }) });
    }
    const youtube = event.target.closest('[data-youtube]');
    if (youtube) {
      await api(`/api/requests/${youtube.dataset.youtube}/youtube`, { method: 'POST', body: JSON.stringify({}) });
      await loadQueue();
    }
    const provision = event.target.closest('[data-provision]');
    if (provision) {
      await api(`/super/tenants/${provision.dataset.provision}/provision`, { method: 'POST', body: JSON.stringify({}) });
      await loadTenants();
    }
  });

  $('[data-announcement-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    await api('/api/announcements', { method: 'POST', body: JSON.stringify(formData(event.target)) });
    event.target.reset();
  });

  $('[data-song-create]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/songs', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      $('[data-status]', event.target).textContent = 'Song saved.';
      event.target.reset();
    } catch (error) { $('[data-status]', event.target).textContent = error.message; }
  });

  $('[data-content-upload]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const body = new FormData(event.target);
    try {
      await api('/api/admin/content', { method: 'POST', body });
      $('[data-status]', event.target).textContent = 'File uploaded.';
      event.target.reset();
      await loadContentFiles();
    } catch (error) { $('[data-status]', event.target).textContent = error.message; }
  });

  $('[data-tenant-create]')?.addEventListener('submit', async event => {
    event.preventDefault();
    await api('/super/tenants', { method: 'POST', body: JSON.stringify(formData(event.target)) });
    event.target.reset();
    await loadTenants();
  });
}

async function loadTenants() {
  const container = $('[data-tenants]');
  if (!container) return;
  try {
    const data = await api('/api/super/tenants');
    container.innerHTML = data.tenants.map(t => `<article class="tenant-card"><strong>${escapeHtml(t.venue_name)}</strong><br>${escapeHtml(t.database_name)}<br><button data-provision="${t.id}">Provision</button></article>`).join('');
  } catch (error) {
    container.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
  }
}

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
    `).join('') || '<p>No content uploaded yet.</p>';
  } catch (error) {
    container.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
  }
}

function startEvents() {
  if (!window.EventSource || location.pathname.startsWith(url('/super'))) return;
  const source = new EventSource(url('/api/events'));
  ['queue:updated', 'request:created', 'request:status_changed', 'display:state_changed', 'announcement:shown'].forEach(name => {
    source.addEventListener(name, () => loadQueue().catch(() => {}));
  });
}

bindEvents();
loadQueue().catch(() => {});
loadTenants();
loadContentFiles();
startEvents();

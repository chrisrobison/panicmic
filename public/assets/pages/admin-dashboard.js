/* pages/admin-dashboard.js — KJ Command Center (queue, session, display, settings).
 *
 * Shared across admin-dashboard, admin-login, admin-content, admin-settings
 * (see main.js's PAGES map) — every selector below is guarded with `?.` or
 * an early return so sections belonging to other pages are silently
 * skipped.
 */

import { $, $$, setStatus, formData, escapeHtml } from '../lib/dom.js';
import { api, url, appConfig } from '../lib/api.js';
import { loadQueue, elapsedLabel } from '../lib/queue.js';
import { startEvents } from '../lib/events.js';
import { startRealtime, onMessage, isConnected } from '../lib/ws.js';
import { broadcastDisplayCommand } from '../lib/broadcast.js';
import { searchSongs } from '../lib/catalog.js';

const displayWindows = new Map();

// Per-screen display presence, keyed by screen id. Populated from WS
// display:ready / display:status messages when the daemon is running.
const displayPresence = new Map();

// Last-known now-singing item, used to tick the elapsed-time readouts
// between full queue reloads.
let nowSinging = null;
let displayPaused = false;

async function loadDisplayScreens() {
  try {
    const { screens = [] } = await api('/api/display/screens');
    renderConnectedDisplays(screens);
    renderDisplayScreensSettings(screens);
    syncContentSelects(screens);
    return screens;
  } catch (_) { return []; } // not authorized on this page
}

/** Pre-select each display card's Content dropdown to reflect its actual current mode. */
async function syncContentSelects(screens) {
  await Promise.all(screens.map(async s => {
    try {
      const { display } = await api(`/api/display/state?screen=${encodeURIComponent(s.screen)}`);
      const select = $(`[data-content-screen="${s.screen}"]`);
      if (select && display?.mode) select.value = display.mode;
    } catch (_) { /* leave default */ }
  }));
}

function renderDisplayScreensSettings(screens) {
  const list = $('[data-display-screens-list]');
  if (!list) return;
  list.innerHTML = screens.map(s => `
    <div class="screen-row">
      <strong>${escapeHtml(s.label)}</strong>
      <code>?screen=${escapeHtml(s.screen)}</code>
      <span class="muted">${escapeHtml(s.layout)} · vol ${escapeHtml(String(s.default_volume))}</span>
      ${s.screen === 'main' ? '' : `<button type="button" data-delete-screen="${escapeHtml(s.screen)}">Remove</button>`}
    </div>
  `).join('') || '<p class="muted">No custom screens yet. The default "main" screen is always available.</p>';
}

const CONTENT_MODES = [
  ['idle', 'Idle'], ['queue', 'Queue'], ['now_singing', 'Now Singing'],
  ['clean_stage', 'Clean Stage'], ['announcement', 'Announcement'], ['blackout', 'Blackout'],
];

/** Connected Displays panel: one card per configured screen, with live presence + per-screen controls. */
function renderConnectedDisplays(screens) {
  const container = $('[data-connected-displays]');
  const now = Date.now();
  const connected = screens.filter(s => {
    const info = displayPresence.get(s.screen);
    return info && (now - info.lastSeen) < 15000;
  });
  const countLabel = String(connected.length || screens.length);
  $$('[data-displays-connected-count]').forEach(el => { el.textContent = countLabel; });
  $$('[data-displays-count]').forEach(el => { el.textContent = countLabel; });

  if (!container) return;
  container.innerHTML = screens.map(s => {
    const info = displayPresence.get(s.screen);
    const online = info && (now - info.lastSeen) < 15000;
    return `
    <article class="display-card" data-screen-card="${escapeHtml(s.screen)}">
      <div class="display-card-head">
        <span class="status-dot ${online ? 'online' : 'offline'}"></span>
        <strong>${escapeHtml(s.label)}</strong>
        <span class="muted display-card-layout">${escapeHtml(s.layout)}</span>
      </div>
      <div class="display-card-actions">
        <button type="button" data-mirror="${escapeHtml(s.screen)}">⧉ Mirror</button>
        <button type="button" data-message-screen="${escapeHtml(s.screen)}">✉ Message</button>
        <button type="button" data-blackout-screen="${escapeHtml(s.screen)}">⛔ Blackout</button>
        <select data-content-screen="${escapeHtml(s.screen)}" title="Set content">
          ${CONTENT_MODES.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
        </select>
      </div>
    </article>`;
  }).join('') || '<p class="muted">No displays configured yet.</p>';
}

async function openDisplayWindow(screen) {
  const target = `panicmic_${screen}`;
  const existing = displayWindows.get(screen);
  if (existing && !existing.closed) {
    existing.focus();
    return;
  }
  let features = 'popup,width=1280,height=720';
  try {
    if ('getScreenDetails' in window) {
      const details = await window.getScreenDetails();
      const monitor = details.screens[displayWindows.size % details.screens.length];
      if (monitor) {
        features = `popup,left=${monitor.availLeft},top=${monitor.availTop},width=${monitor.availWidth},height=${monitor.availHeight}`;
      }
    }
  } catch (_) { /* no permission / API missing */ }
  const popup = window.open(url(`/display?screen=${encodeURIComponent(screen)}`), target, features);
  if (popup) displayWindows.set(screen, popup);
}

/** "Start Song" — promote the next singer and trigger synchronized playback on every screen. */
async function startNextSong() {
  const data = await loadQueue();
  const next = data.queue.find(item => !item.is_incoming && item.queue_status === 'up_next')
    || data.queue.find(item => !item.is_incoming && item.queue_status === 'pending');
  if (!next) {
    alert('Rotation queue is empty — accept a request first.');
    return;
  }
  await api(`/api/requests/${next.request_id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status: 'now_singing' }),
  });
  const screens = await loadDisplayScreens();
  const others = screens.filter(s => s.screen && s.screen !== 'main');
  for (const s of others) {
    try {
      await api('/api/display/state', {
        method: 'POST',
        body: JSON.stringify({ mode: 'now_singing', now_request_id: next.request_id, screen: s.screen }),
      });
    } catch (_) { /* keep mirroring the rest */ }
  }
  try {
    await api('/api/display/play', {
      method: 'POST',
      body: JSON.stringify({ screen: 'all', request_id: next.request_id, start_delay_ms: 2000, offset_seconds: 0 }),
    });
  } catch (_) {
    broadcastDisplayCommand({ screen: 'all', action: 'cue', payload: { requestId: next.request_id } });
  }
  displayPaused = false;
  syncPauseButtons();
  await loadQueue();
}

async function skipCurrentSong() {
  const data = await loadQueue();
  const current = data.queue.find(item => item.queue_status === 'now_singing');
  if (!current) {
    alert('Nobody is currently singing.');
    return;
  }
  await api(`/api/requests/${current.request_id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'skipped' }) });
  await loadQueue();
}

function syncPauseButtons() {
  $$('[data-toggle-pause], [data-np-pause]').forEach(btn => {
    btn.textContent = displayPaused ? '▶ Resume' : '⏸ Pause';
    btn.dataset.paused = displayPaused ? '1' : '0';
  });
}

async function togglePause() {
  displayPaused = !displayPaused;
  syncPauseButtons();
  try {
    await api('/api/display/pause', { method: 'POST', body: JSON.stringify({ screen: 'all', paused: displayPaused }) });
  } catch (error) {
    displayPaused = !displayPaused; // revert on failure
    syncPauseButtons();
    alert(error.message);
  }
}

async function blackoutAllDisplays() {
  if (!confirm('Blackout every connected display?')) return;
  const screens = await loadDisplayScreens();
  for (const s of screens) {
    try {
      await api('/api/display/state', { method: 'POST', body: JSON.stringify({ mode: 'blackout', screen: s.screen }) });
    } catch (_) { /* keep going */ }
  }
}

function trackDisplayPresence(msg) {
  if (!msg || !msg.type) return;
  if (msg.type === 'display:ready' || msg.type === 'display:status') {
    const screen = msg.screen || 'main';
    displayPresence.set(screen, {
      lastSeen: Date.now(),
      playerState: msg.playerState || (msg.type === 'display:ready' ? 'ready' : ''),
      requestId: msg.requestId ?? null,
    });
  }
}

/* -------------------------------------------------------------- */
/* Now Playing panel                                               */
/* -------------------------------------------------------------- */

function renderNowPlaying(queue, display) {
  const root = $('[data-now-playing]');
  if (!root) return;
  const current = queue.find(item => item.request_id === display?.now_request_id)
    || queue.find(item => item.queue_status === 'now_singing');

  nowSinging = current && current.queue_status === 'now_singing' ? current : null;

  const titleEl = $('[data-now-playing-title]');
  const artistEl = $('[data-now-playing-artist]');
  const singerEl = $('[data-now-playing-singer]');
  const elapsedEl = $('[data-now-playing-elapsed]');

  if (current) {
    if (titleEl) titleEl.textContent = current.title || '(untitled)';
    if (artistEl) artistEl.textContent = current.artist || '';
    if (singerEl) singerEl.textContent = current.singer_name ? `🎤 ${current.singer_name}` : '';
  } else {
    if (titleEl) titleEl.textContent = 'Nothing playing';
    if (artistEl) artistEl.textContent = '';
    if (singerEl) singerEl.textContent = '';
  }
  if (elapsedEl) elapsedEl.textContent = nowSinging ? elapsedLabel(nowSinging.status_updated_at) : '';
}

function tickNowPlayingElapsed() {
  const elapsedEl = $('[data-now-playing-elapsed]');
  if (elapsedEl && nowSinging) elapsedEl.textContent = elapsedLabel(nowSinging.status_updated_at);
  const chip = $(`.queue-item[data-request-id="${nowSinging ? nowSinging.request_id : ''}"] .chip-live`);
  if (chip && nowSinging) chip.textContent = `NOW SINGING · ${elapsedLabel(nowSinging.status_updated_at)}`;
}

/* -------------------------------------------------------------- */
/* Activity log                                                    */
/* -------------------------------------------------------------- */

async function loadActivity() {
  const container = $('[data-activity-log]');
  if (!container) return;
  try {
    const { activity = [] } = await api('/api/admin/activity?limit=30');
    container.innerHTML = activity.map(item => `
      <div class="activity-row">
        <time>${escapeHtml(new Date(String(item.created_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }))}</time>
        <span>${escapeHtml(item.message)}</span>
      </div>
    `).join('') || '<p class="muted">No activity yet.</p>';
  } catch (_) { /* not authorized / no session yet */ }
}

/* -------------------------------------------------------------- */
/* Settings / branding / content / billing (admin-settings, admin-content) */
/* -------------------------------------------------------------- */

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

async function loadAutoAccept() {
  const checkbox = $('[data-auto-accept]');
  if (!checkbox) return;
  try {
    const { settings = {} } = await api('/api/admin/settings');
    checkbox.checked = !!settings.auto_accept_requests;
  } catch (_) { /* leave default */ }
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

// Map of venue id -> default night name, so picking a venue can prefill
// the night name input.
const venueDefaults = new Map();

async function loadStartVenues() {
  const select = $('[data-session-venue]');
  if (!select) return;
  try {
    const { venues = [] } = await api('/api/admin/venues');
    const active = venues.filter(v => Number(v.is_active) === 1);
    select.innerHTML = '<option value="">No venue</option>' + active.map(v =>
      `<option value="${escapeHtml(String(v.id))}">${escapeHtml(v.name)}</option>`
    ).join('');
    venueDefaults.clear();
    active.forEach(v => venueDefaults.set(String(v.id), v.default_night_name || ''));
  } catch (_) { /* not authorized / no venues */ }
}

async function loadTonightEvents() {
  const container = $('[data-tonight-events]');
  if (!container) return;
  const today = new Date().toISOString().slice(0, 10);
  try {
    const { events = [] } = await api(`/api/admin/events?from=${today}&to=${today}`);
    const startable = events.filter(e => e.status === 'scheduled' || e.status === 'live');
    if (!startable.length) { container.hidden = true; return; }
    const buttons = startable.map(e => {
      const time = String(e.scheduled_for || '').slice(11, 16);
      return `<button type="button" class="primary" data-start-event="${escapeHtml(String(e.id))}">▶ ${escapeHtml(e.name)} · ${escapeHtml(e.venue_name || '')} ${escapeHtml(time)}</button>`;
    }).join('');
    container.innerHTML = `<span class="muted">Tonight's schedule:</span> ${buttons}`;
    container.hidden = false;
  } catch (_) { container.hidden = true; }
}

async function loadBilling() {
  const panel = $('[data-billing-panel]');
  if (!panel) return;
  try {
    const { billing } = await api('/api/admin/billing');
    const dollars = cents => `$${(Number(cents || 0) / 100).toFixed(2)}`;
    panel.innerHTML = `
      <h2>Plan &amp; usage</h2>
      <ul class="billing-list">
        <li><span>Plan</span><strong>${escapeHtml(billing.plan_name)} · ${dollars(billing.base_monthly_cents)}/mo</strong></li>
        <li><span>Venues used</span><strong>${escapeHtml(String(billing.venues_used))} / ${escapeHtml(String(billing.max_venues))}</strong></li>
        <li><span>KJ seats</span><strong>${escapeHtml(String(billing.kj_seats))} (${escapeHtml(String(billing.included_kj))} included)</strong></li>
        <li><span>Additional KJ</span><strong>${escapeHtml(String(billing.additional_kj))} × ${dollars(billing.additional_kj_cents)}</strong></li>
        <li class="billing-total"><span>Projected monthly</span><strong>${dollars(billing.projected_monthly_cents)}</strong></li>
      </ul>
      <p class="muted">Subscription status: ${escapeHtml(String(billing.subscription_status))}.</p>`;
  } catch (error) {
    panel.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

/* -------------------------------------------------------------- */
/* Add Request modal                                               */
/* -------------------------------------------------------------- */

function openAddRequestModal(prefillQuery) {
  const dialog = $('[data-add-request-modal]');
  if (!dialog) return;
  if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
  const query = $('[data-song-query]', dialog);
  if (query && prefillQuery !== undefined) {
    query.value = prefillQuery;
    searchSongs(true).catch(() => {});
  }
  if (query && !prefillQuery) query.focus();
}

function closeAddRequestModal() {
  const dialog = $('[data-add-request-modal]');
  const form = $('[data-add-request-form]');
  if (dialog?.open) dialog.close();
  form?.reset();
  const results = $('[data-song-results]');
  if (results) results.innerHTML = '';
}

export function init() {
  // Login (admin-dashboard runs on /admin/login too — guard via form presence).
  $('[data-login-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/login', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      location.href = url('/admin/dashboard');
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  // Click-delegated admin actions.
  document.addEventListener('click', async event => {
    const status = event.target.closest('[data-status][data-id]');
    if (status) {
      await api(`/api/requests/${status.dataset.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status: status.dataset.status }),
      });
      return;
    }

    const approve = event.target.closest('[data-approve]');
    if (approve) {
      await api(`/api/requests/${approve.dataset.approve}/approve`, { method: 'POST', body: JSON.stringify({}) });
      await loadQueue();
      return;
    }
    const deny = event.target.closest('[data-deny]');
    if (deny) {
      await api(`/api/requests/${deny.dataset.deny}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'canceled' }) });
      await loadQueue();
      return;
    }
    const fastTrack = event.target.closest('[data-fast-track]');
    if (fastTrack) {
      await api(`/api/requests/${fastTrack.dataset.fastTrack}/approve`, { method: 'POST', body: JSON.stringify({ fast_track: true }) });
      await loadQueue();
      return;
    }
    const priority = event.target.closest('[data-priority]');
    if (priority) {
      const next = priority.dataset.priorityCurrent !== '1';
      await api(`/api/requests/${priority.dataset.priority}/priority`, { method: 'POST', body: JSON.stringify({ priority: next }) });
      await loadQueue();
      return;
    }

    if (event.target.closest('[data-start-song]')) { await startNextSong(); return; }
    if (event.target.closest('[data-skip-song], [data-np-skip]')) { await skipCurrentSong(); return; }
    if (event.target.closest('[data-toggle-pause], [data-np-pause]')) { await togglePause(); return; }
    if (event.target.closest('[data-blackout-all]')) { await blackoutAllDisplays(); return; }
    if (event.target.closest('[data-quick-announce]')) {
      $('[data-announcement-form] textarea')?.focus();
      $('[data-announcement-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    const reorderMode = event.target.closest('[data-reorder-mode]');
    if (reorderMode) {
      const on = $('[data-admin-queue]')?.classList.toggle('reorder-mode');
      reorderMode.classList.toggle('primary', !!on);
      return;
    }
    if (event.target.closest('[data-add-request]')) { openAddRequestModal(); return; }
    if (event.target.closest('[data-modal-cancel]')) { closeAddRequestModal(); return; }

    if (event.target.closest('[data-session-end]')) {
      if (!confirm('End the current session? The queue will be archived.')) return;
      await api('/api/admin/sessions/end', { method: 'POST', body: JSON.stringify({}) });
      location.reload();
      return;
    }

    const mirror = event.target.closest('[data-mirror]');
    if (mirror) { await openDisplayWindow(mirror.dataset.mirror); return; }

    const messageScreen = event.target.closest('[data-message-screen]');
    if (messageScreen) {
      const message = prompt('Message to show on this display:');
      if (!message || !message.trim()) return;
      await api('/api/announcements', { method: 'POST', body: JSON.stringify({ message: message.trim(), screen: messageScreen.dataset.messageScreen }) });
      return;
    }

    const blackoutScreen = event.target.closest('[data-blackout-screen]');
    if (blackoutScreen) {
      await api('/api/display/state', { method: 'POST', body: JSON.stringify({ mode: 'blackout', screen: blackoutScreen.dataset.blackoutScreen }) });
      return;
    }

    const deleteScreen = event.target.closest('[data-delete-screen]');
    if (deleteScreen) {
      if (!confirm(`Remove display "${deleteScreen.dataset.deleteScreen}"?`)) return;
      await api(`/api/display/screens/${encodeURIComponent(deleteScreen.dataset.deleteScreen)}`, { method: 'DELETE' });
      await loadDisplayScreens();
      return;
    }
    const youtube = event.target.closest('[data-youtube]');
    if (youtube) {
      await api(`/api/requests/${youtube.dataset.youtube}/youtube`, { method: 'POST', body: JSON.stringify({}) });
      await loadQueue();
      return;
    }
    const manualVideo = event.target.closest('[data-manual-video]');
    if (manualVideo) {
      const input = prompt('Paste a video URL to link to this request (leave blank to remove):', manualVideo.dataset.manualCurrent || '');
      if (input === null) return; // cancelled
      try {
        await api(`/api/requests/${manualVideo.dataset.manualVideo}/manual-video`, {
          method: 'POST',
          body: JSON.stringify({ url: input.trim() }),
        });
        await loadQueue();
      } catch (error) { alert(error.message); }
    }
  });

  // Content dropdown per connected display.
  document.addEventListener('change', async event => {
    const select = event.target.closest('[data-content-screen]');
    if (select) {
      await api('/api/display/state', { method: 'POST', body: JSON.stringify({ mode: select.value, screen: select.dataset.contentScreen }) });
      return;
    }
    if (event.target.closest('[data-auto-accept]')) {
      try {
        await api('/api/admin/settings', { method: 'POST', body: JSON.stringify({ auto_accept_requests: event.target.checked }) });
      } catch (error) { alert(error.message); event.target.checked = !event.target.checked; }
    }
  });

  // Announcements.
  $('[data-announcement-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const data = formData(event.target);
    if (!data.message || !data.message.trim()) return;
    await api('/api/announcements', { method: 'POST', body: JSON.stringify({ message: data.message.trim(), screen: 'all' }) });
    event.target.reset();
    loadActivity();
  });

  // Quick header search bridges into the Add Request modal.
  let quickSearchDebounce = null;
  $('[data-quick-search]')?.addEventListener('input', event => {
    clearTimeout(quickSearchDebounce);
    const value = event.target.value;
    quickSearchDebounce = setTimeout(() => openAddRequestModal(value), 200);
  });

  // Add Request modal: song search + pick + submit.
  const addRequestDialog = $('[data-add-request-modal]');
  if (addRequestDialog) {
    let addSearchDebounce = null;
    $('[data-song-query]', addRequestDialog)?.addEventListener('input', () => {
      const form = $('[data-add-request-form]');
      if (form) { form.elements.song_id.value = ''; form.elements.shared_song_id.value = ''; }
      clearTimeout(addSearchDebounce);
      addSearchDebounce = setTimeout(() => searchSongs(true).catch(() => {}), 200);
    });
    addRequestDialog.addEventListener('click', event => {
      const pick = event.target.closest('[data-song-pick]');
      if (!pick) return;
      const form = $('[data-add-request-form]');
      if (!form) return;
      const source = pick.dataset.songSource || 'local';
      form.elements.song_id.value = source === 'local' ? pick.dataset.songId : '';
      form.elements.shared_song_id.value = source === 'shared' ? pick.dataset.songId : '';
      const q = $('[data-song-query]', addRequestDialog);
      if (q) q.value = pick.dataset.songLabel || '';
      const results = $('[data-song-results]', addRequestDialog);
      if (results) results.innerHTML = '';
    });
    addRequestDialog.addEventListener('cancel', () => closeAddRequestModal());
    addRequestDialog.addEventListener('click', event => {
      if (event.target === addRequestDialog) closeAddRequestModal(); // backdrop click
    });
  }
  $('[data-add-request-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const statusEl = $('[data-status]', form);
    if (!form.elements.song_id.value && !form.elements.shared_song_id.value) {
      setStatus(statusEl, 'Pick a song from the search results first.');
      return;
    }
    try {
      await api('/api/requests', { method: 'POST', body: JSON.stringify(formData(form)) });
      closeAddRequestModal();
      await loadQueue();
    } catch (error) { setStatus(statusEl, error.message); }
  });

  // Picking a venue prefills the night name from its default.
  $('[data-session-venue]')?.addEventListener('change', event => {
    const nameInput = $('[data-session-start] input[name="name"]');
    const def = venueDefaults.get(event.target.value);
    if (nameInput && def && !nameInput.value.trim()) nameInput.value = def;
  });

  // Session lifecycle. Name is optional — the server falls back to the
  // venue's default night name, then the account default.
  $('[data-session-start]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const data = formData(event.target);
    const name = (data.name || '').trim();
    const venueId = data.venue_id || '';
    const label = name || 'a new night';
    if (!confirm(`Start ${label}? The current session will be archived.`)) return;
    await api('/api/admin/sessions/start', {
      method: 'POST',
      body: JSON.stringify({ name, venue_id: venueId || null }),
    });
    location.reload();
  });

  // Quick-start a scheduled event for tonight.
  document.addEventListener('click', async event => {
    const startEvent = event.target.closest('[data-start-event]');
    if (!startEvent) return;
    if (!confirm('Start this scheduled night? The current session will be archived.')) return;
    await api(`/api/admin/events/${startEvent.dataset.startEvent}/start`, { method: 'POST', body: JSON.stringify({}) });
    location.reload();
  });

  // Display screens settings form.
  $('[data-display-screens-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const data = formData(event.target);
    if (!data.screen) return;
    await api('/api/display/screens', {
      method: 'POST',
      body: JSON.stringify({
        screen: data.screen,
        label: data.label,
        layout: data.layout || 'main',
        default_volume: parseInt(data.default_volume || '80', 10),
        show_qr: !!data.show_qr,
        show_queue: !!data.show_queue,
      }),
    });
    event.target.reset();
    await loadDisplayScreens();
  });

  // Settings save.
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

  // Branding save.
  $('[data-branding-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      await api('/api/admin/branding', { method: 'POST', body: JSON.stringify(formData(event.target)) });
      setStatus($('[data-status]', event.target), 'Branding saved. Refreshing…');
      setTimeout(() => location.reload(), 400);
    } catch (error) { setStatus($('[data-status]', event.target), error.message); }
  });

  // Content upload.
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

  // Initial paint.
  loadQueue().then(data => renderNowPlaying(data.queue, data.display)).catch(() => {});
  loadSettings();
  loadBranding();
  loadContentFiles();
  loadBilling();
  loadAutoAccept();
  if (appConfig.page === 'admin-dashboard') {
    loadStartVenues();
    loadTonightEvents();
    loadActivity();
    setInterval(loadActivity, 8000);
    setInterval(tickNowPlayingElapsed, 1000);
    setInterval(() => {
      const el = $('[data-ws-status]');
      if (!el) return;
      const connected = isConnected();
      el.textContent = connected ? '● WebSocket Connected' : '● Short-poll (no WebSocket)';
      el.classList.toggle('status-ok', connected);
      el.classList.toggle('status-warn', !connected);
    }, 3000);
  }
  if (appConfig.page === 'admin-dashboard' || appConfig.page === 'admin-settings') {
    loadDisplayScreens().catch(() => {});
  }

  // Realtime: prefers the WS daemon (role=kj), falls back to short-poll.
  startRealtime(() => {
    loadQueue().then(data => renderNowPlaying(data.queue, data.display)).catch(() => {});
  });
  // Track display presence from WS status messages.
  onMessage(trackDisplayPresence);
  // Age out stale presence entries + refresh the display cards.
  setInterval(() => { loadDisplayScreens().catch(() => {}); }, 5000);
}

// startEvents stays imported as the short-poll primitive that ws.js
// delegates to; referencing it here keeps linters from flagging it unused
// while documenting that the fallback path is still wired in.
void startEvents;

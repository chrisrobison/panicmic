/* pages/admin-dashboard.js — KJ dashboard (queue, session, display, settings). */

import { $, $$, setStatus, formData, escapeHtml } from '../lib/dom.js';
import { api, url, appConfig } from '../lib/api.js';
import { loadQueue } from '../lib/queue.js';
import { startEvents } from '../lib/events.js';
import { broadcast, broadcastDisplayCommand } from '../lib/broadcast.js';

const displayWindows = new Map();

async function loadDisplayScreens() {
  try {
    const { screens = [] } = await api('/api/display/screens');
    renderDisplayWindowsToolbar(screens);
    renderDisplayScreensSettings(screens);
  } catch (_) { /* not authorized on this page */ }
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

function renderDisplayWindowsToolbar(screens) {
  const container = $('[data-display-windows]');
  if (!container) return;
  const buttons = screens.map(s => `
    <button type="button" data-open-display="${escapeHtml(s.screen)}" title="${escapeHtml(s.label)} (${escapeHtml(s.layout)})">
      ⧉ ${escapeHtml(s.label)}
    </button>
  `).join('');
  container.innerHTML = `<span class="muted">Displays:</span> ${buttons}
    <button type="button" data-cue-all class="primary" title="Cue current up-next on all screens">▶ Cue & Play</button>`;
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

async function cueAndPlayAll() {
  const data = await loadQueue();
  const next = data.queue.find(item => item.queue_status === 'up_next') || data.queue.find(item => item.queue_status === 'pending');
  if (!next) {
    alert('Queue is empty — nothing to cue.');
    return;
  }
  // Step 1a: transition request state machine.
  await api(`/api/requests/${next.request_id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status: 'now_singing' }),
  });
  // Step 1b: mirror to every configured non-main screen.
  let screens = [];
  try {
    const list = await api('/api/display/screens');
    screens = Array.isArray(list.screens) ? list.screens : [];
  } catch (_) { /* main-only fallback */ }
  const others = screens.filter(s => s.screen && s.screen !== 'main');
  for (const s of others) {
    try {
      await api('/api/display/state', {
        method: 'POST',
        body: JSON.stringify({
          mode: 'now_singing',
          now_request_id: next.request_id,
          screen: s.screen,
        }),
      });
    } catch (_) { /* keep mirroring */ }
  }
  // Step 2: fan out instant cue to local display windows.
  broadcastDisplayCommand({ screen: 'all', action: 'cue', payload: { requestId: next.request_id } });
}

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
    if (event.target.closest('[data-session-end]')) {
      if (!confirm('End the current session? The queue will be archived.')) return;
      await api('/api/admin/sessions/end', { method: 'POST', body: JSON.stringify({}) });
      location.reload();
      return;
    }
    const openDisplay = event.target.closest('[data-open-display]');
    if (openDisplay) {
      await openDisplayWindow(openDisplay.dataset.openDisplay);
      return;
    }
    if (event.target.closest('[data-cue-all]')) {
      await cueAndPlayAll();
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

  // Announcements.
  $('[data-announcement-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    await api('/api/announcements', { method: 'POST', body: JSON.stringify(formData(event.target)) });
    event.target.reset();
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
  loadQueue().catch(() => {});
  loadSettings();
  loadBranding();
  loadContentFiles();
  loadBilling();
  if (appConfig.page === 'admin-dashboard') {
    loadStartVenues();
    loadTonightEvents();
  }
  if (appConfig.page === 'admin-dashboard' || appConfig.page === 'admin-settings') {
    loadDisplayScreens().catch(() => {});
  }
  startEvents(() => loadQueue().catch(() => {}));
}

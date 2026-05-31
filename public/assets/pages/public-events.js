/* pages/public-events.js — public upcoming nights + past setlists. */

import { $, escapeHtml } from '../lib/dom.js';
import { api } from '../lib/api.js';

function venueLine(e) {
  const where = [e.venue_city, e.venue_region].filter(Boolean).join(', ');
  return [e.venue_name, where].filter(Boolean).join(' · ');
}

function formatDateTime(value) {
  const dt = new Date(String(value || '').replace(' ', 'T'));
  if (isNaN(dt.getTime())) return String(value || '');
  return dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
    + ' · ' + dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatDate(value) {
  const dt = new Date(String(value || '').replace(' ', 'T'));
  if (isNaN(dt.getTime())) return String(value || '');
  return dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

async function loadUpcoming() {
  const container = $('[data-upcoming-events]');
  if (!container) return;
  try {
    const { events = [] } = await api('/api/public/schedule');
    if (!events.length) {
      container.innerHTML = '<p class="muted">No upcoming nights scheduled yet — check back soon.</p>';
      return;
    }
    container.innerHTML = events.map(e => `
      <article class="event-card">
        <div class="event-card-date">${escapeHtml(formatDateTime(e.scheduled_for))}</div>
        <strong>${escapeHtml(e.name)}</strong>
        <div class="muted">${escapeHtml(venueLine(e))}</div>
      </article>`).join('');
  } catch (error) {
    container.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

async function loadPast() {
  const container = $('[data-past-events]');
  if (!container) return;
  try {
    const { events = [] } = await api('/api/public/events/past');
    if (!events.length) {
      container.innerHTML = '<p class="muted">No past nights yet.</p>';
      return;
    }
    container.innerHTML = events.map(e => `
      <article class="event-card is-clickable" data-setlist="${escapeHtml(String(e.session_id))}">
        <div class="event-card-date">${escapeHtml(formatDate(e.ends_at || e.starts_at))}</div>
        <strong>${escapeHtml(e.name)}</strong>
        <div class="muted">${escapeHtml(venueLine(e) || 'Karaoke night')}</div>
        <div class="event-card-meta">${escapeHtml(String(e.songs_count || 0))} songs · view setlist</div>
      </article>`).join('');
  } catch (error) {
    container.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

async function openSetlist(sessionId) {
  const modal = $('[data-setlist-modal]');
  const body = $('[data-setlist-body]');
  if (!modal || !body) return;
  body.innerHTML = '<p class="muted">Loading…</p>';
  modal.hidden = false;
  try {
    const { event } = await api(`/api/public/events/${sessionId}`);
    const performances = event.performances || [];
    const list = performances.length
      ? `<ol class="setlist">${performances.map(p =>
          `<li><span class="setlist-singer">${escapeHtml(p.singer_name)}</span> — <span class="setlist-song">${escapeHtml(p.title)}</span>${p.artist ? ` <span class="muted">by ${escapeHtml(p.artist)}</span>` : ''}</li>`
        ).join('')}</ol>`
      : '<p class="muted">No songs were recorded for this night.</p>';
    body.innerHTML = `
      <h3>${escapeHtml(event.name)}</h3>
      <p class="muted">${escapeHtml([event.venue_name, formatDate(event.ends_at || event.starts_at)].filter(Boolean).join(' · '))}</p>
      ${list}`;
  } catch (error) {
    body.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

function closeSetlist() {
  const modal = $('[data-setlist-modal]');
  if (modal) modal.hidden = true;
}

export function init() {
  document.addEventListener('click', event => {
    const card = event.target.closest('[data-setlist]');
    if (card) { openSetlist(card.dataset.setlist); return; }
    if (event.target.closest('[data-setlist-close]')) { closeSetlist(); return; }
    if (event.target.matches('[data-setlist-modal]')) { closeSetlist(); }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeSetlist();
  });
  loadUpcoming();
  loadPast();
}

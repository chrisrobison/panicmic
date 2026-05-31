/* pages/admin-schedule.js — recurring schedules + one-off events. */

import { $, $$, setStatus, formData, escapeHtml } from '../lib/dom.js';
import { api } from '../lib/api.js';

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const WEEK_OF_MONTH = { '1': 'First', '2': 'Second', '3': 'Third', '4': 'Fourth', '-1': 'Last' };

function recurrenceLabel(s) {
  const day = WEEKDAYS[Number(s.weekday)] ?? '';
  const time = String(s.start_time || '').slice(0, 5);
  if (s.recurrence_type === 'weekly') return `Every ${day} at ${time}`;
  if (s.recurrence_type === 'biweekly') return `Every other ${day} at ${time}`;
  if (s.recurrence_type === 'monthly') {
    const which = WEEK_OF_MONTH[String(s.week_of_month)] ?? '';
    return `${which} ${day} monthly at ${time}`.trim();
  }
  return `${day} at ${time}`;
}

async function loadVenueSelects() {
  const selects = $$('[data-venue-select]');
  if (!selects.length) return;
  try {
    const { venues = [] } = await api('/api/admin/venues');
    const active = venues.filter(v => Number(v.is_active) === 1);
    const options = active.length
      ? active.map(v => `<option value="${escapeHtml(String(v.id))}">${escapeHtml(v.name)}</option>`).join('')
      : '';
    selects.forEach(sel => { sel.innerHTML = options || '<option value="">Add a venue first</option>'; });
  } catch (_) { /* not authorized */ }
}

async function loadSchedules() {
  const list = $('[data-schedule-list]');
  if (!list) return;
  try {
    const { schedules = [] } = await api('/api/admin/schedules');
    const active = schedules.filter(s => Number(s.is_active) === 1);
    if (!active.length) {
      list.innerHTML = '<p class="muted">No recurring shows yet.</p>';
      return;
    }
    list.innerHTML = active.map(s => `
      <div class="schedule-row">
        <div>
          <strong>${escapeHtml(s.name)}</strong>
          <span class="muted"> · ${escapeHtml(s.venue_name || '')}</span>
          <div class="muted">${escapeHtml(recurrenceLabel(s))}</div>
        </div>
        <button type="button" class="danger" data-delete-schedule="${escapeHtml(String(s.id))}">Stop</button>
      </div>`).join('');
  } catch (error) {
    list.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

async function loadEvents() {
  const list = $('[data-event-list]');
  if (!list) return;
  const today = new Date().toISOString().slice(0, 10);
  const to = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
  try {
    const { events = [] } = await api(`/api/admin/events?from=${today}&to=${to}`);
    const upcoming = events.filter(e => e.status !== 'canceled');
    if (!upcoming.length) {
      list.innerHTML = '<p class="muted">No upcoming events. Recurring shows materialize automatically.</p>';
      return;
    }
    list.innerHTML = upcoming.map(e => {
      const dt = String(e.scheduled_for || '').replace('T', ' ');
      const date = dt.slice(0, 16);
      return `
        <div class="event-row">
          <div>
            <strong>${escapeHtml(e.name)}</strong>
            <span class="muted"> · ${escapeHtml(e.venue_name || '')}</span>
            <div class="muted">${escapeHtml(date)} · ${escapeHtml(e.status)}</div>
          </div>
          ${e.status === 'scheduled'
            ? `<button type="button" class="button-like" data-cancel-event="${escapeHtml(String(e.id))}">Cancel</button>`
            : ''}
        </div>`;
    }).join('');
  } catch (error) {
    list.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

export function init() {
  // Show the "which week" selector only for monthly recurrence.
  const recurrence = $('[data-recurrence]');
  const weekField = $('[data-week-of-month]');
  const syncWeekField = () => { if (weekField) weekField.hidden = recurrence?.value !== 'monthly'; };
  recurrence?.addEventListener('change', syncWeekField);
  syncWeekField();

  // Default starts_on to today.
  const startsOn = document.querySelector('[data-schedule-form] input[name="starts_on"]');
  if (startsOn && !startsOn.value) startsOn.value = new Date().toISOString().slice(0, 10);

  $('[data-schedule-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    try {
      await api('/api/admin/schedules', { method: 'POST', body: JSON.stringify(formData(form)) });
      setStatus(status, 'Recurring show added.');
      await Promise.all([loadSchedules(), loadEvents()]);
    } catch (error) { setStatus(status, error.message); }
  });

  $('[data-oneoff-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    try {
      await api('/api/admin/events', { method: 'POST', body: JSON.stringify(formData(form)) });
      setStatus(status, 'Event added.');
      form.reset();
      await loadEvents();
    } catch (error) { setStatus(status, error.message); }
  });

  document.addEventListener('click', async event => {
    const del = event.target.closest('[data-delete-schedule]');
    if (del) {
      if (!confirm('Stop this recurring show? Existing scheduled events stay on the calendar.')) return;
      await api(`/api/admin/schedules/${del.dataset.deleteSchedule}`, { method: 'DELETE' });
      await Promise.all([loadSchedules(), loadEvents()]);
      return;
    }
    const cancel = event.target.closest('[data-cancel-event]');
    if (cancel) {
      if (!confirm('Cancel this event?')) return;
      await api(`/api/admin/events/${cancel.dataset.cancelEvent}`, { method: 'DELETE' });
      await loadEvents();
    }
  });

  loadVenueSelects();
  loadSchedules();
  loadEvents();
}

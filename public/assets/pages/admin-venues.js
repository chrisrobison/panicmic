/* pages/admin-venues.js — venue CRUD for the KJ console. */

import { $, setStatus, formData, escapeHtml } from '../lib/dom.js';
import { api } from '../lib/api.js';

const EDITABLE = [
  'name', 'default_night_name', 'address_line1', 'address_line2',
  'city', 'region', 'postal_code', 'country', 'notes',
];

async function load() {
  const list = $('[data-venue-list]');
  const usage = $('[data-venue-usage]');
  if (!list) return;
  try {
    const { venues = [], venues_used = 0, max_venues = 0 } = await api('/api/admin/venues');
    if (usage) {
      usage.textContent = max_venues > 0
        ? `${venues_used} of ${max_venues} venue slots used.`
        : `${venues_used} venues.`;
    }
    if (!venues.length) {
      list.innerHTML = '<p class="muted">No venues yet. Add your first venue above.</p>';
      return;
    }
    list.innerHTML = venues.map(v => {
      const where = [v.city, v.region].filter(Boolean).join(', ');
      const inactive = Number(v.is_active) !== 1;
      return `
        <article class="venue-card${inactive ? ' is-archived' : ''}">
          <div>
            <strong>${escapeHtml(v.name)}</strong>${inactive ? ' <span class="muted">(archived)</span>' : ''}
            ${v.default_night_name ? `<span class="muted"> · ${escapeHtml(v.default_night_name)}</span>` : ''}
            ${where ? `<div class="muted">${escapeHtml(where)}</div>` : ''}
          </div>
          <div class="venue-card-actions">
            <button type="button" class="button-like" data-edit-venue='${escapeHtml(JSON.stringify(v))}'>Edit</button>
            ${inactive
              ? `<button type="button" class="button-like" data-restore-venue="${escapeHtml(String(v.id))}">Restore</button>`
              : `<button type="button" class="danger" data-archive-venue="${escapeHtml(String(v.id))}">Archive</button>`}
          </div>
        </article>`;
    }).join('');
  } catch (error) {
    list.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
  }
}

function resetForm() {
  const form = $('[data-venue-form]');
  if (!form) return;
  form.reset();
  form.elements.id.value = '';
  $('[data-venue-submit]').textContent = 'Add venue';
  $('[data-venue-reset]').hidden = true;
}

function fillForm(venue) {
  const form = $('[data-venue-form]');
  if (!form) return;
  form.elements.id.value = venue.id;
  for (const key of EDITABLE) {
    const field = form.elements.namedItem(key);
    if (field) field.value = venue[key] ?? '';
  }
  $('[data-venue-submit]').textContent = 'Save venue';
  $('[data-venue-reset]').hidden = false;
  form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export function init() {
  $('[data-venue-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    const status = $('[data-status]', form);
    const data = formData(form);
    const id = data.id;
    const payload = {};
    for (const key of EDITABLE) payload[key] = data[key] ?? '';
    try {
      if (id) {
        await api(`/api/admin/venues/${id}`, { method: 'PATCH', body: JSON.stringify(payload) });
      } else {
        await api('/api/admin/venues', { method: 'POST', body: JSON.stringify(payload) });
      }
      resetForm();
      setStatus(status, 'Saved.');
      await load();
    } catch (error) { setStatus(status, error.message); }
  });

  $('[data-venue-reset]')?.addEventListener('click', resetForm);

  document.addEventListener('click', async event => {
    const edit = event.target.closest('[data-edit-venue]');
    if (edit) {
      try { fillForm(JSON.parse(edit.dataset.editVenue)); } catch (_) {}
      return;
    }
    const archive = event.target.closest('[data-archive-venue]');
    if (archive) {
      if (!confirm('Archive this venue? It will free a venue slot but keep its history.')) return;
      await api(`/api/admin/venues/${archive.dataset.archiveVenue}`, { method: 'DELETE' });
      await load();
      return;
    }
    const restore = event.target.closest('[data-restore-venue]');
    if (restore) {
      await api(`/api/admin/venues/${restore.dataset.restoreVenue}`, { method: 'PATCH', body: JSON.stringify({ is_active: true }) });
      await load();
    }
  });

  load();
}

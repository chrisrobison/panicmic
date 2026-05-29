/* catalog.js — shared catalog search used by the public request form
 * and the admin songs page. Different pages render results differently
 * (public buttons vs admin editor cards), so the renderer is injected.
 */

import { $, escapeHtml } from './dom.js';
import { api, appConfig } from './api.js';

const state = { page: 1, size: 50, total: 0, lastFilters: {} };

export function readCatalogFilters() {
  return {
    query: $('[data-song-query]')?.value || $('[name="song_search"]')?.value || '',
    genre: $('[data-song-genre]')?.value || '',
    decade: $('[data-song-decade]')?.value || '',
  };
}

export async function searchSongs(reset = true) {
  if (reset) state.page = 1;
  const filters = { ...readCatalogFilters(), page: state.page, size: state.size };
  state.lastFilters = filters;
  const isAdmin = appConfig.page === 'admin-songs';
  const endpoint = isAdmin ? '/api/admin/songs' : '/api/catalog';
  const params = new URLSearchParams(filters);
  const data = await api(`${endpoint}?${params.toString()}`);
  state.total = data.total || 0;
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

export const catalogState = state;

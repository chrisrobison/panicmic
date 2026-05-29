/* pages/public.js — public landing page (song search + request form + queue strip). */

import { $, $$, setStatus, formData } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { loadQueue } from '../lib/queue.js';
import { searchSongs, catalogState } from '../lib/catalog.js';
import { startEvents } from '../lib/events.js';

export function init() {
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

  // Catalog search wiring (used inside the request form).
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
    if (catalogState.page * catalogState.size < catalogState.total) {
      catalogState.page++;
      searchSongs(false).catch(() => {});
    }
  });

  // Song pick — selecting a search result populates the hidden field.
  document.addEventListener('click', event => {
    const pick = event.target.closest('[data-song-pick]');
    if (!pick) return;
    const form = $('[data-request-form]');
    if (form) {
      const source = pick.dataset.songSource || 'local';
      form.elements.song_id.value = source === 'local' ? pick.dataset.songId : '';
      form.elements.shared_song_id.value = source === 'shared' ? pick.dataset.songId : '';
    }
    $$('.song-result.selected').forEach(b => b.classList.remove('selected'));
    pick.classList.add('selected');
  });

  // Initial paint + SSE.
  loadQueue().catch(() => {});
  startEvents(() => loadQueue().catch(() => {}));
}

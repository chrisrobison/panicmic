/* pages/admin-songs.js — tenant catalog CRUD + YouTube playlist import. */

import { $, setStatus, formData } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { searchSongs, catalogState } from '../lib/catalog.js';

export function init() {
  // Catalog search wiring (filter dropdowns + pagination + debounced query).
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

  // Create song.
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

  // Update song (delegated to any data-song-update form).
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-song-update]');
    if (!form) return;
    event.preventDefault();
    try {
      await api(`/api/admin/songs/${form.dataset.songUpdate}`, { method: 'PATCH', body: JSON.stringify(formData(form)) });
      setStatus($('[data-status]', form), 'Updated.');
    } catch (error) { setStatus($('[data-status]', form), error.message); }
  });

  // Delete song (click-delegated).
  document.addEventListener('click', async event => {
    const deleteSong = event.target.closest('[data-song-delete]');
    if (!deleteSong) return;
    event.preventDefault();
    if (!confirm('Remove this song from the catalog?')) return;
    try {
      await api(`/api/admin/songs/${deleteSong.dataset.songDelete}`, { method: 'DELETE' });
      await searchSongs(false);
    } catch (error) { alert(error.message); }
  });

  // Toggle inline forms.
  $('[data-toggle-add]')?.addEventListener('click', () => $('[data-add-song-panel]')?.toggleAttribute('open'));
  $('[data-toggle-playlist]')?.addEventListener('click', () => $('[data-playlist-panel]')?.toggleAttribute('open'));

  // YouTube playlist import.
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

  searchSongs(true).catch(() => {});
}

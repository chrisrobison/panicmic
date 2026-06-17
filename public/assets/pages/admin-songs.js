/* pages/admin-songs.js — tenant catalog CRUD + YouTube playlist import. */

import { $, setStatus, formData } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { searchSongs, catalogState } from '../lib/catalog.js';
import { installCoverFallback } from '../lib/albumArt.js';

export function init() {
  // Ensure broken hotlinked covers swap to their generated fallbacks.
  installCoverFallback();

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

  // Fetch album art for a song (click-delegated).
  //
  // Strategy:
  //   1. Try the Spotify-backed album-art JS library (loaded from CDN as
  //      window.albumArt). If it returns a URL, POST it to cache-album-art-url
  //      so the server downloads and stores a local copy.
  //   2. If the CDN library is unavailable or returns nothing, fall back to the
  //      backend Last.fm lookup via fetch-album-art.
  //
  // Either way the response contains the new local /files/… URL which we write
  // into the album_art_url input and use to update the preview thumbnail.
  document.addEventListener('click', async event => {
    const fetchBtn = event.target.closest('[data-fetch-art]');
    if (!fetchBtn) return;
    event.preventDefault();

    const songId   = fetchBtn.dataset.fetchArt;
    const artist   = fetchBtn.dataset.songArtist;
    const title    = fetchBtn.dataset.songTitle;
    const album    = fetchBtn.dataset.songAlbum;
    const form     = fetchBtn.closest('[data-song-update]');
    const urlInput = form?.querySelector('[name="album_art_url"]');
    const coverImg = form?.querySelector('.song-card-art');
    const status   = form ? $('[data-status]', form) : null;

    fetchBtn.disabled = true;
    if (status) setStatus(status, 'Fetching album art…');

    try {
      let data;

      // Try Spotify (album-art CDN library) first.
      const albumArtLib = /** @type {Function|undefined} */ (window.albumArt);
      if (typeof albumArtLib === 'function') {
        let spotifyUrl = null;
        try {
          const opts = album ? { album } : {};
          spotifyUrl = await albumArtLib(artist, opts);
        } catch (_) {
          spotifyUrl = null;
        }

        if (spotifyUrl) {
          data = await api(`/api/admin/songs/${songId}/cache-album-art-url`, {
            method: 'POST',
            body: JSON.stringify({ url: spotifyUrl }),
          });
        }
      }

      // Fall back to backend Last.fm lookup if Spotify gave nothing.
      if (!data) {
        data = await api(`/api/admin/songs/${songId}/fetch-album-art`, { method: 'POST' });
      }

      // Update the UI with the newly cached local URL.
      if (data?.album_art_url) {
        if (urlInput) urlInput.value = data.album_art_url;
        if (coverImg) {
          coverImg.src = data.album_art_url;
          delete coverImg.dataset.coverFallback; // clear old fallback so it won't revert
        }
        if (status) setStatus(status, 'Album art fetched and cached locally.');
      }
    } catch (error) {
      if (status) setStatus(status, `Could not fetch art: ${error.message}`);
    } finally {
      fetchBtn.disabled = false;
    }
  });

  searchSongs(true).catch(() => {});
}

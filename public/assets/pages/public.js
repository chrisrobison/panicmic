/* pages/public.js — public landing page (song search + request form + queue strip). */

import { $, $$, setStatus, formData } from '../lib/dom.js';
import { api, appConfig } from '../lib/api.js';
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
      // Clear search field and results so the form is ready for the next person.
      const queryInput = $('[data-song-query]') || $('[name="song_search"]');
      if (queryInput) queryInput.value = '';
      const resultsEl = $('[data-song-results]');
      if (resultsEl) resultsEl.innerHTML = '';
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

  // Catalog page (/songs): initial load, help (?) toggle, and infinite scroll.
  if (appConfig.page === 'songs') {
    searchSongs(true).catch(() => {});
    setupCatalogHelpToggle();
    setupInfiniteScroll();
  }

  // Initial paint + SSE.
  loadQueue().catch(() => {});
  startEvents(() => loadQueue().catch(() => {}));
}

// (?) icon next to the catalog header reveals the explanation text (used on mobile).
function setupCatalogHelpToggle() {
  const toggle = $('[data-help-toggle]');
  const help = $('[data-catalog-help]');
  if (!toggle || !help) return;
  toggle.addEventListener('click', () => {
    const shown = help.classList.toggle('show');
    toggle.setAttribute('aria-expanded', shown ? 'true' : 'false');
  });
}

// Auto-fetch the next page of songs when the user scrolls near the bottom.
function setupInfiniteScroll() {
  const indicator = $('[data-catalog-loading]');
  let loading = false;
  const onScroll = async () => {
    if (loading) return;
    if (catalogState.page * catalogState.size >= catalogState.total) return; // everything loaded
    const nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 400;
    if (!nearBottom) return;
    loading = true;
    catalogState.page++;
    if (indicator) indicator.hidden = false;
    try {
      await searchSongs(false, { append: true });
    } catch {
      catalogState.page--; // roll back so a later scroll can retry
    } finally {
      if (indicator) indicator.hidden = true;
      loading = false;
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
}

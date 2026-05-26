<section class="workspace">
  <header class="admin-page-header">
    <div>
      <h1>Song catalog</h1>
      <p class="muted">Browse the full catalog. Selections from this page are stored locally and used when you return to request a song.</p>
    </div>
  </header>
  <div class="catalog-toolbar">
    <input data-song-query placeholder="Search title or artist">
    <select data-song-genre>
      <option value="">All genres</option>
      <option>Pop</option><option>Rock</option><option>Country</option>
      <option>R&amp;B</option><option>Hip-Hop</option><option>Soundtrack</option>
    </select>
    <select data-song-decade>
      <option value="">All decades</option>
      <option>1960</option><option>1970</option><option>1980</option>
      <option>1990</option><option>2000</option><option>2010</option><option>2020</option>
    </select>
    <button data-song-search>Search</button>
  </div>
  <div class="catalog-meta">
    <span data-catalog-meta>Loading…</span>
    <div class="pager">
      <button data-page-prev disabled>‹ Prev</button>
      <span data-page-indicator></span>
      <button data-page-next disabled>Next ›</button>
    </div>
  </div>
  <div data-song-table class="song-grid"></div>
</section>

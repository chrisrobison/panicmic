<section class="workspace">
  <header class="admin-page-header">
    <div>
      <h1>Song catalog
        <button class="help-toggle" type="button" data-help-toggle aria-label="About the catalog" aria-expanded="false">?</button>
      </h1>
      <p class="muted catalog-help" data-catalog-help>Browse the full catalog. Selections from this page are stored locally and used when you return to request a song.</p>
    </div>
  </header>

  <!-- Discovery browse chips -->
  <div class="catalog-chips" aria-label="Browse by category">
    <button class="chip" type="button" data-tag-chip="" aria-pressed="false">All</button>
    <button class="chip" type="button" data-tag-chip="songs-everyone-knows" aria-pressed="false">Songs Everyone Knows</button>
    <button class="chip" type="button" data-tag-chip="beginner-friendly" aria-pressed="false">Beginner Friendly</button>
    <button class="chip" type="button" data-tag-chip="crowd-favorite" aria-pressed="false">Crowd Favorites</button>
    <button class="chip" type="button" data-tag-chip="power-ballad" aria-pressed="false">Power Ballads</button>
    <button class="chip" type="button" data-tag-chip="guilty-pleasure" aria-pressed="false">Guilty Pleasures</button>
    <button class="chip" type="button" data-tag-chip="duet" aria-pressed="false">Duets</button>
    <button class="chip" type="button" data-tag-chip="live105-classic" aria-pressed="false">Live 105 Classics</button>
    <button class="chip" type="button" data-tag-chip="bay-area-nostalgia" aria-pressed="false">Bay Area</button>
    <button class="chip" type="button" data-tag-chip="1980s" aria-pressed="false">80s</button>
    <button class="chip" type="button" data-tag-chip="1990s" aria-pressed="false">90s</button>
    <button class="chip" type="button" data-tag-chip="punk-ish-singalong" aria-pressed="false">Punk-ish</button>
    <button class="chip" type="button" data-tag-chip="kj-panic-pick" aria-pressed="false">KJ Picks</button>
  </div>

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
  </div>
  <div data-song-table class="song-grid"></div>
  <p class="catalog-loading muted" data-catalog-loading hidden>Loading more songs…</p>
</section>

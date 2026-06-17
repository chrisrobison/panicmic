<?php use function PanicMic\Support\e; use PanicMic\Support\Url; ?>
<section class="workspace super-workspace">
  <nav class="super-nav">
    <a href="<?= e(Url::path('/super/tenants')) ?>">Tenants</a>
    <a class="active" href="<?= e(Url::path('/super/catalog')) ?>">Shared catalog</a>
    <button data-super-logout class="muted-link" type="button">Sign out</button>
  </nav>

  <header class="super-header">
    <div>
      <h1>Shared catalog</h1>
      <p class="muted">Master catalog every tenant searches. Import curated lists, enrich with Last.fm, browse by discovery tags.</p>
    </div>
    <div class="catalog-summary">
      <div class="stat-tile"><span class="stat-value" data-shared-total>—</span><span class="stat-label">Songs</span></div>
    </div>
  </header>

  <!-- Tab bar -->
  <div class="catalog-tabs" role="tablist">
    <button class="catalog-tab active" role="tab" aria-selected="true"  data-tab="browse"   aria-controls="tab-browse">Browse</button>
    <button class="catalog-tab"        role="tab" aria-selected="false" data-tab="import"   aria-controls="tab-import">Import</button>
    <button class="catalog-tab"        role="tab" aria-selected="false" data-tab="sources"  aria-controls="tab-sources">Sources</button>
    <button class="catalog-tab"        role="tab" aria-selected="false" data-tab="runs"     aria-controls="tab-runs">Import runs</button>
  </div>

  <!-- ===== Tab: Browse ===== -->
  <div id="tab-browse" class="catalog-tab-panel" role="tabpanel" data-tab-panel="browse">
    <div class="catalog-toolbar">
      <input data-shared-query placeholder="Search title or artist">
      <select data-shared-tag>
        <option value="">All tags</option>
        <option value="songs-everyone-knows">Songs Everyone Knows</option>
        <option value="beginner-friendly">Beginner Friendly</option>
        <option value="crowd-favorite">Crowd Favorite</option>
        <option value="power-ballad">Power Ballad</option>
        <option value="guilty-pleasure">Guilty Pleasure</option>
        <option value="duet">Duet</option>
        <option value="live105-classic">Live 105 Classic</option>
        <option value="bay-area-nostalgia">Bay Area Nostalgia</option>
        <option value="alternative">Alternative</option>
        <option value="punk-ish-singalong">Punk-ish Singalong</option>
        <option value="1980s">1980s</option>
        <option value="1990s">1990s</option>
        <option value="kj-panic-pick">KJ Panic Pick</option>
      </select>
      <select data-shared-sort>
        <option value="">Sort: A–Z</option>
        <option value="karaoke">Sort: Karaoke score</option>
        <option value="nostalgia">Sort: Nostalgia score</option>
        <option value="source">Sort: Source score</option>
      </select>
      <button data-shared-search>Search</button>
    </div>

    <div class="catalog-meta">
      <span data-shared-meta>Loading…</span>
    </div>

    <table class="shared-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Artist</th>
          <th>Year</th>
          <th>Genre</th>
          <th>K-Score</th>
          <th>Sources</th>
          <th class="row-actions"></th>
        </tr>
      </thead>
      <tbody data-shared-rows>
        <tr><td colspan="7" class="muted">Loading…</td></tr>
      </tbody>
    </table>

    <div class="infinite-status" data-shared-status aria-live="polite"></div>
    <div class="infinite-sentinel" data-shared-sentinel aria-hidden="true"></div>
  </div>

  <!-- ===== Tab: Import ===== -->
  <div id="tab-import" class="catalog-tab-panel" role="tabpanel" data-tab-panel="import" hidden>
    <div class="panel import-panel">
      <h2>Import from CSV or JSON</h2>
      <p class="muted">
        CSV must be semicolon-delimited.<br>
        Headers accepted: <code>Title;Artist;Year;Genre;Rank;Duo;Explicit;Styles;Languages;Tags;Notes</code><br>
        Also accepts rich format with Source, List Title, Source URL columns.<br>
        JSON: array of objects with <code>title</code> and <code>artist</code> keys.
      </p>
      <form data-shared-import enctype="multipart/form-data">
        <label class="file-row">
          <input type="file" name="file" accept=".csv,.json,text/csv,application/json" required>
        </label>
        <div class="song-card-actions">
          <button class="primary" type="submit">Upload &amp; import</button>
          <a class="button-like" href="<?= e(Url::path('/api/super/catalog/export')) ?>" target="_blank" rel="noreferrer">Export current catalog</a>
          <button class="button-like" type="button" data-recalculate-scores>Recalculate scores</button>
          <span data-status></span>
        </div>
        <div class="import-progress" data-import-progress hidden>
          <div class="import-bar"><span data-import-fill></span></div>
          <p class="muted" data-import-summary></p>
        </div>
      </form>
    </div>

    <div class="panel import-panel" style="margin-top:1rem">
      <h2>Last.fm Enrichment</h2>
      <p class="muted">Fill in album art, MBID, tags, and listener counts from Last.fm. Enriches up to 25 songs at a time.</p>
      <div class="song-card-actions">
        <button class="primary" type="button" data-shared-enrich>Enrich next 25</button>
        <span data-enrich-status></span>
      </div>
    </div>
  </div>

  <!-- ===== Tab: Sources ===== -->
  <div id="tab-sources" class="catalog-tab-panel" role="tabpanel" data-tab-panel="sources" hidden>
    <div class="catalog-meta"><span data-sources-meta>Loading…</span></div>
    <table class="shared-table">
      <thead>
        <tr>
          <th>Source</th>
          <th>Type</th>
          <th>Station</th>
          <th>Market</th>
          <th>Lists</th>
        </tr>
      </thead>
      <tbody data-sources-rows>
        <tr><td colspan="5" class="muted">Loading…</td></tr>
      </tbody>
    </table>
    <h3 style="margin-top:1.5rem">All imported lists</h3>
    <table class="shared-table">
      <thead>
        <tr>
          <th>List</th>
          <th>Source</th>
          <th>Year</th>
          <th>Type</th>
          <th>Last fetched</th>
        </tr>
      </thead>
      <tbody data-lists-rows>
        <tr><td colspan="5" class="muted">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- ===== Tab: Import Runs ===== -->
  <div id="tab-runs" class="catalog-tab-panel" role="tabpanel" data-tab-panel="runs" hidden>
    <div class="catalog-meta"><span data-runs-meta>Loading…</span></div>
    <table class="shared-table">
      <thead>
        <tr>
          <th>Source</th>
          <th>Status</th>
          <th>Started</th>
          <th>Seen</th>
          <th>Created</th>
          <th>Matched</th>
          <th>Skipped</th>
          <th>Review</th>
          <th>Report</th>
        </tr>
      </thead>
      <tbody data-runs-rows>
        <tr><td colspan="9" class="muted">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Song metadata drawer -->
  <div class="drawer" data-song-drawer hidden aria-labelledby="drawer-song-title" role="dialog">
    <div class="drawer-content">
      <div class="drawer-header">
        <h2 id="drawer-song-title" data-drawer-song-title>Song details</h2>
        <button class="icon-btn" data-song-drawer-close aria-label="Close">✕</button>
      </div>
      <div data-song-drawer-body>
        <p class="muted">Select a song to view details.</p>
      </div>
    </div>
  </div>
</section>

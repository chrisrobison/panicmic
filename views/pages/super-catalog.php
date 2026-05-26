<?php use function NextUp\Support\e; use NextUp\Support\Url; ?>
<section class="workspace super-workspace">
  <nav class="super-nav">
    <a href="<?= e(Url::path('/super/tenants')) ?>">Tenants</a>
    <a class="active" href="<?= e(Url::path('/super/catalog')) ?>">Shared catalog</a>
    <button data-super-logout class="muted-link" type="button">Sign out</button>
  </nav>

  <header class="super-header">
    <div>
      <h1>Shared catalog</h1>
      <p class="muted">Read-only master catalog every tenant searches. Import from songs.csv, export the whole list, or remove individual entries.</p>
    </div>
    <div class="catalog-summary">
      <div class="stat-tile"><span class="stat-value" data-shared-total>—</span><span class="stat-label">Songs in shared catalog</span></div>
    </div>
  </header>

  <div class="panel import-panel">
    <h2>Import from songs.csv</h2>
    <p class="muted">CSV must be semicolon-delimited with these headers in row 1: <code>Id;Title;Artist;Year;Duo;Explicit;Date Added;Styles;Languages</code>. Existing entries (same title + artist) get updated.</p>
    <form data-shared-import enctype="multipart/form-data">
      <label class="file-row">
        <input type="file" name="file" accept=".csv,text/csv" required>
      </label>
      <div class="song-card-actions">
        <button class="primary" type="submit">Upload &amp; import</button>
        <a class="button-like" href="<?= e(Url::path('/api/super/catalog/export')) ?>" target="_blank" rel="noreferrer">Export current catalog</a>
        <span data-status></span>
      </div>
      <div class="import-progress" data-import-progress hidden>
        <div class="import-bar"><span data-import-fill></span></div>
        <p class="muted" data-import-summary></p>
      </div>
    </form>
  </div>

  <div class="catalog-toolbar">
    <input data-shared-query placeholder="Search title or artist">
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
        <th>Languages</th>
        <th class="row-actions"></th>
      </tr>
    </thead>
    <tbody data-shared-rows>
      <tr><td colspan="6" class="muted">Loading…</td></tr>
    </tbody>
  </table>

  <div class="infinite-status" data-shared-status aria-live="polite"></div>
  <div class="infinite-sentinel" data-shared-sentinel aria-hidden="true"></div>
</section>

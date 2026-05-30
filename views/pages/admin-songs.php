<?php
use function PanicMic\Support\e;
use PanicMic\Support\Url;
$current = 'songs';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header">
      <div>
        <h1>Song catalog</h1>
        <p class="muted">Curated catalog for this venue. Public search also includes the shared catalog managed by your administrator.</p>
      </div>
    </header>

    <div class="catalog-toolbar">
      <input data-song-query placeholder="Search title or artist" autocomplete="off">
      <button data-song-search>Search</button>
      <button data-toggle-add>+ Add song</button>
      <button data-toggle-playlist>Import YouTube playlist</button>
      <a class="button-like" href="<?= e(Url::path('/api/admin/songs/export')) ?>" target="_blank" rel="noreferrer">Export CSV</a>
    </div>

    <details class="panel inline-form" data-add-song-panel>
      <summary>Add a song</summary>
      <form class="song-editor" data-song-create>
        <div class="song-editor-grid">
          <label>Title<input name="title" required></label>
          <label>Artist<input name="artist" required></label>
          <label>Genre<input name="genre"></label>
          <label>Decade<input name="decade" type="number" min="1900" max="2090" step="10"></label>
          <label>Popularity<input name="popularity" type="number" min="0" value="0"></label>
          <label>External ID<input name="external_id"></label>
          <label>Video URL<input name="video_url" placeholder="https://… or /files/song.mp4"><small class="muted">Self-hosted (<code>/files/yourfile.mp4</code>) plays without YouTube quota.</small></label>
          <label>Provider
            <select name="video_provider">
              <option value="">Custom / none</option>
              <option value="youtube">YouTube</option>
              <option value="karafun">KaraFun</option>
              <option value="stingray">Stingray</option>
              <option value="singa">Singa</option>
              <option value="local">Local file</option>
            </select>
          </label>
          <label>Provider track ID<input name="provider_track_id"></label>
          <label>Provider URL<input name="provider_url" type="url"></label>
          <label>Lyrics URL<input name="lyrics_url" type="url"></label>
        </div>
        <div class="song-card-actions">
          <button class="primary">Save song</button>
          <span data-status></span>
        </div>
      </form>
    </details>

    <details class="panel inline-form" data-playlist-panel>
      <summary>Import a YouTube playlist</summary>
      <form data-playlist-import>
        <label>Playlist URL or ID
          <input name="playlist" required placeholder="https://www.youtube.com/playlist?list=PLxxxxxxxxxxxxxxxx">
        </label>
        <p class="muted">Each video becomes a tenant catalog entry. Titles formatted as <code>Artist - Title</code> import cleanly; the rest fall back to the channel name as artist.</p>
        <div class="song-card-actions">
          <button class="primary">Start import</button>
          <span data-status></span>
        </div>
      </form>
    </details>

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
</section>

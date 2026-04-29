<?php use function NextUp\Support\e; ?>
<section class="admin-layout">
  <aside>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/dashboard')) ?>">Queue</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/songs')) ?>">Songs</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/content')) ?>">Content</a>
  </aside>
  <section class="operator">
    <form class="panel song-editor" data-song-create>
      <h1>Add Song</h1>
      <label>Title<input name="title" required></label>
      <label>Artist<input name="artist" required></label>
      <label>Genre<input name="genre"></label>
      <label>Decade<input name="decade" type="number" min="1900" max="2090" step="10"></label>
      <label>Popularity<input name="popularity" type="number" min="0" value="0"></label>
      <label>Video with lyrics URL<input name="video_url" type="url" placeholder="https://..."></label>
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
      <label>Provider URL<input name="provider_url" type="url" placeholder="https://..."></label>
      <label>Lyrics URL<input name="lyrics_url" type="url" placeholder="https://..."></label>
      <button class="primary">Save Song</button>
      <p data-status></p>
    </form>
    <div class="toolbar"><input data-song-query placeholder="Search catalog"><button data-song-search>Search</button></div>
    <div data-song-table class="song-grid"></div>
  </section>
</section>

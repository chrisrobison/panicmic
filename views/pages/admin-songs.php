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
      <button class="primary">Save Song</button>
      <p data-status></p>
    </form>
    <div class="toolbar"><input data-song-query placeholder="Search catalog"><button data-song-search>Search</button></div>
    <div data-song-table class="song-grid"></div>
  </section>
</section>

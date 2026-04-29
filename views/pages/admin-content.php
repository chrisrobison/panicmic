<?php use function NextUp\Support\e; ?>
<section class="admin-layout">
  <aside>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/dashboard')) ?>">Queue</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/songs')) ?>">Songs</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/content')) ?>">Content</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/settings')) ?>">Settings</a>
  </aside>
  <section class="operator">
    <form class="panel content-upload" data-content-upload enctype="multipart/form-data">
      <h1>Venue Content</h1>
      <label>Image, video, audio, or PDF
        <input name="content_file" type="file" accept="image/*,video/*,audio/*,.pdf" required>
      </label>
      <button class="primary">Upload</button>
      <p data-status></p>
    </form>
    <div data-content-files class="content-grid"></div>
  </section>
</section>

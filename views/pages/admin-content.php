<?php
use function NextUp\Support\e;
use NextUp\Support\Url;
$current = 'content';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header">
      <div>
        <h1>Content uploads</h1>
        <p class="muted">Images, videos, audio, and PDFs are served from <code>/files/...</code> on this tenant's domain only.</p>
      </div>
    </header>
    <form class="panel content-upload" data-content-upload enctype="multipart/form-data">
      <label>Upload a file
        <input type="file" name="content_file" required accept="image/*,video/*,audio/*,.pdf">
      </label>
      <div class="song-card-actions">
        <button class="primary">Upload</button>
        <span data-status></span>
      </div>
    </form>
    <div data-content-files class="content-grid"></div>
  </section>
</section>

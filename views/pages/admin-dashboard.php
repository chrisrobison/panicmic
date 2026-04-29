<?php use function NextUp\Support\e; ?>
<section class="admin-layout">
  <aside>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/dashboard')) ?>">Dashboard</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/songs')) ?>">Songs</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/content')) ?>">Content</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/settings')) ?>">Settings</a>
    <a href="<?= e(\NextUp\Support\Url::path('/display/control')) ?>">Display Control</a>
  </aside>
  <section class="operator">
    <div class="toolbar live-controls">
      <button data-display-mode="idle">Idle</button>
      <button data-display-mode="queue">Queue</button>
      <button data-display-mode="clean_stage">Clean Stage</button>
      <button data-next-singer class="primary">Next Singer</button>
    </div>
    <form class="announcement" data-announcement-form>
      <input name="message" maxlength="500" placeholder="Announcement">
      <button>Show</button>
    </form>
    <div data-admin-queue class="queue-board"></div>
  </section>
</section>

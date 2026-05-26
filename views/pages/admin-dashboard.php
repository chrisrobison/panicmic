<?php
use function NextUp\Support\e;
use NextUp\Support\Url;
$current = 'dashboard';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header">
      <div>
        <h1>Dashboard</h1>
        <p class="muted">Live queue control for <?= e($tenant['night_name']) ?>.</p>
      </div>
      <div class="admin-stats" data-admin-stats>
        <div class="stat-tile"><span class="stat-value" data-stat="queue">—</span><span class="stat-label">In queue</span></div>
        <div class="stat-tile"><span class="stat-value" data-stat="up_next">—</span><span class="stat-label">Up next</span></div>
        <div class="stat-tile"><span class="stat-value" data-stat="now_singing">—</span><span class="stat-label">Now singing</span></div>
        <div class="stat-tile"><span class="stat-value" data-stat="completed">—</span><span class="stat-label">Completed</span></div>
      </div>
    </header>
    <div class="toolbar live-controls">
      <button data-display-mode="idle">Idle</button>
      <button data-display-mode="queue">Queue</button>
      <button data-display-mode="clean_stage">Clean Stage</button>
      <button data-next-singer class="primary">Next singer</button>
    </div>
    <div class="toolbar session-controls">
      <span class="muted">Session: <strong><?= e($session['name']) ?></strong> (<?= e($session['status'] ?? 'active') ?>)</span>
      <form data-session-start class="inline">
        <input name="name" placeholder="New session name" maxlength="180">
        <button>Start new</button>
      </form>
      <button data-session-end class="danger">End session</button>
    </div>
    <form class="announcement" data-announcement-form>
      <input name="message" maxlength="500" placeholder="Announcement to display">
      <button>Show on display</button>
    </form>
    <div data-admin-queue class="queue-board"></div>
  </section>
</section>

<?php
use function PanicMic\Support\e;
use PanicMic\Support\QrCode;
use PanicMic\Support\Url;
$current = 'dashboard';
$host = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$singerUrl = $tenant['public_request_url'] ?: ($scheme . '://' . $host . Url::path('/'));
$dashboardQr = QrCode::svg($singerUrl, 120);
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
    <div class="toolbar display-windows" data-display-windows>
      <span class="muted">Displays:</span>
      <!-- populated by JS from /api/display/screens -->
    </div>
    <div class="toolbar session-controls">
      <span class="muted">Session: <strong><?= e($session['name']) ?></strong> (<?= e($session['status'] ?? 'active') ?>)</span>
      <form data-session-start class="inline">
        <select name="venue_id" data-session-venue>
          <option value="">No venue</option>
        </select>
        <input name="name" placeholder="Night name" maxlength="180">
        <button>Start new</button>
      </form>
      <button data-session-end class="danger">End session</button>
    </div>
    <div class="toolbar tonight-events" data-tonight-events hidden>
      <span class="muted">Tonight's schedule:</span>
      <!-- quick-start buttons populated by JS from /api/admin/events -->
    </div>
    <form class="announcement" data-announcement-form>
      <input name="message" maxlength="500" placeholder="Announcement to display">
      <button>Show on display</button>
    </form>
    <aside class="qr-widget">
      <div class="qr-image"><?= $dashboardQr ?></div>
      <div>
        <h3>Singer URL</h3>
        <p class="qr-url"><?= e($singerUrl) ?></p>
        <div class="qr-actions">
          <a class="button-like" href="<?= e(Url::path('/admin/promote')) ?>">Print poster</a>
          <a class="button-like" href="<?= e($singerUrl) ?>" target="_blank" rel="noreferrer">Open</a>
        </div>
      </div>
    </aside>
    <div data-admin-queue class="queue-board"></div>
  </section>
</section>

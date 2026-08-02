<?php
use function PanicMic\Support\e;
use PanicMic\Auth\Auth;
use PanicMic\Http\HealthController;
use PanicMic\Support\QrCode;
use PanicMic\Support\Url;
$current = 'dashboard';

// Schema drift is invisible until it breaks a show, so surface it where
// the KJ will actually see it. Unapplied migrations previously took down
// every realtime write with no signal outside a log file.
$pendingMigrations = ($healthDb = Auth::db()) ? HealthController::pendingMigrations($healthDb) : [];

// Loading the dashboard no longer auto-creates a session (a GET must not
// have that side effect), so "no live session" is now a real state the KJ
// has to see. It was previously hidden inside a collapsed panel.
$sessionIsLive = in_array((string)($session['status'] ?? ''), ['live', 'active', 'paused'], true)
    && (int)($session['id'] ?? 0) > 0;
$host = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$singerUrl = $tenant['public_request_url'] ?: ($scheme . '://' . $host . Url::path('/'));
$dashboardQr = QrCode::svg($singerUrl, 96);
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator kj-console">

    <?php if ($pendingMigrations !== []): ?>
      <div class="kj-alert kj-alert--danger" role="alert">
        <strong>Database update required.</strong>
        <?= count($pendingMigrations) ?> migration<?= count($pendingMigrations) === 1 ? '' : 's' ?>
        (<?= e(implode(', ', $pendingMigrations)) ?>)
        <?= count($pendingMigrations) === 1 ? 'has' : 'have' ?> not been applied to this account's database.
        Realtime updates, the queue, and display sync may fail until an
        administrator runs <code>make migrate</code> on the server.
      </div>
    <?php endif; ?>

    <?php if (!$sessionIsLive): ?>
      <div class="kj-alert kj-alert--warning" role="status">
        <strong>No live session.</strong>
        Requests, the queue, and the displays stay dark until you start the
        night. Name it and hit start — you can change the name later.
        <form data-session-start class="kj-alert-form inline">
          <select name="venue_id" data-session-venue>
            <option value="">No venue</option>
          </select>
          <input name="name" placeholder="<?= e($tenant['night_name'] ?: 'Karaoke Night') ?>" maxlength="180">
          <button class="primary">Start the night</button>
        </form>
      </div>
    <?php endif; ?>

    <header class="kj-toolbar">
      <div class="kj-toolbar-search">
        <input type="search" data-quick-search placeholder="Search songs, artists, or singers…" aria-label="Search catalog">
      </div>
      <div class="kj-toolbar-actions">
        <button type="button" class="primary" data-add-request>+ Add Request</button>
        <button type="button" class="ok" data-start-song>▶ Start Song</button>
        <button type="button" data-toggle-pause data-paused="0">⏸ Pause</button>
        <button type="button" data-skip-song>⏭ Skip</button>
        <button type="button" data-quick-announce>📢 Announce</button>
        <button type="button" class="danger" data-blackout-all>⛔ Blackout Displays</button>
      </div>
      <div class="kj-toolbar-meta">
        <span class="kj-displays-connected"><strong data-displays-connected-count>0</strong> Displays Connected</span>
        <details class="kj-menu">
          <summary><?= e($tenant['night_name'] ?: 'KJ') ?></summary>
          <div class="kj-menu-panel">
            <a href="<?= e(Url::path('/admin/settings')) ?>">Settings</a>
            <a href="<?= e(Url::path('/admin/help')) ?>" data-help-modal>Shortcuts &amp; Support</a>
            <a href="<?= e(Url::path('/admin/promote')) ?>">Print poster</a>
            <a href="<?= e(Url::path('/admin/logout')) ?>">Logout</a>
          </div>
        </details>
      </div>
    </header>

    <div class="admin-stats" data-admin-stats>
      <div class="stat-tile"><span class="stat-value" data-stat="incoming">—</span><span class="stat-label">Incoming</span></div>
      <div class="stat-tile"><span class="stat-value" data-stat="queue">—</span><span class="stat-label">In queue</span></div>
      <div class="stat-tile"><span class="stat-value" data-stat="up_next">—</span><span class="stat-label">Up next</span></div>
      <div class="stat-tile"><span class="stat-value" data-stat="now_singing">—</span><span class="stat-label">Now singing</span></div>
      <div class="stat-tile"><span class="stat-value" data-stat="completed">—</span><span class="stat-label">Completed</span></div>
    </div>

    <div class="kj-columns">

      <!-- Column 1: Incoming Requests -->
      <section class="panel kj-col kj-incoming">
        <div class="panel-head">
          <h2>Incoming Requests <span class="count-badge" data-incoming-count>0</span></h2>
        </div>
        <div class="incoming-list" data-incoming-requests><p class="muted">No new requests.</p></div>
        <label class="auto-accept-row">
          <input type="checkbox" data-auto-accept> Auto-accept
          <a href="<?= e(Url::path('/admin/settings')) ?>" class="settings-gear" title="More settings">⚙</a>
        </label>
      </section>

      <!-- Column 2: Rotation Queue + Now Playing -->
      <section class="kj-col kj-rotation-col">
        <div class="panel kj-rotation">
          <div class="panel-head">
            <h2>Rotation Queue <span class="count-badge" data-rotation-count>0</span></h2>
            <div class="panel-head-meta">
              <span class="muted">Est. Time: <strong data-rotation-estimate>00:00:00</strong></span>
              <button type="button" data-reorder-mode>⇅ Reorder Mode</button>
            </div>
          </div>
          <div data-admin-queue class="queue-board"><p class="muted">Loading…</p></div>
          <div class="rotation-footer">
            <span class="muted" data-rotation-footer></span>
          </div>
        </div>

        <div class="panel now-playing" data-now-playing>
          <h2 class="visually-hidden">Now Playing</h2>
          <div class="now-playing-art"><span data-now-playing-art>🎤</span></div>
          <div class="now-playing-info">
            <strong data-now-playing-title>Nothing playing</strong>
            <span class="muted" data-now-playing-artist></span>
            <span class="muted" data-now-playing-singer></span>
          </div>
          <div class="now-playing-transport">
            <button type="button" data-np-pause title="Pause / resume">⏸</button>
            <button type="button" data-np-skip title="Skip to next singer">⏭</button>
            <span class="now-playing-elapsed" data-now-playing-elapsed></span>
          </div>
        </div>
      </section>

      <!-- Column 3: Crowd preview, connected displays, announcements, activity -->
      <section class="kj-col kj-side">
        <div class="panel crowd-preview">
          <div class="panel-head"><h2>Crowd Display Preview</h2><span class="live-dot">● LIVE</span></div>
          <div class="crowd-preview-frame">
            <iframe src="<?= e(Url::path('/display?screen=main')) ?>" title="Crowd display preview" loading="lazy" tabindex="-1" allow="autoplay"></iframe>
          </div>
        </div>

        <div class="panel connected-displays" id="displays">
          <div class="panel-head"><h2>Connected Displays <span class="count-badge" data-displays-count>0</span></h2></div>
          <div data-connected-displays><p class="muted">Loading…</p></div>
        </div>

        <div class="panel announcement-panel">
          <h2>Announcement to Displays</h2>
          <form data-announcement-form>
            <textarea name="message" maxlength="500" placeholder="Welcome! Thanks for joining us tonight." rows="2"></textarea>
            <button class="primary">Send Announcement</button>
          </form>
        </div>

        <div class="panel activity-log">
          <div class="panel-head"><h2>Activity Log</h2></div>
          <div data-activity-log><p class="muted">No activity yet.</p></div>
        </div>

        <details class="panel session-panel">
          <summary>Session &amp; singer link</summary>
          <div class="session-panel-body">
            <p class="muted">Session: <strong><?= e($session['name']) ?></strong> (<?= e($session['status'] ?? 'active') ?>)</p>
            <form data-session-start class="inline">
              <select name="venue_id" data-session-venue>
                <option value="">No venue</option>
              </select>
              <input name="name" placeholder="Night name" maxlength="180">
              <button>Start new</button>
            </form>
            <button data-session-end class="danger" type="button">End session</button>
            <div class="tonight-events" data-tonight-events hidden></div>
            <div class="qr-widget">
              <div class="qr-image"><?= $dashboardQr ?></div>
              <div>
                <p class="qr-url"><?= e($singerUrl) ?></p>
                <div class="qr-actions">
                  <a class="button-like" href="<?= e(Url::path('/admin/promote')) ?>">Print poster</a>
                  <a class="button-like" href="<?= e($singerUrl) ?>" target="_blank" rel="noreferrer">Open</a>
                </div>
              </div>
            </div>
          </div>
        </details>
      </section>
    </div>

    <footer class="kj-status-bar">
      <span data-ws-status class="status-dot-label">● Connecting…</span>
      <span class="muted"><?= e(date('g:i A · F j, Y')) ?></span>
    </footer>

    <!-- Add Request modal -->
    <dialog data-add-request-modal class="add-request-modal">
      <form data-add-request-form>
        <h2>Add a singer</h2>
        <p class="muted">
          Goes straight into the rotation. Works while requests are paused,
          and the song doesn't have to be in your catalog.
        </p>
        <label>Singer name
          <input name="display_name" required maxlength="160" placeholder="Singer's name" data-add-request-name>
        </label>
        <label>Song
          <input type="search" data-song-query placeholder="Search the catalog…" autocomplete="off">
        </label>
        <div class="song-results" data-song-results></div>

        <p class="picked-song" data-picked-song hidden>
          <span data-picked-song-label></span>
          <button type="button" class="link" data-clear-song>change</button>
        </p>

        <!-- Walk-ups whose song isn't in either catalog. Revealed on
             demand so the catalog stays the default path. -->
        <details class="walkup-song" data-walkup-song>
          <summary>Not in the catalog? Type it in</summary>
          <div class="walkup-song-fields">
            <label>Song title
              <input name="custom_song_title" maxlength="200" placeholder="Song title">
            </label>
            <label>Artist <span class="muted">(optional)</span>
              <input name="custom_song_artist" maxlength="200" placeholder="Artist">
            </label>
          </div>
        </details>

        <input type="hidden" name="song_id">
        <input type="hidden" name="shared_song_id">
        <label>Party
          <select name="party_type">
            <option value="solo">Solo</option>
            <option value="duet">Duet</option>
            <option value="group">Group</option>
          </select>
        </label>
        <p data-status></p>
        <div class="modal-actions">
          <button type="button" data-modal-cancel>Cancel</button>
          <button type="submit" class="primary">Add Request</button>
        </div>
      </form>
    </dialog>
  </section>
</section>

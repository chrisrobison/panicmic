<?php
use NextUp\Support\QrCode;
use NextUp\Support\Url;
use function NextUp\Support\e;

$requestUrl = $tenant['public_request_url'] ?: ('http://' . ($_SERVER['HTTP_HOST'] ?? '') . Url::path('/'));
$screenId = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['screen'] ?? '')) ?: 'main';
$requestQr = QrCode::svg($requestUrl, 320);
?>
<section class="display-shell" data-screen="<?= e($screenId) ?>">
  <div class="display-brand">
    <strong><?= e($tenant['venue_name']) ?></strong>
    <span><?= e($tenant['night_name']) ?> · <span data-screen-label><?= e($screenId) ?></span></span>
  </div>

  <!-- Player layer: visible only when mode === 'now_singing'. -->
  <div class="display-player" data-display-player hidden>
    <div data-display-yt class="display-player-frame" hidden></div>
    <video data-display-video class="display-player-frame" playsinline muted hidden></video>
    <div data-display-player-empty class="display-player-empty" hidden>
      <h2 data-display-player-title></h2>
      <p>This song doesn't have an embedded video. Cue the source on the operator console.</p>
    </div>
    <div class="display-lower-third" data-display-lower-third hidden>
      <strong data-display-lt-singer></strong>
      <span data-display-lt-song></span>
    </div>
  </div>

  <!-- Grid layer: visible for idle / queue / clean_stage / announcement modes. -->
  <div class="display-grid" data-display-grid>
    <div class="display-now" data-display-now>
      <span>Ready for requests</span>
    </div>
    <div>
      <h2>Up Next</h2>
      <div data-up-next></div>
    </div>
    <div>
      <h2>Queue</h2>
      <div data-display-queue></div>
    </div>
    <div class="qr">
      <h2>Request Songs</h2>
      <div data-qr><?= $requestQr ?></div>
      <p><?= e($requestUrl) ?></p>
    </div>
  </div>

  <div class="display-announcement" data-display-announcement hidden></div>
</section>

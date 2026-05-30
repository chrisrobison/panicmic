<?php
use PanicMic\Support\QrCode;
use PanicMic\Support\Url;
use function PanicMic\Support\e;

$current = 'promote';

$host = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$singerUrl = $tenant['public_request_url'] ?: ($scheme . '://' . $host . Url::path('/'));
$singerQr = QrCode::svg($singerUrl, 640);
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header no-print">
      <div>
        <h1>Promote this venue</h1>
        <p class="muted">Print a poster, tape it to a table tent, or show this screen on a tablet near the bar. The QR code below points singers straight at <strong><?= e($singerUrl) ?></strong>.</p>
      </div>
      <div class="song-card-actions">
        <button type="button" onclick="window.print()" class="primary">Print poster</button>
        <a class="button-like" href="<?= e($singerUrl) ?>" target="_blank" rel="noreferrer">Open singer page</a>
      </div>
    </header>

    <article class="poster">
      <header class="poster-header">
        <h1 class="poster-venue"><?= e($tenant['venue_name']) ?></h1>
        <p class="poster-night"><?= e($tenant['night_name']) ?></p>
      </header>
      <div class="poster-qr"><?= $singerQr ?></div>
      <div class="poster-cta">
        <h2>Scan to request a song</h2>
        <p class="poster-url"><?= e($singerUrl) ?></p>
        <ol class="poster-steps">
          <li>Point your phone camera at the code.</li>
          <li>Tap the link that pops up.</li>
          <li>Type your name, pick a song, hit <strong>Add me to the queue</strong>.</li>
        </ol>
      </div>
    </article>
  </section>
</section>

<?php
use NextUp\Support\Url;
use function NextUp\Support\e;

$requestUrl = $tenant['public_request_url'] ?: ('http://' . ($_SERVER['HTTP_HOST'] ?? '') . Url::path('/'));
?>
<section class="display-shell">
  <div class="display-brand">
    <strong><?= e($tenant['venue_name']) ?></strong>
    <span><?= e($tenant['night_name']) ?></span>
  </div>
  <div class="display-now" data-display-now>
    <span>Ready for requests</span>
  </div>
  <div class="display-grid">
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
      <div data-qr></div>
      <p><?= e($requestUrl) ?></p>
    </div>
  </div>
  <div class="display-announcement" data-display-announcement hidden></div>
</section>

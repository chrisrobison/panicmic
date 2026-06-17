<?php

declare(strict_types=1);

use function PanicMic\Support\e;
use PanicMic\Auth\Auth;
use PanicMic\Support\Url;

$title = $tenant['venue_name'] . ' - ' . $tenant['night_name'];
$bodyClass = str_replace('-', ' ', $page);
$logoUrl = !empty($tenant['logo_url']) ? (str_starts_with($tenant['logo_url'], '/files/') ? Url::path($tenant['logo_url']) : $tenant['logo_url']) : null;
$profileImageUrl = !empty($tenant['profile_image_url']) ? (str_starts_with($tenant['profile_image_url'], '/files/') ? Url::path($tenant['profile_image_url']) : $tenant['profile_image_url']) : null;
$backgroundImageUrl = !empty($tenant['background_image_url']) ? (str_starts_with($tenant['background_image_url'], '/files/') ? Url::path($tenant['background_image_url']) : $tenant['background_image_url']) : null;
$actingAsSuper = Auth::actingAsSuper();
$isAdminPage = str_starts_with((string)$page, 'admin-') || $page === 'display';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <meta name="app-base-path" content="<?= e($basePath) ?>">
  <meta name="app-page" content="<?= e($page) ?>">
  <meta name="app-tenant-slug" content="<?= e($tenant['slug'] ?? '') ?>">
  <meta name="app-session-id" content="<?= e((string)($session['id'] ?? '')) ?>">
  <meta name="app-ws-enabled" content="<?= e(strtolower((string)(\PanicMic\Support\Env::get('WEBSOCKET_ENABLED', 'true') ?? 'true')) === 'true' ? '1' : '0') ?>">
  <meta name="app-ws-path" content="<?= e((string)(\PanicMic\Support\Env::get('WEBSOCKET_PUBLIC_PATH', '/ws') ?? '/ws')) ?>">
  <link rel="icon" type="image/png" href="<?= e(Url::path('/favicon-96x96.png')) ?>" sizes="96x96">
  <link rel="icon" type="image/svg+xml" href="<?= e(Url::path('/favicon.svg')) ?>">
  <link rel="shortcut icon" href="<?= e(Url::path('/favicon.ico')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(Url::path('/apple-touch-icon.png')) ?>">
  <meta name="apple-mobile-web-app-title" content="PanicMic">
  <link rel="manifest" href="<?= e(Url::path('/site.webmanifest')) ?>">
  <link rel="stylesheet" href="<?= e(Url::path('/assets/app.css')) ?>">
  <style nonce="<?= e(\PanicMic\Support\Security::styleNonce()) ?>">
    :root {
      --primary: <?= e($tenant['primary_color'] ?? '#22c55e') ?>;
      --accent: <?= e($tenant['accent_color'] ?? '#facc15') ?>;
      --bg: <?= e($tenant['background_color'] ?? '#101216') ?>;
      --surface: <?= e($tenant['surface_color'] ?? '#191d24') ?>;
      --text: <?= e($tenant['text_color'] ?? '#f5f7fb') ?>;
      --tenant-bg-image: <?= $backgroundImageUrl ? 'url("' . e($backgroundImageUrl) . '")' : 'none' ?>;
    }
  </style>
</head>
<body class="<?= e($page) ?><?= $actingAsSuper ? ' acting-as-super' : '' ?>">
  <header class="topbar">
    <a class="brand" href="<?= e(Url::path('/')) ?>">
      <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php endif; ?>
      <span><strong><?= e($tenant['venue_name']) ?></strong><small><?= e($tenant['night_name']) ?></small></span>
    </a>
    <button class="nav-toggle" type="button" data-nav-toggle aria-label="Menu" aria-controls="primary-nav" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
    <nav id="primary-nav" data-nav>
      <a href="<?= e(Url::path('/')) ?>">Request</a>
      <a href="<?= e(Url::path('/songs')) ?>">Catalog</a>
      <a href="<?= e(Url::path('/events')) ?>">Events</a>
      <a href="<?= e(Url::path('/me')) ?>">My Spot</a>
      <a href="<?= e(Url::path('/admin/dashboard')) ?>">KJ</a>
      <a href="<?= e(Url::path('/display')) ?>">Display</a>
      <a href="<?= e(Url::path($isAdminPage ? '/admin/help' : '/help')) ?>" data-help-modal>Help</a>
      <?php if ($actingAsSuper || !empty($_SESSION['tenant_user'])): ?>
        <a href="<?= e(Url::path('/admin/logout')) ?>" class="nav-logout">Logout</a>
      <?php endif; ?>
    </nav>
    <?php if ($profileImageUrl): ?><img class="profile-pic" src="<?= e($profileImageUrl) ?>" alt=""><?php endif; ?>
  </header>
  <main data-page="<?= e($page) ?>">
    <?php require __DIR__ . "/pages/{$page}.php"; ?>
  </main>
  <script src="<?= e(Url::path('/assets/vendor/geopattern.min.js')) ?>"></script>
<?php if ($page === 'admin-songs'): ?>
  <!-- album-art: Spotify-backed cover lookup used by the "Fetch Art" button in the song editor -->
  <script src="https://cdn.jsdelivr.net/npm/album-art/index.min.js"></script>
<?php endif; ?>
  <script type="module" src="<?= e(Url::path('/assets/main.js')) ?>"></script>
</body>
</html>

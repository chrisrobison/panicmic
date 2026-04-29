<?php

declare(strict_types=1);

use function NextUp\Support\e;

$title = $tenant['venue_name'] . ' - ' . $tenant['night_name'];
$bodyClass = str_replace('-', ' ', $page);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    :root { --primary: <?= e($tenant['primary_color'] ?? '#22c55e') ?>; --accent: <?= e($tenant['accent_color'] ?? '#facc15') ?>; }
  </style>
</head>
<body class="<?= e($page) ?>">
  <header class="topbar">
    <a class="brand" href="/">
      <?php if (!empty($tenant['logo_url'])): ?><img src="<?= e($tenant['logo_url']) ?>" alt=""><?php endif; ?>
      <span><strong><?= e($tenant['venue_name']) ?></strong><small><?= e($tenant['night_name']) ?></small></span>
    </a>
    <nav>
      <a href="/songs">Songs</a>
      <a href="/me">My Spot</a>
      <a href="/admin/dashboard">KJ</a>
      <a href="/display">Display</a>
    </nav>
  </header>
  <main data-page="<?= e($page) ?>">
    <?php require __DIR__ . "/pages/{$page}.php"; ?>
  </main>
  <script>
    window.NEXTUP = {
      csrf: <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>,
      page: <?= json_encode($page, JSON_THROW_ON_ERROR) ?>
    };
  </script>
  <script src="/assets/app.js"></script>
</body>
</html>

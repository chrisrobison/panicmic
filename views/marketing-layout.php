<?php

declare(strict_types=1);

use function PanicMic\Support\e;
use PanicMic\Support\Url;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($meta['title']) ?></title>
  <meta name="description" content="<?= e($meta['description']) ?>">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="PanicMic">
  <meta property="og:title" content="<?= e($meta['title']) ?>">
  <meta property="og:description" content="<?= e($meta['description']) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="theme-color" content="#101216">
  <link rel="icon" type="image/svg+xml" href="<?= e(Url::path('/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(Url::path('/assets/app.css')) ?>">
</head>
<body class="marketing">
  <header class="marketing-nav">
    <a class="marketing-logo" href="/">Panic<span>Mic</span></a>
    <nav aria-label="Main navigation">
      <a href="/#features">Features</a>
      <a href="/#workflow">How it works</a>
      <a href="<?= e($signupUrl) ?>" class="button-like primary">Start free trial</a>
    </nav>
  </header>
  <main>
    <?php require __DIR__ . '/pages/marketing-' . $page . '.php'; ?>
  </main>
  <footer class="marketing-footer">
    <span>© <?= date('Y') ?> PanicMic</span>
    <nav aria-label="Legal">
      <a href="/privacy">Privacy</a>
      <a href="/terms">Terms</a>
      <a href="mailto:support@panicmic.com">Support</a>
    </nav>
  </footer>
</body>
</html>

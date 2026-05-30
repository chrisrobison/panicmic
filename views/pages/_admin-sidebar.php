<?php
use function PanicMic\Support\e;
use PanicMic\Support\Url;
use PanicMic\Auth\Auth;
$current = $current ?? '';
$links = [
  ['key' => 'dashboard', 'href' => '/admin/dashboard', 'label' => 'Dashboard'],
  ['key' => 'songs',     'href' => '/admin/songs',     'label' => 'Song catalog'],
  ['key' => 'content',   'href' => '/admin/content',   'label' => 'Content'],
  ['key' => 'settings',  'href' => '/admin/settings',  'label' => 'Settings'],
  ['key' => 'display',   'href' => '/display/control', 'label' => 'Display control'],
  ['key' => 'promote',   'href' => '/admin/promote',   'label' => 'Promote'],
  ['key' => 'help',      'href' => '/admin/help',      'label' => 'Help',          'modal' => true],
];
?>
<aside class="admin-sidebar">
  <div class="admin-sidebar-heading">KJ console</div>
  <?php foreach ($links as $link): ?>
    <a href="<?= e(Url::path($link['href'])) ?>" <?= $current === $link['key'] ? 'aria-current="page"' : '' ?><?= !empty($link['modal']) ? ' data-help-modal' : '' ?>><?= e($link['label']) ?></a>
  <?php endforeach; ?>
  <?php if (Auth::actingAsSuper()): ?>
    <div class="admin-sidebar-divider"></div>
    <a href="<?= e(Url::path('/super/tenants')) ?>" class="muted">↩ Back to super</a>
  <?php endif; ?>
</aside>

<?php
use function PanicMic\Support\e;
use PanicMic\Support\Url;
use PanicMic\Auth\Auth;
$current = $current ?? '';
// "Display control" used to be its own nav entry pointing at
// /display/control, but that route renders the dashboard — the same page
// as "Dashboard", which stayed highlighted after you clicked it. Two nav
// items, one destination, and no indication you had arrived. It now
// deep-links to the Connected Displays panel on the console it always
// rendered, so the label matches what actually happens.
$links = [
  ['key' => 'dashboard', 'href' => '/admin/dashboard',  'label' => 'Dashboard'],
  ['key' => 'display',   'href' => '/admin/dashboard#displays', 'label' => 'Displays'],
  ['key' => 'venues',    'href' => '/admin/venues',     'label' => 'Venues'],
  ['key' => 'schedule',  'href' => '/admin/schedule',   'label' => 'Schedule'],
  ['key' => 'songs',     'href' => '/admin/songs',      'label' => 'Song catalog'],
  ['key' => 'content',   'href' => '/admin/content',    'label' => 'Content'],
  ['key' => 'team',      'href' => '/admin/team',       'label' => 'Team'],
  ['key' => 'settings',  'href' => '/admin/settings',   'label' => 'Settings'],
  ['key' => 'promote',   'href' => '/admin/promote',    'label' => 'Promote'],
  ['key' => 'help',      'href' => '/admin/help',       'label' => 'Help',         'modal' => true],
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

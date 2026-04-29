<?php use function NextUp\Support\e; ?>
<section class="admin-layout">
  <aside>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/dashboard')) ?>">Queue</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/content')) ?>">Content</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/settings')) ?>">Settings</a>
  </aside>
  <section class="operator">
    <h1>Tenant Settings</h1>
    <p>Branding and operational settings are tenant-scoped. Extend this page to write settings through the same tenant database context.</p>
  </section>
</section>

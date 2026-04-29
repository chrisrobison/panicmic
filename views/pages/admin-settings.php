<?php use function NextUp\Support\e; ?>
<section class="admin-layout">
  <aside>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/dashboard')) ?>">Queue</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/content')) ?>">Content</a>
    <a href="<?= e(\NextUp\Support\Url::path('/admin/settings')) ?>">Settings</a>
  </aside>
  <section class="operator">
    <form class="panel branding-form" data-branding-form>
      <h1>Look and Feel</h1>
      <label>Venue name<input name="venue_name" required></label>
      <label>Karaoke night name<input name="night_name" required></label>
      <label>Logo URL<input name="logo_url" placeholder="/files/logo.png or https://..."></label>
      <label>Profile image URL<input name="profile_image_url" placeholder="/files/kj-profile.jpg or https://..."></label>
      <label>Background image URL<input name="background_image_url" placeholder="/files/background.jpg or https://..."></label>
      <div class="color-grid">
        <label>Background color<input name="background_color" type="color"></label>
        <label>Panel color<input name="surface_color" type="color"></label>
        <label>Text color<input name="text_color" type="color"></label>
        <label>Primary highlight<input name="primary_color" type="color"></label>
        <label>Accent highlight<input name="accent_color" type="color"></label>
      </div>
      <button class="primary">Save Branding</button>
      <p data-status></p>
    </form>
  </section>
</section>

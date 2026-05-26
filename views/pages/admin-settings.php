<?php
use function NextUp\Support\e;
use NextUp\Support\Url;
$current = 'settings';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator settings-grid">
    <header class="admin-page-header">
      <div>
        <h1>Settings</h1>
        <p class="muted">Tune how requests, search, and YouTube matching behave for this tenant.</p>
      </div>
    </header>

    <form class="panel settings-form" data-settings-form>
      <h2>Request behavior</h2>
      <div class="setting-row">
        <label class="toggle">
          <input type="checkbox" name="prevent_duplicate_requests">
          <span>Prevent duplicate requests from the same browser session</span>
        </label>
      </div>
      <div class="setting-row">
        <label>Default party type
          <select name="default_party_type">
            <option value="solo">Solo</option>
            <option value="duet">Duet</option>
            <option value="group">Group</option>
          </select>
        </label>
      </div>
      <div class="setting-row">
        <label>Max active requests per singer
          <input type="number" name="max_requests_per_singer" min="0" step="1">
        </label>
      </div>
      <div class="setting-row">
        <label class="toggle">
          <input type="checkbox" name="show_explicit_songs">
          <span>Show explicit songs in public search</span>
        </label>
      </div>

      <h2>Song sources</h2>
      <div class="setting-row">
        <label>Search source
          <select name="song_source">
            <option value="catalog">Shared + tenant catalogs only</option>
            <option value="catalog+youtube">Also blend in live YouTube results</option>
          </select>
        </label>
        <p class="muted">Singers always see the shared catalog plus your tenant additions. With YouTube enabled, the search form also offers a live YouTube match.</p>
      </div>
      <div class="setting-row">
        <label class="toggle">
          <input type="checkbox" name="auto_attach_youtube">
          <span>Automatically attach a YouTube karaoke video when a singer requests a song</span>
        </label>
        <p class="muted" data-youtube-status></p>
      </div>

      <div class="song-card-actions">
        <button class="primary">Save settings</button>
        <span data-status></span>
      </div>
    </form>

    <form class="panel branding-form" data-branding-form>
      <h2>Branding</h2>
      <label>Venue name<input name="venue_name" required></label>
      <label>Karaoke night name<input name="night_name" required></label>
      <label>Logo URL<input name="logo_url" placeholder="/files/logo.png or https://..."></label>
      <label>Profile image URL<input name="profile_image_url" placeholder="/files/kj-profile.jpg or https://..."></label>
      <label>Background image URL<input name="background_image_url" placeholder="/files/background.jpg or https://..."></label>
      <div class="color-grid">
        <label>Background<input name="background_color" type="color"></label>
        <label>Panel<input name="surface_color" type="color"></label>
        <label>Text<input name="text_color" type="color"></label>
        <label>Primary<input name="primary_color" type="color"></label>
        <label>Accent<input name="accent_color" type="color"></label>
      </div>
      <div class="song-card-actions">
        <button class="primary">Save branding</button>
        <span data-status></span>
      </div>
    </form>
  </section>
</section>

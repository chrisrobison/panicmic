<?php
use function PanicMic\Support\e;
$current = 'venues';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header">
      <div>
        <h1>Venues</h1>
        <p class="muted">The places you host at. <span data-venue-usage></span></p>
      </div>
    </header>

    <form class="panel venue-form" data-venue-form>
      <h2>Add a venue</h2>
      <input type="hidden" name="id">
      <label>Name<input name="name" maxlength="160" required></label>
      <label>Default night name<input name="default_night_name" maxlength="180" placeholder="e.g. Thursday Karaoke"></label>
      <div class="field-grid">
        <label>Address<input name="address_line1" maxlength="255"></label>
        <label>Suite / unit<input name="address_line2" maxlength="255"></label>
        <label>City<input name="city" maxlength="120"></label>
        <label>State / region<input name="region" maxlength="120"></label>
        <label>Postal code<input name="postal_code" maxlength="40"></label>
        <label>Country<input name="country" maxlength="80"></label>
      </div>
      <label>Notes<textarea name="notes" maxlength="1000"></textarea></label>
      <div class="song-card-actions">
        <button class="primary" data-venue-submit>Add venue</button>
        <button type="button" class="button-like" data-venue-reset hidden>Cancel edit</button>
        <span data-status></span>
      </div>
    </form>

    <div class="venue-list" data-venue-list></div>
  </section>
</section>

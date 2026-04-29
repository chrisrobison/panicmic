<?php use function NextUp\Support\e; ?>
<section class="hero">
  <div>
    <p class="eyebrow">Tonight's karaoke queue</p>
    <h1><?= e($tenant['night_name']) ?></h1>
    <p>Find your song, add your name, and watch your place update live.</p>
  </div>
  <form class="panel request-form" data-request-form>
    <h2>Request a Song</h2>
    <label>Display name<input name="display_name" maxlength="160" required></label>
    <label>Search catalog<input name="song_search" placeholder="Artist or title" autocomplete="off"></label>
    <div class="song-results" data-song-results></div>
    <input type="hidden" name="song_id" required>
    <label>Party type
      <select name="party_type"><option>solo</option><option>duet</option><option>group</option></select>
    </label>
    <label>Notes for KJ<textarea name="notes" maxlength="500"></textarea></label>
    <button class="primary" type="submit">Submit Request</button>
    <p class="form-status" data-status></p>
  </form>
</section>
<section class="queue-strip">
  <h2>Queue</h2>
  <div data-public-queue class="queue-list"></div>
</section>

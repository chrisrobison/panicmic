<?php use function PanicMic\Support\e; ?>
<?php $sessionStatus = (string)($session['status'] ?? 'live'); ?>
<?php $isClosed = $sessionStatus === 'closed' || $sessionStatus === 'archived'; ?>
<section class="hero">
  <?php if ($isClosed): ?>
    <div class="panel closed-banner" data-session-closed>
      <h2>We're closed for tonight</h2>
      <p>Requests reopen when the host starts the next session. Thanks for singing with us!</p>
    </div>
  <?php else: ?>
    <form class="panel request-form" data-request-form>
      <h2>Request a Song</h2>
      <label>Display name<input name="display_name" maxlength="160" required></label>
      <label>Search catalog<input name="song_search" placeholder="Artist or title" autocomplete="off"></label>
      <div class="song-results" data-song-results></div>
      <input type="hidden" name="song_id">
      <input type="hidden" name="shared_song_id">
      <label>Party type
        <select name="party_type"><option>solo</option><option>duet</option><option>group</option></select>
      </label>
      <label>Notes for KJ<textarea name="notes" maxlength="500"></textarea></label>
      <button class="primary" type="submit">Submit Request</button>
      <p class="form-status" data-status></p>
    </form>
  <?php endif; ?>
</section>
<?php if (!$isClosed): ?>
<section class="queue-strip">
  <h2>Queue</h2>
  <div data-public-queue class="queue-list"></div>
</section>
<?php endif; ?>

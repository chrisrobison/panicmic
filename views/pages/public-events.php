<?php
use function PanicMic\Support\e;
?>
<section class="hero events-hero">
  <h1>Events at <?= e($tenant['venue_name']) ?></h1>
  <p class="muted">Upcoming karaoke nights and what's been sung.</p>
</section>

<section class="events-section">
  <h2>Upcoming nights</h2>
  <div class="events-grid" data-upcoming-events>
    <p class="muted">Loading…</p>
  </div>
</section>

<section class="events-section">
  <h2>Past nights</h2>
  <div class="events-grid" data-past-events>
    <p class="muted">Loading…</p>
  </div>
</section>

<div class="setlist-modal" data-setlist-modal hidden>
  <div class="setlist-modal-card">
    <button type="button" class="setlist-close" data-setlist-close aria-label="Close">×</button>
    <div data-setlist-body></div>
  </div>
</div>

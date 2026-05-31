<?php
use function PanicMic\Support\e;
$current = 'schedule';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator schedule-grid">
    <header class="admin-page-header">
      <div>
        <h1>Schedule</h1>
        <p class="muted">Recurring shows and one-off events across your venues.</p>
      </div>
    </header>

    <form class="panel schedule-form" data-schedule-form>
      <h2>Recurring show</h2>
      <div class="field-grid">
        <label>Venue
          <select name="venue_id" data-venue-select required></select>
        </label>
        <label>Night name<input name="name" maxlength="180" placeholder="e.g. Thursday Karaoke" required></label>
        <label>Repeats
          <select name="recurrence_type" data-recurrence>
            <option value="weekly">Weekly</option>
            <option value="biweekly">Every other week</option>
            <option value="monthly">Monthly</option>
          </select>
        </label>
        <label>Day of week
          <select name="weekday">
            <option value="0">Sunday</option>
            <option value="1">Monday</option>
            <option value="2">Tuesday</option>
            <option value="3">Wednesday</option>
            <option value="4" selected>Thursday</option>
            <option value="5">Friday</option>
            <option value="6">Saturday</option>
          </select>
        </label>
        <label data-week-of-month hidden>Which week
          <select name="week_of_month">
            <option value="1">First</option>
            <option value="2">Second</option>
            <option value="3">Third</option>
            <option value="4">Fourth</option>
            <option value="-1">Last</option>
          </select>
        </label>
        <label>Start time<input name="start_time" type="time" value="20:00" required></label>
        <label>Starts on<input name="starts_on" type="date" required></label>
        <label>Ends on <small>(optional)</small><input name="ends_on" type="date"></label>
      </div>
      <div class="song-card-actions">
        <button class="primary">Add recurring show</button>
        <span data-status></span>
      </div>
    </form>

    <form class="panel oneoff-form" data-oneoff-form>
      <h2>One-off event</h2>
      <div class="field-grid">
        <label>Venue
          <select name="venue_id" data-venue-select required></select>
        </label>
        <label>Name<input name="name" maxlength="180" required></label>
        <label>When<input name="scheduled_for" type="datetime-local" required></label>
      </div>
      <div class="song-card-actions">
        <button class="primary">Add event</button>
        <span data-status></span>
      </div>
    </form>

    <section class="panel">
      <h2>Recurring shows</h2>
      <div data-schedule-list></div>
    </section>

    <section class="panel">
      <h2>Upcoming events</h2>
      <div data-event-list></div>
    </section>
  </section>
</section>

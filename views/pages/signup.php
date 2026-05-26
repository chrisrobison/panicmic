<?php
use function NextUp\Support\e;
?>
<section class="signup-shell">
  <div class="signup-card">
    <h1>Host karaoke nights with NextUp</h1>
    <p class="muted">Self-serve signup. Pick a subdomain, set up your venue, then activate your KJ login from your email.</p>
    <form data-signup-form>
      <label>Venue name<input name="venue_name" required placeholder="Bluebird Lounge"></label>
      <label>Karaoke night name<input name="night_name" placeholder="Tuesday Night Karaoke"></label>
      <label>Your KJ email<input name="email" type="email" required></label>
      <label>Subdomain
        <div class="subdomain-row">
          <input name="subdomain" required pattern="[a-z][a-z0-9-]{1,40}[a-z0-9]" placeholder="bluebird">
          <span class="muted" data-subdomain-hint>.panicmic.com</span>
        </div>
        <small class="muted">3–42 lowercase letters, digits, or hyphens.</small>
      </label>
      <button class="primary">Create my venue</button>
      <p class="signup-status muted" data-signup-status></p>
    </form>
  </div>
</section>

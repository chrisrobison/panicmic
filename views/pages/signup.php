<?php
use function PanicMic\Support\e;
use PanicMic\Support\Env;
$rootDomain = (string)(Env::get('SIGNUP_ROOT_DOMAIN', 'panicmic.com') ?? 'panicmic.com');
?>
<section class="signup-shell">
  <div class="signup-card">
    <h1>Create your KJ command center</h1>
    <p class="muted">Your PanicMic account belongs to you, the KJ. Pick one branded subdomain, then use the same catalog and console at every venue you host.</p>
    <form data-signup-form>
      <label>KJ business or brand name<input name="venue_name" required placeholder="Casey Karaoke"></label>
      <label>Default show name<input name="night_name" placeholder="Casey's Karaoke Night"></label>
      <label>Your KJ email<input name="email" type="email" required></label>
      <label>Subdomain
        <div class="subdomain-row">
          <input name="subdomain" required pattern="[a-z][a-z0-9-]{1,40}[a-z0-9]" placeholder="caseykaraoke">
          <span class="muted" data-subdomain-hint>.<?= e($rootDomain) ?></span>
        </div>
        <small class="muted">3–42 lowercase letters, digits, or hyphens. This is your KJ account URL and follows you between venues.</small>
      </label>
      <button class="primary">Create my KJ account</button>
      <p class="signup-status muted" data-signup-status></p>
    </form>
  </div>
</section>

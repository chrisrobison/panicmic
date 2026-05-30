<?php
use function PanicMic\Support\e;
?>
<section class="signup-shell">
  <div class="signup-card">
    <h1>Activate your account</h1>
    <p class="muted">Set your KJ password to finish signing up.</p>
    <form data-signup-accept>
      <input type="hidden" name="token" data-token-from-query>
      <label>Your name<input name="display_name" required></label>
      <label>Password<input name="password" type="password" required minlength="10"></label>
      <button class="primary">Activate &amp; sign in</button>
      <p class="signup-status muted" data-signup-status></p>
    </form>
  </div>
</section>

<?php
use PanicMic\Support\Url;
?>
<section class="login">
  <form class="panel" data-password-reset-confirm>
    <h1>Choose a new password</h1>
    <input name="token" type="hidden">
    <label>New password
      <input name="password" type="password" autocomplete="new-password" minlength="12" required>
    </label>
    <label>Confirm password
      <input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
    </label>
    <p class="muted">Use at least 12 characters.</p>
    <button class="primary" type="submit">Save password</button>
    <p role="status" data-status></p>
    <p><a href="<?= \PanicMic\Support\e(Url::path('/admin/login')) ?>">Back to sign in</a></p>
  </form>
</section>

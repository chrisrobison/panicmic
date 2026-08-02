<?php
use PanicMic\Support\Url;
?>
<section class="login">
  <form class="panel" data-password-reset-request>
    <h1>Reset your password</h1>
    <p class="muted">Enter the email used for your KJ or administrator account.</p>
    <label>Email<input name="email" type="email" autocomplete="email" required></label>
    <button class="primary" type="submit">Send reset link</button>
    <p role="status" data-status></p>
    <p><a href="<?= \PanicMic\Support\e(Url::path('/admin/login')) ?>">Back to sign in</a></p>
  </form>
</section>

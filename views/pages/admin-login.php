<section class="login">
  <form class="panel" data-login-form>
    <h1>KJ Login</h1>
    <label>Email<input name="email" type="email" required></label>
    <label>Password<input name="password" type="password" required></label>
    <button class="primary" type="submit">Sign In</button>
    <p role="status" data-status></p>
    <p><a href="<?= \PanicMic\Support\e(\PanicMic\Support\Url::path('/admin/forgot-password')) ?>">Forgot password?</a></p>
  </form>
</section>

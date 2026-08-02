<?php
$current = 'team';
?>
<section class="admin-layout">
  <?php include __DIR__ . '/_admin-sidebar.php'; ?>
  <section class="operator">
    <header class="admin-page-header">
      <div>
        <h1>Team</h1>
        <p class="muted">Invite KJs, assign administrators, and deactivate access immediately.</p>
      </div>
    </header>

    <form class="panel team-invite-form" data-team-invite>
      <h2>Invite a team member</h2>
      <div class="form-grid">
        <label>Name<input name="display_name" maxlength="160" required></label>
        <label>Email<input name="email" type="email" maxlength="255" required></label>
        <label>Role
          <select name="role">
            <option value="kj">KJ</option>
            <option value="tenant_admin">Administrator</option>
          </select>
        </label>
      </div>
      <p class="muted">They will receive a one-time link to create their password.</p>
      <button class="primary" type="submit">Send invitation</button>
      <span role="status" data-status></span>
    </form>

    <section class="panel">
      <h2>Team members</h2>
      <div class="team-list" data-team-list><p class="muted">Loading…</p></div>
    </section>
  </section>
</section>

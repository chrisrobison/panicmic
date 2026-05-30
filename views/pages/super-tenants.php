<?php use function PanicMic\Support\e; use PanicMic\Support\Url; ?>
<section class="workspace super-workspace">
  <nav class="super-nav">
    <a class="active" href="<?= e(Url::path('/super/tenants')) ?>">Tenants</a>
    <a href="<?= e(Url::path('/super/catalog')) ?>">Shared catalog</a>
    <button data-super-logout class="muted-link" type="button">Sign out</button>
  </nav>

  <header class="super-header">
    <div>
      <h1>Tenants</h1>
      <p class="muted">Click a row to edit. Use the icons to jump into a tenant's site or KJ console.</p>
    </div>
  </header>

  <div class="tenant-table-wrap">
    <table class="tenant-table" data-tenants>
      <thead>
        <tr>
          <th>Venue</th>
          <th>Slug</th>
          <th>Primary domain</th>
          <th>Database</th>
          <th>Status</th>
          <th class="row-actions"><span class="sr-only">Open</span></th>
        </tr>
      </thead>
      <tbody data-tenant-rows>
        <tr><td colspan="6" class="muted">Loading tenants…</td></tr>
      </tbody>
    </table>
  </div>

  <details class="panel tenant-create-panel">
    <summary>+ New tenant</summary>
    <form class="tenant-create" data-tenant-create>
      <label>Slug<input name="slug" required></label>
      <label>Venue name<input name="venue_name" required></label>
      <label>Night name<input name="night_name" required></label>
      <label>Database<input name="database_name" placeholder="panicmic_example" required></label>
      <label>Timezone<input name="timezone" value="America/Los_Angeles"></label>
      <label>Signup mode<select name="signup_mode"><option>both</option><option>display_name</option><option>account</option></select></label>
      <button class="primary">Create tenant</button>
    </form>
  </details>
</section>

<aside class="drawer" data-tenant-editor hidden aria-labelledby="tenant-editor-title">
  <div class="drawer-backdrop" data-editor-close></div>
  <div class="drawer-panel" role="dialog" aria-modal="true">
    <header class="drawer-header">
      <h2 id="tenant-editor-title" data-editor-title>Edit tenant</h2>
      <button type="button" class="icon-btn" data-editor-close aria-label="Close editor">&times;</button>
    </header>

    <form class="drawer-body" data-tenant-edit-form>
      <label>Slug<input name="slug" required></label>
      <label>Venue name<input name="venue_name" required></label>
      <label>Night name<input name="night_name" required></label>
      <label>Database name<input name="database_name" readonly></label>
      <label>Timezone<input name="timezone"></label>
      <label>Signup mode
        <select name="signup_mode">
          <option value="both">both</option>
          <option value="display_name">display_name</option>
          <option value="account">account</option>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="active">active</option>
          <option value="suspended">suspended</option>
          <option value="provisioning">provisioning</option>
        </select>
      </label>
      <label>Public request URL<input name="public_request_url" type="url" placeholder="https://example.com/"></label>
      <label>Projection URL<input name="projection_url" type="url" placeholder="https://example.com/display"></label>

      <div class="drawer-actions">
        <button type="button" data-editor-handoff>Open KJ console</button>
        <button type="button" data-editor-provision>Re-provision</button>
        <button class="primary">Save changes</button>
      </div>
      <p class="form-status" data-status></p>
    </form>

    <section class="drawer-section">
      <h3>Domains</h3>
      <ul class="domain-list" data-editor-domains></ul>
      <form class="domain-add" data-domain-add>
        <input name="domain" placeholder="venue.example.com" required>
        <label class="inline"><input type="checkbox" name="is_primary"> Primary</label>
        <button>Add</button>
      </form>
    </section>
  </div>
</aside>

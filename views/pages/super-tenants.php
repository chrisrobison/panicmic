<section class="workspace">
  <h1>Tenants</h1>
  <form class="panel tenant-create" data-tenant-create>
    <label>Slug<input name="slug" required></label>
    <label>Venue name<input name="venue_name" required></label>
    <label>Night name<input name="night_name" required></label>
    <label>Database<input name="database_name" placeholder="nextup_example" required></label>
    <label>Timezone<input name="timezone" value="America/Los_Angeles"></label>
    <label>Signup mode<select name="signup_mode"><option>both</option><option>display_name</option><option>account</option></select></label>
    <button class="primary">Create Tenant</button>
  </form>
  <div data-tenants class="tenant-list"></div>
</section>

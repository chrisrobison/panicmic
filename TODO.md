# NextUp — TODO

Tracking the follow-ups identified in the project state review on 2026-05-23.
Items are grouped by category and roughly ordered by impact. Check off as completed.

## Recently completed (shared-catalog + KJ console pass)

- [x] **Shared song catalog.** `nextup_super.shared_songs` table, super-admin
      UI at `/super/catalog`, streamed CSV import, CSV export, and per-row
      delete. KJs see shared songs in their tenant catalog search but cannot
      modify them.
- [x] **Per-request source switching.** `song_requests` now accepts either a
      tenant `song_id` or a shared `shared_song_id`. Queue rendering merges
      both sources transparently.
- [x] **Super-admin impersonation.** HMAC-signed handoff token lets a
      super-admin open a tenant's KJ console in a new tab without a second
      login. Banner across the top while impersonating + Exit link.
- [x] **Settings page.** Real per-tenant settings UI for request behavior,
      song source, YouTube auto-attach, etc. Persisted in the `settings` table
      via `SettingsService::saveMany`.
- [x] **Dashboard stat tiles.** Live queue counts on `/admin/dashboard`.
- [x] **YouTube playlist import.** Paste a URL on `/admin/songs`; server walks
      the playlist via the YouTube Data API and writes tenant catalog rows.
- [x] **Tenant catalog export.** CSV export of the tenant's own additions.

## Security / abuse hardening

- [ ] **Rate-limit `/api/admin/login` and `/api/super/login`.** Currently only the
      public song-request endpoint is rate-limited. Add a `Security::rateLimit`
      bucket per remote IP + email, e.g. 5 attempts / 5 minutes, returning 429.
- [ ] **Decide whether `/api/songs` should require auth.** Today the entire
      catalog is anonymously scrapeable. Either accept this and document it, or
      gate the endpoint behind a session / requester token.
- [ ] **Re-check `users.is_active` on each authenticated request.** Right now a
      deactivated user keeps their session until expiry. Either re-query on
      each `Auth::requireTenantRole` call (with a small in-request cache) or
      invalidate sessions on deactivate.
- [ ] **Tighten CSP `style-src`.** Replace `'unsafe-inline'` with a per-request
      nonce or hash on the single inline `<style>` block emitted by
      `views/layout.php`.
- [ ] **Audit upload extension whitelist.** `ContentService::MIME_TYPES`
      controls the allowlist; add server-side magic-byte verification (e.g.
      `finfo_file`) so a renamed `.exe → .png` is caught.

## Operational hardening

- [ ] **Add a `schema_migrations` table** and migrate `scripts/migrate.php` to
      track applied files. Today the script re-runs every `*.sql` on every
      invocation, which only works because everything uses
      `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`. A real versioned runner
      is overdue before any destructive migration lands.
- [ ] **Prune `realtime_events`.** The table grows unbounded. Options: a
      `DELETE FROM realtime_events WHERE created_at < NOW() - INTERVAL 1 HOUR`
      sweep on each SSE poll, or a scheduled cron job. Add an index hint check
      after.
- [ ] **Cap SSE worker lifetime under PHP-FPM.** The current
      `sleep(1)` + 25s deadline holds a worker per client. Consider switching
      to a redis pub/sub model, or shorter polls with `Connection: close`
      after the first batch, depending on deployment target.
- [ ] **Add a basic PHPUnit suite.** Start with `QueueService`, `SongService`,
      and `TenantContext::resolve` — the three pieces most likely to silently
      regress as the front controller grows.
- [ ] **Add a CI workflow** (GitHub Actions) running `php -l` on all files
      plus the test suite once it exists.
- [ ] **Add `composer.json`** even if there are no runtime deps — locks the
      PHP version, declares the PSR-4 autoload, and gives CI/composer a single
      command (`composer test`).

## Data model fixes

- [ ] **Stop creating a fresh `singers` row per request.** `QueueService::submit`
      inserts a new singer for every submission. Either upsert by
      `(session_id, display_name)` or scope singers per `requester_token`.
- [ ] **Use the FULLTEXT index in `SongService::search`.** Replace
      `LIKE '%query%'` with `MATCH(title, artist) AGAINST (? IN BOOLEAN MODE)`
      when the query is non-empty; keep `LIKE` as a fallback for very short
      terms. Critical once the 75k-song catalog is imported.
- [ ] **Relax `songs.uniq_song_title_artist` UNIQUE** or change the import
      strategy. The constraint will reject duplicate `(title, artist)` pairs
      in `songs.csv`. Either include the `Id` or a content hash in the key,
      or use `INSERT IGNORE` and accept lossy import.

## Features the README claims but the code doesn't deliver

- [ ] **Build the CSV importer.** README lists "CSV import/export hooks" but
      no loader exists. Add `scripts/import-songs.php <tenant_database>
      <path/to/songs.csv>` (semicolon delimiter, quoted strings, `;`-separated
      `Styles`/`Languages`) and an admin-UI button that points to it.
- [ ] **Decide on `songs.json`.** Either wire it into a JSON importer for tools
      that prefer JSON, or remove the untracked file from the repo root.

## Code quality

- [ ] **Extract route handlers out of `public/index.php`.** It's at 448 lines;
      pull `SuperController`, `QueueController`, `SongController`,
      `ContentController` into `src/Http/`. Keep `index.php` as a pure router.
- [ ] **Consolidate helper functions.** `NextUp\Support\e()` lives at the
      bottom of `Response.php`; move all view helpers into a single
      `src/Support/helpers.php` that the autoloader requires.
- [ ] **Invalidate `Connection::$tenants` cache after provisioning.** Not yet
      a real bug, but a foreseeable one — provision a tenant and immediately
      hit it in the same request and you'll be using a pre-existing handle.

## Housekeeping

- [ ] **Track `index.html`** (root-level redirect to `public/`) if it's
      intentional, otherwise delete it.
- [ ] **Add `songs.csv` / `songs.json` to `.gitignore`** if they are
      developer-local data; otherwise commit them under `seeds/` and document.
- [ ] **README discrepancy pass.** After CSV import lands, re-read the
      "Features" section and remove any claim still not backed by code.

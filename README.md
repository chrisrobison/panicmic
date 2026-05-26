# NextUp Karaoke SaaS PHP

[![CI](https://github.com/chrisrobison/nextup/actions/workflows/ci.yml/badge.svg)](https://github.com/chrisrobison/nextup/actions/workflows/ci.yml)

Full-stack multi-tenant karaoke night management app for bars and KJs, implemented in PHP with PDO and MySQL/MariaDB. Tenants are selected by the incoming hostname, with a super-admin database for tenant lookup and isolated tenant schemas.

## Features

- Hostname-based tenant resolution and per-tenant PDO connections
- Tenant branding, settings, timezone, signup modes, public request URL, and projection URL
- Public singer song search, request submission, queue position, update, and cancel flows
- KJ dashboard with queue status controls, drag-and-drop reorder, manual requests, announcements, and display state controls
- Song catalog CRUD with CSV export, YouTube playlist import, and search filters
- Fullscreen projection UI with live SSE updates, QR code, queue, announcements, clean-stage, and idle modes
- Super-admin tenant creation, domain management, provisioning, migrations, and initial admin creation
- REST API plus Server-Sent Events for live queue, request, announcement, and display updates
- Base-path support for installs at `/`, `/nextup/public`, or another mounted path
- Tenant-scoped content uploads served through `/files/*` from `/content/<tenant-slug>`
- Optional YouTube karaoke video matching for song requests
- Security controls: secure sessions, CSRF token checks, public request rate limiting, PHP `password_hash`, parameterized SQL, tenant-domain validation, escaping in all pages

## Requirements

- PHP 8.2+ with PDO MySQL
- MySQL 8+ or MariaDB 10.6+

## Setup

```bash
cp .env.example .env
mysql -uroot -e "CREATE DATABASE nextup_super CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php scripts/migrate.php super
php scripts/seed.php
php -S 0.0.0.0:8000 -t public
```

For subdirectory installs, set `APP_BASE_PATH` in `.env` before opening the app.

The seed creates two local tenants:

- `bluebird.test:8000`
- `neon.test:8000`

Add these to `/etc/hosts`:

```text
127.0.0.1 bluebird.test
127.0.0.1 neon.test
127.0.0.1 nextup.test
```

Open:

- Public requests: `http://bluebird.test:8000/`
- KJ dashboard: `http://bluebird.test:8000/admin/dashboard`
- Projection: `http://bluebird.test:8000/display`
- Super admin: `http://nextup.test:8000/super/tenants`

Seeded logins:

- Tenant admin/KJ: `admin@bluebird.test` / `password123`
- Super admin: `super@nextup.test` / `password123`

## Local Multi-Hostname Development

Browsers include the port in the Host header. Tenant lookup normalizes hosts by stripping the port and checking the hostname against `tenant_domains.domain`.

Use separate local names in `/etc/hosts` to test isolation:

```text
127.0.0.1 bluebird.test
127.0.0.1 neon.test
```

Each hostname resolves to its own tenant record and database schema.

Avoid `.local` hostnames for local development on macOS. `.local` is reserved for Bonjour/mDNS and can add about 5 seconds of DNS delay before the browser connects. The seed still registers `.local` aliases for compatibility, but `.test` is the fast local default.

## Database Layout

`nextup_super` stores SaaS-wide records:

- `tenants`
- `tenant_domains`
- `super_admin_users`
- `provisioning_jobs`

Each tenant schema stores isolated operational data:

- `users`
- `singers`
- `songs`
- `song_artists`
- `karaoke_sessions`
- `song_requests`
- `queue_items`
- `announcements`
- `display_state`
- `audit_log`
- `settings`
- `payments_tips`

Run migrations:

```bash
php scripts/migrate.php super
php scripts/migrate.php tenant nextup_bluebird
```

## Developer Workflow

`make` is the entry point. Dev tools (PHPUnit, PHPStan) ship as PHARs
downloaded into `tools/` on demand — there is no `composer.json` and no
`vendor/` directory.

```bash
make tools     # one-time, downloads phpunit.phar + phpstan.phar
make lint      # php -l across src/, public/, scripts/
make stan      # static analysis at level 5
make test      # PHPUnit (requires a local MySQL for DB-backed tests)
make check     # all three; what CI runs
```

The migration runner tracks applied files in a `schema_migrations`
table per database (auto-created on first run). On its first run
against an existing dev or production database it bootstraps the ledger
by marking every migration on disk as applied without re-executing.

```bash
php scripts/migrate.php super
php scripts/migrate.php tenant nextup_bluebird
php scripts/migrate.php tenants                 # iterate all tenants
php scripts/migrate.php status tenants
php scripts/migrate.php super --dry-run
```

## Production Deployment Notes

Place the app behind Nginx, Apache, or Caddy with PHP-FPM. Forward the original host:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
```

Set:

```env
TRUST_PROXY=true
ALLOWED_HOSTS=example.com,bluebird.example.com,neon.example.com
SESSION_SECRET=<strong secret>
CSRF_SECRET=<strong secret>
```

Only domains present in `tenant_domains` are accepted for tenant traffic. For proxy deployments, keep `TRUST_PROXY=true` only when the app is reachable exclusively through the trusted proxy.

Point the web root at `public/`. All routes are handled by `public/index.php`.

### MySQL privileges

The runtime app does not need `CREATE` privileges. Provision tenant
databases ahead of time and grant the app user only the privileges it
needs against existing schemas:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON `nextup_super`.* TO 'nextup_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON `nextup_<tenant>`.* TO 'nextup_app'@'%';
```

Run migrations with a separate user that has `CREATE`/`ALTER`/`DROP`,
either via `scripts/migrate.php` from a deploy host or by handing the
migration SQL to a DBA. Tenant provisioning that creates new databases
should run as the migration user, not the runtime app user.

## Apache Under a Subdirectory

If your Apache vhost points at the project root and the app is reached at `/nextup/public`, set this in `.env`:

```env
APP_BASE_PATH=/nextup/public
```

The included `public/.htaccess` uses `mod_rewrite` to route clean URLs through `public/index.php`. Enable rewrite support in Apache, then allow `.htaccess` in the public directory:

```apache
LoadModule rewrite_module libexec/apache2/mod_rewrite.so

<Directory "/Users/cdr/Projects/nextup/public">
    AllowOverride All
    Require all granted
</Directory>
```

With that setup, use URLs such as:

- `http://bluebird.test/nextup/public/`
- `http://bluebird.test/nextup/public/admin/dashboard`
- `http://bluebird.test/nextup/public/files/example.mp4`

## Tenant Content

KJs can upload images, videos, audio, and PDFs from `Admin -> Content`. Files are stored under:

```text
/Users/cdr/Projects/nextup/content/<tenant-slug>/
```

The public route `/files/<filename>` maps to the current tenant's content folder after hostname tenant resolution, so `bluebird.test/files/logo.png` and `neon.test/files/logo.png` are isolated even if the filename is the same. Uploaded content is ignored by Git; only `content/.gitkeep` is tracked.

## YouTube Karaoke Matching

Set these in `.env` to attach YouTube karaoke videos to requests:

```env
YOUTUBE_API_KEY=<youtube-data-api-key>
YOUTUBE_AUTO_ATTACH=true
```

When enabled, new song requests automatically search YouTube for `<artist> <title> karaoke`, request embeddable videos ordered by view count, and attach the top result to the KJ queue item. KJs can also retry matching from the queue with `Find video`.

After pulling this feature into an existing tenant database, run:

```bash
php scripts/migrate.php tenant nextup_bluebird
php scripts/migrate.php tenant nextup_neon
```

## Catalog visibility

`/api/songs` and `/api/catalog` are intentionally public, returning the
tenant's song catalog blended with the shared catalog for any visitor
without requiring authentication. Karaoke songbooks are designed to be
read by anyone walking into the venue — singers need to browse before
they sign up, and singup_mode='display_name' means no account is
needed at all. Gating the catalog would break the core request flow on
phones.

The trade-off is that a competitor can scrape the list of titles and
artists. Songbook content is not proprietary in this domain, so the
exposure is acceptable. A future per-tenant `catalog_visibility`
setting could opt into a token gate; this is not implemented today.

## Multi-monitor displays

Each tenant session can drive one or more independent display windows.
`display_state` is keyed by `(session_id, screen)`, so the main
projector, a lyrics TV, and a lobby monitor can each show different
content at the same time.

Configure screens under **Admin → Settings → Multi-monitor displays**.
Each row adds a button to the operator dashboard that opens a new
window at `/display?screen=<id>`.

### Operator → display control

The KJ console talks to its own popped-out display windows via the
browser's native `BroadcastChannel`, not the network. Channel name is
`nextup:display:<tenant-slug>:<session-id>`. The server stays the
source of truth — every command also POSTs to `/api/display/state` —
so a reloaded display window recovers its state by fetching
`/api/display/state?screen=…` and re-rendering. Cross-device viewers
(singer phones, a projector running off a different PC) receive the
same `display:state_changed` event through SSE and fetch the same
endpoint. One model, two transports.

`BroadcastChannel` throttles message delivery to backgrounded tabs to
about 1 msg/sec after ~5 minutes hidden. Keep both windows visible
during local development and you won't see the throttling. In
production the operator window stays focused, so it's not an issue.

### Multi-monitor setup recipes

**One PC, multiple HDMI outputs (most common):**

* Open the KJ dashboard in your main browser window.
* For each physical display, click its "Open" button in the toolbar.
  Drag the new popup onto the right monitor, then press F11 for
  fullscreen. The window's URL survives reloads via its `?screen=`
  param so a dropped popup re-attaches by reopening it.
* On Chromium-based browsers, the toolbar will offer to place each
  popup automatically using the
  [Window Management API](https://developer.mozilla.org/en-US/docs/Web/API/Window_Management_API)
  after you grant the one-time "Open windows on other displays"
  permission.

**Same content on multiple TVs (cheapest):**

Run one display window on the KJ PC and split its HDMI output through
a powered splitter to every TV. Zero browser overhead, perfect frame
sync, no setup beyond cables.

**Dedicated projector PC:**

Set up a tenant-specific Chrome shortcut:

```
chrome --kiosk --window-position=0,0 \
       "https://bluebird.example.com/display?screen=main"
```

Add it to the projector PC's startup items so the display comes up
on boot.

## Curated Song Catalogs

Each tenant has its own isolated song catalog. KJs can manage `Admin -> Songs` and attach:

- a direct video-with-lyrics URL
- a provider name such as `youtube`, `karafun`, `stingray`, `singa`, `local`, or a custom value
- provider track IDs and provider URLs
- a separate lyrics URL

This keeps the public songbook tenant-specific while still allowing the KJ to launch the correct source from the queue or catalog.

KaraFun integration should be treated as a licensed provider integration. KaraFun’s public help documents CSV catalog downloads for catalog curation, and KaraFun Business documents API access through bearer tokens plus downloadable OpenAPI YAML from the Business dashboard. Configure credentials with:

```env
KARAFUN_API_TOKEN=
KARAFUN_API_BASE_URL=https://business.karafun.com/api
```

Other providers can follow the same pattern using `video_provider`, `provider_track_id`, and `provider_url`; Stingray placeholders are included in `.env.example`.

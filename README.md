# NextUp Karaoke SaaS PHP

Full-stack multi-tenant karaoke night management app for bars and KJs, implemented in PHP with PDO and MySQL/MariaDB. Tenants are selected by the incoming hostname, with a super-admin database for tenant lookup and isolated tenant schemas.

## Features

- Hostname-based tenant resolution and per-tenant PDO connections
- Tenant branding, settings, timezone, signup modes, public request URL, and projection URL
- Public singer song search, request submission, queue position, update, and cancel flows
- KJ dashboard with queue status controls, drag-and-drop reorder, manual requests, announcements, and display state controls
- Song catalog CRUD, CSV import/export hooks, and search filters
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

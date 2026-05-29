# NextUp Karaoke SaaS PHP

[![CI](https://github.com/chrisrobison/nextup/actions/workflows/ci.yml/badge.svg)](https://github.com/chrisrobison/nextup/actions/workflows/ci.yml)

Multi-tenant karaoke night management for **KJs** (karaoke jockeys),
implemented in PHP with PDO and MySQL/MariaDB. NextUp is sold per KJ, not
per bar: every KJ signs up for their own account, gets their own subdomain
(`yourname.panicmic.com`), and brings that one console to whatever room
they're hosting that night. Tenants are selected by the incoming hostname,
with a super-admin database for tenant lookup and an isolated schema per KJ.

A "tenant" in the data model is therefore a single KJ's show — their
catalog, their queue, their branding, their singers — independent of which
venue they happen to be working. The marketing site and self-serve signup
live on the apex (`panicmic.com`); KJ traffic resolves by subdomain.

## Features

- Hostname-based tenant resolution and per-tenant PDO connections — one
  isolated schema per KJ
- Self-serve signup: a KJ picks a subdomain, the tenant database is
  provisioned automatically, and an activation email sets their password
- Stripe-backed billing — Checkout, plan selection, and a signature-verified
  webhook; new accounts start on a 14-day trial
- KJ branding, settings, timezone, signup modes, public request URL, and
  projection URL
- Public singer song search, request submission, queue position, update, and
  cancel flows
- KJ dashboard with queue status controls, drag-and-drop reorder, manual
  requests, announcements, session start/end, and display state controls
- Song catalog CRUD with CSV export, YouTube playlist import, and FULLTEXT
  search
- Fullscreen projection UI with live SSE updates, an embedded video player,
  QR code, queue, announcements, clean-stage, and idle modes
- Multi-monitor support: independent display windows per screen, coordinated
  over `BroadcastChannel` with the server as source of truth
- Super-admin tenant creation, domain management, provisioning, migrations,
  billing controls, and impersonation handoff
- REST API plus Server-Sent Events for live queue, request, announcement, and
  display updates
- Base-path support for installs at `/`, `/nextup/public`, or another mounted
  path
- Tenant-scoped content uploads served through `/files/*` from
  `/content/<tenant-slug>`, with magic-byte upload verification
- Optional YouTube karaoke video matching, plus KJ-supplied manual video links
- Mobile-first public and dashboard layouts (hamburger nav, infinite-scroll
  catalog)
- Security controls: secure sessions, CSRF token checks, login and
  public-request rate limiting, PHP `password_hash`, parameterized SQL,
  per-request CSP nonce, tenant-domain validation, escaping in all pages

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

The seed creates two demo KJ accounts (named after the rooms they happen to
run, for realism):

- `bluebird.panicmic.com:8000`
- `neon.panicmic.com:8000`

Add these to `/etc/hosts` so they resolve to your dev box instead of production:

```text
127.0.0.1 bluebird.panicmic.com
127.0.0.1 neon.panicmic.com
127.0.0.1 admin.panicmic.com
127.0.0.1 signup.panicmic.com
```

Open:

- Public requests: `http://bluebird.panicmic.com:8000/`
- KJ dashboard: `http://bluebird.panicmic.com:8000/admin/dashboard`
- Projection: `http://bluebird.panicmic.com:8000/display`
- Signup landing: `http://signup.panicmic.com:8000/`
- Super admin: `http://admin.panicmic.com:8000/super/tenants`

Seeded logins:

- KJ (tenant admin): `admin@bluebird.panicmic.com` / `password123`
- Super admin: `super@panicmic.com` / `password123`

## Local Multi-Hostname Development

Browsers include the port in the Host header. Tenant lookup normalizes hosts by
stripping the port and checking the hostname against `tenant_domains.domain`.

Use separate local names in `/etc/hosts` to test isolation between KJs:

```text
127.0.0.1 bluebird.panicmic.com
127.0.0.1 neon.panicmic.com
```

Each hostname resolves to its own KJ tenant record and database schema. Because
`/etc/hosts` overrides DNS, these names point at your dev box locally while
still resolving to the production load balancer everywhere else.

Avoid `.local` hostnames for local development on macOS. `.local` is reserved
for Bonjour/mDNS and can add about 5 seconds of DNS delay before the browser
connects. The seed still registers `.local` aliases for compatibility, but the
`<slug>.panicmic.com` names are the recommended local default.

## Database Layout

`nextup_super` stores SaaS-wide records:

- `tenants`
- `tenant_domains`
- `super_admin_users`
- `provisioning_jobs`
- `signup_invites`
- `shared_songs`
- `login_attempts`
- `plans` / subscription columns on `tenants` (Stripe billing)
- `schema_migrations`

Each KJ's tenant schema stores isolated operational data:

- `users`
- `singers`
- `songs`
- `song_artists`
- `karaoke_sessions`
- `song_requests`
- `queue_items`
- `announcements`
- `display_state`
- `display_screens`
- `realtime_events`
- `audit_log`
- `settings`
- `payments_tips`
- `schema_migrations`

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

CI (`.github/workflows/ci.yml`) runs `make check` across PHP 8.2, 8.3, and 8.4.

The migration runner tracks applied files in a `schema_migrations` table per
database (auto-created on first run), keyed by filename and checksum. On its
first run against an existing dev or production database it bootstraps the
ledger by marking every migration on disk as applied without re-executing.

```bash
php scripts/migrate.php super
php scripts/migrate.php tenant nextup_bluebird
php scripts/migrate.php tenants                 # iterate all tenants
php scripts/migrate.php status tenants
php scripts/migrate.php super --dry-run
```

## Self-Serve SaaS Deployment (panicmic.com)

NextUp is hosted self-serve at `panicmic.com`, with each **KJ** getting their
own subdomain (`bluebird.panicmic.com`, `neon.panicmic.com`, …). The marketing
landing + signup flow lives on the signup host (`signup.panicmic.com`); KJ
traffic resolves by subdomain via `tenant_domains`. A single wildcard vhost
serves every KJ.

### nginx vhost (one block, wildcard subdomain)

```nginx
server {
    listen 443 ssl http2;
    server_name panicmic.com *.panicmic.com;

    ssl_certificate     /etc/letsencrypt/live/panicmic.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panicmic.com/privkey.pem;

    root /var/www/nextup/public;
    index index.php;

    location / {
        try_files $uri /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### Apache vhost (one block, wildcard subdomain)

The equivalent under Apache + PHP-FPM (`mod_proxy_fcgi`). Enable
`proxy`, `proxy_fcgi`, `rewrite`, and `ssl`:

```bash
a2enmod proxy proxy_fcgi rewrite ssl
```

```apache
<VirtualHost *:443>
    ServerName panicmic.com
    ServerAlias *.panicmic.com

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/panicmic.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/panicmic.com/privkey.pem

    DocumentRoot /var/www/nextup/public
    DirectoryIndex index.php

    <Directory /var/www/nextup/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Hand .php requests to PHP-FPM. Apache preserves the original Host
    # header, which tenant resolution depends on.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
```

Clean-URL routing is handled by `public/.htaccess` (`mod_rewrite`), so every
request flows through `public/index.php`. `AllowOverride All` is what lets the
`.htaccess` take effect; without it, add the rewrite rules directly to the
`<Directory>` block.

### Wildcard TLS via Let's Encrypt

```bash
certbot certonly --dns-cloudflare --dns-cloudflare-credentials ~/.cf.ini \
  -d panicmic.com -d '*.panicmic.com'
```

### Production .env

```env
APP_ENV=production
APP_BASE_PATH=
SESSION_SECRET=<random 32 bytes hex>
CSRF_SECRET=<random 32 bytes hex>
TRUST_PROXY=true
ALLOWED_HOSTS=panicmic.com,*.panicmic.com

SUPER_DB_HOST=127.0.0.1
SUPER_DB_USER=nextup_app
SUPER_DB_PASSWORD=<runtime user, no CREATE>
SUPER_DB_NAME=nextup_super

TENANT_DB_HOST=127.0.0.1
TENANT_DB_USER=nextup_app
TENANT_DB_PASSWORD=<same as above>
TENANT_DB_PREFIX=nextup_

# Provisioning user — needs CREATE/ALTER/DROP so signup can create a new
# KJ's database. Falls back to SUPER_DB_* if unset.
PROVISION_DB_USER=nextup_admin
PROVISION_DB_PASSWORD=<elevated>

SIGNUP_ROOT_DOMAIN=panicmic.com
SIGNUP_HOST=signup.panicmic.com
# Dedicated host for the global admin UI. When set, /super and /api/super
# are only served from this hostname; tenant hosts return 404 for those
# paths. Leave blank to keep /super reachable from every allowed host.
SUPER_HOST=admin.panicmic.com

MAIL_DRIVER=exim
MAIL_FROM=hello@panicmic.com
MAIL_FROM_NAME=Panic Mic
MAIL_SENDMAIL_PATH=/usr/sbin/exim
# Optional: only needed if MAIL_DRIVER=postmark
# POSTMARK_TOKEN=<from Postmark>

# Stripe billing (see "Billing" below).
STRIPE_SECRET_KEY=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…
STRIPE_PRICE_STARTER=price_…
STRIPE_PRICE_PRO=price_…

# Errors and structured events are written to storage/logs/errors-YYYY-MM-DD.log
# (JSON lines, one file per day). Prune old days with:
#   find storage/logs -name 'errors-*.log' -mtime +30 -delete

YOUTUBE_API_KEY=<youtube data api>
YOUTUBE_AUTO_ATTACH=true
```

### Bootstrap on a fresh server

```bash
git clone … /var/www/nextup
cd /var/www/nextup
cp .env.example .env && vi .env       # fill in production values

# Run as a DBA user with CREATE rights (separate from the app user).
mysql -uroot -e "CREATE DATABASE nextup_super CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php scripts/migrate.php super

# Seed the super-admin login.
php scripts/seed.php   # or run the manual SQL — see scripts/seed.php

# Optional: import the shared catalog you maintain on your laptop.
php scripts/import-shared-catalog.php seeds/songs.csv

# Make /content and /storage writable by PHP-FPM.
chown -R www-data:www-data content storage
```

After this, visit `https://signup.panicmic.com` to onboard the first KJ and
verify the flow end-to-end. New KJs land with a 14-day `trialing`
subscription; once Stripe is configured (below), Checkout flips them to
`active` automatically, or a super-admin can set the status manually.

## Billing (Stripe)

Self-serve billing is wired end-to-end:

- `GET  /api/billing/plans` — available plans for the current KJ.
- `POST /api/billing/checkout` — creates a Stripe Checkout session and returns
  the redirect URL.
- `POST /webhooks/stripe` — public, signature-verified. Stripe events arrive
  without tenant context, so this route runs before tenant resolution.

`StripeService` talks to the Stripe REST API directly (no SDK dependency) and
reads its configuration from the environment:

```env
STRIPE_SECRET_KEY=sk_live_…        # or sk_test_…
STRIPE_WEBHOOK_SECRET=whsec_…      # verifies the webhook signature
STRIPE_PRICE_STARTER=price_…       # mapped from the plan's code
STRIPE_PRICE_PRO=price_…
```

`checkout.session.completed` records the `stripe_customer_id` /
`stripe_subscription_id` against the tenant; `customer.subscription.updated`
and `customer.subscription.deleted` drive `BillingService::setStatus`. When
`STRIPE_SECRET_KEY` is unset the billing endpoints fail closed, and a
super-admin can still set `tenants.subscription_status` manually from the API
or in SQL. Lapsed KJs can always reach the billing UI to reactivate.

## Production Deployment Notes

Place the app behind Nginx, Apache, or Caddy with PHP-FPM. Forward the original
host so tenant resolution sees the real subdomain.

nginx:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
```

Apache (when fronting another backend; enable `headers` and `remoteip`):

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"
RemoteIPHeader X-Forwarded-For
```

Set:

```env
TRUST_PROXY=true
ALLOWED_HOSTS=panicmic.com,*.panicmic.com
SESSION_SECRET=<strong secret>
CSRF_SECRET=<strong secret>
```

Only domains present in `tenant_domains` are accepted for tenant traffic. For
proxy deployments, keep `TRUST_PROXY=true` only when the app is reachable
exclusively through the trusted proxy.

Point the web root at `public/`. All routes are handled by `public/index.php`.

### MySQL privileges

The runtime app does not need `CREATE` privileges. The app user only needs DML
against existing schemas:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON `nextup_super`.* TO 'nextup_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON `nextup_<tenant>`.* TO 'nextup_app'@'%';
```

Self-serve signup needs to create a new KJ's database on the fly, so set the
`PROVISION_DB_*` credentials to a user that has `CREATE`/`ALTER`/`DROP`. The
same user backs `scripts/migrate.php`. Keep it distinct from the runtime app
user:

```sql
GRANT ALL PRIVILEGES ON `nextup_super`.* TO 'nextup_admin'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `nextup_%`.*     TO 'nextup_admin'@'127.0.0.1';
```

If you prefer not to grant runtime provisioning rights at all, leave
`PROVISION_DB_*` unset, pre-create tenant databases by hand, and run migrations
from a deploy host instead.

## Apache Under a Subdirectory

If your Apache vhost points at the project root and the app is reached at
`/nextup/public`, set this in `.env`:

```env
APP_BASE_PATH=/nextup/public
```

The included `public/.htaccess` uses `mod_rewrite` to route clean URLs through
`public/index.php`. Enable rewrite support in Apache, then allow `.htaccess` in
the public directory:

```apache
LoadModule rewrite_module libexec/apache2/mod_rewrite.so

<Directory "/var/www/nextup/public">
    AllowOverride All
    Require all granted
</Directory>
```

With that setup, use URLs such as:

- `http://bluebird.panicmic.com/nextup/public/`
- `http://bluebird.panicmic.com/nextup/public/admin/dashboard`
- `http://bluebird.panicmic.com/nextup/public/files/example.mp4`

## Tenant Content

KJs can upload images, videos, audio, and PDFs from `Admin -> Content`. Files
are stored under:

```text
/var/www/nextup/content/<tenant-slug>/
```

Uploads are checked with `finfo_file` so a renamed `.exe → .png` is rejected at
the magic-byte level. The public route `/files/<filename>` maps to the current
KJ's content folder after hostname tenant resolution, so
`bluebird.panicmic.com/files/logo.png` and `neon.panicmic.com/files/logo.png`
are isolated even if the filename is the same. Uploaded content is ignored by
Git; only `content/.gitkeep` is tracked.

## YouTube Karaoke Matching

Set these in `.env` to attach YouTube karaoke videos to requests:

```env
YOUTUBE_API_KEY=<youtube-data-api-key>
YOUTUBE_AUTO_ATTACH=true
```

When enabled, new song requests automatically search YouTube for
`<artist> <title> karaoke`, request embeddable videos ordered by view count,
and attach the top result to the KJ queue item. KJs can also retry matching
from the queue with `Find video`.

After pulling this feature into an existing tenant database, run:

```bash
php scripts/migrate.php tenant nextup_bluebird
php scripts/migrate.php tenant nextup_neon
```

## Manual Video Links

A KJ can paste their own video URL onto any queued request when automatic
matching misses or when they already know the right karaoke track. Manual
links take precedence over the auto-attached YouTube result and survive
re-matching, so the KJ's choice always wins on the projection player.

## Catalog visibility

`/api/songs` and `/api/catalog` are intentionally public, returning the KJ's
song catalog blended with the shared catalog for any visitor without requiring
authentication. Karaoke songbooks are designed to be read by anyone walking
into the room — singers need to browse before they sign up, and
`signup_mode='display_name'` means no account is needed at all. Gating the
catalog would break the core request flow on phones.

The trade-off is that a competitor can scrape the list of titles and artists.
Songbook content is not proprietary in this domain, so the exposure is
acceptable. A future per-tenant `catalog_visibility` setting could opt into a
token gate; this is not implemented today.

## Multi-monitor displays

Each KJ session can drive one or more independent display windows.
`display_state` is keyed by `(session_id, screen)`, so the main projector, a
lyrics TV, and a lobby monitor can each show different content at the same
time. Screens are configured in the `display_screens` table.

Configure screens under **Admin → Settings → Multi-monitor displays**. Each row
adds a button to the operator dashboard that opens a new window at
`/display?screen=<id>`.

### Operator → display control

The KJ console talks to its own popped-out display windows via the browser's
native `BroadcastChannel`, not the network. Channel name is
`nextup:display:<tenant-slug>:<session-id>`. The server stays the source of
truth — every command also POSTs to `/api/display/state` — so a reloaded
display window recovers its state by fetching `/api/display/state?screen=…` and
re-rendering. Cross-device viewers (singer phones, a projector running off a
different PC) receive the same `display:state_changed` event through SSE and
fetch the same endpoint. One model, two transports.

`BroadcastChannel` throttles message delivery to backgrounded tabs to about
1 msg/sec after ~5 minutes hidden. Keep both windows visible during local
development and you won't see the throttling. In production the operator window
stays focused, so it's not an issue.

### Multi-monitor setup recipes

**One PC, multiple HDMI outputs (most common):**

* Open the KJ dashboard in your main browser window.
* For each physical display, click its "Open" button in the toolbar. Drag the
  new popup onto the right monitor, then press F11 for fullscreen. The window's
  URL survives reloads via its `?screen=` param so a dropped popup re-attaches
  by reopening it.
* On Chromium-based browsers, the toolbar will offer to place each popup
  automatically using the
  [Window Management API](https://developer.mozilla.org/en-US/docs/Web/API/Window_Management_API)
  after you grant the one-time "Open windows on other displays" permission.

**Same content on multiple TVs (cheapest):**

Run one display window on the KJ PC and split its HDMI output through a powered
splitter to every TV. Zero browser overhead, perfect frame sync, no setup
beyond cables.

**Dedicated projector PC:**

Set up a tenant-specific Chrome shortcut:

```
chrome --kiosk --window-position=0,0 \
       "https://bluebird.panicmic.com/display?screen=main"
```

Add it to the projector PC's startup items so the display comes up on boot.

## Curated Song Catalogs

Each KJ has their own isolated song catalog. KJs can manage `Admin -> Songs`
and attach:

- a direct video-with-lyrics URL
- a provider name such as `youtube`, `karafun`, `stingray`, `singa`, `local`,
  or a custom value
- provider track IDs and provider URLs
- a separate lyrics URL

This keeps each KJ's public songbook their own while still allowing them to
launch the correct source from the queue or catalog.

KaraFun integration should be treated as a licensed provider integration.
KaraFun's public help documents CSV catalog downloads for catalog curation, and
KaraFun Business documents API access through bearer tokens plus downloadable
OpenAPI YAML from the Business dashboard. Configure credentials with:

```env
KARAFUN_API_TOKEN=
KARAFUN_API_BASE_URL=https://business.karafun.com/api
```

Other providers can follow the same pattern using `video_provider`,
`provider_track_id`, and `provider_url`; Stingray placeholders are included in
`.env.example`.

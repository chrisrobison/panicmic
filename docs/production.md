# Production checklist

PanicMic is a multi-host application: the marketing site, signup, super-admin,
and each KJ account can use distinct hostnames. Complete this checklist before
accepting live traffic.

## Application and DNS

- Set `APP_ENV=production`, `MARKETING_HOST`, `SIGNUP_HOST`,
  `SIGNUP_ROOT_DOMAIN`, `SUPER_HOST`, and `ALLOWED_HOSTS`.
- Set `APP_URL` only when the deployment has one canonical application origin.
  Leave it blank in a multi-tenant deployment so links use the resolved,
  allow-listed tenant host.
- Generate a long random `CSRF_SECRET`; production intentionally refuses the
  development fallback.
- Terminate TLS before PHP. Production responses emit HSTS, so validate every
  subdomain over HTTPS before enabling traffic.
- Point the web root at `public/`; do not expose `.env`, `content/`, `storage/`,
  `migrations/`, or `scripts/`.

## Database and migrations

Use separate runtime and provisioning credentials. The runtime user should
access existing super/tenant schemas; the provisioning user needs database DDL
for signup and migrations.

```bash
php scripts/migrate.php status tenants
php scripts/migrate.php super
php scripts/migrate.php tenants
```

Back up both `panicmic_super` and every `panicmic_*` tenant schema before
deploying schema changes.

## Mail and account recovery

- Configure `MAIL_DRIVER=exim`, `sendmail`, or `postmark`.
- Set a verified `MAIL_FROM` and test signup, team invitation, and password
  reset delivery.
- Confirm reset links return to the correct tenant hostname. Tokens are
  SHA-256 hashed, single-use, and expire after one hour (team invites: seven
  days).

## Billing

- Set `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, and a
  `STRIPE_PRICE_<PLAN_CODE>` value for every active plan offered at checkout.
- Register `https://<host>/webhooks/stripe` in Stripe and subscribe to checkout
  session, subscription lifecycle, and failed invoice events.
- Use Stripe test mode first. The Settings page reports whether checkout and
  webhook configuration are present.

## Realtime displays

- Run `php scripts/ws-server.php` under a process supervisor, or allow the
  on-demand launcher and set `PHP_CLI_BINARY` to an executable PHP binary.
- Reverse-proxy `WEBSOCKET_PUBLIC_PATH` with Upgrade/Connection headers.
- Test two sessions at once: queue and display commands must remain
  session-scoped. Disconnect and reconnect a display during playback to verify
  persisted clock recovery.

## Uploads and storage

- Set `CONTENT_UPLOAD_MAX_MB` and `VIDEO_UPLOAD_MAX_MB`.
- Match or exceed those values in PHP `upload_max_filesize`/`post_max_size` and
  the reverse proxy request-body limit.
- Ensure `content/` and `storage/` are writable by PHP, backed up, and excluded
  from direct static serving. `/files/*` performs tenant isolation, MIME
  controls, and video byte-range delivery.

## Observability

- Production enables structured `storage/access.log` by default. Set
  `ACCESS_LOG=0` only if the web tier already produces equivalent logs.
- Set `LOG_AGGREGATOR_URL` to forward access events and
  `ERROR_REPORTING_URL` to forward exception events. Optional bearer tokens
  are supported by the matching `*_TOKEN` variables.
- Alert on HTTP 5xx, webhook failures, failed provisioning jobs, disk space,
  database availability, and WebSocket process restarts.
- Rotate or ship local logs and periodically prune expired reset/rate-limit
  rows according to your retention policy.

## Release verification

```bash
make check
npm ci
npx playwright install --with-deps chromium
npm run test:browser
```

Manually smoke-test signup, login/reset, team invitations, request submission,
queue reordering, session start/end, Stripe test checkout, content upload, and
multi-screen playback before directing production traffic to the release.

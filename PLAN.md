# NextUp — Implementation Plan

Sequenced roadmap from the 2026-05-26 architecture review. Phases are ordered
so each one unlocks the next; estimates are working-days for a single engineer.

This file supersedes the free-form list at the top of `TODO.md`. Items from
`TODO.md` that are folded into a phase below are linked in-line; anything not
mentioned here stays in `TODO.md` as backlog.

---

## Phase 0 — Pre-flight (½ day)

**Goal:** dev-tool baseline without taking on runtime dependencies.

NextUp has zero runtime PHP dependencies and a working hand-rolled autoloader.
Composer would be tooling-shell only, so this phase uses PHPUnit and PHPStan as
PHAR files instead.

- Add `Makefile` with targets:
  - `tools` — curl `phpunit.phar` and `phpstan.phar` into `tools/` with
    signature verification.
  - `lint` — `php -l` over all PHP under `src/`, `public/`, `scripts/`.
  - `stan` — `php tools/phpstan.phar analyse` (level 5, paths `src/`).
  - `test` — `php tools/phpunit.phar`.
  - `check` — all three; what CI runs.
- Add `tools/`, `.phpunit.result.cache`, `.phpstan-cache` to `.gitignore`.
- Decide `songs.csv` / `songs.json` / `index.html` — track under `seeds/` or
  ignore. (From `TODO.md` Housekeeping.)
- Keep `src/autoload.php` as the runtime autoloader. No `composer.json`, no
  `vendor/`.
- **★ Decision required before Phase 5: hosting story for production.**
  - "I install for KJs I know" → ignore Redis/Mercure, lean on HDMI splitter
    docs, skip billing/signup forever.
  - "Self-serve at nextup.io" → forces signup, billing, isolation, and
    observability into Phase 7.

**Exit criteria:** `make check` runs end-to-end (even with zero tests so far).

---

## Phase 1 — Operational safety net (2 days)

**Goal:** be able to refactor without fear. Nothing user-visible changes.

### 1.1 Real migration runner (½ day)
- Add `schema_migrations` table to both super and tenant schemas
  (`filename VARCHAR(255) PRIMARY KEY`, `applied_at TIMESTAMP`).
- Rewrite `scripts/migrate.php`:
  - List `migrations/{super,tenant}/*.sql` sorted.
  - Skip files already in `schema_migrations`.
  - Wrap each file in a transaction where the SQL allows.
  - Insert into `schema_migrations` on success.
- Backfill: on first run against an existing DB, mark all currently-applied
  files (`001_*` through the latest) as applied so they don't re-execute.
- Add `--dry-run` flag listing pending migrations.

### 1.2 PHPUnit smoke suite (½ day)
Twenty tests minimum, covering the silent-regression hot spots:
- `TenantContext::resolve` — hostname normalization, port stripping, unknown
  host rejection.
- `QueueService::queue` and `::submit` — shared vs local song source.
- `Auth::requireTenantRole` — role gates, deactivated user rejection
  (once 2.4 lands).
- `SongService::search` — both LIKE and FULLTEXT paths.
- `Security::csrfToken` / `::verify` — round-trip.
- `Impersonation::signedToken` / `::verify` — HMAC behavior, expiry.
- `EventBus::publish` / `::after` — pagination by `lastId`.

Use a disposable MySQL schema per test run; do **not** test against dev
tenants.

### 1.3 GitHub Actions CI (½ day)
- Matrix: PHP 8.2, 8.3, 8.4.
- Cache the phar downloads.
- Run `make check` (lint + stan + test).

### 1.4 Documentation patch (¼ day)
- README "Production" section: prod MySQL user should have `CREATE` revoked;
  tenant provisioning becomes a DBA-run script. (From review.)
- README: replace "CSV import/export hooks" with the truthful state.
  (From `TODO.md`.)

**Exit criteria:** A deliberate breaking change in `QueueService` fails CI.

---

## Phase 2 — Three production landmines (1 day)

### 2.1 Login rate-limiting (¼ day)
- Bucket: `login:{remote_ip}:{email_lower}`.
- Limit: 5 attempts / 5 min → 429 with `Retry-After`.
- Apply to `/api/admin/login` and `/api/super/login`.
- (From `TODO.md` Security.)

### 2.2 `realtime_events` retention (¼ day)
- Migration: `CREATE INDEX idx_realtime_events_created_at ON realtime_events(created_at)`.
- `EventBus::publish` deletes rows older than 1 hour after each insert.
- Test: pruning happens.
- (From `TODO.md` Operational.)

### 2.3 FULLTEXT search (½ day)
- Ensure `songs` has `FULLTEXT(title, artist)`; add migration if not.
- `SongService::search`: when query length ≥ 3, use
  `MATCH … AGAINST (… IN BOOLEAN MODE)` with `+term*` prefix rewriting; LIKE
  fallback for shorter queries.
- Test both paths.
- (From `TODO.md` Data model.)

### 2.4 ★ Piggybacks
- **Magic-byte upload verification** in `ContentService` via `finfo_file`;
  reject when MIME mismatches the claimed extension.
  (From `TODO.md` Security.)
- **Session re-check on auth.** In `Auth::requireTenantRole`, re-query
  `users.is_active` once per request (request-scoped static cache is fine).
  (From `TODO.md` Security.)

---

## Phase 3 — Untangle the front controller (½ day)

Pure refactor, gated by Phase 1 tests.

- Create `src/Http/` with: `QueueController`, `RequestController`,
  `DisplayController`, `SongController`, `ContentController`,
  `SettingsController`, `BrandingController`, `SuperController`,
  `AuthController`.
- Each controller is a final class with static methods matching the current
  route closures in `public/index.php`.
- `public/index.php` becomes a route table — `[$method, $pattern] => [Class, 'method']`
  — plus a dispatcher.
- Target: `public/index.php` ≤ 200 lines.
- **★ While in here:** move `e()` and view helpers out of `Response.php` into
  `src/Support/helpers.php`, eager-required by `src/autoload.php`.
  (From `TODO.md` Code quality.)

**Exit criteria:** `wc -l public/index.php` ≤ 200; Phase 1 tests still pass.

---

## Phase 4 — Data model hygiene (1 day)

### 4.1 Singers dedupe (½ day)
- Migration: `UNIQUE KEY uniq_singer_session_name (session_id, display_name)`.
- Add `singers.last_seen_at` column.
- `QueueService::submit` upserts via `INSERT … ON DUPLICATE KEY UPDATE
  last_seen_at = NOW()`.
- Data-fix script: merge existing duplicates per session, repoint
  `song_requests.singer_id`, drop dupes. One transaction per session.
- (From `TODO.md` Data model.)

### 4.2 Session lifecycle UI (½ day)
- `karaoke_sessions` gains `started_at`, `ended_at`, `status ENUM('draft','live','closed')`.
- Operator dashboard: "Start tonight's session" / "End session" controls
  above the queue board.
- On end: snapshot stats to `audit_log`, set `display_state.mode='idle'`,
  leave queue items at `status='completed'`.
- Public page shows "We're closed for tonight" when `status='closed'`.

### 4.3 ★ Catalog auth decision (½ hour)
- Either gate `/api/songs` behind a requester token or leave it public.
  Document the choice in the README and stop drifting.
  (From `TODO.md` Security.)

---

## Phase 5 — Multi-monitor display (3 days)

Architecture: **`display_state` in MySQL is source of truth.**
**BroadcastChannel** carries operator → operator's-own-displays commands.
**SSE** stays for cross-device viewers (singer phones, remote-machine
projectors, second-operator tablets).

### 5.1 Schema + state (½ day)
- Migration: `display_state` gains
  `screen VARCHAR(32) NOT NULL DEFAULT 'main'` and the primary key becomes
  `(session_id, screen)`.
- New `display_screens` table:
  ```sql
  id, session_id, screen, label,
  layout ENUM('main','lyrics','lobby','stage','custom'),
  default_volume TINYINT, show_qr TINYINT(1), show_queue TINYINT(1),
  created_at,
  UNIQUE KEY (session_id, screen)
  ```
- `DisplayService::state()` and `::update()` take optional `$screen` arg
  (default `'main'`). Backwards compatible.
- Whitelist `now_singing` in `DisplayService::update`'s mode check.
- `GET /api/display/state?screen=main` returns the row for that screen.

### 5.2 Display page becomes a real player (1 day)
- `views/pages/display.php`: add `<div data-player>` layer above the grid.
- Three render paths from the resolved request's video fields:
  - YouTube IFrame Player API for `youtube_video_id`/`youtube_url`.
  - `<video>` for self-hosted `songs.video_url` under `/files/`.
  - "Open on provider" CTA (operator side only) when no embed is possible.
- Player visible only when `display.mode === 'now_singing'`; other modes show
  the existing grid.
- **Autoplay muted on load.** Unmute on first KJ-driven `cue` (works around
  Chromium autoplay policy — call `player.unMute()` from inside the YT player
  state-change handler after `playVideo()` succeeds).
- Page reads `?screen=` from URL and passes it to all state fetches.

### 5.3 Operator control via BroadcastChannel (½ day)
- Channel name: `nextup:display:{tenant_slug}:{session_id}`.
- Dashboard publishes commands:
  `{ screen: 'main' | 'all', action: 'cue' | 'play' | 'pause' | 'skip' | 'seek' | 'volume', payload }`.
- Display windows join on load; filter on `screen`.
- On any bus message → display fetches `/api/display/state?screen=…` and
  re-renders. **Server stays authoritative.**
- KJ's "Cue & Play" handler does, in order:
  1. `POST /api/display/state` with the new mode + `now_request_id` + screen.
  2. `bus.postMessage({ screen, action: 'cue' })` for instant local fan-out.
- SSE `display:state_changed` listener stays as the cross-device fallback.

### 5.4 "Open Displays" toolbar (½ day)
- Settings page: configure screens for this session (label, layout). Persists
  to `display_screens`.
- Dashboard toolbar: one button per configured screen →
  `window.open('/display?screen=…','nextup_<screen>','popup,width=…,height=…')`.
- Feature-checked path: when available, call `window.getScreenDetails()`
  first (Chromium) and pass `left/top/width/height` to land popups on
  specific monitors.

### 5.5 ★ Operational docs
- README "Multi-monitor setups": Chrome `--kiosk` per display, HDMI splitter
  for mirrored TVs, Window Management API for Chromium.
- README note: backgrounded-tab BroadcastChannel throttling during dev (keep
  both windows visible while testing).

**Exit criteria:** One PC, three monitors, KJ clicks "Cue & Play" → projector
video plays within ~250 ms, lyrics TV shows large title, lobby TV stays on
queue+QR. Browser reload of any window recovers state without operator
intervention.

---

## Phase 6 — Frontend hygiene (1 day) ★

`public/assets/app.js` is 1000 lines and will grow with the player code.

- Split into ES modules (no bundler needed):
  - `assets/lib/api.js`, `assets/lib/dom.js`, `assets/lib/events.js`,
    `assets/lib/broadcast.js`.
  - `assets/pages/public.js`, `pages/admin-dashboard.js`,
    `pages/admin-songs.js`, `pages/display.js`, `pages/super-catalog.js`.
- `<script type="module" src="…">` selected per page based on
  `appConfig.page`.
- **★ Tighten CSP `style-src`:** replace `'unsafe-inline'` with a per-request
  nonce on the single inline `<style>` block in `views/layout.php`.
  (From `TODO.md` Security.)

---

## Phase 7 — Strategic (driven by Phase 0 hosting decision)

### If "self-serve SaaS":
- Signup flow (marketing page → email + venue → DB provisioning job →
  tenant admin invite email).
- Stripe Billing on `nextup_super`. Plans, seats, trial.
- Email delivery (Postmark or SES). Templated transactional mails.
- Observability (Sentry, request log aggregation).
- Self-hosted video fallback (first-class; YouTube quota becomes a real
  liability at scale).

### If "I install for KJs I know":
- Self-hosted video fallback (still important — durable answer to YouTube
  fragility).
- Licensed provider polish (KaraFun, Stingray actually working
  end-to-end, not just stored as URLs).
- Mobile-first operator pass (tablet-friendly dashboard).
- Skip billing/signup/emails until they're needed.

---

## Deliberately deferred

These came up in review and are **not** in this plan:

- **Redis pub/sub / Mercure.** With BroadcastChannel for the local-display
  path, the remaining SSE consumers tolerate ≤1 s latency. Revisit only if a
  Phase 7 deployment shows PHP-FPM worker saturation. Possibly never.
- **Switching off SSE entirely.** Singer phones still need server push.
  Phase 2.2 makes the current implementation operationally fine.
- **Framework migration.** Tempting; wrong move. The codebase is small and
  the architecture is right.
- **Composer / `vlucas/phpdotenv` adoption.** Zero runtime deps; the `Env`
  class works. Dev tools ship as PHARs.

---

## Estimate summary

| Phase | Time | Cumulative |
|---|---|---|
| 0. Pre-flight | ½ d | ½ d |
| 1. Operational safety net | 2 d | 2½ d |
| 2. Production landmines | 1 d | 3½ d |
| 3. Front controller refactor | ½ d | 4 d |
| 4. Data model hygiene | 1 d | 5 d |
| 5. Multi-monitor display | 3 d | 8 d |
| 6. Frontend hygiene | 1 d | 9 d |
| 7. Strategic | open-ended | — |

**~9 working days to "production-credible NextUp with multi-monitor."**

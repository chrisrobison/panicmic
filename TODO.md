# PanicMic — TODO

The original backlog and the phased `PLAN.md` roadmap have been delivered:
the migration runner, PHPUnit suite, GitHub Actions CI, login rate-limiting,
`realtime_events` pruning, FULLTEXT search, magic-byte upload checks,
per-request `is_active` re-checks, the `src/Http/` controller refactor,
`src/Support/helpers.php`, singer dedupe, session lifecycle, the catalog-auth
decision, multi-monitor displays, the ES-module frontend split, the CSP
style-src nonce, self-serve signup with auto-provisioning, Stripe billing, and
email delivery are all shipped and tested.

What remains is a short list of polish and future-facing work.

## Open

- [ ] **Single-tenant CSV importer.** `scripts/import-shared-catalog.php`
      handles the shared (super) catalog, but there's no per-tenant loader.
      Add `scripts/import-songs.php <tenant_database> <path/to/songs.csv>`
      (semicolon delimiter, quoted strings, `;`-separated `Styles`/`Languages`)
      and wire an admin-UI button to it.
- [ ] **Trim `public/index.php`.** Controllers are extracted, but the router is
      still ~297 lines vs the 200-line target. Pull the remaining inline
      pre-tenant logic (signup/super/webhook host handling) into the dispatcher.
- [ ] **Observability.** Today errors go to daily JSON log files in
      `storage/logs/` only. Wire a real error tracker (Sentry or similar) and
      request-log aggregation before scaling signups.

## Future / nice-to-have

- [ ] **Self-hosted video as a first-class fallback.** KJ-supplied manual video
      links and a `video_url` column exist; making self-hosting a smooth path
      (upload + transcode + player) is the durable answer to YouTube quota
      limits at scale.
- [ ] **Licensed provider polish.** KaraFun and Stingray are stored as
      provider URLs/track IDs today; wiring their APIs end-to-end (catalog sync,
      authenticated launch) is future work.

## Deliberately deferred

Not planned unless a deployment proves the need:

- **Redis pub/sub / Mercure.** BroadcastChannel covers the local-display path;
  the remaining SSE consumers tolerate ≤1 s latency. `realtime_events` pruning
  keeps the current implementation operationally fine.
- **Framework migration.** The codebase is small and the architecture is right.
- **Composer / `vlucas/phpdotenv` adoption.** Zero runtime deps; the `Env`
  class works and dev tools ship as PHARs.

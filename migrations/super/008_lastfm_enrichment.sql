-- 008_lastfm_enrichment.sql
--
-- Augment the shared catalog with data fetched from the Last.fm API:
-- cover art (hotlinked), album name, MBID, canonical Last.fm URL, top
-- tags, and listener/playcount popularity. `lastfm_enriched_at` lets the
-- backfill job (scripts/enrich-lastfm.php) skip rows it has already seen.
--
-- `genre` and `year` already exist on shared_songs; enrichment only fills
-- them when they're empty (COALESCE), so curated CSV data wins.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php.

ALTER TABLE shared_songs
  ADD COLUMN album VARCHAR(255) NULL AFTER artist,
  ADD COLUMN album_art_url VARCHAR(512) NULL AFTER album,
  ADD COLUMN mbid VARCHAR(64) NULL AFTER album_art_url,
  ADD COLUMN lastfm_url VARCHAR(512) NULL AFTER mbid,
  ADD COLUMN listeners INT UNSIGNED NULL AFTER lastfm_url,
  ADD COLUMN playcount INT UNSIGNED NULL AFTER listeners,
  ADD COLUMN tags JSON NULL AFTER playcount,
  ADD COLUMN lastfm_enriched_at TIMESTAMP NULL DEFAULT NULL AFTER tags,
  ADD INDEX idx_shared_enriched (lastfm_enriched_at);

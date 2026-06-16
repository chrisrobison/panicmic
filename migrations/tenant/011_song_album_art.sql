-- 011_song_album_art.sql
--
-- Album art for tenant-local songs. Mirrors the shared catalog so the KJ
-- queue, displays, and catalog search can show a cover for every song —
-- real art when present, a generated cover as the fallback. KJs can paste
-- an Album art URL directly in the song editor; the field is also filled
-- by future imports.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php.

ALTER TABLE songs
  ADD COLUMN album VARCHAR(255) NULL AFTER artist,
  ADD COLUMN album_art_url VARCHAR(512) NULL AFTER album;

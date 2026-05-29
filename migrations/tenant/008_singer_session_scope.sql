-- 008_singer_session_scope.sql
--
-- Phase 4.1 (corrected): scope singer uniqueness to (session_id,
-- display_name) so "Chris" tonight and "Chris" next month are separate
-- rows, while reusing the same row within a single session.
--
-- Migration 005 added a global UNIQUE KEY on display_name, which
-- conflicts with the plan's session-scoped intent. This migration:
--   1. Adds singers.session_id (nullable to permit orphan rows).
--   2. Backfills session_id to each singer's FIRST observed session.
--   3. Clones the singer row for every additional session the same
--      display_name was active in, and repoints song_requests at the
--      new per-session rows.
--   4. Replaces the global UNIQUE with uniq_singer_session_name.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger
-- in scripts/migrate.php — re-running this file is not safe (the
-- ALTER ADD COLUMN would fail on the second pass). The runner skips
-- already-applied filenames so this is a single-shot operation.

ALTER TABLE singers
  ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER user_id;

ALTER TABLE singers DROP INDEX uniq_singers_display_name;

UPDATE singers s
  JOIN (
    SELECT singer_id, MIN(session_id) AS first_session
    FROM song_requests
    GROUP BY singer_id
  ) r ON r.singer_id = s.id
  SET s.session_id = r.first_session
  WHERE s.session_id IS NULL;

INSERT INTO singers (user_id, session_id, display_name, phone, email, last_seen_at, created_at, updated_at)
SELECT DISTINCT s.user_id, sr.session_id, s.display_name, s.phone, s.email, s.last_seen_at, NOW(), NOW()
FROM song_requests sr
JOIN singers s ON s.id = sr.singer_id
WHERE sr.session_id IS NOT NULL
  AND (s.session_id IS NULL OR sr.session_id <> s.session_id)
  AND NOT EXISTS (
    SELECT 1 FROM singers s2
    WHERE s2.display_name = s.display_name
      AND s2.session_id = sr.session_id
  );

UPDATE song_requests sr
  JOIN singers s_old ON s_old.id = sr.singer_id
  JOIN singers s_new
    ON s_new.display_name = s_old.display_name
   AND s_new.session_id = sr.session_id
  SET sr.singer_id = s_new.id
  WHERE sr.session_id IS NOT NULL
    AND (s_old.session_id IS NULL OR sr.session_id <> s_old.session_id);

ALTER TABLE singers
  ADD UNIQUE KEY uniq_singer_session_name (session_id, display_name);

-- Phase 4.1: dedupe singers.
-- Previously QueueService::submit created a fresh `singers` row per
-- request, producing dozens of "Chris" rows in one night. This adds a
-- last_seen_at column, collapses existing duplicates per tenant
-- (keeping the oldest row), and enforces uniqueness on display_name.

ALTER TABLE singers
  ADD COLUMN last_seen_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;

-- Map every duplicate display_name to the lowest id sharing it, then
-- repoint song_requests at that id before deleting the dupes.
CREATE TEMPORARY TABLE singers_keep AS
  SELECT display_name, MIN(id) AS keep_id
  FROM singers
  GROUP BY display_name;

UPDATE song_requests sr
  JOIN singers s ON s.id = sr.singer_id
  JOIN singers_keep k ON k.display_name = s.display_name
  SET sr.singer_id = k.keep_id
  WHERE s.id <> k.keep_id;

DELETE s FROM singers s
  JOIN singers_keep k ON k.display_name = s.display_name
  WHERE s.id <> k.keep_id;

DROP TEMPORARY TABLE singers_keep;

-- Backfill last_seen_at from the most recent request submitted by each
-- singer. Singers with no requests get NULL (they'll get a timestamp
-- on their next submission).
UPDATE singers s
  LEFT JOIN (
    SELECT singer_id, MAX(created_at) AS last_at
    FROM song_requests
    GROUP BY singer_id
  ) r ON r.singer_id = s.id
  SET s.last_seen_at = COALESCE(r.last_at, s.created_at);

-- Drop the non-unique index that the new UNIQUE KEY makes redundant.
ALTER TABLE singers DROP INDEX idx_singers_display_name;

ALTER TABLE singers
  ADD UNIQUE KEY uniq_singers_display_name (display_name);

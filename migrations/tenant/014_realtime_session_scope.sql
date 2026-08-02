-- Scope realtime events to a karaoke session while retaining NULL for
-- tenant-wide events such as catalog, venue, schedule, and settings changes.
--
-- Existing rows are intentionally left NULL. They predate session scoping and
-- will age out under the one-hour retention policy without being incorrectly
-- attributed to whichever session happens to be current during migration.

ALTER TABLE realtime_events
  ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER id,
  ADD INDEX idx_realtime_events_session_id (session_id, id),
  ADD CONSTRAINT fk_realtime_events_session
    FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE;

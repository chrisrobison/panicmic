-- Migration 013: KJ Command Center dashboard support.
--
-- * song_requests.reviewed_at — set when a KJ accepts an incoming request
--   into the rotation queue. NULL means it's still sitting in the
--   "Incoming Requests" review tray.
-- * song_requests.is_priority — KJ-toggled VIP flag surfaced as a queue
--   badge.
-- * display_state.mode gains 'blackout' alongside the existing modes, so a
--   screen can be blanked without losing its configured state.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php.

ALTER TABLE song_requests
  ADD COLUMN reviewed_at DATETIME NULL AFTER status,
  ADD COLUMN is_priority BOOLEAN NOT NULL DEFAULT FALSE AFTER reviewed_at,
  ADD INDEX idx_requests_reviewed (session_id, reviewed_at);

ALTER TABLE display_state
  MODIFY COLUMN mode ENUM('idle','queue','now_singing','clean_stage','announcement','blackout') NOT NULL DEFAULT 'idle';

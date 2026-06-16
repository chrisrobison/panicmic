-- Migration 012: add playback sync fields to display_state
-- Tracks the in-progress play command so reconnecting displays can
-- recover the current sync state. Fields are nullable so existing rows
-- are unaffected.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php.

ALTER TABLE display_state
  ADD COLUMN play_command_id    VARCHAR(64)                                    NULL,
  ADD COLUMN play_state         ENUM('stopped','cued','playing','paused')      NOT NULL DEFAULT 'stopped',
  ADD COLUMN play_started_at_ms BIGINT UNSIGNED                                NULL,
  ADD COLUMN play_offset_seconds DECIMAL(10,3)                                 NOT NULL DEFAULT 0,
  ADD COLUMN play_updated_at    DATETIME                                        NULL;

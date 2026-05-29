-- 007_sessions_lifecycle.sql
--
-- Align karaoke_sessions.status with the PLAN.md Phase 4.2 lifecycle:
--   ENUM('draft','live','closed').
--
-- The original schema used ('scheduled','active','paused','archived').
-- We migrate existing rows to the new vocabulary first, then narrow the
-- ENUM. Idempotent: re-running is safe (ALTER COLUMN to the same type
-- is a no-op; the UPDATEs match zero rows on a clean schema).

START TRANSACTION;

-- Map legacy values onto the new lifecycle. 'paused' becomes 'live' (a
-- live session that's mid-break), 'active' becomes 'live', 'scheduled'
-- becomes 'draft', 'archived' becomes 'closed'.
UPDATE karaoke_sessions SET status = 'live'   WHERE status IN ('active','paused');
UPDATE karaoke_sessions SET status = 'draft'  WHERE status = 'scheduled';
UPDATE karaoke_sessions SET status = 'closed' WHERE status = 'archived';

COMMIT;

-- ALTER TABLE in MySQL implicitly commits; the transaction above
-- protects only the data migration. The narrower ENUM is applied after.
ALTER TABLE karaoke_sessions
  MODIFY COLUMN status ENUM('draft','live','closed') NOT NULL DEFAULT 'draft';

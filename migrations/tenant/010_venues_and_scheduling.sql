-- 010_venues_and_scheduling.sql
--
-- Venues + scheduling. The product is driven by professional KJs who
-- host at different venues throughout the week (usually the same venue on
-- the same weekday). This migration introduces:
--   1. `venues`         — the places a KJ hosts at (account-scoped).
--   2. `show_schedules` — recurring templates (weekly/biweekly/monthly).
--   3. `events`         — concrete calendar entries (one-off rows and
--                          materialized occurrences of a schedule).
-- and links a live `karaoke_sessions` row back to the venue + event it
-- was started for.
--
-- The "account" is the existing tenant (one isolated DB). The single
-- live-session-per-account rule is unchanged: each session simply gains
-- an optional venue/event tag. Both columns are nullable so legacy and
-- ad-hoc sessions keep working.
--
-- Once-only: idempotency is enforced by the schema_migrations ledger in
-- scripts/migrate.php. Re-running is not safe (ADD COLUMN/CREATE would
-- fail on a second pass); the runner skips already-applied filenames.

CREATE TABLE IF NOT EXISTS venues (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NULL,
  address_line1 VARCHAR(255) NULL,
  address_line2 VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  region VARCHAR(120) NULL,
  postal_code VARCHAR(40) NULL,
  country VARCHAR(80) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  timezone VARCHAR(80) NULL,
  default_night_name VARCHAR(180) NULL,
  notes VARCHAR(1000) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_venues_active (is_active),
  UNIQUE KEY uniq_venues_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS show_schedules (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  venue_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  recurrence_type ENUM('weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  -- 0=Sunday .. 6=Saturday (matches PHP's `w` date format).
  weekday TINYINT NOT NULL,
  -- Monthly only: 1..5 = first..fifth occurrence, -1 = last occurrence.
  week_of_month TINYINT NULL,
  start_time TIME NOT NULL,
  duration_minutes INT UNSIGNED NULL,
  -- Phase reference for biweekly cadence (which week is "on").
  anchor_date DATE NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedules_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
  INDEX idx_schedules_venue (venue_id),
  INDEX idx_schedules_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  venue_id BIGINT UNSIGNED NOT NULL,
  schedule_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  scheduled_for DATETIME NOT NULL,
  status ENUM('scheduled','live','closed','canceled') NOT NULL DEFAULT 'scheduled',
  session_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_events_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_schedule FOREIGN KEY (schedule_id) REFERENCES show_schedules(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  -- Idempotent materialization: one row per (schedule, occurrence). One-off
  -- events carry schedule_id = NULL; MySQL allows multiple NULLs here so
  -- they never collide.
  UNIQUE KEY uniq_event_occurrence (schedule_id, scheduled_for),
  INDEX idx_events_scheduled (scheduled_for),
  INDEX idx_events_venue (venue_id),
  INDEX idx_events_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE karaoke_sessions
  ADD COLUMN venue_id BIGINT UNSIGNED NULL AFTER name,
  ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER venue_id,
  ADD CONSTRAINT fk_sessions_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_sessions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL;

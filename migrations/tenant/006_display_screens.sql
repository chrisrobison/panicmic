-- Phase 5.1: per-screen display state for multi-monitor support.
-- display_state used to be one row per session; now it's one row per
-- (session, screen) so a KJ can drive a main projector, a lyrics TV,
-- and a lobby monitor independently from one dashboard.

-- Drop the FK that depends on the unique index, then drop the unique,
-- then add the screen column with the composite unique, then restore
-- the FK.
ALTER TABLE display_state DROP FOREIGN KEY fk_display_session;
ALTER TABLE display_state DROP INDEX session_id;

ALTER TABLE display_state
  ADD COLUMN screen VARCHAR(32) NOT NULL DEFAULT 'main' AFTER session_id;

ALTER TABLE display_state
  ADD UNIQUE KEY uniq_display_state_session_screen (session_id, screen);

ALTER TABLE display_state
  ADD CONSTRAINT fk_display_session FOREIGN KEY (session_id)
  REFERENCES karaoke_sessions(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS display_screens (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  screen VARCHAR(32) NOT NULL,
  label VARCHAR(120) NOT NULL,
  layout ENUM('main','lyrics','lobby','stage','custom') NOT NULL DEFAULT 'main',
  default_volume TINYINT UNSIGNED NOT NULL DEFAULT 80,
  show_qr TINYINT(1) NOT NULL DEFAULT 1,
  show_queue TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_display_screens_session_screen (session_id, screen),
  CONSTRAINT fk_display_screens_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

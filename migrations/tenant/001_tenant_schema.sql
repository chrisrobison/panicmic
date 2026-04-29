CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  display_name VARCHAR(160) NOT NULL,
  role ENUM('singer','kj','tenant_admin') NOT NULL DEFAULT 'singer',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS singers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_singers_display_name (display_name),
  CONSTRAINT fk_singers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS songs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  artist VARCHAR(255) NOT NULL,
  genre VARCHAR(120) NULL,
  decade SMALLINT NULL,
  popularity INT NOT NULL DEFAULT 0,
  external_id VARCHAR(120) NULL,
  video_url VARCHAR(512) NULL,
  video_provider VARCHAR(80) NULL,
  provider_track_id VARCHAR(160) NULL,
  provider_url VARCHAR(512) NULL,
  lyrics_url VARCHAR(512) NULL,
  provider_metadata JSON NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_songs_title_artist (title, artist),
  UNIQUE KEY uniq_song_title_artist (title, artist),
  INDEX idx_songs_artist (artist),
  INDEX idx_songs_genre (genre),
  INDEX idx_songs_decade (decade),
  INDEX idx_songs_popularity (popularity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS song_artists (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  song_id BIGINT UNSIGNED NOT NULL,
  artist_name VARCHAR(255) NOT NULL,
  role ENUM('primary','featured','composer') NOT NULL DEFAULT 'primary',
  CONSTRAINT fk_song_artists_song FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE,
  INDEX idx_song_artists_name (artist_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS karaoke_sessions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(180) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  status ENUM('scheduled','active','paused','archived') NOT NULL DEFAULT 'scheduled',
  requests_paused BOOLEAN NOT NULL DEFAULT FALSE,
  queue_locked BOOLEAN NOT NULL DEFAULT FALSE,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_sessions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS song_requests (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  singer_id BIGINT UNSIGNED NOT NULL,
  song_id BIGINT UNSIGNED NOT NULL,
  party_type ENUM('solo','duet','group') NOT NULL DEFAULT 'solo',
  notes VARCHAR(500) NULL,
  status ENUM('pending','up_next','now_singing','completed','skipped','canceled') NOT NULL DEFAULT 'pending',
  requester_token CHAR(64) NULL,
  youtube_video_id VARCHAR(32) NULL,
  youtube_title VARCHAR(255) NULL,
  youtube_channel_title VARCHAR(255) NULL,
  youtube_url VARCHAR(512) NULL,
  youtube_matched_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_requests_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_requests_singer FOREIGN KEY (singer_id) REFERENCES singers(id) ON DELETE CASCADE,
  CONSTRAINT fk_requests_song FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE RESTRICT,
  INDEX idx_requests_session_status (session_id, status),
  INDEX idx_requests_requester_token (requester_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_items (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  request_id BIGINT UNSIGNED NOT NULL UNIQUE,
  position INT NOT NULL,
  status ENUM('pending','up_next','now_singing','completed','skipped','canceled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_queue_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_request FOREIGN KEY (request_id) REFERENCES song_requests(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_session_position (session_id, position),
  INDEX idx_queue_session_status (session_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(500) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  show_until DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_announcements_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcements_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS display_state (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL UNIQUE,
  mode ENUM('idle','queue','now_singing','clean_stage','announcement') NOT NULL DEFAULT 'idle',
  now_request_id BIGINT UNSIGNED NULL,
  announcement_id BIGINT UNSIGNED NULL,
  sponsor_slide_url VARCHAR(512) NULL,
  tip_qr_url VARCHAR(512) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_display_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_display_request FOREIGN KEY (now_request_id) REFERENCES song_requests(id) ON DELETE SET NULL,
  CONSTRAINT fk_display_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE SET NULL,
  CONSTRAINT fk_display_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(120) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL UNIQUE,
  setting_value JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments_tips (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NULL,
  singer_id BIGINT UNSIGNED NULL,
  provider VARCHAR(80) NOT NULL,
  amount_cents INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  external_reference VARCHAR(255) NULL,
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tips_session FOREIGN KEY (session_id) REFERENCES karaoke_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tips_singer FOREIGN KEY (singer_id) REFERENCES singers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  event_name VARCHAR(120) NOT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_realtime_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 009_curated_catalog_imports.sql
--
-- Adds the curated-import / discovery metadata layer to the shared catalog.
--
-- Philosophy:
--   shared_songs stays canonical. These tables explain *where* each song
--   came from, which lists it appeared on, and how useful it is for
--   karaoke browsing. Nothing here replaces shared_songs; everything
--   either annotates it or stages raw import data.
--
-- Safe: every ALTER TABLE uses ADD COLUMN ... NULL or ADD COLUMN ... DEFAULT,
-- so existing rows are unaffected. Tables are CREATE TABLE IF NOT EXISTS.

-- -----------------------------------------------------------------------
-- 1. Extend shared_songs with discovery metadata
-- -----------------------------------------------------------------------

ALTER TABLE shared_songs
  ADD COLUMN IF NOT EXISTS normalized_title VARCHAR(255) NULL AFTER title,
  ADD COLUMN IF NOT EXISTS normalized_artist VARCHAR(255) NULL AFTER artist,
  ADD COLUMN IF NOT EXISTS release_year SMALLINT NULL AFTER year,
  ADD COLUMN IF NOT EXISTS primary_genre VARCHAR(120) NULL AFTER genre,
  ADD COLUMN IF NOT EXISTS secondary_genres JSON NULL AFTER primary_genre,
  ADD COLUMN IF NOT EXISTS karaoke_difficulty TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS singalong_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS nostalgia_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS crowd_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS source_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS karaoke_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS discovery_tags JSON NULL,
  ADD COLUMN IF NOT EXISTS curator_notes TEXT NULL;

-- Indexes may already exist — ignore errors from duplicate key names by
-- using IF NOT EXISTS syntax where supported, or wrapping in a procedure.
-- MariaDB 10.5+ supports ADD INDEX IF NOT EXISTS; MySQL 8.0 does not.
-- Use a safe fallback: only add if the index is missing.

SET @exists_karaoke = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'shared_songs'
    AND index_name = 'idx_shared_karaoke_score'
);
SET @sql_karaoke = IF(@exists_karaoke = 0,
  'ALTER TABLE shared_songs ADD INDEX idx_shared_karaoke_score (karaoke_score)',
  'SELECT 1');
PREPARE stmt FROM @sql_karaoke;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists_source = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'shared_songs'
    AND index_name = 'idx_shared_source_score'
);
SET @sql_source = IF(@exists_source = 0,
  'ALTER TABLE shared_songs ADD INDEX idx_shared_source_score (source_score)',
  'SELECT 1');
PREPARE stmt FROM @sql_source;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists_norm = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'shared_songs'
    AND index_name = 'idx_shared_normalized'
);
SET @sql_norm = IF(@exists_norm = 0,
  'ALTER TABLE shared_songs ADD INDEX idx_shared_normalized (normalized_artist, normalized_title)',
  'SELECT 1');
PREPARE stmt FROM @sql_norm;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------
-- 2. shared_song_sources — source definitions
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  source_type ENUM('radio_countdown','national_chart','api','manual','playlist','editorial') NOT NULL DEFAULT 'manual',
  station VARCHAR(80) NULL,
  market VARCHAR(120) NULL,
  url VARCHAR(512) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_source_slug (slug),
  INDEX idx_source_type (source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 3. shared_song_source_lists — one imported list per row
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_source_lists (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  year SMALLINT NULL,
  decade SMALLINT NULL,
  genre_hint VARCHAR(120) NULL,
  list_type ENUM('year_end','all_time','genre','decade','station_special','manual') NOT NULL DEFAULT 'manual',
  url VARCHAR(512) NULL,
  fetched_at TIMESTAMP NULL DEFAULT NULL,
  raw_cache_path VARCHAR(512) NULL,
  parser_version VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_list_slug (slug),
  INDEX idx_list_source (source_id),
  INDEX idx_list_year (year),
  INDEX idx_list_decade (decade),
  CONSTRAINT fk_ssl_source FOREIGN KEY (source_id) REFERENCES shared_song_sources (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 4. shared_song_candidates — raw import rows before/during matching
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_candidates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_list_id INT UNSIGNED NOT NULL,
  shared_song_id BIGINT UNSIGNED NULL,
  artist VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  normalized_artist VARCHAR(255) NULL,
  normalized_title VARCHAR(255) NULL,
  rank SMALLINT UNSIGNED NULL,
  year SMALLINT NULL,
  decade SMALLINT NULL,
  genre_hint VARCHAR(120) NULL,
  raw_row_json JSON NULL,
  confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  match_status ENUM('matched','created','possible_duplicate','needs_review','ignored') NOT NULL DEFAULT 'needs_review',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cand_list (source_list_id),
  INDEX idx_cand_song (shared_song_id),
  INDEX idx_cand_status (match_status),
  INDEX idx_cand_normalized (normalized_artist, normalized_title),
  CONSTRAINT fk_cand_list FOREIGN KEY (source_list_id) REFERENCES shared_song_source_lists (id) ON DELETE CASCADE,
  CONSTRAINT fk_cand_song FOREIGN KEY (shared_song_id) REFERENCES shared_songs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 5. shared_song_source_links — many-to-many source appearances
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_source_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shared_song_id BIGINT UNSIGNED NOT NULL,
  candidate_id BIGINT UNSIGNED NULL,
  source_id INT UNSIGNED NOT NULL,
  source_list_id INT UNSIGNED NOT NULL,
  rank SMALLINT UNSIGNED NULL,
  year SMALLINT NULL,
  decade SMALLINT NULL,
  source_weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_link (shared_song_id, source_list_id),
  INDEX idx_link_song (shared_song_id),
  INDEX idx_link_source (source_id),
  INDEX idx_link_list (source_list_id),
  CONSTRAINT fk_link_song FOREIGN KEY (shared_song_id) REFERENCES shared_songs (id) ON DELETE CASCADE,
  CONSTRAINT fk_link_source FOREIGN KEY (source_id) REFERENCES shared_song_sources (id) ON DELETE RESTRICT,
  CONSTRAINT fk_link_list FOREIGN KEY (source_list_id) REFERENCES shared_song_source_lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 6. shared_song_tags — controlled vocabulary
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_tags (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  tag_type ENUM('genre','mood','difficulty','era','occasion','voice','local','editorial') NOT NULL DEFAULT 'editorial',
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tag_slug (slug),
  INDEX idx_tag_type (tag_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 7. shared_song_tag_links — many-to-many tags for shared songs
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_tag_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shared_song_id BIGINT UNSIGNED NOT NULL,
  tag_id SMALLINT UNSIGNED NOT NULL,
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 100,
  source ENUM('rule','manual','lastfm','import','admin') NOT NULL DEFAULT 'rule',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_song_tag (shared_song_id, tag_id),
  INDEX idx_tl_song (shared_song_id),
  INDEX idx_tl_tag (tag_id),
  CONSTRAINT fk_tl_song FOREIGN KEY (shared_song_id) REFERENCES shared_songs (id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_tag FOREIGN KEY (tag_id) REFERENCES shared_song_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 8. shared_song_import_runs — tracks import runs
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_import_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_slug VARCHAR(120) NOT NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  total_seen INT UNSIGNED NOT NULL DEFAULT 0,
  total_imported INT UNSIGNED NOT NULL DEFAULT 0,
  total_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  total_created INT UNSIGNED NOT NULL DEFAULT 0,
  total_matched INT UNSIGNED NOT NULL DEFAULT 0,
  total_needs_review INT UNSIGNED NOT NULL DEFAULT 0,
  report_path VARCHAR(512) NULL,
  error_message TEXT NULL,
  INDEX idx_run_slug (source_slug),
  INDEX idx_run_status (status),
  INDEX idx_run_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 9. shared_song_import_warnings
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shared_song_import_warnings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_run_id INT UNSIGNED NOT NULL,
  source_list_id INT UNSIGNED NULL,
  candidate_id BIGINT UNSIGNED NULL,
  warning_type VARCHAR(80) NOT NULL,
  message TEXT NOT NULL,
  raw_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_warn_run (import_run_id),
  CONSTRAINT fk_warn_run FOREIGN KEY (import_run_id) REFERENCES shared_song_import_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 10. Seed controlled tag vocabulary
-- -----------------------------------------------------------------------

INSERT IGNORE INTO shared_song_tags (name, slug, tag_type, description) VALUES
  -- Genre
  ('Pop',          'pop',          'genre', 'Pop music'),
  ('Rock',         'rock',         'genre', 'Rock music'),
  ('Alternative',  'alternative',  'genre', 'Alternative rock'),
  ('New Wave',     'new-wave',     'genre', '80s New Wave'),
  ('Punk',         'punk',         'genre', 'Punk rock'),
  ('Post-Punk',    'post-punk',    'genre', 'Post-punk'),
  ('Metal',        'metal',        'genre', 'Heavy metal'),
  ('Classic Rock', 'classic-rock', 'genre', 'Classic rock'),
  ('Grunge',       'grunge',       'genre', '90s Grunge'),
  ('Indie',        'indie',        'genre', 'Indie rock/pop'),
  ('Hip-Hop',      'hip-hop',      'genre', 'Hip-hop'),
  ('Rap',          'rap',          'genre', 'Rap'),
  ('R&B',          'rnb',          'genre', 'R&B'),
  ('Soul',         'soul',         'genre', 'Soul'),
  ('Funk',         'funk',         'genre', 'Funk'),
  ('Country',      'country',      'genre', 'Country'),
  ('Folk',         'folk',         'genre', 'Folk'),
  ('Reggae',       'reggae',       'genre', 'Reggae'),
  ('Ska',          'ska',          'genre', 'Ska'),
  ('Latin',        'latin',        'genre', 'Latin'),
  ('Dance',        'dance',        'genre', 'Dance'),
  ('Disco',        'disco',        'genre', 'Disco'),
  ('Electronic',   'electronic',   'genre', 'Electronic / synth'),
  ('Jazz',         'jazz',         'genre', 'Jazz'),
  ('Blues',        'blues',        'genre', 'Blues'),
  ('Showtunes',    'showtunes',    'genre', 'Broadway / musical theatre'),
  ('Soundtrack',   'soundtrack',   'genre', 'Film & TV soundtrack'),
  -- Era
  ('1970s',        '1970s',        'era',  '1970s'),
  ('1980s',        '1980s',        'era',  '1980s'),
  ('1990s',        '1990s',        'era',  '1990s'),
  ('2000s',        '2000s',        'era',  '2000s'),
  ('2010s',        '2010s',        'era',  '2010s'),
  ('2020s',        '2020s',        'era',  '2020s'),
  -- Karaoke/occasion
  ('Beginner Friendly',     'beginner-friendly',      'difficulty', 'Good for first-time or nervous singers'),
  ('Crowd Favorite',        'crowd-favorite',         'occasion',   'Crowd always goes wild'),
  ('Power Ballad',          'power-ballad',           'mood',       'Big emotional ballad'),
  ('Guilty Pleasure',       'guilty-pleasure',        'mood',       'Embarrassingly fun'),
  ('Duet',                  'duet',                   'occasion',   'Written for two voices'),
  ('Group Song',            'group-song',             'occasion',   'Great for a whole group'),
  ('Big Chorus',            'big-chorus',             'occasion',   'Huge singalong chorus'),
  ('Easy Chorus',           'easy-chorus',            'difficulty', 'Simple chorus anyone can join'),
  ('Hard Vocals',           'hard-vocals',            'difficulty', 'Technically demanding'),
  ('Fast Rap',              'fast-rap',               'difficulty', 'Rapid-fire rap sections'),
  ('Explicit Lyrics',       'explicit-lyrics',        'occasion',   'Contains explicit content'),
  ('Under 4 Minutes',       'under-4-minutes',        'occasion',   'Short enough to squeeze in'),
  ('Long Song',             'long-song',              'occasion',   'Over 5 minutes'),
  ('Low Voice Friendly',    'low-voice-friendly',     'voice',      'Comfortable for lower voices'),
  ('High Voice Friendly',   'high-voice-friendly',    'voice',      'Comfortable for higher voices'),
  ('Bar Singalong',         'bar-singalong',          'occasion',   'Classic bar singalong'),
  ('Last Call',             'last-call',              'occasion',   'Perfect for end of night'),
  ('Dance Floor',           'dance-floor',            'occasion',   'Gets people moving'),
  ('Breakup Song',          'breakup-song',           'mood',       'Breakup / heartbreak'),
  ('Love Song',             'love-song',              'mood',       'Romantic'),
  ('Angry Song',            'angry-song',             'mood',       'Cathartic rage'),
  ('Sad Banger',            'sad-banger',             'mood',       'Upbeat melody, sad lyrics'),
  ('Funny Song',            'funny-song',             'mood',       'Novelty or comedic'),
  ('Wedding-ish',           'wedding-ish',            'occasion',   'Suitable for wedding party'),
  ('Dangerous But Glorious','dangerous-but-glorious', 'difficulty', 'Very hard but worth attempting'),
  -- Local/editorial
  ('Mabuhay Classic',           'mabuhay-classic',           'local',     'Classic from the Mabuhay Gardens era'),
  ('Live 105 Classic',          'live105-classic',           'local',     'Live 105 / KITS San Francisco'),
  ('Bay Area Nostalgia',        'bay-area-nostalgia',        'local',     'Beloved Bay Area nostalgia'),
  ('Punk-ish Singalong',        'punk-ish-singalong',        'editorial', 'Punk or punk-adjacent singalong'),
  ('Songs You Forgot You Know', 'songs-you-forgot-you-know', 'editorial', 'Oh wait, I know this one!'),
  ('Songs Everyone Knows',      'songs-everyone-knows',      'editorial', 'Universal cultural touchstone'),
  ('Deep Cut',                  'deep-cut',                  'editorial', 'Obscure gem for real fans'),
  ('KJ Panic Pick',             'kj-panic-pick',             'editorial', 'KJ curated emergency pick');

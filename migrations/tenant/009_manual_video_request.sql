-- KJ-entered external video link for a song request (non-YouTube sources).
-- Idempotent: only adds columns that are not already present.

SET @db_name = DATABASE();

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'manual_video_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN manual_video_url VARCHAR(512) NULL AFTER youtube_matched_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'manual_video_attached_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN manual_video_attached_at DATETIME NULL AFTER manual_video_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

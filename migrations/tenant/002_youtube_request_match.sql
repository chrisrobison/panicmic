SET @db_name = DATABASE();

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'youtube_video_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN youtube_video_id VARCHAR(32) NULL AFTER requester_token', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'youtube_title'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN youtube_title VARCHAR(255) NULL AFTER youtube_video_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'youtube_channel_title'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN youtube_channel_title VARCHAR(255) NULL AFTER youtube_title', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'youtube_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN youtube_url VARCHAR(512) NULL AFTER youtube_channel_title', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'youtube_matched_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE song_requests ADD COLUMN youtube_matched_at DATETIME NULL AFTER youtube_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

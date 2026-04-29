SET @db_name = DATABASE();

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'video_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN video_url VARCHAR(512) NULL AFTER external_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'video_provider'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN video_provider VARCHAR(80) NULL AFTER video_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'provider_track_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN provider_track_id VARCHAR(160) NULL AFTER video_provider', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'provider_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN provider_url VARCHAR(512) NULL AFTER provider_track_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'lyrics_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN lyrics_url VARCHAR(512) NULL AFTER provider_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'songs' AND COLUMN_NAME = 'provider_metadata'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE songs ADD COLUMN provider_metadata JSON NULL AFTER lyrics_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

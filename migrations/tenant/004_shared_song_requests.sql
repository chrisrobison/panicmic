SET @db_name = DATABASE();

SET @col_nullable = (
  SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'song_id'
);
SET @sql = IF(@col_nullable = 'NO',
              'ALTER TABLE song_requests MODIFY song_id BIGINT UNSIGNED NULL',
              'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND COLUMN_NAME = 'shared_song_id'
);
SET @sql = IF(@col_exists = 0,
              'ALTER TABLE song_requests ADD COLUMN shared_song_id BIGINT UNSIGNED NULL AFTER song_id',
              'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'song_requests' AND INDEX_NAME = 'idx_requests_shared_song'
);
SET @sql = IF(@idx_exists = 0,
              'ALTER TABLE song_requests ADD INDEX idx_requests_shared_song (shared_song_id)',
              'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

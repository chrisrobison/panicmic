SET @db_name = DATABASE();

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'profile_image_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tenants ADD COLUMN profile_image_url VARCHAR(512) NULL AFTER logo_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'background_image_url'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tenants ADD COLUMN background_image_url VARCHAR(512) NULL AFTER profile_image_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'background_color'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tenants ADD COLUMN background_color VARCHAR(24) NOT NULL DEFAULT ''#101216'' AFTER background_image_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'surface_color'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tenants ADD COLUMN surface_color VARCHAR(24) NOT NULL DEFAULT ''#191d24'' AFTER background_color', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'text_color'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tenants ADD COLUMN text_color VARCHAR(24) NOT NULL DEFAULT ''#f5f7fb'' AFTER surface_color', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

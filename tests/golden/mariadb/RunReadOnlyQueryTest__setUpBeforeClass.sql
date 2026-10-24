SELECT 1
-- params: []
-- ----------------------------------------
DROP TABLE IF EXISTS `CounterRows`
-- params: []
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `CounterRows`(
	`businessKey` VARCHAR(255) DEFAULT NULL,
	`counter` INT DEFAULT NULL,
	`keptValue` INT DEFAULT NULL,
	`label` VARCHAR(255) DEFAULT NULL,
	`amount` INT DEFAULT NULL,
	`ratio` DOUBLE DEFAULT NULL,
	`isActive` BOOLEAN DEFAULT 1,
	`dueOn` DATE DEFAULT NULL,
	`occurredAt` DATETIME DEFAULT NULL,
	`displayOrder` INT DEFAULT 0,
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`))
ENGINE=InnoDB
COLLATE=utf8mb4_unicode_ci
-- params: []
-- ----------------------------------------
ALTER TABLE `CounterRows`
	ADD COLUMN IF NOT EXISTS `businessKey` VARCHAR(255) DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `counter` INT DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `keptValue` INT DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `label` VARCHAR(255) DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `amount` INT DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `ratio` DOUBLE DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `isActive` BOOLEAN DEFAULT 1,
	ADD COLUMN IF NOT EXISTS `dueOn` DATE DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `occurredAt` DATETIME DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `displayOrder` INT DEFAULT 0,
	ADD COLUMN IF NOT EXISTS `id` INT UNSIGNED NOT NULL AUTO_INCREMENT
-- params: []
-- ----------------------------------------
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_businessKey` ON `CounterRows` (`businessKey`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_counter` ON `CounterRows` (`counter`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_keptValue` ON `CounterRows` (`keptValue`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_label` ON `CounterRows` (`label`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_amount` ON `CounterRows` (`amount`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_ratio` ON `CounterRows` (`ratio`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_isActive` ON `CounterRows` (`isActive`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_dueOn` ON `CounterRows` (`dueOn`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_occurredAt` ON `CounterRows` (`occurredAt`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_displayOrder` ON `CounterRows` (`displayOrder`)
-- params: []
-- ----------------------------------------
DROP TABLE IF EXISTS `TranslatedRows`
-- params: []
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `TranslatedRows`(
	`name` JSON DEFAULT NULL,
	`title` JSON DEFAULT NULL,
	`settings` JSON DEFAULT NULL,
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`))
ENGINE=InnoDB
COLLATE=utf8mb4_unicode_ci
-- params: []
-- ----------------------------------------
ALTER TABLE `TranslatedRows`
	ADD COLUMN IF NOT EXISTS `name` JSON DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `title` JSON DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `settings` JSON DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	ADD COLUMN IF NOT EXISTS `virtualNameSearch` TEXT GENERATED ALWAYS AS (
  CASE
    WHEN name IS NULL OR JSON_VALID(name) = 0 THEN ''
    ELSE REGEXP_REPLACE(
      TRIM(
        BOTH ' '
        FROM REGEXP_REPLACE(
          REGEXP_REPLACE(JSON_UNQUOTE(name), '^\\{\\s*|\\s*\\}\\s*$', ''),
          '"[^"]+"\\s*:\\s*"([^"]*)"\\s*(,\\s*)?',
          '\\1 | '
        )
      ),
      '\\s*\\|\\s*$', ''
    )
  END
) STORED
-- params: []
-- ----------------------------------------
CREATE FULLTEXT INDEX IF NOT EXISTS `ft_virtualNameSearch` ON `TranslatedRows` (`virtualNameSearch`)
-- params: []
-- ----------------------------------------
DROP TABLE IF EXISTS `SpatialRows`
-- params: []
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `SpatialRows`(
	`label` VARCHAR(255) DEFAULT NULL,
	`location` POINT DEFAULT NULL,
	`embedding` VECTOR(3) NOT NULL DEFAULT (VEC_FromText(CONCAT('[', REPEAT('0,', 3-1), '0]'))),
	`flag` BOOLEAN DEFAULT 0,
	`payload` JSON DEFAULT NULL,
	`amount` INT DEFAULT NULL,
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	PRIMARY KEY (`id`))
ENGINE=InnoDB
COLLATE=utf8mb4_unicode_ci
-- params: []
-- ----------------------------------------
ALTER TABLE `SpatialRows`
	ADD COLUMN IF NOT EXISTS `label` VARCHAR(255) DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `location` POINT DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `embedding` VECTOR(3) NOT NULL DEFAULT (VEC_FromText(CONCAT('[', REPEAT('0,', 3-1), '0]'))),
	ADD COLUMN IF NOT EXISTS `flag` BOOLEAN DEFAULT 0,
	ADD COLUMN IF NOT EXISTS `payload` JSON DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `amount` INT DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `id` INT UNSIGNED NOT NULL AUTO_INCREMENT
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_label` ON `SpatialRows` (`label`)
-- params: []
-- ----------------------------------------
CREATE VECTOR INDEX IF NOT EXISTS `vx_embedding` ON `SpatialRows` (`embedding`) DISTANCE=cosine M=8
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_flag` ON `SpatialRows` (`flag`)
-- params: []
-- ----------------------------------------
CREATE INDEX IF NOT EXISTS `idx_amount` ON `SpatialRows` (`amount`)
-- params: []
-- ----------------------------------------

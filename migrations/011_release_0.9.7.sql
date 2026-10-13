-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.7
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 010_release_0.9.6.sql to have run.
--
--  Everything since 0.9.6 in one file — prices no longer name
--  PriceCharting as their source:
--  · games.pc_link → games.price_link (the link to a game's price page)
--  · games.pc_id → games.external_id (the game's id at the price
--    source), index idx_pc_id → idx_external_id
--  · activity log entries of the price import and the game form moved
--    to the new action / field names
--
--  The Database export settings live in app_settings (no table change).
--  Every step is safe to run twice (test sites already ran the
--  development files dev/011 and dev/012).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;


-- ── Price link (was dev/011_price_link.sql) ──

SET @gc_ren := (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'pc_link')
           AND (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'price_link');
SET @gc_sql := IF(@gc_ren,
  'ALTER TABLE `games` CHANGE COLUMN `pc_link` `price_link` varchar(255) DEFAULT NULL',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Price source id (was dev/012_external_id.sql) ──

SET @gc_ren := (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'pc_id')
           AND (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'external_id');
SET @gc_sql := IF(@gc_ren,
  'ALTER TABLE `games` CHANGE COLUMN `pc_id` `external_id` varchar(20) DEFAULT NULL',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

-- The index keeps its old name after the column rename: drop it and add it under the new one
SET @gc_drop := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND INDEX_NAME = 'idx_pc_id');
SET @gc_sql := IF(@gc_drop, 'ALTER TABLE `games` DROP INDEX `idx_pc_id`', 'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND INDEX_NAME = 'idx_external_id');
SET @gc_sql := IF(@gc_add, 'ALTER TABLE `games` ADD KEY `idx_external_id` (`external_id`)', 'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

-- Activity log: the import's action and the game form's field names
UPDATE `activity_log` SET `action` = 'prices.import' WHERE `action` = 'pc.import';
UPDATE `activity_log` SET `data` = REPLACE(REPLACE(`data`, '"pc_id"', '"external_id"'), '"pc_link"', '"price_link"')
WHERE `data` LIKE '%"pc\_id"%' OR `data` LIKE '%"pc\_link"%';

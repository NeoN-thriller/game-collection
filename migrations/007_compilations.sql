-- ═══════════════════════════════════════════════════════════════════
--  Migration: compilations
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 006_condition_report.sql to have run.
--
--  A compilation is a catalogue game that contains other games of the
--  same system (e.g. "Dishonored Prey: The Arkane Collection" contains
--  Dishonored [Definitive Edition] and Prey). Unlike an edition group, a
--  game can be in any number of compilations.
--
--  compilation_items: what each compilation contains (admin-managed).
--  games.comp_dismissed: the admin said "not a compilation"; the
--  compilation suggestions leave the game out.
--  users.compilation_mode: own = a compilation counts as its own game
--  (as before); contents = the games inside count as owned and the
--  compilation itself is left out of the totals.
--
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `compilation_items` (
  `compilation_id` int(10) unsigned NOT NULL,
  `game_id` int(10) unsigned NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`compilation_id`,`game_id`),
  KEY `game_id` (`game_id`),
  CONSTRAINT `compilation_items_comp_fk` FOREIGN KEY (`compilation_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compilation_items_game_fk` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- games.comp_dismissed (skipped when the column is already there)
SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'comp_dismissed');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `games` ADD COLUMN `comp_dismissed` tinyint(1) NOT NULL DEFAULT 0 AFTER `edition_sort`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

-- users.compilation_mode (skipped when the column is already there)
SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'compilation_mode');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `compilation_mode` enum(''own'',''contents'') NOT NULL DEFAULT ''own'' AFTER `label_template_id`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

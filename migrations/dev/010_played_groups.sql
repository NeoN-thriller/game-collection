-- ═══════════════════════════════════════════════════════════════════
--  Migration: played status groups
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 009_defect_info.sql to have run.
--
--  user_played_options.play_group: how a played status counts in the
--  Collection page's Finished / Started counters: finished (e.g. Finished,
--  Cheated, 100%), started (e.g. Started, Stuck) or none. NULL = not chosen
--  yet: the app guesses from the name until the user saves their options
--  (Settings › Completeness, Played & Tags).
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_played_options' AND COLUMN_NAME = 'play_group');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `user_played_options` ADD COLUMN `play_group` enum(''finished'',''started'',''none'') DEFAULT NULL AFTER `label`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

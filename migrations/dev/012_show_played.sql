-- ═══════════════════════════════════════════════════════════════════
--  Migration: show or hide the played counters
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 011_played_percentage.sql to have run.
--
--  users.show_played: 1 = show the Finished / Started counters on the
--  Collection page and the dashboard (default), 0 = hide them.
--  Set under Settings › Completeness, Played & Tags.
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'show_played');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `show_played` tinyint(1) NOT NULL DEFAULT 1 AFTER `played_pct`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

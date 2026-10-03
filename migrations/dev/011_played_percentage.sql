-- ═══════════════════════════════════════════════════════════════════
--  Migration: played percentage base
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 010_played_groups.sql to have run.
--
--  users.played_pct: what the Collection page's Finished / Started
--  counters count against: all = every game of the system (owned or not),
--  owned = only the games you own. Set under Settings › Completeness,
--  Played & Tags.
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'played_pct');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `played_pct` enum(''all'',''owned'') NOT NULL DEFAULT ''all'' AFTER `compilation_mode`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

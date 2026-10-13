-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.2
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 006_condition_report.sql to have run.
--
--  Everything since 0.9.1 in one file:
--  · compilation_items, games.comp_dismissed, users.compilation_mode:
--    compilations (games that contain other games) and how they count
--  · copy_photo_tags, copy_photo_tag_defects: which part unit and which
--    of its defects a copy's photo shows (shared condition reports)
--  · grade_defects.description, grade_defect_photos: the explanation and
--    example photos of a defect (admin), plus starter texts via
--    Settings › Grading System › "Fill empty explanations"
--  · user_played_options.play_group, users.played_pct, users.show_played:
--    the Completed / Started counters on the Collection page and dashboard
--
--  Every step checks first, so it is safe on a database that already has
--  some or all of it (e.g. a test site that ran the separate files).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Compilations (was 007_compilations.sql) ──

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


-- ── Photo tags (which part, and which of its defects, a photo shows) (was dev/008_photo_tags.sql) ──

CREATE TABLE IF NOT EXISTS `copy_photo_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `photo_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `part_ref` varchar(160) NOT NULL DEFAULT '',
  `unit_no` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_photo` (`photo_id`),
  KEY `idx_entry` (`entry_id`),
  CONSTRAINT `copy_photo_tags_photo_fk` FOREIGN KEY (`photo_id`) REFERENCES `copy_photos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copy_photo_tags_entry_fk` FOREIGN KEY (`entry_id`) REFERENCES `collection_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `copy_photo_tag_defects` (
  `tag_id` int(10) unsigned NOT NULL,
  `defect_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`tag_id`,`defect_id`),
  KEY `idx_defect` (`defect_id`),
  CONSTRAINT `copy_photo_tag_defects_tag_fk` FOREIGN KEY (`tag_id`) REFERENCES `copy_photo_tags` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copy_photo_tag_defects_defect_fk` FOREIGN KEY (`defect_id`) REFERENCES `grade_defects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Defect explanations (description + example photos) (was dev/009_defect_info.sql) ──

-- grade_defects.description (skipped when the column is already there)
SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_defects' AND COLUMN_NAME = 'description');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `grade_defects` ADD COLUMN `description` varchar(500) DEFAULT NULL AFTER `level_group`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

CREATE TABLE IF NOT EXISTS `grade_defect_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `defect_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `defect_id` (`defect_id`),
  CONSTRAINT `grade_defect_photos_defect_fk` FOREIGN KEY (`defect_id`) REFERENCES `grade_defects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Played status groups (Completed / Started counters) (was dev/010_played_groups.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_played_options' AND COLUMN_NAME = 'play_group');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `user_played_options` ADD COLUMN `play_group` enum(''finished'',''started'',''none'') DEFAULT NULL AFTER `label`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Played percentage base (all games or owned games) (was dev/011_played_percentage.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'played_pct');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `played_pct` enum(''all'',''owned'') NOT NULL DEFAULT ''all'' AFTER `compilation_mode`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Show or hide the played counters (was dev/012_show_played.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'show_played');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `show_played` tinyint(1) NOT NULL DEFAULT 1 AFTER `played_pct`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

-- ═══════════════════════════════════════════════════════════════════
--  Migration: defect explanations (description + example photos)
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 008_photo_tags.sql to have run.
--
--  grade_defects.description: what the defect means, shown as an ⓘ
--  next to the defect while grading and on the shared condition report.
--  grade_defect_photos: up to 3 example photos per defect (files in
--  uploads/defects/). Both are managed by the admin under
--  Settings › Grading System › Templates.
--
--  Starter descriptions for the default defects: Settings › Grading
--  System › "Fill empty descriptions" (in the site's language).
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

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

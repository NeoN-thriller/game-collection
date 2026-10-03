-- ═══════════════════════════════════════════════════════════════════
--  Migration: photo tags (which part, and which of its defects, a photo shows)
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 007_compilations.sql to have run.
--
--  copy_photo_tags: at most one tag per photo. part_ref is a stable key
--  that survives re-saving the grading ('c<profile component id>', or
--  'o:<own item name>' with '~2', '~3' for repeated names; '' = general /
--  overview), unit_no is the part's unit (1-based).
--  copy_photo_tag_defects: the defects (grade_defects.id) the photo shows.
--
--  Nothing to convert: every existing photo starts untagged.
--  Safe on a database where this was already run by hand.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

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

-- ═══════════════════════════════════════════════════════════════════
--  Migration: point grading (condition labels, format profiles,
--  component templates, per-copy grading data)
--
--  Run ONCE on an existing database (MySQL 8+ / MariaDB 10.5+).
--  Back up the database first.
--
--  This file only changes the schema. The default data (grade labels,
--  templates, profiles, system → profile defaults) and the conversion of
--  the old `quality` values are done by the app itself, from
--  assets/grading-defaults.json, the first time any page is opened after
--  this migration. Nothing to click.
--
--  The old `collection_entries.quality` column is kept (read-only, no
--  longer written). Once you've checked everything carried over, you can
--  drop it with the statement at the bottom of this file.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Admin-managed, global ─────────────────────────────

CREATE TABLE IF NOT EXISTS `app_settings` (
  `name`  varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grade_labels` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(50) NOT NULL,
  `short`      varchar(6) NOT NULL DEFAULT '',
  `min_score`  tinyint(3) unsigned NOT NULL DEFAULT 0,
  `color`      char(7) NOT NULL DEFAULT '#b0a898',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grade_templates` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grade_categories` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `name`        varchar(100) NOT NULL,
  `max_points`  tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sort_order`  smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `grade_categories_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `grade_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- kind: each = deduction × count · once = at most 1 · max = count capped at max_count
--       level = pick one per level_group within the category (e.g. Fading: Minor / Moderate / Heavy)
CREATE TABLE IF NOT EXISTS `grade_defects` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned NOT NULL,
  `name`        varchar(100) NOT NULL,
  `penalty`     tinyint(3) unsigned NOT NULL DEFAULT 1,
  `kind`        enum('each','once','max','level') NOT NULL DEFAULT 'each',
  `max_count`   tinyint(3) unsigned DEFAULT NULL,
  `level_group` varchar(50) DEFAULT NULL,
  `sort_order`  smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `grade_defects_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `grade_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grade_profiles` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grade_profile_components` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `profile_id`  int(10) unsigned NOT NULL,
  `template_id` int(10) unsigned NOT NULL,
  `label`       varchar(100) NOT NULL,
  `abbr`        varchar(8) NOT NULL DEFAULT '',
  `weight`      tinyint(3) unsigned NOT NULL DEFAULT 0,
  `default_qty` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `sort_order`  smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `profile_id` (`profile_id`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `grade_profile_components_ibfk_1` FOREIGN KEY (`profile_id`)  REFERENCES `grade_profiles` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `grade_profile_components_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `grade_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `systems`
  ADD COLUMN `grade_profile_id` int(10) unsigned DEFAULT NULL,
  ADD CONSTRAINT `systems_grade_profile_fk` FOREIGN KEY (`grade_profile_id`) REFERENCES `grade_profiles` (`id`) ON DELETE SET NULL;

-- ── Per user ───────────────────────────────────────────

ALTER TABLE `users`
  ADD COLUMN `grading_mode`    enum('simple','points','both') NOT NULL DEFAULT 'simple',
  ADD COLUMN `grading_default` enum('simple','points')        NOT NULL DEFAULT 'simple';

-- ── Per copy ───────────────────────────────────────────

ALTER TABLE `collection_entries`
  ADD COLUMN `grade_method`     enum('simple','points') DEFAULT NULL,
  ADD COLUMN `grade_label_id`   int(10) unsigned DEFAULT NULL,   -- the simple-mode choice
  ADD COLUMN `grade_profile_id` int(10) unsigned DEFAULT NULL,   -- profile used for points (set on first point grading)
  ADD COLUMN `grade_score`      tinyint(3) unsigned DEFAULT NULL, -- cached overall score
  ADD KEY `grade_label_id` (`grade_label_id`),
  ADD KEY `grade_profile_id` (`grade_profile_id`),
  ADD CONSTRAINT `collection_entries_grade_label_fk`   FOREIGN KEY (`grade_label_id`)   REFERENCES `grade_labels` (`id`)   ON DELETE SET NULL,
  ADD CONSTRAINT `collection_entries_grade_profile_fk` FOREIGN KEY (`grade_profile_id`) REFERENCES `grade_profiles` (`id`) ON DELETE SET NULL;

-- One row per included part: an admin-defined profile part, or one of the user's own items
CREATE TABLE IF NOT EXISTS `entry_parts` (
  `id`                   int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id`             int(10) unsigned NOT NULL,
  `profile_component_id` int(10) unsigned DEFAULT NULL,  -- set for admin-defined parts
  `custom_name`          varchar(100) DEFAULT NULL,      -- set for own items
  `template_id`          int(10) unsigned DEFAULT NULL,  -- set for own items
  `custom_weight`        tinyint(3) unsigned DEFAULT NULL, -- set for own items
  `qty`                  tinyint(3) unsigned NOT NULL DEFAULT 1, -- 0 = missing
  `sort_order`           smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `entry_id` (`entry_id`),
  KEY `profile_component_id` (`profile_component_id`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `entry_parts_ibfk_1` FOREIGN KEY (`entry_id`)             REFERENCES `collection_entries` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `entry_parts_ibfk_2` FOREIGN KEY (`profile_component_id`) REFERENCES `grade_profile_components` (`id`) ON DELETE CASCADE,
  CONSTRAINT `entry_parts_ibfk_3` FOREIGN KEY (`template_id`)          REFERENCES `grade_templates` (`id`)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per physical unit of a part (2 posters = 2 units); score is cached
CREATE TABLE IF NOT EXISTS `entry_part_units` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_part_id` int(10) unsigned NOT NULL,
  `unit_no`       tinyint(3) unsigned NOT NULL DEFAULT 1,
  `score`         tinyint(3) unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_unit` (`entry_part_id`,`unit_no`),
  CONSTRAINT `entry_part_units_ibfk_1` FOREIGN KEY (`entry_part_id`) REFERENCES `entry_parts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logged defects per unit (level defects: count = 1 on the chosen option)
CREATE TABLE IF NOT EXISTS `entry_defects` (
  `unit_id`   int(10) unsigned NOT NULL,
  `defect_id` int(10) unsigned NOT NULL,
  `count`     tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`unit_id`,`defect_id`),
  KEY `defect_id` (`defect_id`),
  CONSTRAINT `entry_defects_ibfk_1` FOREIGN KEY (`unit_id`)   REFERENCES `entry_part_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `entry_defects_ibfk_2` FOREIGN KEY (`defect_id`) REFERENCES `grade_defects` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Later, once you've checked the conversion (optional) ──
-- ALTER TABLE `collection_entries` DROP COLUMN `quality`;

-- Game Collection — database schema
-- Import into an EMPTY database created with the utf8mb4 character set.
-- WARNING: every table is dropped and recreated. Never run this on a database with data you want to keep.

SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

-- Grading defaults (labels, templates, profiles) are seeded by the app on first use,
-- from assets/grading-defaults.json.

DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `name`  varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `grade_labels`;
CREATE TABLE `grade_labels` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(50) NOT NULL,
  `short`      varchar(6) NOT NULL DEFAULT '',
  `min_score`  tinyint(3) unsigned NOT NULL DEFAULT 0,
  `color`      char(7) NOT NULL DEFAULT '#b0a898',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `grade_templates`;
CREATE TABLE `grade_templates` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `grade_categories`;
CREATE TABLE `grade_categories` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `name`        varchar(100) NOT NULL,
  `max_points`  tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sort_order`  smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `grade_categories_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `grade_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `grade_defects`;
CREATE TABLE `grade_defects` (
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


DROP TABLE IF EXISTS `grade_profiles`;
CREATE TABLE `grade_profiles` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `grade_profile_components`;
CREATE TABLE `grade_profile_components` (
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


DROP TABLE IF EXISTS `entry_parts`;
CREATE TABLE `entry_parts` (
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


DROP TABLE IF EXISTS `entry_part_units`;
CREATE TABLE `entry_part_units` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_part_id` int(10) unsigned NOT NULL,
  `unit_no`       tinyint(3) unsigned NOT NULL DEFAULT 1,
  `score`         tinyint(3) unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_unit` (`entry_part_id`,`unit_no`),
  CONSTRAINT `entry_part_units_ibfk_1` FOREIGN KEY (`entry_part_id`) REFERENCES `entry_parts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `entry_defects`;
CREATE TABLE `entry_defects` (
  `unit_id`   int(10) unsigned NOT NULL,
  `defect_id` int(10) unsigned NOT NULL,
  `count`     tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`unit_id`,`defect_id`),
  KEY `defect_id` (`defect_id`),
  CONSTRAINT `entry_defects_ibfk_1` FOREIGN KEY (`unit_id`)   REFERENCES `entry_part_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `entry_defects_ibfk_2` FOREIGN KEY (`defect_id`) REFERENCES `grade_defects` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `collection_entries`;
CREATE TABLE `collection_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `game_id` int(10) unsigned NOT NULL,
  `copy_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `owned` tinyint(1) NOT NULL DEFAULT 0,
  `completeness` varchar(100) NOT NULL DEFAULT '',
  `played_status` varchar(100) NOT NULL DEFAULT '',
  `wishlist` tinyint(1) NOT NULL DEFAULT 0,
  `upgrade` tinyint(1) NOT NULL DEFAULT 0,
  `upgrade_reason` text DEFAULT NULL,
  `price_paid` decimal(8,2) DEFAULT NULL,
  `chart_price` decimal(8,2) DEFAULT NULL,
  `price_min` decimal(8,2) DEFAULT NULL,
  `price_max` decimal(8,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `tag` varchar(100) DEFAULT NULL,
  `value_price_type` enum('loose','cib','new') NOT NULL DEFAULT 'cib',
  `primary_photo` varchar(255) DEFAULT NULL,
  `grade_method` enum('simple','points') DEFAULT NULL,
  `grade_label_id` int(10) unsigned DEFAULT NULL,
  `grade_profile_id` int(10) unsigned DEFAULT NULL,
  `grade_score` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_game_copy` (`user_id`,`game_id`,`copy_number`),
  KEY `game_id` (`game_id`),
  KEY `idx_user_game` (`user_id`,`game_id`),
  KEY `grade_label_id` (`grade_label_id`),
  KEY `grade_profile_id` (`grade_profile_id`),
  CONSTRAINT `collection_entries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_entries_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_entries_grade_label_fk` FOREIGN KEY (`grade_label_id`) REFERENCES `grade_labels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collection_entries_grade_profile_fk` FOREIGN KEY (`grade_profile_id`) REFERENCES `grade_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `copy_photos`;
CREATE TABLE `copy_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `entry_id` (`entry_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `copy_photos_ibfk_1` FOREIGN KEY (`entry_id`) REFERENCES `collection_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copy_photos_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `games`;
CREATE TABLE `games` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `sort_title` varchar(255) NOT NULL,
  `default_image` varchar(255) DEFAULT NULL,
  `pc_id` varchar(20) DEFAULT NULL,
  `pc_link` varchar(255) DEFAULT NULL,
  `cib_price` decimal(8,2) DEFAULT NULL,
  `loose_price` decimal(8,2) DEFAULT NULL,
  `loose_price_updated_at` timestamp NULL DEFAULT NULL,
  `new_price` decimal(8,2) DEFAULT NULL,
  `new_price_updated_at` timestamp NULL DEFAULT NULL,
  `cib_price_updated_at` timestamp NULL DEFAULT NULL,
  `notes_admin` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system` (`system_id`),
  KEY `idx_sort` (`system_id`,`sort_title`),
  KEY `idx_pc_id` (`pc_id`),
  CONSTRAINT `games_ibfk_1` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `invite_codes`;
CREATE TABLE `invite_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `used_by` int(10) unsigned DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `created_by` (`created_by`),
  KEY `used_by` (`used_by`),
  CONSTRAINT `invite_codes_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `invite_codes_ibfk_2` FOREIGN KEY (`used_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `attempt_key` varchar(120) NOT NULL,
  `fail_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_fail_at` timestamp NULL DEFAULT NULL,
  `locked_until` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`attempt_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE `remember_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `systems`;
CREATE TABLE `systems` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `short_name` varchar(30) NOT NULL,
  `icon_image` varchar(255) DEFAULT NULL,
  `region` varchar(20) NOT NULL DEFAULT 'PAL',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `grade_profile_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `grade_profile_id` (`grade_profile_id`),
  CONSTRAINT `systems_grade_profile_fk` FOREIGN KEY (`grade_profile_id`) REFERENCES `grade_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `status` enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `wishlist_public` tinyint(1) NOT NULL DEFAULT 0,
  `wishlist_token` varchar(32) DEFAULT NULL,
  `column_prefs_collection` text DEFAULT NULL,
  `column_prefs_wishlist` text DEFAULT NULL,
  `show_system_icons` tinyint(1) NOT NULL DEFAULT 1,
  `auction_sites` text DEFAULT NULL,
  `grading_mode` enum('simple','points','both') NOT NULL DEFAULT 'simple',
  `grading_default` enum('simple','points') NOT NULL DEFAULT 'simple',
  `theme` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `user_backups`;
CREATE TABLE `user_backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `system_id` int(10) unsigned NOT NULL,
  `token` varchar(32) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `status` enum('generating','ready','failed') NOT NULL DEFAULT 'generating',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `uq_user_system` (`user_id`,`system_id`),
  KEY `system_id` (`system_id`),
  CONSTRAINT `user_backups_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_backups_ibfk_2` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `user_completeness_options`;
CREATE TABLE `user_completeness_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_label` (`user_id`,`label`),
  CONSTRAINT `user_completeness_options_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `user_played_options`;
CREATE TABLE `user_played_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_label` (`user_id`,`label`),
  CONSTRAINT `user_played_options_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `user_system_prefs`;
CREATE TABLE `user_system_prefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `system_id` int(10) unsigned NOT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  `user_sort_order` smallint(6) NOT NULL DEFAULT 0,
  `count_for_totals` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sys` (`user_id`,`system_id`),
  KEY `system_id` (`system_id`),
  CONSTRAINT `user_system_prefs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_system_prefs_ibfk_2` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `user_tag_options`;
CREATE TABLE `user_tag_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_tag` (`user_id`,`label`),
  CONSTRAINT `user_tag_options_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
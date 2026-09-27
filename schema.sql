-- Game Collection — database schema
-- Import into an EMPTY database created with the utf8mb4 character set.
-- WARNING: every table is dropped and recreated. Never run this on a database with data you want to keep.

SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `collection_entries`;
CREATE TABLE `collection_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `game_id` int(10) unsigned NOT NULL,
  `copy_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `owned` tinyint(1) NOT NULL DEFAULT 0,
  `quality` enum('','Mint','Good','Fair','Poor') NOT NULL DEFAULT '',
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_game_copy` (`user_id`,`game_id`,`copy_number`),
  KEY `game_id` (`game_id`),
  KEY `idx_user_game` (`user_id`,`game_id`),
  CONSTRAINT `collection_entries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_entries_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
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
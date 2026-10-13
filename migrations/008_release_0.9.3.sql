-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.3
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 007_release_0.9.2.sql to have run.
--
--  Everything since 0.9.2 in one file:
--  · site_backups: full site backups kept in uploads/backups/site/
--    (Settings › Site Backup)
--  · activity_log: the activity log (Settings › Activity log); it starts
--    empty, its settings fall back to defaults in code
--
--  Every step is safe to run twice (test sites already ran the
--  development files dev/008 and dev/009).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Full site backups ──
CREATE TABLE IF NOT EXISTS `site_backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(100) NOT NULL,
  `parts` varchar(30) NOT NULL,
  `trigger_type` enum('manual','schedule','update') NOT NULL DEFAULT 'manual',
  `status` enum('running','ready','failed') NOT NULL DEFAULT 'running',
  `file_count` int(10) unsigned NOT NULL DEFAULT 0,
  `missing_count` int(10) unsigned NOT NULL DEFAULT 0,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `error` varchar(500) DEFAULT NULL,
  `app_version` varchar(20) NOT NULL DEFAULT '',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `filename` (`filename`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `site_backups_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Activity log ──
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `user_id` int(10) unsigned DEFAULT NULL,
  `username` varchar(50) NOT NULL DEFAULT '',
  `category` enum('collection','catalogue','sharing','accounts','system') NOT NULL,
  `action` varchar(60) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_warning` tinyint(1) NOT NULL DEFAULT 0,
  `subject_type` varchar(30) DEFAULT NULL,
  `subject_id` int(10) unsigned DEFAULT NULL,
  `target_user_id` int(10) unsigned DEFAULT NULL,
  `target_username` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user` (`user_id`,`created_at`),
  KEY `idx_type` (`category`,`created_at`),
  KEY `idx_admin` (`is_admin`,`created_at`),
  KEY `idx_subject` (`subject_type`,`subject_id`,`created_at`),
  KEY `idx_target` (`target_user_id`),
  CONSTRAINT `activity_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_log_target_fk` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

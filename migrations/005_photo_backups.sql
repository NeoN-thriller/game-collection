-- ═══════════════════════════════════════════════════════════════════
--  Migration: photo backups
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first.
--
--  Adds user_backups: the per-system photo zips made under
--  Settings › Backup (expire after 24 hours). Databases that already
--  have the table are left as they are.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_backups` (
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

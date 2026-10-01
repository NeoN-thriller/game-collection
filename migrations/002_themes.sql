-- ═══════════════════════════════════════════════════════════════════
--  Migration: themes
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first.
--
--  Adds users.theme (the user's own theme; NULL = follow the site
--  default). The site default is stored in app_settings under the key
--  'default_theme' and is set on the admin page; until then the app
--  uses Arcade Gold (the original look).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- Also created by 001_point_grading.sql; harmless if it already exists
CREATE TABLE IF NOT EXISTS `app_settings` (
  `name`  varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `theme` varchar(50) DEFAULT NULL AFTER `grading_default`;

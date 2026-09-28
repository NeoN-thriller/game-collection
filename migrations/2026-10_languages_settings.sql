-- ═══════════════════════════════════════════════════════════════════
--  Migration: languages + site settings
--
--  Run ONCE on an existing database (MySQL 8+ / MariaDB 10.5+).
--  Back up the database first. Needs 2026-09_themes.sql to have run.
--
--  Adds users.language (the user's own language; NULL = follow the
--  site default).
--
--  Site settings (site name, currency, separators, region, date format,
--  default language, default grading, image sizes) live in app_settings
--  and are edited on the admin page. Nothing needs inserting: every
--  setting has a built-in default until the admin saves it. The old
--  uploads/img_settings.json is copied into app_settings (and removed)
--  automatically the first time a page loads.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `language` varchar(10) DEFAULT NULL AFTER `theme`;

-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.5
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 008_release_0.9.3.sql to have run
--  (0.9.4 had no database changes).
--
--  Everything since 0.9.4 in one file:
--  · game_barcodes: barcodes linked to games, shared by all users
--    (one row per barcode + game; a code on two editions = two rows).
--    Users can flag a row for removal; only admins remove.
--  · barcode_unknown: valid barcodes that were scanned but are not
--    linked yet (Settings › Barcodes › Unknown scans)
--
--  Settings fall back to defaults in code (SITE_DEFAULTS).
--  Every step is safe to run twice (test sites already ran the
--  development file dev/009).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Barcodes linked to games ──
CREATE TABLE IF NOT EXISTS `game_barcodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `barcode` varchar(13) NOT NULL,
  `game_id` int(10) unsigned NOT NULL,
  `variant` varchar(100) NOT NULL DEFAULT '',
  `note` varchar(200) DEFAULT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `added_username` varchar(50) NOT NULL DEFAULT '',
  `source` enum('user','import') NOT NULL DEFAULT 'user',
  `flagged_by` int(10) unsigned DEFAULT NULL,
  `flagged_username` varchar(50) NOT NULL DEFAULT '',
  `flag_reason` varchar(200) DEFAULT NULL,
  `flagged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barcode_game` (`barcode`,`game_id`),
  KEY `idx_game` (`game_id`),
  KEY `idx_flagged` (`flagged_at`),
  KEY `added_by` (`added_by`),
  KEY `flagged_by` (`flagged_by`),
  CONSTRAINT `game_barcodes_game_fk` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `game_barcodes_user_fk` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `game_barcodes_flag_fk` FOREIGN KEY (`flagged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Scanned barcodes that are not linked yet ──
CREATE TABLE IF NOT EXISTS `barcode_unknown` (
  `barcode` varchar(13) NOT NULL,
  `times` int(10) unsigned NOT NULL DEFAULT 1,
  `first_user_id` int(10) unsigned DEFAULT NULL,
  `first_username` varchar(50) NOT NULL DEFAULT '',
  `last_username` varchar(50) NOT NULL DEFAULT '',
  `first_seen` timestamp NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NULL DEFAULT current_timestamp(),
  `dismissed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`barcode`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `first_user_id` (`first_user_id`),
  CONSTRAINT `barcode_unknown_user_fk` FOREIGN KEY (`first_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

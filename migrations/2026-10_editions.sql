-- ═══════════════════════════════════════════════════════════════════
--  Migration: editions + print variants
--
--  Run ONCE on an existing database (MySQL 8+ / MariaDB 10.5+).
--  Back up the database first. Needs 2026-10_languages_settings.sql
--  to have run.
--
--  Editions: games that PriceCharting lists separately (original,
--  [Platinum], [Nintendo Selects], …) can be linked into a group by the
--  admin (Settings → Catalogue). Each edition keeps its own games row.
--  Adds game_groups, edition_ignores (rejected suggestions, per pair of
--  games) and games.group_id / edition_label / edition_sort.
--
--  Per user: users.edition_mode (count one per game or every edition),
--  users.edition_wishlist (wishlist any edition or the exact one) and
--  users.track_variants, plus the user's own list of print variants in
--  user_variant_options (starts empty).
--
--  Per copy: collection_entries.variant and wishlist_any.
--
--  Nothing needs converting: every game starts unlinked, every user
--  counts one per game with variants switched off.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE `game_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `sort_title` varchar(255) NOT NULL,
  `main_game_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system` (`system_id`,`sort_title`),
  KEY `main_game_id` (`main_game_id`),
  CONSTRAINT `game_groups_system_fk` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `game_groups_main_fk` FOREIGN KEY (`main_game_id`) REFERENCES `games` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `games`
  ADD COLUMN `group_id` int(10) unsigned DEFAULT NULL AFTER `active`,
  ADD COLUMN `edition_label` varchar(100) DEFAULT NULL AFTER `group_id`,
  ADD COLUMN `edition_sort` smallint(6) NOT NULL DEFAULT 0 AFTER `edition_label`,
  ADD KEY `idx_group` (`group_id`),
  ADD CONSTRAINT `games_group_fk` FOREIGN KEY (`group_id`) REFERENCES `game_groups` (`id`) ON DELETE SET NULL;

CREATE TABLE `edition_ignores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_id` int(10) unsigned NOT NULL,
  `game_a` int(10) unsigned NOT NULL,
  `game_b` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pair` (`game_a`,`game_b`),
  KEY `system_id` (`system_id`),
  KEY `game_b` (`game_b`),
  CONSTRAINT `edition_ignores_system_fk` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `edition_ignores_a_fk` FOREIGN KEY (`game_a`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `edition_ignores_b_fk` FOREIGN KEY (`game_b`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `edition_mode` enum('one','every') NOT NULL DEFAULT 'one' AFTER `language`,
  ADD COLUMN `edition_wishlist` enum('any','exact') NOT NULL DEFAULT 'any' AFTER `edition_mode`,
  ADD COLUMN `track_variants` tinyint(1) NOT NULL DEFAULT 0 AFTER `edition_wishlist`;

CREATE TABLE `user_variant_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_label` (`user_id`,`label`),
  CONSTRAINT `user_variant_options_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `collection_entries`
  ADD COLUMN `variant` varchar(100) NOT NULL DEFAULT '' AFTER `played_status`,
  ADD COLUMN `wishlist_any` tinyint(1) NOT NULL DEFAULT 0 AFTER `wishlist`;

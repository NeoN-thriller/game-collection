-- ═══════════════════════════════════════════════════════════════════
--  Migration: condition reports + printable labels
--
--  Run ONCE on an existing database (MySQL 8+ / MariaDB 10.5+).
--  Back up the database first. Needs 2026-10_editions.sql to have run.
--
--  copy_shares: a public, token-based link per copy to its condition
--  report (grade.php?t=…), as a snapshot (default) or live.
--
--  label_sizes: sticker sizes. The four seeded sizes are site-wide
--  (user_id NULL, managed by the admin); users can add sizes of their
--  own.
--
--  label_templates: sticker layouts. The seeded "Default" is a site
--  template (user_id NULL); users make their own and may share them.
--  users.label_template_id is the user's default (NULL = the first
--  site template).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- Condition reports: one public share per copy (a copy can be re-shared with a new token; old rows stay revoked)
CREATE TABLE `copy_shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `mode` enum('snapshot','live') NOT NULL DEFAULT 'snapshot',
  `snapshot` mediumtext DEFAULT NULL,
  `for_sale` tinyint(1) NOT NULL DEFAULT 0,
  `graded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `entry_id` (`entry_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `copy_shares_entry_fk` FOREIGN KEY (`entry_id`) REFERENCES `collection_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copy_shares_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Sticker sizes. user_id NULL = site-wide (added by the admin); otherwise a user's own size.
CREATE TABLE `label_sizes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `width_mm` decimal(5,1) NOT NULL,
  `height_mm` decimal(5,1) NOT NULL,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `label_sizes_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `label_sizes` (`id`, `user_id`, `name`, `width_mm`, `height_mm`, `sort_order`) VALUES
  (1, NULL, 'Dymo 99012',       89.0,  36.0, 1),
  (2, NULL, 'Brother DK-11209', 62.0,  29.0, 2),
  (3, NULL, 'Brother DK-11202', 62.0, 100.0, 3),
  (4, NULL, 'A4 sheet 3×8',     70.0,  37.0, 4);


-- Label templates. user_id NULL = site template (the admin edits it; everyone can use it);
-- otherwise a user's own template, optionally shared (read-only) with everyone.
CREATE TABLE `label_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `size_id` int(10) unsigned DEFAULT NULL,
  `orientation` enum('landscape','portrait') NOT NULL DEFAULT 'landscape',
  `layout` enum('horizontal','stacked') NOT NULL DEFAULT 'horizontal',
  `fields` text NOT NULL,
  `colour` tinyint(1) NOT NULL DEFAULT 1,
  `cut_line` tinyint(1) NOT NULL DEFAULT 1,
  `shared` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `shared` (`shared`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `label_templates_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `label_templates_size_fk` FOREIGN KEY (`size_id`) REFERENCES `label_sizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The site's default template: every user without a default of their own uses the first site template
INSERT INTO `label_templates` (`user_id`, `name`, `size_id`, `orientation`, `layout`, `fields`, `colour`, `cut_line`, `shared`)
SELECT NULL, 'Default', `id`, 'landscape', 'horizontal',
   '[{"id":"score","on":true,"scale":1},{"id":"cond","on":true,"scale":1},{"id":"qr","on":true,"scale":1},{"id":"title","on":true,"scale":1},{"id":"meta","on":true,"scale":1},{"id":"date","on":true,"scale":1},{"id":"id","on":true,"scale":1}]',
   1, 1, 0
FROM `label_sizes` WHERE `name` = 'Dymo 99012' AND `user_id` IS NULL LIMIT 1;


ALTER TABLE `users`
  ADD COLUMN `label_template_id` int(10) unsigned DEFAULT NULL AFTER `track_variants`,
  ADD KEY `label_template_id` (`label_template_id`),
  ADD CONSTRAINT `users_label_template_fk` FOREIGN KEY (`label_template_id`) REFERENCES `label_templates` (`id`) ON DELETE SET NULL;

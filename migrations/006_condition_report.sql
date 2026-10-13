-- ═══════════════════════════════════════════════════════════════════
--  Migration: condition reports + printable labels
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first. Needs 004_editions.sql to have run.
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
--
--  Safe on a database where this was already run by hand: existing
--  tables, seeds and the users column are left as they are.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `copy_shares` (
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

CREATE TABLE IF NOT EXISTS `label_sizes` (
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

-- The site's sizes (only when the site has none yet)
INSERT INTO `label_sizes` (`user_id`, `name`, `width_mm`, `height_mm`, `sort_order`)
SELECT * FROM (
  SELECT NULL AS user_id, 'Dymo 99012' AS name, 89.0 AS width_mm, 36.0 AS height_mm, 1 AS sort_order
  UNION ALL SELECT NULL, 'Brother DK-11209', 62.0, 29.0, 2
  UNION ALL SELECT NULL, 'Brother DK-11202', 62.0, 100.0, 3
  UNION ALL SELECT NULL, 'A4 sheet 3×8', 70.0, 37.0, 4
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `label_sizes` WHERE `user_id` IS NULL);

CREATE TABLE IF NOT EXISTS `label_templates` (
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

-- The site's default template (only when the site has no template yet)
INSERT INTO `label_templates` (`user_id`, `name`, `size_id`, `orientation`, `layout`, `fields`, `colour`, `cut_line`, `shared`)
SELECT NULL, 'Default', s.`id`, 'landscape', 'horizontal',
   '[{"id":"score","on":true,"scale":1},{"id":"cond","on":true,"scale":1},{"id":"qr","on":true,"scale":1},{"id":"title","on":true,"scale":1},{"id":"meta","on":true,"scale":1},{"id":"date","on":true,"scale":1},{"id":"id","on":true,"scale":1}]',
   1, 1, 0
FROM `label_sizes` s
WHERE s.`user_id` IS NULL AND s.`name` = 'Dymo 99012'
  AND NOT EXISTS (SELECT 1 FROM `label_templates` WHERE `user_id` IS NULL)
LIMIT 1;

-- users.label_template_id (skipped when the column is already there)
SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'label_template_id');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `label_template_id` int(10) unsigned DEFAULT NULL AFTER `track_variants`, ADD KEY `label_template_id` (`label_template_id`), ADD CONSTRAINT `users_label_template_fk` FOREIGN KEY (`label_template_id`) REFERENCES `label_templates` (`id`) ON DELETE SET NULL',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

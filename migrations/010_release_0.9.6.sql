-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.6
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 009_release_0.9.5.sql to have run.
--
--  Everything since 0.9.5 in one file:
--  · value_history: the owned value of each user's collection per
--    system, one row each time it changes (PriceCharting import,
--    collection change, manual price change); the dashboard draws the
--    value graphs from it. Seeded with one 'start' row per user and
--    system with value above 0 (past prices cannot be rebuilt).
--  · users.show_value_graph: show the value graphs (default on)
--  · users.value_graph_collapsed: the dashboard card folded away with
--    its Hide graphs button (default open)
--  · systems.icon_image_light: a second system icon for light themes
--  · games.loose_price_prev / cib_price_prev / new_price_prev: the price
--    before the last PriceCharting import that changed it (▲ / ▼ on the
--    collection page)
--
--  Every step is safe to run twice (test sites already ran the
--  development files dev/010 to dev/013).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;


-- ── Collection value over time (was dev/010_value_history.sql) ──

CREATE TABLE IF NOT EXISTS `value_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `system_id` int(10) unsigned NOT NULL,
  `recorded_at` datetime NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `source` enum('start','import','collection','price') NOT NULL,
  `run` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run` (`user_id`,`system_id`,`run`),
  KEY `idx_user_time` (`user_id`,`recorded_at`),
  KEY `idx_user_sys` (`user_id`,`system_id`,`recorded_at`),
  KEY `system_id` (`system_id`),
  CONSTRAINT `value_history_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `value_history_system_fk` FOREIGN KEY (`system_id`) REFERENCES `systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'show_value_graph');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `show_value_graph` tinyint(1) NOT NULL DEFAULT 1 AFTER `show_played`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

-- Seed: same value rule as valueBySystem() in value.php (owned copies of active games, each at its own price type)
INSERT INTO `value_history` (`user_id`, `system_id`, `recorded_at`, `value`, `source`)
SELECT v.user_id, v.system_id, NOW(), v.value, 'start'
FROM (
  SELECT ce.user_id, g.system_id,
         ROUND(COALESCE(SUM(CASE ce.value_price_type WHEN 'loose' THEN g.loose_price WHEN 'new' THEN g.new_price ELSE g.cib_price END), 0), 2) AS value
  FROM collection_entries ce
  JOIN games g ON g.id = ce.game_id
  JOIN users u ON u.id = ce.user_id
  WHERE ce.owned = 1 AND g.active = 1
  GROUP BY ce.user_id, g.system_id
) v
WHERE v.value > 0 AND NOT EXISTS (SELECT 1 FROM `value_history`);


-- ── Fold away the value graph card (was dev/011_value_graph_collapsed.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'value_graph_collapsed');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `users` ADD COLUMN `value_graph_collapsed` tinyint(1) NOT NULL DEFAULT 0 AFTER `show_value_graph`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── System icons for light themes (was dev/012_system_icon_light.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'systems' AND COLUMN_NAME = 'icon_image_light');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `systems` ADD COLUMN `icon_image_light` varchar(255) DEFAULT NULL AFTER `icon_image`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Price before the last PriceCharting import (was dev/013_price_previous.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'loose_price_prev');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `games` ADD COLUMN `loose_price_prev` decimal(8,2) DEFAULT NULL AFTER `loose_price_updated_at`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'cib_price_prev');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `games` ADD COLUMN `cib_price_prev` decimal(8,2) DEFAULT NULL AFTER `cib_price_updated_at`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'new_price_prev');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `games` ADD COLUMN `new_price_prev` decimal(8,2) DEFAULT NULL AFTER `new_price_updated_at`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

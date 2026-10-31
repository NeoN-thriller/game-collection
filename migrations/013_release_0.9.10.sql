-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.10
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 012_release_0.9.8.sql to have run.
--
--  Everything since 0.9.9 in one file:
--  · wishlist_shares — several wishlist share links per user, each with
--    its own token, name, visible columns and "listed for other users"
--    (was dev/013_wishlist_shares.sql); a public wishlist's existing link
--    moves over with the same token, and users.wishlist_public /
--    users.wishlist_token are dropped
--  · renamed themes: users' and the site's theme choice follows the new
--    file names (nintendo → nintechduo, playstation → gamestation,
--    sega → zenga, xbox → y-block)
--
--  Every step is safe to run twice (test sites already ran
--  dev/013_wishlist_shares.sql).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;


-- ── Wishlist share links (was dev/013_wishlist_shares.sql) ──

CREATE TABLE IF NOT EXISTS `wishlist_shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `columns` text NOT NULL,                                        -- JSON list of the visible column ids
  `listed` tinyint(1) NOT NULL DEFAULT 0,                         -- shown to other users under "Other public wishlists"
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `wishlist_shares_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Move the existing public links over (only while users.wishlist_token still exists) ──

SET @gc_has := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'wishlist_token');
SET @gc_sql := IF(@gc_has,
  'INSERT IGNORE INTO `wishlist_shares` (`user_id`, `token`, `name`, `columns`, `listed`)
   SELECT u.`id`, u.`wishlist_token`, '''',
          ''["img","owned","upgrade","system","title","upgrade_reason","quality","completeness","buy_range","loose_price","cib_price","new_price","tag","notes"]'', 1
   FROM `users` u
   WHERE u.`wishlist_public` = 1 AND u.`wishlist_token` IS NOT NULL AND u.`wishlist_token` <> ''''',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

SET @gc_sql := IF(@gc_has,
  'ALTER TABLE `users` DROP COLUMN `wishlist_public`, DROP COLUMN `wishlist_token`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Renamed themes ──

UPDATE `users` SET `theme` = CASE `theme`
    WHEN 'nintendo'    THEN 'nintechduo'
    WHEN 'playstation' THEN 'gamestation'
    WHEN 'sega'        THEN 'zenga'
    WHEN 'xbox'        THEN 'y-block'
  END
WHERE `theme` IN ('nintendo', 'playstation', 'sega', 'xbox');

UPDATE `app_settings` SET `value` = CASE `value`
    WHEN 'nintendo'    THEN 'nintechduo'
    WHEN 'playstation' THEN 'gamestation'
    WHEN 'sega'        THEN 'zenga'
    WHEN 'xbox'        THEN 'y-block'
  END
WHERE `name` = 'default_theme' AND `value` IN ('nintendo', 'playstation', 'sega', 'xbox');

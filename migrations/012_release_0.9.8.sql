-- ═══════════════════════════════════════════════════════════════════
--  Release migration 0.9.8
--
--  Run by Settings › Updates (or by hand, once, in number order).
--  Back up the database first (Settings › Updates offers a database
--  backup before it runs). Needs 011_release_0.9.7.sql to have run.
--
--  Everything since 0.9.7 in one file — IGDB game info and covers:
--  · systems.igdb_platform_id — the system's platform at IGDB
--  · game_igdb — per game: the IGDB match (or why there is none),
--    developer, publisher, genres, release date, ratings and the
--    IGDB cover
--  · games.cover_source — per game: the site default (NULL), the
--    uploaded cover or the IGDB cover
--
--  The IGDB settings (cover default, cached token and regions) live in
--  app_settings (no table change). Every step is safe to run twice
--  (test sites already ran the development files dev/012 and dev/013).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;


-- ── IGDB platform per system (was dev/012_igdb.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'systems' AND COLUMN_NAME = 'igdb_platform_id');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `systems` ADD COLUMN `igdb_platform_id` int(10) unsigned DEFAULT NULL AFTER `region`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── IGDB info per game (was dev/012_igdb.sql, covers from dev/013_igdb_covers.sql) ──

CREATE TABLE IF NOT EXISTS `game_igdb` (
  `game_id` int(10) unsigned NOT NULL,
  `status` enum('auto','confirmed','review','none') NOT NULL,     -- auto/confirmed: matched · review: an admin must pick · none: not on IGDB
  `igdb_id` int(10) unsigned DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,                               -- igdb.com/games/<slug>
  `name` varchar(255) DEFAULT NULL,                               -- the game's name at IGDB
  `developers` varchar(500) DEFAULT NULL,
  `publishers` varchar(500) DEFAULT NULL,
  `genres` varchar(500) DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `release_prec` enum('day','month','quarter','year') DEFAULT NULL,
  `release_region` varchar(20) DEFAULT NULL,                      -- IGDB region name (europe, north_america, worldwide, …)
  `release_kind` enum('own','worldwide','other') DEFAULT NULL,    -- own: the system's region · other: shown with its region
  `rating` decimal(5,2) DEFAULT NULL,                             -- IGDB users, 0–100
  `rating_count` int(10) unsigned DEFAULT NULL,
  `critic_rating` decimal(5,2) DEFAULT NULL,                      -- critics, 0–100
  `critic_count` int(10) unsigned DEFAULT NULL,
  `cover_id` varchar(64) DEFAULT NULL,                            -- IGDB cover image id (images.igdb.com)
  `cover_region` varchar(20) DEFAULT NULL,                        -- the cover's region; NULL = IGDB's main cover
  `candidates` text DEFAULT NULL,                                 -- review: JSON [{id, name, slug, year}] found by the search
  `fetched_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`game_id`),
  KEY `idx_status` (`status`),
  KEY `idx_igdb` (`igdb_id`),
  CONSTRAINT `game_igdb_game_fk` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Test sites that ran dev/012 but not dev/013: the cover columns
SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_igdb' AND COLUMN_NAME = 'cover_id');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `game_igdb` ADD COLUMN `cover_id` varchar(64) DEFAULT NULL AFTER `critic_count`, ADD COLUMN `cover_region` varchar(20) DEFAULT NULL AFTER `cover_id`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;


-- ── Cover choice per game (was dev/013_igdb_covers.sql) ──

SET @gc_add := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'games' AND COLUMN_NAME = 'cover_source');
SET @gc_sql := IF(@gc_add,
  'ALTER TABLE `games` ADD COLUMN `cover_source` enum(''uploaded'',''igdb'') DEFAULT NULL AFTER `default_image`',
  'SET @gc_noop := 1');
PREPARE gc_stmt FROM @gc_sql;
EXECUTE gc_stmt;
DEALLOCATE PREPARE gc_stmt;

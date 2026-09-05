-- ═══════════════════════════════════════════
--  MIGRATION 001 — Run in phpMyAdmin SQL tab
-- ═══════════════════════════════════════════

-- 1. Add played status to collection_entries
ALTER TABLE collection_entries
    ADD COLUMN played_status VARCHAR(100) NOT NULL DEFAULT '' AFTER completeness;

-- 2. Add primary_photo to collection_entries (filename of chosen primary photo)
ALTER TABLE collection_entries
    ADD COLUMN primary_photo VARCHAR(255) DEFAULT NULL AFTER notes;

-- 3. User played status options (same pattern as completeness)
CREATE TABLE IF NOT EXISTS user_played_options (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(100) NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_label (user_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. User system visibility preferences
CREATE TABLE IF NOT EXISTS user_system_prefs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    system_id   INT UNSIGNED NOT NULL,
    visible     TINYINT(1)   NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_sys (user_id, system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Seed default played options for existing users
INSERT IGNORE INTO user_played_options (user_id, label, sort_order)
SELECT id, 'Finished', 0 FROM users;
INSERT IGNORE INTO user_played_options (user_id, label, sort_order)
SELECT id, 'Started', 1 FROM users;
INSERT IGNORE INTO user_played_options (user_id, label, sort_order)
SELECT id, 'Stuck', 2 FROM users;
INSERT IGNORE INTO user_played_options (user_id, label, sort_order)
SELECT id, 'Cheated', 3 FROM users;

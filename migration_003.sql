-- ═══════════════════════════════════════════
--  MIGRATION 003 — Run in phpMyAdmin SQL tab
-- ═══════════════════════════════════════════

-- Add PriceCharting fields to games table
ALTER TABLE games
    ADD COLUMN pc_id       VARCHAR(20)  DEFAULT NULL AFTER default_image,
    ADD COLUMN pc_link     VARCHAR(255) DEFAULT NULL AFTER pc_id;

-- Add wishlist_public flag to users
ALTER TABLE users
    ADD COLUMN wishlist_public TINYINT(1) NOT NULL DEFAULT 0 AFTER last_login;

-- Extend session lifetime (handled in PHP, no DB change needed)
-- Add persistent login token table
CREATE TABLE IF NOT EXISTS remember_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at TIMESTAMP    NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Add cib_price to games table (global reference price from PriceCharting)
ALTER TABLE games
    ADD COLUMN cib_price DECIMAL(8,2) DEFAULT NULL AFTER pc_link;

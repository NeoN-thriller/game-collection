-- ═══════════════════════════════════════════
--  MIGRATION 002 — Run in phpMyAdmin SQL tab
-- ═══════════════════════════════════════════

-- Add wishlist flag to collection_entries
ALTER TABLE collection_entries
    ADD COLUMN wishlist TINYINT(1) NOT NULL DEFAULT 0 AFTER upgrade;

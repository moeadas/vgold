-- Victory Genomics CRM — Migration V6: Make region column nullable
-- The Region field has been replaced by Country in the UI.
-- This migration makes it nullable so INSERTs without region don't fail.
-- Run this on the production database.

ALTER TABLE leads
    MODIFY COLUMN `region` enum('North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other') DEFAULT NULL;

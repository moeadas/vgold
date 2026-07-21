-- Victory Genomics CRM — Migration V5: Microsoft OAuth2 token storage
-- Run this on the production database AFTER migrate-v4-user-smtp.sql
-- Compatible with MySQL 5.7+ / MariaDB 10.x

ALTER TABLE users
    ADD COLUMN ms_access_token TEXT DEFAULT NULL,
    ADD COLUMN ms_refresh_token TEXT DEFAULT NULL,
    ADD COLUMN ms_token_expires DATETIME DEFAULT NULL,
    ADD COLUMN ms_connected_email VARCHAR(255) DEFAULT NULL;

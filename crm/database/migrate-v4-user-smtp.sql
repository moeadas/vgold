-- Victory Genomics CRM — Migration V4: Per-user SMTP settings
-- Run this on the production database to add email columns to users table
-- Compatible with MySQL 5.7+ / MariaDB 10.x

ALTER TABLE users
    ADD COLUMN smtp_host VARCHAR(255) DEFAULT 'smtp.office365.com',
    ADD COLUMN smtp_port INT DEFAULT 587,
    ADD COLUMN smtp_email VARCHAR(255) DEFAULT NULL,
    ADD COLUMN smtp_password VARCHAR(500) DEFAULT NULL,
    ADD COLUMN smtp_encryption VARCHAR(10) DEFAULT 'tls';

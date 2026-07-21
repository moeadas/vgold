-- 006_identity.sql — Microsoft 365 identity columns
ALTER TABLE `users`
  ADD COLUMN `auth_provider` ENUM('microsoft','password') NOT NULL DEFAULT 'password' AFTER `email`,
  ADD COLUMN `ms_oid` VARCHAR(64) NULL AFTER `auth_provider`,
  ADD UNIQUE KEY `uniq_ms_oid` (`ms_oid`);
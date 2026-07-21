-- app/migrations/005_sharepoint.sql
-- Add SharePoint tracking columns to files table
ALTER TABLE `files`
  ADD COLUMN `sp_item_id` VARCHAR(255) NULL AFTER `filename`,
  ADD COLUMN `sp_drive_id` VARCHAR(255) NULL AFTER `sp_item_id`,
  ADD COLUMN `storage` ENUM('local','sharepoint') NOT NULL DEFAULT 'local' AFTER `sp_drive_id`;
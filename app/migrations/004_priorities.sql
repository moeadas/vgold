-- VGo Schema Update 004 — priorities

-- Update task priority enum to include only Normal and Urgent
ALTER TABLE `tasks` MODIFY COLUMN `priority` ENUM('normal','urgent') DEFAULT 'normal';

-- Migrate existing priorities
UPDATE `tasks` SET `priority` = 'normal' WHERE `priority` IN ('low','medium','high');
UPDATE `tasks` SET `priority` = 'urgent' WHERE `priority` = 'urgent';
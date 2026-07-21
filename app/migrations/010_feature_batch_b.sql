-- VGo Migration 010 — Feature batch B: folders, per-user card order, DM/comment reads, notification defaults
-- Idempotent-ish: uses IF NOT EXISTS where MariaDB supports it. Safe to re-run on MariaDB 10.5+.

-- ===== B9: Push / chat notifications ON by default =====
-- New users get chat notifications enabled; existing rows are turned on too.
ALTER TABLE `user_settings` MODIFY COLUMN `notify_chat` TINYINT(1) DEFAULT 1;
UPDATE `user_settings` SET `notify_chat` = 1 WHERE `notify_chat` = 0;

-- Per-user "default landing screen" preference (defaults to My Tasks).
ALTER TABLE `user_settings`
  ADD COLUMN IF NOT EXISTS `default_screen` VARCHAR(20) NOT NULL DEFAULT 'mytasks';

-- Per-user "notify me about new comments" preference (ON by default).
ALTER TABLE `user_settings`
  ADD COLUMN IF NOT EXISTS `notify_comments` TINYINT(1) DEFAULT 1;

-- ===== B4b/c: Folders for files =====
CREATE TABLE IF NOT EXISTS `file_folders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `parent_folder_id` INT NULL,
  `name` VARCHAR(255) NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attach files to a folder (NULL = project/sub-project root).
ALTER TABLE `files`
  ADD COLUMN IF NOT EXISTS `folder_id` INT NULL AFTER `project_id`;

-- ===== B4d: Upload via link (SharePoint / external URL) =====
-- Widen the storage enum to allow 'link' entries (external URL, no bytes stored).
ALTER TABLE `files` MODIFY COLUMN `storage` ENUM('local','sharepoint','link') NOT NULL DEFAULT 'local';
-- External link URL (only used when storage='link').
ALTER TABLE `files`
  ADD COLUMN IF NOT EXISTS `external_url` TEXT NULL AFTER `sp_drive_id`;

-- ===== B6: Per-user card ordering =====
-- Stores a user's preferred ordering of category cards and of sub-project cards
-- within each category. scope_id = 0 for the top-level category grid, or the
-- category id for a sub-project grid. order_json is a JSON array of project ids.
CREATE TABLE IF NOT EXISTS `card_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `scope_id` INT NOT NULL DEFAULT 0,
  `order_json` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_scope` (`user_id`, `scope_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== B7: DM read tracking (mirror of chat_reads, keyed by channel) =====
CREATE TABLE IF NOT EXISTS `channel_reads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `channel_id` INT NOT NULL,
  `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_channel` (`user_id`, `channel_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== B7: Comments feed read tracking =====
-- Tracks the last time a user viewed the "Comments" feed (project chat across
-- all their projects). Unread = project_chat authored by others after this time.
CREATE TABLE IF NOT EXISTS `comment_feed_reads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VGo Migration 004 — Multi-user tasks, SMTP settings, notification preferences

-- Multi-user task assignment: create task_assignees table
CREATE TABLE IF NOT EXISTS `task_assignees` (
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`, `user_id`),
  FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing assigned_to data to task_assignees
INSERT IGNORE INTO `task_assignees` (`task_id`, `user_id`)
SELECT `id`, `assigned_to` FROM `tasks` WHERE `assigned_to` IS NOT NULL;

-- Add notification preference enum to user_settings
ALTER TABLE `user_settings` ADD COLUMN `email_notify_pref` ENUM('all','mentions','none') DEFAULT 'all' AFTER `notify_digest`;

-- SMTP settings table (workspace-level)
CREATE TABLE IF NOT EXISTS `smtp_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `host` VARCHAR(255) NOT NULL,
  `port` INT NOT NULL DEFAULT 465,
  `username` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `from_name` VARCHAR(120) DEFAULT 'VGo',
  `from_email` VARCHAR(255) NOT NULL,
  `encryption` ENUM('ssl','tls','none') DEFAULT 'ssl',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `workspace_smtp` (`workspace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
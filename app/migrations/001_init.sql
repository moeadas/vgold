-- VGo Database Schema
-- Simple, clean project management database

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `avatar_color` VARCHAR(7) DEFAULT '#9C8060',
  `role` ENUM('admin','member') DEFAULT 'member',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workspaces` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workspace_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` ENUM('admin','member') DEFAULT 'member',
  `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ws_user` (`workspace_id`, `user_id`),
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invitations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','member') DEFAULT 'member',
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `status` ENUM('pending','accepted','expired') DEFAULT 'pending',
  `invited_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `color` VARCHAR(7) DEFAULT '#7C8454',
  `health` ENUM('on_track','at_risk','blocked','completed') DEFAULT 'on_track',
  `progress` INT DEFAULT 0,
  `due_date` DATE NULL,
  `deadline_date` DATE NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` VARCHAR(60) DEFAULT '',
  UNIQUE KEY `proj_user` (`project_id`, `user_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `title` VARCHAR(300) NOT NULL,
  `description` TEXT,
  `status` ENUM('todo','in_progress','canceled','completed') DEFAULT 'todo',
  `priority` ENUM('normal','urgent') DEFAULT 'normal',
  `assigned_to` INT,
  `created_by` INT NOT NULL,
  `due_date` DATE NULL,
  `deadline_date` DATE NULL,
  `ai_flagged` TINYINT(1) DEFAULT 0,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `uploaded_by` INT NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(500) NOT NULL,
  `file_size` BIGINT NOT NULL,
  `file_type` VARCHAR(100),
  `file_ext` VARCHAR(10),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('channel','dm') DEFAULT 'channel',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channel_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `channel_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  UNIQUE KEY `chan_user` (`channel_id`, `user_id`),
  FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `channel_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_chat` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `notify_assigned` TINYINT(1) DEFAULT 1,
  `notify_mentions` TINYINT(1) DEFAULT 1,
  `notify_chat` TINYINT(1) DEFAULT 0,
  `notify_digest` TINYINT(1) DEFAULT 1,
  `week_start` VARCHAR(10) DEFAULT 'Monday',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `provider` ENUM('gemini','anthropic','openai','ollama') NOT NULL,
  `api_key` TEXT,
  `base_url` VARCHAR(255),
  `model` VARCHAR(120),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_provider` (`user_id`, `provider`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `digests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `content` LONGTEXT,
  `for_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed default admin user + workspace.
-- SECURITY: the password below is an intentionally INVALID/unusable hash (not a real bcrypt
-- string) so this account CANNOT be logged into until an administrator sets a real password.
-- Set a password after import, e.g. from a one-off script:
--   php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
-- then: UPDATE users SET password='<hash>' WHERE email='moe@northwind.studio';
INSERT INTO `users` (`name`, `email`, `password`, `avatar_color`, `role`) 
VALUES ('Moe Adas', 'moe@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#7e6549', 'admin');
INSERT INTO `workspaces` (`name`, `created_by`) VALUES ('Northwind Studio', 1);
INSERT INTO `workspace_members` (`workspace_id`, `user_id`, `role`) VALUES (1, 1, 'admin');
INSERT INTO `user_settings` (`user_id`) VALUES (1);
-- Seed default channels
INSERT INTO `channels` (`workspace_id`, `name`, `type`) VALUES (1, 'general', 'channel');
INSERT INTO `channels` (`workspace_id`, `name`, `type`) VALUES (1, 'random', 'channel');
INSERT INTO `channel_members` (`channel_id`, `user_id`) VALUES (1, 1);
INSERT INTO `channel_members` (`channel_id`, `user_id`) VALUES (2, 1);
-- Seed a sample project
INSERT INTO `projects` (`workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) 
VALUES (1, 'Rivera launch', 'Marketing site for the Rivera rebrand. Design sign-off is the last blocker before build.', '#C99520', 'at_risk', 78, '2026-06-27', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (1, 1, 'Lead');
INSERT INTO `tasks` (`project_id`, `title`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(1, 'Final design sign-off', 'todo', 'urgent', 1, 1, '2026-06-23', 1),
(1, 'Wire up the pricing section', 'in_progress', 'urgent', 1, 1, '2026-06-25', 0),
(1, 'QA pass on mobile breakpoints', 'in_progress', 'normal', 1, 1, '2026-06-26', 0),
(1, 'Copy review', 'completed', 'normal', 1, 1, '2026-06-20', 0),
(1, 'Set up the staging domain', 'completed', 'normal', 1, 1, '2026-06-18', 0);
-- 008_task_status_and_agenda.sql
-- Task status simplification + meeting agenda table + message attachments + group DM support

-- ===== 1. TASK STATUS: simplify to in_progress + completed =====
-- Convert existing 'todo' tasks to 'in_progress'
UPDATE `tasks` SET `status` = 'in_progress' WHERE `status` = 'todo';
-- Convert 'canceled' tasks to 'completed' (they are no longer active)
UPDATE `tasks` SET `status` = 'completed', `completed_at` = COALESCE(`completed_at`, `updated_at`) WHERE `status` = 'canceled';

-- Modify the enum column to only allow in_progress and completed
ALTER TABLE `tasks` MODIFY COLUMN `status` ENUM('in_progress','completed') DEFAULT 'in_progress';

-- ===== 2. MESSAGE ATTACHMENTS TABLE =====
CREATE TABLE IF NOT EXISTS `message_attachments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(500) NOT NULL,
  `file_size` BIGINT NOT NULL,
  `file_type` VARCHAR(100),
  `file_ext` VARCHAR(10),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 3. GROUP DM SUPPORT =====
-- Add 'group_dm' to channels type enum
ALTER TABLE `channels` MODIFY COLUMN `type` ENUM('channel','dm','group_dm') DEFAULT 'channel';

-- ===== 4. MEETING AGENDA TABLE =====
CREATE TABLE IF NOT EXISTS `meeting_agenda` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `title` VARCHAR(300) NOT NULL,
  `description` TEXT,
  `assigned_to` INT,
  `related_task_id` INT,
  `related_project_id` INT,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL,
  FOREIGN KEY (`workspace_id`) REFERENCES `workspaces`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`related_task_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`related_project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
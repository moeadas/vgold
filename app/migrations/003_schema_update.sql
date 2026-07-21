-- VGo Schema Update — new statuses, deadlines, progress

-- First migrate statuses
UPDATE `tasks` SET `status` = 'completed' WHERE `status` = 'done';
UPDATE `tasks` SET `status` = 'todo' WHERE `status` = 'blocked';

-- Update task status enum: todo, in_progress, canceled, completed
ALTER TABLE `tasks` MODIFY COLUMN `status` ENUM('todo','in_progress','canceled','completed') DEFAULT 'todo';

-- Update project health enum: on_track, at_risk, blocked, completed, cancelled
ALTER TABLE `projects` MODIFY COLUMN `health` ENUM('on_track','at_risk','blocked','completed','cancelled') DEFAULT 'on_track';

-- Add deadline_date to tasks
ALTER TABLE `tasks` ADD COLUMN `deadline_date` DATE NULL AFTER `due_date`;
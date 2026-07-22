-- VGold native CRM modules and per-user access control.
-- Requires the prefixed CRM schema from 011_crm_integration.sql.

CREATE TABLE IF NOT EXISTS `user_module_access` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `module_key` VARCHAR(80) NOT NULL,
  `can_access` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `workspace_user_module` (`workspace_id`, `user_id`, `module_key`),
  KEY `user_module_idx` (`user_id`, `module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workspace_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `workspace_id` INT NOT NULL,
  `setting_group` VARCHAR(60) NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `workspace_group_key` (`workspace_id`, `setting_group`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_module_access (workspace_id, user_id, module_key, can_access, updated_by)
SELECT wm.workspace_id, u.id, modules.module_key, 1, NULL
FROM users u
JOIN workspace_members wm ON wm.user_id = u.id
JOIN (
  SELECT 'crm.dashboard' module_key UNION ALL SELECT 'crm.leads' UNION ALL
  SELECT 'crm.interactions' UNION ALL SELECT 'crm.proposals' UNION ALL
  SELECT 'crm.email' UNION ALL SELECT 'crm.communications' UNION ALL
  SELECT 'crm.automation' UNION ALL SELECT 'crm.reports' UNION ALL
  SELECT 'crm.knowledge'
) modules
WHERE u.crm_user_id IS NOT NULL
  AND (
    u.crm_role IN ('Admin', 'Sales Manager')
    OR (u.crm_role = 'Sales Rep' AND modules.module_key IN ('crm.dashboard','crm.leads','crm.interactions','crm.communications','crm.knowledge'))
    OR (u.crm_role = 'Viewer' AND modules.module_key IN ('crm.dashboard','crm.leads','crm.reports','crm.knowledge'))
  );

-- Canonical task-side link metadata; applied idempotently at runtime by Schema.
ALTER TABLE `tasks` ADD COLUMN IF NOT EXISTS `source_module` VARCHAR(40) NULL;
ALTER TABLE `tasks` ADD COLUMN IF NOT EXISTS `source_record_id` INT NULL;
ALTER TABLE `tasks` ADD COLUMN IF NOT EXISTS `crm_lead_id` INT NULL;

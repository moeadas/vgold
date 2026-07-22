-- 011_crm_integration.sql — VGold Phase 1: CRM data model integration
-- ============================================================================
-- Strategy (Option B, Layer 1):
--   * CRM tables are imported VERBATIM under a `crm_` prefix, preserving every
--     internal FK (they reference each other by their own integer PKs, not VGo
--     tables) so no CRM data is lost or re-keyed.
--   * The single bridge to the VGo/VGold world is `users.crm_user_id`, which
--     links each unified user to their original CRM `user_id`. Reconciliation is
--     by Microsoft/login email (case-insensitive) — see the importer.
--   * A "CRM" root category is created so CRM-derived tasks (Phase 5) have a home.
-- This file is idempotent (CREATE TABLE IF NOT EXISTS / guarded ALTERs via
-- Schema.php) so SiteGround runtime application is safe.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Extend unified `users` with CRM linkage + CRM role vocabulary
-- ---------------------------------------------------------------------------
-- (ALTERs are applied idempotently by Schema::ensureCrm(); listed here as the
--  canonical definition.)
--   crm_user_id  : original CRM users.user_id this account maps to (NULL if none)
--   crm_role     : preserved CRM role vocabulary (Admin/Sales Manager/Sales Rep/Viewer)
--   crm_username : original CRM username (for reference / external login)
ALTER TABLE `users`
  ADD COLUMN `crm_user_id`  INT NULL AFTER `ms_oid`,
  ADD COLUMN `crm_role`     VARCHAR(32) NULL AFTER `crm_user_id`,
  ADD COLUMN `crm_username` VARCHAR(50) NULL AFTER `crm_role`,
  ADD UNIQUE KEY `uniq_crm_user_id` (`crm_user_id`);

-- ---------------------------------------------------------------------------
-- 2) Configurable CRM<->VGold role mapping (Settings-driven, Phase 4 will edit)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_role_map` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `crm_role` VARCHAR(32) NOT NULL,
  `vgold_role` ENUM('admin','member') NOT NULL DEFAULT 'member',
  UNIQUE KEY `uniq_crm_role` (`crm_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default mapping (confirmed with user): Admin->admin, everything else->member.
INSERT INTO `crm_role_map` (`crm_role`, `vgold_role`) VALUES
  ('Admin','admin'),
  ('Sales Manager','member'),
  ('Sales Rep','member'),
  ('Viewer','member')
ON DUPLICATE KEY UPDATE `vgold_role` = VALUES(`vgold_role`);

-- ---------------------------------------------------------------------------
-- 3) CRM tables (prefixed `crm_`) — structure mirrors the live CRM verbatim.
--    Internal FKs are recreated between crm_ tables. No FK points at VGo tables;
--    the bridge is users.crm_user_id (see importer).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `crm_users` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('Admin','Sales Manager','Sales Rep','Viewer') NOT NULL DEFAULT 'Sales Rep',
  `phone` VARCHAR(20) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_leads` (
  `lead_id` INT NOT NULL AUTO_INCREMENT,
  `lead_type` ENUM('Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other') NOT NULL DEFAULT 'Stable',
  `company_name` VARCHAR(200) DEFAULT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `title_position` VARCHAR(100) DEFAULT NULL,
  `region` ENUM('North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other') DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `mobile` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `facebook_url` VARCHAR(255) DEFAULT NULL,
  `instagram_url` VARCHAR(255) DEFAULT NULL,
  `linkedin_url` VARCHAR(255) DEFAULT NULL,
  `twitter_url` VARCHAR(255) DEFAULT NULL,
  `youtube_url` VARCHAR(255) DEFAULT NULL,
  `specialization` VARCHAR(100) DEFAULT NULL,
  `facility_type` ENUM('Breeding','Racing','Training','Multi-Purpose','Other') DEFAULT NULL,
  `number_of_horses` INT DEFAULT NULL,
  `horse_breed` VARCHAR(100) DEFAULT NULL,
  `horse_sex` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `lead_status` ENUM('New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold') NOT NULL DEFAULT 'New Lead',
  `lead_source` ENUM('Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other') DEFAULT 'Other',
  `priority` ENUM('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `assigned_to` INT DEFAULT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`lead_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`),
  KEY `lead_status` (`lead_status`),
  KEY `region` (`region`),
  KEY `country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_interactions` (
  `interaction_id` INT NOT NULL AUTO_INCREMENT,
  `lead_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `interaction_type` ENUM('Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS','VoIP Call') NOT NULL,
  `interaction_date` DATETIME NOT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `outcome` ENUM('Positive','Neutral','Negative','No Response') DEFAULT NULL,
  `next_action` VARCHAR(255) DEFAULT NULL,
  `next_action_date` DATE DEFAULT NULL,
  `duration_minutes` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`interaction_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `interaction_date` (`interaction_date`),
  KEY `next_action_date` (`next_action_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_documents` (
  `document_id` INT NOT NULL AUTO_INCREMENT,
  `lead_id` INT NOT NULL,
  `uploaded_by` INT NOT NULL,
  `document_name` VARCHAR(255) NOT NULL,
  `document_type` ENUM('Proposal','Contract','Brochure','Test Results','Presentation','Other') DEFAULT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `lead_id` (`lead_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_proposals` (
  `proposal_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estimate_number` INT UNSIGNED NOT NULL,
  `proposal_date` DATE NOT NULL,
  `customer_company` VARCHAR(255) DEFAULT NULL,
  `contact_name` VARCHAR(255) DEFAULT NULL,
  `customer_address` TEXT DEFAULT NULL,
  `line_items` LONGTEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `accepted_by` VARCHAR(255) DEFAULT NULL,
  `accepted_date` DATE DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `status` ENUM('Draft','Sent','Accepted','Declined') NOT NULL DEFAULT 'Draft',
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`proposal_id`),
  KEY `idx_estimate_number` (`estimate_number`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_proposal_date` (`proposal_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_activity_log` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` ENUM('Lead','User','Document','Interaction','System','Campaign','VoIP','WhatsApp') NOT NULL,
  `entity_id` INT DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_voip_calls` (
  `call_id` INT NOT NULL AUTO_INCREMENT,
  `lead_id` INT DEFAULT NULL,
  `user_id` INT NOT NULL,
  `twilio_call_sid` VARCHAR(64) DEFAULT NULL,
  `direction` ENUM('Outbound','Inbound') NOT NULL,
  `from_number` VARCHAR(20) NOT NULL,
  `to_number` VARCHAR(20) NOT NULL,
  `status` ENUM('Initiated','Ringing','In-Progress','Completed','Busy','No-Answer','Canceled','Failed') NOT NULL,
  `duration_seconds` INT DEFAULT NULL,
  `recording_url` VARCHAR(500) DEFAULT NULL,
  `recording_sid` VARCHAR(64) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `outcome` ENUM('Positive','Neutral','Negative','No Response','Voicemail') DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`call_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `twilio_call_sid` (`twilio_call_sid`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_whatsapp_messages` (
  `message_id` INT NOT NULL AUTO_INCREMENT,
  `lead_id` INT DEFAULT NULL,
  `user_id` INT DEFAULT NULL,
  `twilio_message_sid` VARCHAR(64) DEFAULT NULL,
  `direction` ENUM('Outbound','Inbound') NOT NULL,
  `from_number` VARCHAR(20) NOT NULL,
  `to_number` VARCHAR(20) NOT NULL,
  `profile_name` VARCHAR(200) DEFAULT NULL,
  `message_body` TEXT DEFAULT NULL,
  `media_url` VARCHAR(500) DEFAULT NULL,
  `media_type` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('Queued','Sent','Delivered','Read','Failed','Received') NOT NULL,
  `error_code` VARCHAR(20) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `template_id` INT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `twilio_message_sid` (`twilio_message_sid`),
  KEY `to_number` (`to_number`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_notifications` (
  `notification_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `link` VARCHAR(500) DEFAULT NULL,
  `lead_id` INT DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_lead` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_automation_rules` (
  `rule_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `trigger_type` VARCHAR(50) NOT NULL,
  `trigger_config` TEXT DEFAULT NULL,
  `conditions` TEXT DEFAULT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `action_config` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `run_count` INT NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rule_id`),
  KEY `trigger_type` (`trigger_type`),
  KEY `is_active` (`is_active`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_automation_logs` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `rule_id` INT NOT NULL,
  `rule_name` VARCHAR(200) DEFAULT NULL,
  `trigger_type` VARCHAR(50) NOT NULL,
  `lead_id` INT DEFAULT NULL,
  `proposal_id` INT DEFAULT NULL,
  `status` ENUM('success','failed','skipped') NOT NULL,
  `action_taken` VARCHAR(500) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `execution_ms` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `rule_id` (`rule_id`),
  KEY `lead_id` (`lead_id`),
  KEY `proposal_id` (`proposal_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_email_campaigns` (
  `campaign_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `from_name` VARCHAR(100) DEFAULT NULL,
  `from_email` VARCHAR(100) DEFAULT NULL,
  `reply_to` VARCHAR(100) DEFAULT NULL,
  `template_id` INT DEFAULT NULL,
  `list_id` INT DEFAULT NULL,
  `content_json` LONGTEXT DEFAULT NULL,
  `content_html` LONGTEXT DEFAULT NULL,
  `status` ENUM('Draft','Scheduled','Sending','Sent','Paused','Cancelled') NOT NULL DEFAULT 'Draft',
  `scheduled_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `total_recipients` INT NOT NULL DEFAULT 0,
  `total_sent` INT NOT NULL DEFAULT 0,
  `total_failed` INT NOT NULL DEFAULT 0,
  `total_opened` INT NOT NULL DEFAULT 0,
  `total_clicked` INT NOT NULL DEFAULT 0,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campaign_id`),
  KEY `template_id` (`template_id`),
  KEY `list_id` (`list_id`),
  KEY `created_by` (`created_by`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_email_campaign_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `campaign_id` INT NOT NULL,
  `lead_id` INT DEFAULT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `opened_at` DATETIME DEFAULT NULL,
  `clicked_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `lead_id` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_email_lists` (
  `list_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`list_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_email_list_members` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `list_id` INT NOT NULL,
  `lead_id` INT DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `name` VARCHAR(200) DEFAULT NULL,
  `subscribed` TINYINT(1) NOT NULL DEFAULT 1,
  `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`),
  KEY `lead_id` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_email_templates` (
  `template_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `content_html` LONGTEXT DEFAULT NULL,
  `content_json` LONGTEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_whatsapp_templates` (
  `template_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `variables` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_knowledge_hub_cards` (
  `card_id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` LONGTEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `sort_order` INT DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_settings` (
  `setting_id` INT NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `setting_type` VARCHAR(50) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_webhook_endpoints` (
  `endpoint_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `token` VARCHAR(100) NOT NULL,
  `source` VARCHAR(100) DEFAULT NULL,
  `default_assigned_to` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `field_map` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`endpoint_id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_webhook_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `endpoint_id` INT DEFAULT NULL,
  `payload` LONGTEXT DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `lead_id` INT DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `endpoint_id` (`endpoint_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4) Bridge table: task <-> crm interaction/lead (populated in Phase 5)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_task_links` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT NOT NULL,
  `crm_lead_id` INT NULL,
  `crm_interaction_id` INT NULL,
  `link_type` VARCHAR(32) NOT NULL DEFAULT 'follow_up',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_task` (`task_id`),
  KEY `lead_idx` (`crm_lead_id`),
  KEY `interaction_idx` (`crm_interaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

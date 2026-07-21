-- ============================================
-- Victory Genomics CRM V2 — Email Marketing Tables
-- Run AFTER the base schema (schema.sql)
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- Add 'Campaign' to activity_log entity_type
ALTER TABLE `activity_log`
  MODIFY COLUMN `entity_type` enum('Lead','User','Document','Interaction','System','Campaign') NOT NULL;

-- ============================================
-- Table: email_templates
-- ============================================
CREATE TABLE IF NOT EXISTS `email_templates` (
  `template_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content_json` longtext DEFAULT NULL COMMENT 'JSON block structure for builder',
  `content_html` longtext DEFAULT NULL COMMENT 'Rendered HTML output',
  `category` enum('Marketing','Newsletter','Announcement','Follow-up','Welcome','Custom') DEFAULT 'Custom',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `email_templates_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: email_lists
-- ============================================
CREATE TABLE IF NOT EXISTS `email_lists` (
  `list_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `filter_criteria` text DEFAULT NULL COMMENT 'JSON: auto-populate from lead filters',
  `is_dynamic` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=auto-refresh from filter, 0=static members',
  `member_count` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`list_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `email_lists_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: email_list_members
-- ============================================
CREATE TABLE IF NOT EXISTS `email_list_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `status` enum('Active','Unsubscribed','Bounced') NOT NULL DEFAULT 'Active',
  `subscribed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `list_lead_unique` (`list_id`, `lead_id`),
  KEY `lead_id` (`lead_id`),
  KEY `status` (`status`),
  CONSTRAINT `elm_list_fk` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`list_id`) ON DELETE CASCADE,
  CONSTRAINT `elm_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: email_campaigns
-- ============================================
CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `campaign_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `from_name` varchar(100) DEFAULT NULL,
  `from_email` varchar(100) DEFAULT NULL,
  `reply_to` varchar(100) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `list_id` int(11) DEFAULT NULL,
  `content_json` longtext DEFAULT NULL,
  `content_html` longtext DEFAULT NULL,
  `status` enum('Draft','Scheduled','Sending','Sent','Paused','Cancelled') NOT NULL DEFAULT 'Draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `total_sent` int(11) NOT NULL DEFAULT 0,
  `total_failed` int(11) NOT NULL DEFAULT 0,
  `total_opened` int(11) NOT NULL DEFAULT 0,
  `total_clicked` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campaign_id`),
  KEY `template_id` (`template_id`),
  KEY `list_id` (`list_id`),
  KEY `created_by` (`created_by`),
  KEY `status` (`status`),
  CONSTRAINT `ec_template_fk` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`template_id`) ON DELETE SET NULL,
  CONSTRAINT `ec_list_fk` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`list_id`) ON DELETE SET NULL,
  CONSTRAINT `ec_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: email_campaign_log
-- ============================================
CREATE TABLE IF NOT EXISTS `email_campaign_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `status` enum('Queued','Sent','Failed','Opened','Clicked','Bounced') NOT NULL DEFAULT 'Queued',
  `sent_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `clicked_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `tracking_token` varchar(64) NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `lead_id` (`lead_id`),
  KEY `tracking_token` (`tracking_token`),
  KEY `status` (`status`),
  CONSTRAINT `ecl_campaign_fk` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`campaign_id`) ON DELETE CASCADE,
  CONSTRAINT `ecl_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Default SMTP settings
-- ============================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
('smtp_host', '', 'text'),
('smtp_port', '465', 'number'),
('smtp_username', '', 'text'),
('smtp_password', '', 'password'),
('smtp_encryption', 'ssl', 'text'),
('email_from_name', 'Victory Genomics', 'text'),
('email_from_address', '', 'email'),
('email_reply_to', '', 'email'),
('company_address', '', 'text'),
('email_batch_size', '50', 'number'),
('email_batch_delay', '2', 'number');

COMMIT;

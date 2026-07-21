-- ============================================
-- Victory Genomics CRM - Database Schema
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Database: victory_genomics_crm

-- ============================================
-- Table: users
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('Admin','Sales Manager','Sales Rep','Viewer') NOT NULL DEFAULT 'Sales Rep',
  `phone` varchar(20) DEFAULT NULL,
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `wa_notify_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: leads
-- ============================================
CREATE TABLE IF NOT EXISTS `leads` (
  `lead_id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_type` enum('Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other') NOT NULL DEFAULT 'Stable',
  `company_name` varchar(200) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `title_position` varchar(100) DEFAULT NULL,
  `region` enum('North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other') NOT NULL,
  `country` varchar(100) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `twitter_url` varchar(255) DEFAULT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `facility_type` enum('Breeding','Racing','Training','Multi-Purpose','Other') DEFAULT NULL,
  `number_of_horses` int(11) DEFAULT NULL,
  `horse_breed` varchar(100) DEFAULT NULL,
  `horse_sex` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `lead_status` enum('New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold') NOT NULL DEFAULT 'New Lead',
  `lead_source` enum('Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other') DEFAULT 'Other',
  `priority` enum('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`lead_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`),
  KEY `lead_status` (`lead_status`),
  KEY `region` (`region`),
  KEY `country` (`country`),
  CONSTRAINT `leads_assigned_fk` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `leads_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: interactions
-- ============================================
CREATE TABLE IF NOT EXISTS `interactions` (
  `interaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `interaction_type` enum('Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS') NOT NULL,
  `interaction_date` datetime NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `outcome` enum('Positive','Neutral','Negative','No Response') DEFAULT NULL,
  `next_action` varchar(255) DEFAULT NULL,
  `next_action_date` date DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`interaction_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `interaction_date` (`interaction_date`),
  CONSTRAINT `interactions_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `interactions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: documents
-- ============================================
CREATE TABLE IF NOT EXISTS `documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `document_type` enum('Proposal','Contract','Brochure','Test Results','Presentation','Other') DEFAULT 'Other',
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `lead_id` (`lead_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `documents_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `documents_user_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: activity_log
-- ============================================
CREATE TABLE IF NOT EXISTS `activity_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` enum('Lead','User','Document','Interaction','System') NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `activity_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: settings
-- ============================================
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: webhook_endpoints
-- (Google Sheets / external lead import sources)
-- ============================================
CREATE TABLE IF NOT EXISTS `webhook_endpoints` (
  `endpoint_id`    int(11)      NOT NULL AUTO_INCREMENT,
  `name`           varchar(200) NOT NULL COMMENT 'Friendly name, e.g. Meta Ads - Saudi Arabia',
  `api_key`        varchar(64)  NOT NULL COMMENT 'Unique secret key for authenticating requests',
  `sheet_url`      varchar(500) DEFAULT NULL COMMENT 'Public Google Sheets URL or ID',
  `sheet_name`     varchar(100) DEFAULT NULL COMMENT 'Specific sheet/tab name (default: first sheet)',
  `assigned_to`    int(11)      DEFAULT NULL COMMENT 'Default sales manager to assign new leads to',
  `field_mapping`  text         NOT NULL COMMENT 'JSON: maps incoming field names to CRM lead columns',
  `lead_defaults`  text         DEFAULT NULL COMMENT 'JSON: default values for lead_type, lead_source, lead_status, priority, region, country',
  `enabled`        tinyint(1)   NOT NULL DEFAULT 1,
  `last_received`  timestamp    NULL DEFAULT NULL COMMENT 'Timestamp of last successful lead import',
  `last_synced_row` int(11)    NOT NULL DEFAULT 0 COMMENT 'Last row number successfully synced',
  `total_imported` int(11)      NOT NULL DEFAULT 0 COMMENT 'Running count of leads imported via this endpoint',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`endpoint_id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `webhook_assigned_fk` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook endpoints for Google Sheets / external lead imports';

-- ============================================
-- Table: webhook_log
-- (Audit log for webhook lead imports)
-- ============================================
CREATE TABLE IF NOT EXISTS `webhook_log` (
  `log_id`        int(11)      NOT NULL AUTO_INCREMENT,
  `endpoint_id`   int(11)      NOT NULL,
  `lead_id`       int(11)      DEFAULT NULL COMMENT 'Created lead ID (null if duplicate/error)',
  `status`        enum('created','duplicate','error') NOT NULL DEFAULT 'created',
  `raw_payload`   text         DEFAULT NULL COMMENT 'Original JSON payload received',
  `error_message` varchar(500) DEFAULT NULL,
  `ip_address`    varchar(45)  DEFAULT NULL,
  `created_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `endpoint_id` (`endpoint_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `webhook_log_endpoint_fk` FOREIGN KEY (`endpoint_id`) REFERENCES `webhook_endpoints` (`endpoint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit log for webhook lead imports';

-- ============================================
-- Insert Default Admin User
-- Password: Admin@123 (CHANGE IMMEDIATELY AFTER INSTALLATION)
-- ============================================
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `status`) VALUES
('admin', 'admin@victorygenomics.com', '$2y$12$qVcyFNvWp2I0COzf8w2VK.wtg8AUOz3C72S54hELo2ZyLWt8Bt1Sm', 'System Administrator', 'Admin', 'Active');

-- ============================================
-- Insert Default Settings
-- ============================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
('company_name', 'Victory Genomics', 'text'),
('company_email', 'info@victorygenomics.com', 'email'),
('company_phone', '', 'text'),
('company_website', 'https://victorygenomics.com', 'url'),
('records_per_page', '25', 'number'),
('date_format', 'Y-m-d', 'text'),
('timezone', 'UTC', 'text');

COMMIT;

-- ============================================
-- End of Database Schema
-- ============================================

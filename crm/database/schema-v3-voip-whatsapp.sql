-- ============================================
-- Victory Genomics CRM V3 — VoIP & WhatsApp Tables
-- Run AFTER base schema and email schema
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- Add 'VoIP' and 'WhatsApp' to activity_log entity_type
ALTER TABLE `activity_log`
  MODIFY COLUMN `entity_type` enum('Lead','User','Document','Interaction','System','Campaign','VoIP','WhatsApp') NOT NULL;

-- Add 'VoIP Call' and 'WhatsApp' to interactions type
ALTER TABLE `interactions`
  MODIFY COLUMN `interaction_type` enum('Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS','VoIP Call') NOT NULL;

-- ============================================
-- Table: voip_calls
-- ============================================
CREATE TABLE IF NOT EXISTS `voip_calls` (
  `call_id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `twilio_call_sid` varchar(64) DEFAULT NULL COMMENT 'Twilio Call SID',
  `direction` enum('Outbound','Inbound') NOT NULL DEFAULT 'Outbound',
  `from_number` varchar(20) NOT NULL,
  `to_number` varchar(20) NOT NULL,
  `status` enum('Initiated','Ringing','In-Progress','Completed','Busy','No-Answer','Canceled','Failed') NOT NULL DEFAULT 'Initiated',
  `duration_seconds` int(11) DEFAULT 0,
  `recording_url` varchar(500) DEFAULT NULL,
  `recording_sid` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `outcome` enum('Positive','Neutral','Negative','No Response','Voicemail') DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`call_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `twilio_call_sid` (`twilio_call_sid`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `voip_calls_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL,
  CONSTRAINT `voip_calls_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: whatsapp_messages
-- ============================================
CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `message_id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'CRM user who sent/received',
  `twilio_message_sid` varchar(64) DEFAULT NULL,
  `direction` enum('Outbound','Inbound') NOT NULL DEFAULT 'Outbound',
  `from_number` varchar(20) NOT NULL,
  `to_number` varchar(20) NOT NULL,
  `message_body` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `media_type` varchar(100) DEFAULT NULL,
  `status` enum('Queued','Sent','Delivered','Read','Failed','Received') NOT NULL DEFAULT 'Queued',
  `error_code` varchar(20) DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  KEY `twilio_message_sid` (`twilio_message_sid`),
  KEY `to_number` (`to_number`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `whatsapp_msgs_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL,
  CONSTRAINT `whatsapp_msgs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Table: whatsapp_templates
-- ============================================
CREATE TABLE IF NOT EXISTS `whatsapp_templates` (
  `template_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `category` enum('Introduction','Follow-up','Meeting','Proposal','Thank You','Custom') NOT NULL DEFAULT 'Custom',
  `body` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `wa_templates_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Insert Default WhatsApp Templates
-- ============================================
INSERT INTO `whatsapp_templates` (`name`, `category`, `body`, `is_active`, `created_by`) VALUES
('Introduction', 'Introduction', 'Hello {{contact_name}}, this is {{user_name}} from Victory Genomics. We specialize in advanced equine genomic testing and I''d love to discuss how we can help {{company_name}}. Would you have a few minutes to chat?', 1, 1),
('Follow-up After Call', 'Follow-up', 'Hi {{contact_name}}, thank you for speaking with us today. As discussed, I''ll be sending over more details about our genomic testing services for {{company_name}}. Please don''t hesitate to reach out if you have any questions!', 1, 1),
('Meeting Confirmation', 'Meeting', 'Hi {{contact_name}}, just confirming our meeting scheduled for tomorrow. Looking forward to discussing how Victory Genomics can support {{company_name}}. See you then!', 1, 1),
('Proposal Follow-up', 'Proposal', 'Hello {{contact_name}}, I wanted to follow up on the proposal we sent for {{company_name}}. Have you had a chance to review it? I''d be happy to answer any questions or schedule a call to discuss further.', 1, 1),
('Thank You', 'Thank You', 'Hi {{contact_name}}, thank you for choosing Victory Genomics! We''re excited to work with {{company_name}} and deliver exceptional equine genomic insights. Your account manager will be in touch shortly.', 1, 1);

-- ============================================
-- Insert Twilio settings placeholders
-- ============================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
('twilio_account_sid', '', 'text'),
('twilio_auth_token', '', 'password'),
('twilio_phone_number', '', 'text'),
('twilio_twiml_app_sid', '', 'text'),
('voip_enabled', '1', 'boolean'),
('voip_recording_enabled', '1', 'boolean'),
('whatsapp_enabled', '1', 'boolean'),
('whatsapp_sandbox_mode', '1', 'boolean'),
('whatsapp_from_number', '', 'text')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

COMMIT;

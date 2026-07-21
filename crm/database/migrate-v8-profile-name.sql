-- Migration V8: Add profile_name to whatsapp_messages
-- Stores the WhatsApp profile name from inbound messages (sent by Twilio webhook as ProfileName)
-- This allows showing the sender's name in the Unmatched tab before linking to a lead

ALTER TABLE `whatsapp_messages` ADD COLUMN `profile_name` VARCHAR(200) DEFAULT NULL AFTER `to_number`;
